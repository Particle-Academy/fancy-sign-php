<?php

declare(strict_types=1);

namespace FancySign;

use FancySign\Chain\EventChain;
use FancySign\Completion\Completion;
use FancySign\Mail\SignMailer;
use FancySign\Pdf\Renderer;
use FancySign\Repository\Repository;
use FancySign\Seal\Ed25519;
use FancySign\StepUp\StepUpVerifier;
use FancySign\Support\Clock;
use FancySign\Support\Context;
use FancySign\Support\SystemClock;
use FinePrint\Agent;
use FinePrint\Writer\Schema;

/**
 * The framework-free core: envelopes, signers, one-time codes, consent, the
 * ceremony, completion and the seal. Designed to support simple electronic
 * signatures (SES); see the README for what that does and does not mean.
 *
 * SENDER methods take an `$actor` the host authenticated. SIGNER methods take
 * the link token from the URL and, after the view code, a session token. Every
 * refusal is a {@see SignError} with a code from its closed set.
 *
 * The order the ceremony enforces: view code -> consent -> document opened ->
 * fields + mark + adoption text -> a FRESH accept code -> signed. The accept
 * code is checked last, so a request refused for another reason does not burn
 * it.
 */
final class Signing
{
    private readonly Renderer $renderer;

    private readonly string $sealPublic;

    /**
     * @param  callable(string): string  $linkUrl  the signer's page URL for a link token
     */
    public function __construct(
        private readonly Repository $repo,
        private readonly SignMailer $mailer,
        private readonly string $sealSecretKey,
        private readonly string $appKey,
        private $linkUrl,
        private readonly Config $config = new Config(),
        private readonly Clock $clock = new SystemClock(),
        private readonly ?StepUpVerifier $stepUp = null,
    ) {
        if (strlen($appKey) < 16) {
            throw new \InvalidArgumentException('fancy-sign needs an application key of at least 16 bytes to hash one-time codes.');
        }
        $this->sealPublic = Ed25519::publicFromSecret($sealSecretKey);
        $this->renderer = new Renderer();
    }

    public function config(): Config
    {
        return $this->config;
    }

    /** @return array{key_id: string, algorithm: string, public_key: string} */
    public function publicKey(): array
    {
        return ['key_id' => Ed25519::keyId($this->sealPublic), 'algorithm' => 'ed25519', 'public_key' => $this->sealPublic];
    }

    // ================================================================ sender

    /**
     * @param  array{title?: mixed, document?: mixed, signers?: mixed, settings?: mixed}  $input
     * @return array<string, mixed> the envelope, wire shape
     */
    public function create(array $input, string $actor, ?string $orgId = null): array
    {
        $id = self::ulid($this->clock->now()->getTimestamp());
        $env = $this->validated($input, ['id' => $id, 'org_id' => $orgId, 'status' => 'draft', 'created_at' => $this->at(),
            'sent_at' => null, 'completed_at' => null, 'completion' => null, 'created_by' => $actor]);
        $this->repo->saveEnvelope($env);
        $this->event($id, 'envelope.created', $actor, Context::none(), ['title' => $env['title']]);

        return $this->wire($env);
    }

    /** Change a draft: any of title, document, signers, settings. */
    public function update(string $id, array $input, string $actor): array
    {
        $env = $this->find($id);
        if ($env['status'] !== 'draft') {
            throw new SignError('envelope_not_draft', 'Only a draft envelope can be changed.');
        }
        $merged = [
            'title' => $input['title'] ?? $env['title'],
            'document' => $input['document'] ?? $env['document']['model'],
            'signers' => $input['signers'] ?? array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name'], 'email' => $s['email']], $env['signers']),
            'settings' => $input['settings'] ?? $env['settings'],
        ];
        $keep = array_intersect_key($env, array_flip(['id', 'org_id', 'status', 'created_at', 'sent_at', 'completed_at', 'completion', 'created_by']));
        $env = $this->validated($merged, $keep);
        $this->repo->saveEnvelope($env);

        return $this->wire($env);
    }

    /** Render the as-sent PDF, fix every field's placement, mail every signer a link. */
    public function send(string $id, string $actor): array
    {
        $env = $this->find($id);
        if ($env['status'] !== 'draft') {
            throw new SignError('envelope_not_draft', 'Only a draft envelope can be sent.');
        }
        try {
            $out = $this->renderer->asSent($env, (string) $this->config->get('signature_page_title'));
        } catch (\FinePrint\Exceptions\SchemaException $e) {
            throw new SignError('invalid_request', 'The document could not be rendered: '.($e->errors[0]['path'] ?? '').' '.($e->errors[0]['message'] ?? ''));
        }
        $placed = [];
        foreach ($out['fields'] as $f) {
            $key = $f['origin'] === 'page_mark' ? "every-page-{$f['kind']}-{$f['signer']}" : $f['id'];
            $placed[$key][] = ['page' => $f['page'], 'rect' => $f['rect']];
        }
        foreach ($env['fields'] as &$field) {
            $field['placement'] = $placed[$field['id']] ?? [];
        }
        unset($field);
        $this->repo->putBlob("as-sent/{$id}", $out['bytes']);
        $env['document']['as_sent_sha256'] = hash('sha256', $out['bytes']);
        $env['document']['as_sent_bytes'] = strlen($out['bytes']);
        $env['document']['pages'] = $out['pages'];
        $env['status'] = 'sent';
        $env['sent_at'] = $this->at();
        $this->repo->saveEnvelope($env);
        $this->event($id, 'envelope.sent', $actor, Context::none(), ['as_sent_sha256' => $env['document']['as_sent_sha256'], 'pages' => $out['pages']]);
        foreach ($env['signers'] as $signer) {
            $this->mailLink($env, $signer, $actor, 'email.sent');
        }

        return $this->wire($this->find($id));
    }

    public function void(string $id, string $reason, string $actor): array
    {
        $env = $this->find($id);
        if (! in_array($env['status'], ['draft', 'sent'], true)) {
            throw new SignError('envelope_not_open', 'Only a draft or sent envelope can be voided.');
        }
        $env['status'] = 'voided';
        $this->repo->saveEnvelope($env);
        $this->event($id, 'envelope.voided', $actor, Context::none(), ['reason' => $this->text($reason, (int) $this->config->get('max_decline_reason'), allowEmpty: true)]);

        return $this->wire($env);
    }

    /** Mail every signer who has not finished a NEW link. The old one stops working. */
    public function remind(string $id, string $actor): array
    {
        $env = $this->openEnvelope($this->find($id));
        foreach ($env['signers'] as $signer) {
            if (in_array($signer['status'], ['pending', 'viewed'], true)) {
                $this->mailLink($env, $signer, $actor, 'reminder.sent');
            }
        }

        return $this->wire($this->find($id));
    }

    /** @return array<string, mixed> wire shape */
    public function envelope(string $id): array
    {
        return $this->wire($this->find($id));
    }

    /** @return list<array<string, mixed>> */
    public function listEnvelopes(?string $orgId = null): array
    {
        return array_map(fn (array $e) => $this->wire($e), $this->repo->envelopes($orgId));
    }

    /** @return list<array<string, mixed>> */
    public function events(string $id): array
    {
        $this->find($id);

        return $this->repo->events($id);
    }

    public function asSentDocument(string $id): string
    {
        return $this->repo->blob("as-sent/{$id}") ?? throw new SignError('not_found', 'The envelope has not been sent.');
    }

    public function finalDocument(string $id): string
    {
        return $this->repo->blob("final/{$id}") ?? throw new SignError('not_found', 'The envelope is not completed.');
    }

    /** The final document if completed, else the as-sent one. */
    public function currentDocument(string $id): string
    {
        return $this->repo->blob("final/{$id}") ?? $this->asSentDocument($id);
    }

    /**
     * The certificate of completion: a PDF, or the same data as JSON.
     *
     * @return string|array<string, mixed>
     */
    public function certificate(string $id, string $format = 'pdf'): string|array
    {
        $env = $this->wire($this->find($id));
        $events = $this->repo->events($id);
        if ($format === 'json') {
            return ['envelope' => $env, 'completion' => $env['completion'], 'events' => $events];
        }

        return $this->renderer->certificate($env, $events);
    }

    // ================================================================ signer

    /** @return array{sent: true, expires_in: int} */
    public function requestCode(string $token, string $purpose, Context $ctx, ?string $session = null): array
    {
        if (! in_array($purpose, ['view', 'accept'], true)) {
            throw new SignError('invalid_request', 'purpose is view or accept.');
        }
        [$env, $signer] = $this->openLink($token);
        $this->signerMayAct($signer);
        if ($purpose === 'accept') {
            $this->session($env, $signer, $session);
        }
        $now = $this->clock->now()->getTimestamp();
        $window = $this->config->int('otp.window_seconds');
        if ($this->repo->hit("send|{$env['id']}|{$signer['id']}|{$purpose}", $window, $now) > $this->config->int('otp.sends_per_window')
            || ($ctx->ip !== null && $this->repo->hit("send-ip|{$ctx->ip}", $window, $now) > $this->config->int('otp.sends_per_ip'))) {
            throw new SignError('otp_rate_limited', 'Too many codes were requested. Try again later.');
        }
        if (! ($signer['link_opened'] ?? false)) {
            $this->event($env['id'], 'link.opened', $this->actor($signer), $ctx, []);
            $this->updateSigner($env['id'], $signer['id'], ['link_opened' => true]);
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $ttl = $this->config->int('otp.ttl_seconds');
        $this->repo->putOtp($this->otpKey($env, $signer, $purpose), ['hash' => $this->otpHash($env, $signer, $purpose, $code), 'expires_at' => $now + $ttl, 'attempts' => 0]);
        $this->mailer->sendCode($signer['email'], $signer['name'], $env['title'], $code, $purpose, $ttl);
        $this->event($env['id'], 'otp.sent', $this->actor($signer), $ctx, ['purpose' => $purpose]);

        return ['sent' => true, 'expires_in' => $ttl];
    }

    /**
     * Verify the VIEW code and open a signer session. An accept code is never
     * verified here: it is sent with `sign`, and consumed there.
     *
     * @return array{session: string, expires_at: string}
     */
    public function verifyCode(string $token, string $purpose, mixed $code, Context $ctx): array
    {
        if ($purpose !== 'view') {
            throw new SignError('invalid_request', 'Only a view code opens a session; the accept code is sent with sign.');
        }
        [$env, $signer] = $this->openLink($token);
        $this->signerMayAct($signer);
        $this->consumeCode($env, $signer, 'view', $code, $ctx);
        $session = self::randomToken();
        $expires = $this->clock->now()->getTimestamp() + $this->config->int('session_ttl_seconds');
        $this->repo->putSession(hash('sha256', $session), ['envelope_id' => $env['id'], 'signer_id' => $signer['id'], 'expires_at' => $expires]);

        return ['session' => $session, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires)];
    }

    /** @return array<string, mixed> SignerView */
    public function signerView(string $token, ?string $session): array
    {
        [$env, $signer] = $this->openLink($token, mustBeOpen: false);
        $this->session($env, $signer, $session);

        return $this->view($env, $signer);
    }

    public function consent(string $token, ?string $session, mixed $version, Context $ctx): array
    {
        [$env, $signer] = $this->openLink($token);
        $this->signerMayAct($signer);
        $this->session($env, $signer, $session);
        if ($version !== $this->config->consentVersion()) {
            throw new SignError('invalid_request', 'The consent version does not match the current disclosure.');
        }
        $event = $this->event($env['id'], 'consent.accepted', $this->actor($signer), $ctx, [
            'text_sha256' => hash('sha256', $this->config->consentText()), 'version' => $this->config->consentVersion(),
        ]);
        $this->updateSigner($env['id'], $signer['id'], [
            'consent' => ['text_sha256' => hash('sha256', $this->config->consentText()), 'version' => $this->config->consentVersion(), 'at' => $event['at']],
            'consent_seq' => $event['seq'],
        ]);

        return $this->signerView($token, $session);
    }

    /** The as-sent PDF. The first open, after consent, is the server-observed `document.opened`. */
    public function document(string $token, ?string $session, Context $ctx): string
    {
        [$env, $signer] = $this->openLink($token, mustBeOpen: false);
        $this->session($env, $signer, $session);
        if ($signer['consent'] === null) {
            throw new SignError('consent_required', 'Consent to sign electronically comes before the document.');
        }
        if (($signer['viewed_at'] ?? null) === null) {
            $event = $this->event($env['id'], 'document.opened', $this->actor($signer), $ctx, ['as_sent_sha256' => $env['document']['as_sent_sha256']]);
            $this->updateSigner($env['id'], $signer['id'], ['viewed_at' => $event['at']] + ($signer['status'] === 'pending' ? ['status' => 'viewed'] : []));
        }

        return $this->asSentDocument($env['id']);
    }

    /** A page the signer's browser says it showed. A claim, stored as `source: client`, and capped. */
    public function pageViewed(string $token, ?string $session, mixed $page, mixed $ms, Context $ctx): void
    {
        [$env, $signer] = $this->openLink($token);
        $this->session($env, $signer, $session);
        if (($signer['viewed_at'] ?? null) === null) {
            throw new SignError('not_viewed', 'The document has not been opened.');
        }
        if (! is_int($page) || $page < 1 || $page > (int) $env['document']['pages'] || ! is_int($ms) || $ms < 0 || $ms > $this->config->int('page_views.max_ms')) {
            throw new SignError('invalid_request', 'page must be a page of the document and ms a whole number of milliseconds.');
        }
        $forever = 10 * 365 * 86400;
        $now = $this->clock->now()->getTimestamp();
        if ($this->repo->hit("pv|{$env['id']}|{$signer['id']}|{$page}", $forever, $now) > $this->config->int('page_views.per_page')
            || $this->repo->hit("pv|{$env['id']}|{$signer['id']}", $forever, $now) > $this->config->int('page_views.per_signer')) {
            throw new SignError('limit_exceeded', 'Enough page views have been recorded for this page.');
        }
        $this->event($env['id'], 'page.viewed', $this->actor($signer), $ctx, ['page' => $page, 'ms' => $ms], 'client');
    }

    /**
     * Sign: every required field completed, a mark adopted with the adoption
     * text, and a FRESH accept code (plus a passkey under step-up).
     *
     * @param  array{fields?: mixed, mark?: mixed, adoption?: mixed, code?: mixed, passkey?: mixed}  $input
     */
    public function sign(string $token, ?string $session, array $input, Context $ctx): array
    {
        [$env, $signer] = $this->openLink($token);
        $this->signerMayAct($signer);
        $this->session($env, $signer, $session);
        if ($signer['consent'] === null) {
            throw new SignError('consent_required', 'Consent to sign electronically comes first.');
        }
        if (($signer['viewed_at'] ?? null) === null) {
            throw new SignError('not_viewed', 'The document must be opened before it is signed.');
        }
        $mark = $this->mark($input['mark'] ?? null);
        if (($input['adoption'] ?? null) !== $this->config->adoptionText()) {
            throw new SignError('invalid_request', 'The adoption text does not match.');
        }
        $mine = array_values(array_filter($env['fields'], fn (array $f) => $f['signer_id'] === $signer['id']));
        $given = $input['fields'] ?? [];
        if (! is_array($given) || array_diff(array_map('strval', array_keys($given)), array_column($mine, 'id')) !== []) {
            throw new SignError('invalid_request', 'fields names only this signer\'s field ids.');
        }
        foreach ($mine as $f) {
            if ($f['required'] && ($given[$f['id']] ?? false) !== true) {
                throw new SignError('fields_incomplete', 'Every required field must be completed.');
            }
        }
        $method = 'otp';
        $stepUpEvidence = null;
        if ($env['settings']['step_up'] === 'passkey') {
            $assertion = $input['passkey'] ?? null;
            $stepUpEvidence = is_array($assertion) && $this->stepUp !== null
                ? $this->stepUp->verify($env['id'], $signer['id'], $env['document']['as_sent_sha256'], $assertion) : null;
            if ($stepUpEvidence === null) {
                throw new SignError('step_up_required', 'This envelope needs a passkey confirmation to sign.');
            }
            $method = 'otp+passkey';
        }

        $otp = $this->consumeCode($env, $signer, 'accept', $input['code'] ?? null, $ctx);

        $actor = $this->actor($signer);
        foreach ($mine as $f) {
            if (($given[$f['id']] ?? false) === true) {
                $this->event($env['id'], 'field.completed', $actor, $ctx, ['field_id' => $f['id'], 'kind' => $f['kind']]);
            }
        }
        $this->event($env['id'], 'mark.adopted', $actor, $ctx, [
            'kind' => $mark['kind'], 'mark_sha256' => hash('sha256', Support\CanonicalJson::encode($mark)),
            'adoption_text_sha256' => hash('sha256', $this->config->adoptionText()),
        ]);
        $signed = $this->event($env['id'], 'signer.signed', $actor, $ctx, array_filter([
            'as_sent_sha256' => $env['document']['as_sent_sha256'], 'method' => $method, 'otp_seq' => $otp['seq'],
            'consent_seq' => $signer['consent_seq'], 'step_up' => $stepUpEvidence,
        ], fn ($v) => $v !== null));
        $this->updateSigner($env['id'], $signer['id'], [
            'status' => 'signed', 'signed_at' => $signed['at'], 'mark' => $mark, 'method' => $method, 'otp_seq' => $otp['seq'],
            'completed_fields' => array_keys(array_filter($given, fn ($v) => $v === true)),
        ]);

        $env = $this->find($env['id']);
        if (array_filter($env['signers'], fn (array $s) => $s['status'] !== 'signed') === []) {
            $this->complete($env);
        }

        return $this->signerView($token, $session);
    }

    public function decline(string $token, ?string $session, mixed $reason, Context $ctx): array
    {
        [$env, $signer] = $this->openLink($token);
        $this->signerMayAct($signer);
        $this->session($env, $signer, $session);
        $reason = $this->text($reason, (int) $this->config->get('max_decline_reason'));
        $event = $this->event($env['id'], 'signer.declined', $this->actor($signer), $ctx, ['reason' => $reason]);
        $this->updateSigner($env['id'], $signer['id'], ['status' => 'declined', 'declined_at' => $event['at'], 'decline_reason' => $reason]);
        $env = $this->find($env['id']);
        $env['status'] = 'declined';
        $this->repo->saveEnvelope($env);

        return $this->signerView($token, $session);
    }

    // ================================================================ completion

    private function complete(array $env): void
    {
        $asSent = $this->asSentDocument($env['id']);
        $final = $this->renderer->final($asSent, $env);
        $this->repo->putBlob("final/{$env['id']}", $final);
        $finalHash = hash('sha256', $final);
        $completed = $this->event($env['id'], 'envelope.completed', 'system', Context::none(), ['final_sha256' => $finalHash]);

        $signers = [];
        foreach ($env['signers'] as $s) {
            $signers[] = ['id' => $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'signed_at' => $s['signed_at'],
                'method' => $s['method'], 'otp_seq' => $s['otp_seq'], 'consent_seq' => $s['consent_seq']];
        }
        $completion = Completion::issue([
            'envelope_id' => $env['id'],
            'as_sent_sha256' => $env['document']['as_sent_sha256'],
            'as_sent_bytes' => $env['document']['as_sent_bytes'],
            'final_sha256' => $finalHash,
            'signers' => $signers,
            'chain_head' => $completed['hash'],
            'key_id' => Ed25519::keyId($this->sealPublic),
        ], $this->sealSecretKey);
        $this->event($env['id'], 'seal.applied', 'system', Context::none(), ['token' => $completion['token'], 'key_id' => $completion['key_id']]);

        $env = $this->find($env['id']);
        $env['document']['final_sha256'] = $finalHash;
        $env['completion'] = $completion;
        $env['status'] = 'completed';
        $env['completed_at'] = $completed['at'];
        $this->repo->saveEnvelope($env);
    }

    // ================================================================ internals

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $base
     * @return array<string, mixed> the stored envelope
     */
    private function validated(array $input, array $base): array
    {
        $title = $input['title'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title, 'UTF-8') > (int) $this->config->get('max_title')) {
            throw new SignError('invalid_request', 'title is a non-empty string of at most '.$this->config->get('max_title').' characters.');
        }
        $doc = $input['document'] ?? null;
        if (! is_array($doc) || ! is_array($doc['blocks'] ?? null) || count($doc['blocks']) > (int) $this->config->get('max_document_blocks')) {
            throw new SignError('invalid_request', 'document is a fine-print document with a blocks list.');
        }
        $errors = Agent::validate($doc, ['fields' => true]);
        if ($errors !== []) {
            throw new SignError('invalid_request', 'document is invalid at '.$errors[0]['path'].': '.$errors[0]['message']);
        }

        $list = $input['signers'] ?? null;
        if (! is_array($list) || ! array_is_list($list) || $list === [] || count($list) > (int) $this->config->get('max_signers')) {
            throw new SignError('invalid_request', 'signers is a list of 1 to '.$this->config->get('max_signers').' signers.');
        }
        $signers = [];
        foreach ($list as $i => $s) {
            $sid = $s['id'] ?? 's'.($i + 1);
            $name = $s['name'] ?? null;
            $email = $s['email'] ?? null;
            if (! is_string($sid) || preg_match(Schema::FIELD_ID, $sid) !== 1 || isset($signers[$sid])
                || ! is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > (int) $this->config->get('max_name')
                || ! is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new SignError('invalid_request', "signers[{$i}] needs a unique id (A-Z, a-z, 0-9, _ and -), a name and a valid email.");
            }
            $signers[$sid] = ['id' => $sid, 'name' => trim($name), 'email' => $email, 'order' => $i + 1, 'status' => 'pending',
                'consent' => null, 'consent_seq' => null, 'signed_at' => null, 'declined_at' => null, 'decline_reason' => null,
                'viewed_at' => null, 'link_opened' => false, 'mark' => null, 'method' => null, 'otp_seq' => null];
        }

        $settings = $this->settings($input['settings'] ?? []);
        $fields = [];
        foreach (self::fieldNodes($doc['blocks']) as $node) {
            if (! isset($signers[$node['signer']])) {
                throw new SignError('invalid_request', "the document field {$node['id']} names signer {$node['signer']}, who is not on the envelope.");
            }
            $fields[] = ['id' => $node['id'], 'signer_id' => $node['signer'], 'kind' => Schema::FIELD_KINDS[$node['type']],
                'anchor' => ['node' => $node['id']], 'required' => ($node['required'] ?? true) !== false,
                'label' => is_string($node['label'] ?? null) ? $node['label'] : null, 'placement' => null];
        }
        foreach ($signers as $sid => $s) {
            $fields[] = ['id' => "final-signature-{$sid}", 'signer_id' => $sid, 'kind' => 'signature', 'anchor' => ['final_page' => true], 'required' => true, 'label' => 'Signature', 'placement' => null];
            $fields[] = ['id' => "final-date-{$sid}", 'signer_id' => $sid, 'kind' => 'date_signed', 'anchor' => ['final_page' => true], 'required' => true, 'label' => 'Date signed', 'placement' => null];
            if ($settings['per_page'] !== 'none') {
                $fields[] = ['id' => "every-page-{$settings['per_page']}-{$sid}", 'signer_id' => $sid, 'kind' => $settings['per_page'], 'anchor' => ['every_page' => true], 'required' => true, 'label' => null, 'placement' => null];
            }
        }
        $ids = array_column($fields, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new SignError('invalid_request', 'a document field id collides with a generated one (final-..., every-page-...).');
        }

        return $base + [
            'title' => trim($title),
            'document' => ['model' => $doc, 'as_sent_sha256' => null, 'as_sent_bytes' => null, 'final_sha256' => null, 'pages' => null],
            'settings' => $settings,
            'signers' => array_values($signers),
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private function settings(mixed $in): array
    {
        if (! is_array($in)) {
            throw new SignError('invalid_request', 'settings must be an object.');
        }
        $s = [
            'per_page' => $in['per_page'] ?? 'none',
            'otp' => ['view' => true, 'accept' => true],
            'step_up' => $in['step_up'] ?? 'none',
            'level' => $in['level'] ?? 'ses',
            'seal' => $in['seal'] ?? 'none',
            'expires_at' => $in['expires_at'] ?? null,
        ];
        if (! in_array($s['per_page'], ['none', 'initials', 'signature'], true)) {
            throw new SignError('invalid_request', 'settings.per_page is none, initials or signature.');
        }
        if ($s['level'] !== 'ses') {
            throw new SignError('invalid_request', 'settings.level: only ses is built. Advanced signatures are a later phase.');
        }
        if ($s['seal'] !== 'none') {
            throw new SignError('invalid_request', 'settings.seal: only none is built. A PAdES seal is a later phase.');
        }
        if (! in_array($s['step_up'], ['none', 'passkey'], true) || ($s['step_up'] === 'passkey' && $this->stepUp === null)) {
            throw new SignError('invalid_request', 'settings.step_up is none, or passkey when the host has bound a step-up verifier.');
        }
        if ($s['expires_at'] !== null && (! is_string($s['expires_at']) || strtotime($s['expires_at']) === false)) {
            throw new SignError('invalid_request', 'settings.expires_at is an ISO-8601 time or null.');
        }
        if (isset($in['otp']) && $in['otp'] !== ['view' => true, 'accept' => true]) {
            throw new SignError('invalid_request', 'settings.otp: both codes are always required.');
        }

        return $s;
    }

    /** @return list<array<string, mixed>> every field node, in document order */
    private static function fieldNodes(array $blocks, int $depth = 0): array
    {
        $out = [];
        if ($depth > 16) {
            return $out;
        }
        foreach ($blocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (in_array($b['type'] ?? null, Schema::FIELD_TYPES, true)) {
                $out[] = $b;
            }
            if (is_array($b['blocks'] ?? null)) {
                array_push($out, ...self::fieldNodes($b['blocks'], $depth + 1));
            }
            foreach (is_array($b['rows'] ?? null) ? $b['rows'] : [] as $row) {
                foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $cell) {
                    if (is_array($cell['blocks'] ?? null)) {
                        array_push($out, ...self::fieldNodes($cell['blocks'], $depth + 1));
                    }
                }
            }
        }

        return $out;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function openLink(mixed $token, bool $mustBeOpen = true): array
    {
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            throw new SignError('link_invalid', 'This link is not valid.');
        }
        $link = $this->repo->link(hash('sha256', $token));
        $env = $link === null ? null : $this->repo->envelope($link['envelope_id']);
        $signer = $env === null ? null : (array_column($env['signers'], null, 'id')[$link['signer_id']] ?? null);
        if ($env === null || $signer === null) {
            throw new SignError('link_invalid', 'This link is not valid.');
        }
        if ($mustBeOpen) {
            $env = $this->openEnvelope($env);
        } elseif (in_array($env['status'], ['draft', 'voided', 'expired'], true)) {
            throw new SignError('envelope_not_open', 'This envelope is not open for signing.');
        }

        return [$env, $signer];
    }

    /** @return array<string, mixed> */
    private function openEnvelope(array $env): array
    {
        if ($env['status'] === 'sent' && $env['settings']['expires_at'] !== null && strtotime($env['settings']['expires_at']) <= $this->clock->now()->getTimestamp()) {
            $env['status'] = 'expired';
            $this->repo->saveEnvelope($env);
            $this->event($env['id'], 'envelope.expired', 'system', Context::none(), []);
        }
        if ($env['status'] !== 'sent') {
            throw new SignError('envelope_not_open', 'This envelope is not open for signing.');
        }

        return $env;
    }

    private function signerMayAct(array $signer): void
    {
        if ($signer['status'] === 'signed') {
            throw new SignError('already_signed', 'You have already signed.');
        }
        if ($signer['status'] === 'declined') {
            throw new SignError('already_declined', 'You have already declined.');
        }
    }

    private function session(array $env, array $signer, mixed $session): void
    {
        $row = is_string($session) && $session !== '' && strlen($session) <= 128 ? $this->repo->session(hash('sha256', $session)) : null;
        if ($row === null || $row['envelope_id'] !== $env['id'] || $row['signer_id'] !== $signer['id'] || $row['expires_at'] <= $this->clock->now()->getTimestamp()) {
            throw new SignError('session_required', 'Enter the code we sent to view this document.');
        }
    }

    /**
     * Consume a code by pull: it leaves the store in the same operation that
     * reads it, so a replay finds nothing. A wrong guess puts it back with one
     * more attempt; at the limit it stays, locked.
     *
     * @return array<string, mixed> the otp.verified event
     */
    private function consumeCode(array $env, array $signer, string $purpose, mixed $code, Context $ctx): array
    {
        $key = $this->otpKey($env, $signer, $purpose);
        $actor = $this->actor($signer);
        $row = $this->repo->pullOtp($key);
        $max = $this->config->int('otp.max_attempts');
        $fail = function (string $reason) use ($env, $actor, $ctx, $purpose): never {
            $this->event($env['id'], 'otp.failed', $actor, $ctx, ['purpose' => $purpose, 'reason' => $reason]);
            throw new SignError('otp_invalid', 'That code is not valid. Request a new one if it has expired.');
        };
        if ($row === null) {
            $fail('otp_unknown');
        }
        if ($row['attempts'] >= $max) {
            $this->repo->putOtp($key, $row);
            throw new SignError('otp_locked', 'Too many wrong codes. Request a new code.');
        }
        if ($row['expires_at'] <= $this->clock->now()->getTimestamp()) {
            $fail('otp_expired');
        }
        if (! is_string($code) || preg_match('/^\d{6}$/', $code) !== 1 || ! hash_equals($row['hash'], $this->otpHash($env, $signer, $purpose, $code))) {
            $row['attempts']++;
            $this->repo->putOtp($key, $row);
            if ($row['attempts'] >= $max) {
                $this->event($env['id'], 'otp.locked', $actor, $ctx, ['purpose' => $purpose]);
                throw new SignError('otp_locked', 'Too many wrong codes. Request a new code.');
            }
            $fail('otp_wrong');
        }

        return $this->event($env['id'], 'otp.verified', $actor, $ctx, ['purpose' => $purpose]);
    }

    private function otpKey(array $env, array $signer, string $purpose): string
    {
        return "{$env['id']}|{$signer['id']}|{$purpose}";
    }

    private function otpHash(array $env, array $signer, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$env['id']}|{$signer['id']}|{$purpose}|{$code}", $this->appKey);
    }

    /** @return array<string, mixed> a mark, normalised: drawn points become integers on Renderer::GRID */
    private function mark(mixed $mark): array
    {
        $bad = fn (string $why) => new SignError('mark_invalid', $why);
        if (! is_array($mark)) {
            throw $bad('mark is an object.');
        }
        if (($mark['kind'] ?? null) === 'typed') {
            $text = $mark['text'] ?? null;
            if (! is_string($text) || preg_match('/[\x00-\x1F\x7F]/', $text) === 1 || trim($text) === ''
                || mb_strlen($text, 'UTF-8') > $this->config->int('mark.max_typed') || ! mb_check_encoding($text, 'UTF-8')) {
                throw $bad('A typed mark is 1 to '.$this->config->int('mark.max_typed').' characters of text.');
            }

            return ['kind' => 'typed', 'text' => trim($text)];
        }
        if (($mark['kind'] ?? null) !== 'drawn') {
            throw $bad('mark.kind is drawn or typed. Images are not accepted.');
        }
        $strokes = $mark['strokes'] ?? null;
        if (! is_array($strokes) || ! array_is_list($strokes) || $strokes === [] || count($strokes) > $this->config->int('mark.max_strokes')) {
            throw $bad('A drawn mark is 1 to '.$this->config->int('mark.max_strokes').' strokes.');
        }
        $points = 0;
        $out = [];
        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || ! array_is_list($stroke) || $stroke === []) {
                throw $bad('Each stroke is a non-empty list of points.');
            }
            $line = [];
            foreach ($stroke as $pt) {
                if (++$points > $this->config->int('mark.max_points')) {
                    throw $bad('A drawn mark has at most '.$this->config->int('mark.max_points').' points.');
                }
                if (! is_array($pt) || count($pt) !== 2 || ! array_is_list($pt)) {
                    throw $bad('A point is [x, y].');
                }
                $q = [];
                foreach ($pt as $v) {
                    if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || $v < 0 || $v > 1) {
                        throw $bad('Point coordinates are numbers from 0 to 1.');
                    }
                    $q[] = (int) round($v * Renderer::GRID);
                }
                $line[] = $q;
            }
            $out[] = $line;
        }

        return ['kind' => 'drawn', 'strokes' => $out];
    }

    private function text(mixed $v, int $max, bool $allowEmpty = false): string
    {
        $t = is_string($v) && mb_check_encoding($v, 'UTF-8') ? trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $v)) : null;
        if ($t === null || (! $allowEmpty && $t === '') || mb_strlen($t, 'UTF-8') > $max) {
            throw new SignError('invalid_request', "A reason is 1 to {$max} characters.");
        }

        return $t;
    }

    private function mailLink(array $env, array $signer, string $actor, string $eventType): void
    {
        $token = self::randomToken();
        $this->repo->deleteLinks($env['id'], $signer['id']);
        $this->repo->putLink(hash('sha256', $token), $env['id'], $signer['id']);
        $this->mailer->sendLink($signer['email'], $signer['name'], $env['title'], ($this->linkUrl)($token));
        $this->event($env['id'], $eventType, $actor, Context::none(), ['signer_id' => $signer['id']]);
    }

    /** @param array<string, mixed> $changes */
    private function updateSigner(string $envelopeId, string $signerId, array $changes): void
    {
        $env = $this->find($envelopeId);
        foreach ($env['signers'] as &$s) {
            if ($s['id'] === $signerId) {
                $s = $changes + $s;
            }
        }
        unset($s);
        $this->repo->saveEnvelope($env);
    }

    /** @return array<string, mixed> the stored event */
    private function event(string $envelopeId, string $type, string $actor, Context $ctx, array $data, string $source = 'server'): array
    {
        $at = $this->at();

        return $this->repo->appendEvent($envelopeId, fn (?array $last) => EventChain::append($last, [
            'envelope_id' => $envelopeId, 'at' => $at, 'type' => $type, 'actor' => $actor, 'source' => $source,
            'ip' => $ctx->ip, 'user_agent' => $ctx->userAgent, 'data' => $data,
        ]));
    }

    private function actor(array $signer): string
    {
        return 'signer:'.$signer['id'];
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return $this->repo->envelope($id) ?? throw new SignError('not_found', 'No such envelope.');
    }

    /** @return array<string, mixed> SignerView */
    private function view(array $env, array $signer): array
    {
        $wire = $this->wire($env);

        return [
            'envelope' => ['id' => $env['id'], 'title' => $env['title'], 'status' => $env['status'], 'pages' => $env['document']['pages']],
            'signer' => ['id' => $signer['id'], 'name' => $signer['name'], 'email' => $signer['email'], 'status' => $signer['status'],
                'consent_at' => $signer['consent']['at'] ?? null, 'signed_at' => $signer['signed_at'], 'declined_at' => $signer['declined_at']],
            'consent' => ['required' => $signer['consent'] === null, 'text' => $this->config->consentText(), 'version' => $this->config->consentVersion()],
            'adoption_text' => $this->config->adoptionText(),
            'fields' => array_values(array_filter($wire['fields'], fn (array $f) => $f['signer_id'] === $signer['id'])),
        ];
    }

    /** @return array<string, mixed> the envelope as the wire contract shows it */
    private function wire(array $env): array
    {
        $events = $this->repo->events($env['id']);
        $signers = array_map(fn (array $s) => [
            'id' => $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'order' => $s['order'], 'status' => $s['status'],
            'consent' => $s['consent'], 'signed_at' => $s['signed_at'], 'declined_at' => $s['declined_at'], 'decline_reason' => $s['decline_reason'],
        ], $env['signers']);
        $done = [];
        foreach ($env['signers'] as $s) {
            foreach ($s['completed_fields'] ?? [] as $fid) {
                $done[$fid] = true;
            }
        }
        $fields = array_map(fn (array $f) => $f + ['completed' => isset($done[$f['id']])], $env['fields']);

        return [
            'id' => $env['id'], 'org_id' => $env['org_id'], 'title' => $env['title'], 'status' => $env['status'],
            'document' => ['as_sent_sha256' => $env['document']['as_sent_sha256'], 'final_sha256' => $env['document']['final_sha256'], 'pages' => $env['document']['pages']],
            'settings' => $env['settings'], 'signers' => $signers, 'fields' => $fields,
            'chain_head' => $events === [] ? EventChain::GENESIS : $events[count($events) - 1]['hash'],
            'completion' => $env['completion'],
            'created_at' => $env['created_at'], 'sent_at' => $env['sent_at'], 'completed_at' => $env['completed_at'],
            'created_by' => $env['created_by'] ?? null,
        ];
    }

    private function at(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** A ULID: 48-bit time, 80 random bits, Crockford base32. */
    private static function ulid(int $seconds): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = $seconds * 1000;
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out = $alphabet[$time % 32].$out;
            $time = intdiv($time, 32);
        }
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }
}
