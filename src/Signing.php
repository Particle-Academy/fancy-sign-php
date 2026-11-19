<?php

declare(strict_types=1);

namespace FancySign;

use FancySign\Chain\EventChain;
use FancySign\Completion\Completion;
use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Listener\SignListener;
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
        private readonly ?SignListener $listener = null,
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
            'signers' => $input['signers'] ?? self::signerInputs($env['signers']),
            'settings' => $input['settings'] ?? $this->storedSettings($env),
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
        if ($env['org_id'] !== null && $this->orgSettings($env['org_id'])['per_page'] !== $env['settings']['per_page']) {
            // The org admin changed the per-page setting since the draft was made: send under the one in force now.
            $keep = array_intersect_key($env, array_flip(['id', 'org_id', 'status', 'created_at', 'sent_at', 'completed_at', 'completion', 'created_by']));
            $env = $this->validated(['title' => $env['title'], 'document' => $env['document']['model'],
                'signers' => self::signerInputs($env['signers']),
                'settings' => $this->storedSettings($env)], $keep);
        }
        $model = $this->buildAsSent($env);
        $out = $this->renderAsSent($model);
        $placed = [];
        foreach ($out['fields'] as $f) {
            $key = $f['origin'] === 'page_mark' ? "every-page-{$f['kind']}-{$f['signer']}" : $f['id'];
            $placed[$key][] = ['page' => $f['page'], 'rect' => $f['rect']];
        }
        foreach ($env['fields'] as &$field) {
            $field['placement'] = $placed[$field['id']] ?? [];
        }
        unset($field);
        // settings.order sequential: only the first group is invited; the rest wait, with no link.
        if (self::isSequential($env)) {
            $first = min(array_column($env['signers'], 'order'));
            foreach ($env['signers'] as &$s) {
                if ($s['order'] !== $first) {
                    $s['status'] = 'waiting';
                }
            }
            unset($s);
        }
        $this->repo->putBlob("as-sent/{$id}", $out['bytes']);
        $env['document']['as_sent_sha256'] = hash('sha256', $out['bytes']);
        $env['document']['as_sent_bytes'] = strlen($out['bytes']);
        $env['document']['pages'] = $out['pages'];
        // Where the reference and content hash were printed, for the certificate.
        $placement = $model['signature_section']['placement'];
        $env['document']['reference_placement'] = $placement;
        $env['document']['reference_page'] = $placement === 'signature_page' ? $out['authoredPages'] + 1 : $out['pages'];
        $env['status'] = 'sent';
        $env['sent_at'] = $this->at();
        $this->repo->saveEnvelope($env);
        $this->event($id, 'envelope.sent', $actor, Context::none(), ['as_sent_sha256' => $env['document']['as_sent_sha256'], 'pages' => $out['pages']]);
        foreach ($env['signers'] as $signer) {
            if ($signer['status'] !== 'waiting') {
                $this->mailLink($env, $signer, $actor, 'email.sent');
            }
        }

        return $this->wire($this->find($id));
    }

    /** settings.order; an envelope stored before 0.6 has none and is parallel. */
    private static function isSequential(array $env): bool
    {
        return ($env['settings']['order'] ?? 'parallel') === 'sequential';
    }

    /**
     * settings.order sequential, after a signature: when every signer of the
     * lowest unsigned order is still waiting, the group before it has finished,
     * so invite that group. Signers of the same order sign in parallel.
     */
    private function inviteNextGroup(array $env): void
    {
        $unsigned = array_filter($env['signers'], fn (array $s) => $s['status'] !== 'signed');
        $next = min(array_column($unsigned, 'order'));
        foreach ($unsigned as $signer) {
            if ($signer['order'] === $next && $signer['status'] === 'waiting') {
                $this->updateSigner($env['id'], $signer['id'], ['status' => 'pending']);
                $this->mailLink($env, ['status' => 'pending'] + $signer, 'system', 'email.sent');
            }
        }
    }

    /**
     * A stored envelope's signers, as input again. Their order is kept: under
     * parallel it is the list position anyway, under sequential it is the group.
     *
     * @return list<array{id: string, name: string, email: string, order: int}>
     */
    private static function signerInputs(array $signers): array
    {
        return array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'order' => $s['order']], $signers);
    }

    /**
     * The as-sent PDF exactly as send() would render it for this input and
     * organisation, before anything exists: the same validation as create()
     * (refused with the same SignError), the organisation's per_page setting in
     * force now, and the same render path as send(). The envelope reference and
     * content hash lines read Renderer::PREVIEW_PLACEHOLDER in their usual place.
     *
     * Side-effect free: no envelope, no row, no event, no mail. It only reads the
     * organisation's settings.
     *
     * @param  array{title?: mixed, document?: mixed, signers?: mixed, settings?: mixed}  $input  as create()
     * @return string the PDF bytes
     */
    public function preview(array $input, ?string $orgId = null): string
    {
        return $this->renderAsSent($this->buildAsSent($this->previewEnvelope($input, $orgId), preview: true))['bytes'];
    }

    /**
     * The as-sent document MODEL send() would render for this input and
     * organisation, before anything exists: the document with the generated
     * signature section (when settings.final_page makes one) appended as nodes,
     * the per-page field nodes, and the footer line when there is no section.
     * It comes from the same build step send() uses, so rendering it with
     * Renderer::render() gives exactly the preview() PDF. The reference and
     * content hash read Renderer::PREVIEW_PLACEHOLDER.
     *
     * Side-effect free, refused exactly as create() is, like preview().
     *
     * @param  array{title?: mixed, document?: mixed, signers?: mixed, settings?: mixed}  $input  as create()
     * @return array{document: array<string, mixed>, signature_section: array{placement: string, start: ?int}, reference: string, content_sha256: string, footer: ?array{page: string, lines: list<string>}, page_fields: list<array<string, mixed>>, fields: list<array<string, mixed>>, preview: bool}
     */
    public function previewDocument(array $input, ?string $orgId = null): array
    {
        return $this->buildAsSent($this->previewEnvelope($input, $orgId), preview: true);
    }

    /** The envelope a preview stands in for: validated as create() validates, never stored. */
    private function previewEnvelope(array $input, ?string $orgId): array
    {
        return $this->validated($input, ['id' => 'preview', 'org_id' => $orgId, 'status' => 'draft', 'created_at' => $this->at(),
            'sent_at' => null, 'completed_at' => null, 'completion' => null, 'created_by' => null]);
    }

    /**
     * The one build step of the as-sent document, for send(), preview() and
     * previewDocument().
     *
     * @param  array<string, mixed>  $env
     * @return array<string, mixed> the model
     */
    private function buildAsSent(array $env, bool $preview = false): array
    {
        try {
            return $this->renderer->build($env, (string) $this->config->get('signature_page_title'), $preview);
        } catch (\FinePrint\Exceptions\SchemaException $e) {
            throw self::unrenderable($e);
        }
    }

    /**
     * The one render step: a model from buildAsSent() to the PDF.
     *
     * @param  array<string, mixed>  $model
     * @return array{bytes: string, pages: int, authoredPages: int, fields: list<array<string, mixed>>}
     */
    private function renderAsSent(array $model): array
    {
        try {
            return $this->renderer->render($model);
        } catch (\FinePrint\Exceptions\SchemaException $e) {
            throw self::unrenderable($e);
        }
    }

    private static function unrenderable(\FinePrint\Exceptions\SchemaException $e): SignError
    {
        return new SignError('invalid_request', 'The document could not be rendered: '.($e->errors[0]['path'] ?? '').' '.($e->errors[0]['message'] ?? ''));
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
        $stored = $this->find($id);
        $env = $this->wire($stored);
        $events = $this->repo->events($id);
        $reference = $this->reference($stored);
        if ($format === 'json') {
            return ['envelope' => $env, 'completion' => $env['completion'], 'events' => $events, 'reference' => $reference];
        }

        return $this->renderer->certificate($env, $events, $reference);
    }

    /**
     * Where the as-sent document prints the envelope reference and the content
     * hash, and what they are. Null before send. An envelope sent before 0.5
     * recorded no placement: it had the signature page, which its final-page
     * fields locate.
     *
     * @return array{envelope: string, content_sha256: string, page: ?int, placement: string}|null
     */
    private function reference(array $env): ?array
    {
        if ($env['document']['as_sent_sha256'] === null) {
            return null;
        }
        $page = $env['document']['reference_page'] ?? null;
        if ($page === null) {
            $pages = [];
            foreach ($env['fields'] as $f) {
                if (($f['anchor']['final_page'] ?? false) === true) {
                    array_push($pages, ...array_column($f['placement'] ?? [], 'page'));
                }
            }
            $page = $pages === [] ? null : min($pages);
        }

        return ['envelope' => 'Envelope '.$env['id'], 'content_sha256' => Renderer::modelHash($env['document']['model']),
            'page' => $page, 'placement' => $env['document']['reference_placement'] ?? 'signature_page'];
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
        } elseif (self::isSequential($env)) {
            $this->inviteNextGroup($env);
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

    /**
     * Request changes: the signer sends the sender a message (required, bounded
     * like a decline reason) and the envelope stays OPEN, so they can still sign
     * or decline. Recorded as `signer.requested_changes` {text}. Allowed after
     * the view code, as decline is, never after signing or declining, and at
     * most `max_change_requests` times per signer.
     *
     * @return array<string, mixed> SignerView
     */
    public function requestChanges(string $token, ?string $session, mixed $text, Context $ctx): array
    {
        [$env, $signer] = $this->openLink($token);
        $this->signerMayAct($signer);
        $this->session($env, $signer, $session);
        $text = $this->text($text, (int) $this->config->get('max_decline_reason'));
        if ($this->changeRequests($env['id'], $signer) >= $this->config->int('max_change_requests')) {
            throw new SignError('limit_exceeded', 'You have asked for changes as many times as this envelope allows. Sign or decline, or contact the sender.');
        }
        $this->event($env['id'], 'signer.requested_changes', $this->actor($signer), $ctx, ['text' => $text]);

        return $this->signerView($token, $session);
    }

    /** How many times this signer has asked for changes on this envelope. */
    private function changeRequests(string $envelopeId, array $signer): int
    {
        $actor = $this->actor($signer);

        return count(array_filter($this->repo->events($envelopeId),
            static fn (array $e): bool => $e['type'] === 'signer.requested_changes' && $e['actor'] === $actor));
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

        $settings = $this->settings($input['settings'] ?? [], $base['org_id'] ?? null);
        // settings.order sequential: a signer's `order` is its group (equal orders sign together),
        // the list position when left out. Under parallel it is the list position, whatever the input
        // says, exactly as before 0.6.
        if ($settings['order'] === 'sequential') {
            foreach ($list as $i => $s) {
                if (! array_key_exists('order', $s)) {
                    continue;
                }
                if (! is_int($s['order']) || $s['order'] < 1 || $s['order'] > self::MAX_ORDER) {
                    throw new SignError('invalid_request', "signers[{$i}].order is a whole number from 1 to ".self::MAX_ORDER.'.');
                }
                $signers[$s['id'] ?? 's'.($i + 1)]['order'] = $s['order'];
            }
        }
        $fields = [];
        foreach (self::fieldNodes($doc['blocks']) as $node) {
            if (! isset($signers[$node['signer']])) {
                throw new SignError('invalid_request', "the document field {$node['id']} names signer {$node['signer']}, who is not on the envelope.");
            }
            $fields[] = ['id' => $node['id'], 'signer_id' => $node['signer'], 'kind' => Schema::FIELD_KINDS[$node['type']],
                'anchor' => ['node' => $node['id']], 'required' => ($node['required'] ?? true) !== false,
                'label' => is_string($node['label'] ?? null) ? $node['label'] : null, 'placement' => null];
        }
        // settings.final_page: whether the generated signature section exists at all.
        $has = [];
        foreach ($fields as $f) {
            if ($f['required']) {
                $has[$f['signer_id']][$f['kind']] = true;
            }
        }
        $finalPage = $settings['final_page'];
        if ($finalPage === 'never') {
            foreach ($signers as $sid => $s) {
                if (! isset($has[$sid]['signature'])) {
                    throw new SignError('invalid_request', "settings.final_page is never, so every signer needs a required signatureField in the document; signer {$sid} has none.");
                }
            }
        }
        $covered = array_filter($signers, fn (array $s) => isset($has[$s['id']]['signature'], $has[$s['id']]['date_signed'])) === $signers;
        $generate = $finalPage === 'always' || ($finalPage === 'auto' && ! $covered);
        $anchor = $finalPage === 'always' ? ['final_page' => true] : ['signature_section' => true];
        foreach ($signers as $sid => $s) {
            if ($generate) {
                $fields[] = ['id' => "final-signature-{$sid}", 'signer_id' => $sid, 'kind' => 'signature', 'anchor' => $anchor, 'required' => true, 'label' => 'Signature', 'placement' => null];
                $fields[] = ['id' => "final-date-{$sid}", 'signer_id' => $sid, 'kind' => 'date_signed', 'anchor' => $anchor, 'required' => true, 'label' => 'Date signed', 'placement' => null];
            }
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

    // ============================================================ organisation

    private const PER_PAGE = ['none', 'initials', 'signature'];

    /** settings.final_page: where the generated signature section goes. */
    private const FINAL_PAGE = ['always', 'auto', 'never'];

    /** settings.order: who is invited when. */
    private const ORDER = ['parallel', 'sequential'];

    /** The largest signer order: small enough to be a safe integer in every port. */
    private const MAX_ORDER = 1000000;

    /**
     * An organisation's signing settings. Three: `per_page`, the per-page
     * initials or signature an org admin turns on for every envelope of the
     * org (a lock), and `final_page`, the org's default for
     * settings.final_page, and `order`, the org's default for settings.order
     * (both defaults: a sender may choose otherwise per envelope). Who is
     * an admin is the host's decision (Laravel: OrgAdminPolicy).
     *
     * @return array{org_id: string, per_page: string, final_page: string, order: string, updated_by: ?string, updated_at: ?string}
     */
    public function orgSettings(string $orgId): array
    {
        $row = $this->repo->orgSettings($orgId) ?? ['org_id' => $orgId, 'per_page' => 'none', 'updated_by' => null, 'updated_at' => null];

        // A row saved before final_page (0.5) or order (0.6) existed reads as the default.
        return ['org_id' => $row['org_id'], 'per_page' => $row['per_page'], 'final_page' => $row['final_page'] ?? 'always',
            'order' => $row['order'] ?? 'parallel', 'updated_by' => $row['updated_by'], 'updated_at' => $row['updated_at']];
    }

    /**
     * Set an organisation's settings. They apply to every new envelope of the
     * org and to its drafts when they are sent. An envelope already sent keeps
     * what it was sent with, because that is the document the signer sees.
     *
     * `per_page` left out is none, as it always was; `final_page` and `order`
     * left out keep the organisation's current value.
     *
     * @param  array{per_page?: mixed, final_page?: mixed, order?: mixed}  $input
     * @return array{org_id: string, per_page: string, final_page: string, order: string, updated_by: ?string, updated_at: ?string}
     */
    public function setOrgSettings(string $orgId, array $input, string $actor): array
    {
        if ($orgId === '' || strlen($orgId) > 64) {
            throw new SignError('invalid_request', 'An organisation id is 1 to 64 characters.');
        }
        if (array_diff(array_keys($input), ['per_page', 'final_page', 'order']) !== []) {
            throw new SignError('invalid_request', 'Organisation settings take per_page, final_page and order only.');
        }
        $perPage = $input['per_page'] ?? 'none';
        if (! in_array($perPage, self::PER_PAGE, true)) {
            throw new SignError('invalid_request', 'per_page is none, initials or signature.');
        }
        $finalPage = array_key_exists('final_page', $input) ? $input['final_page'] : $this->orgSettings($orgId)['final_page'];
        if (! in_array($finalPage, self::FINAL_PAGE, true)) {
            throw new SignError('invalid_request', 'final_page is always, auto or never.');
        }
        $order = array_key_exists('order', $input) ? $input['order'] : $this->orgSettings($orgId)['order'];
        if (! in_array($order, self::ORDER, true)) {
            throw new SignError('invalid_request', 'order is parallel or sequential.');
        }
        $row = ['org_id' => $orgId, 'per_page' => $perPage, 'final_page' => $finalPage, 'order' => $order, 'updated_by' => $actor, 'updated_at' => $this->at()];
        $this->repo->saveOrgSettings($row);

        return $row;
    }

    /** A stored envelope's settings, as input again; per_page dropped when an org decides it. */
    private function storedSettings(array $env): array
    {
        $s = $env['settings'];
        if ($env['org_id'] !== null) {
            unset($s['per_page']);
        }

        return $s;
    }

    /** @return array<string, mixed> */
    private function settings(mixed $in, ?string $orgId = null): array
    {
        if (! is_array($in)) {
            throw new SignError('invalid_request', 'settings must be an object.');
        }
        $orgPerPage = $orgId === null ? 'none' : $this->orgSettings($orgId)['per_page'];
        if ($orgPerPage !== 'none' && array_key_exists('per_page', $in) && $in['per_page'] !== $orgPerPage) {
            throw new SignError('invalid_request', "settings.per_page is set by your organisation ({$orgPerPage}).");
        }
        $s = [
            'per_page' => $orgPerPage !== 'none' ? $orgPerPage : ($in['per_page'] ?? 'none'),
            // The organisation's final_page is a default, not a lock: a sender may choose per envelope.
            'final_page' => array_key_exists('final_page', $in) ? $in['final_page'] : ($orgId === null ? 'always' : $this->orgSettings($orgId)['final_page']),
            // settings.order, like final_page: the organisation's is a default, not a lock.
            'order' => array_key_exists('order', $in) ? $in['order'] : ($orgId === null ? 'parallel' : $this->orgSettings($orgId)['order']),
            'otp' => ['view' => true, 'accept' => true],
            'step_up' => $in['step_up'] ?? 'none',
            'level' => $in['level'] ?? 'ses',
            'seal' => $in['seal'] ?? 'none',
            'expires_at' => $in['expires_at'] ?? null,
        ];
        if (! in_array($s['per_page'], self::PER_PAGE, true)) {
            throw new SignError('invalid_request', 'settings.per_page is none, initials or signature.');
        }
        if (! in_array($s['final_page'], self::FINAL_PAGE, true)) {
            throw new SignError('invalid_request', 'settings.final_page is always, auto or never.');
        }
        if (! in_array($s['order'], self::ORDER, true)) {
            throw new SignError('invalid_request', 'settings.order is parallel or sequential.');
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
        // settings.order sequential: a signer whose group has not been invited sees nothing yet.
        if ($signer['status'] === 'waiting') {
            throw new SignError('not_your_turn', 'It is not your turn to sign yet. You will get an email when it is.');
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
        return self::codeDigest($this->appKey, $env['id'], $signer['id'], $purpose, $code);
    }

    /**
     * How a one-time code is stored: `HMAC-SHA256(app key, envelope|signer|purpose|code)`,
     * lowercase hex. Public so the conformance suite `sign/otp` can pin it
     * for a port; the code itself is never stored.
     */
    public static function codeDigest(string $appKey, string $envelopeId, string $signerId, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$envelopeId}|{$signerId}|{$purpose}|{$code}", $appKey);
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

        $event = $this->repo->appendEvent($envelopeId, fn (?array $last) => EventChain::append($last, [
            'envelope_id' => $envelopeId, 'at' => $at, 'type' => $type, 'actor' => $actor, 'source' => $source,
            'ip' => $ctx->ip, 'user_agent' => $ctx->userAgent, 'data' => $data,
        ]));
        $this->tell($event);

        return $event;
    }

    /**
     * The host hook, once the event is stored. A listener's failure is the
     * host's to handle: it never fails or rolls back this request.
     *
     * @param  array<string, mixed>  $event
     */
    private function tell(array $event): void
    {
        if ($this->listener === null) {
            return;
        }
        try {
            $this->listener->recorded(EnvelopeEventRecorded::fromChainEvent($event));
        } catch (\Throwable) {
            // Deliberately swallowed: the event is chained; the hook is best-effort
            // here and at-least-once under the Laravel bridge's queued delivery.
        }
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
            'changes_requested' => $this->changeRequests($env['id'], $signer),
            'max_change_requests' => $this->config->int('max_change_requests'),
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
