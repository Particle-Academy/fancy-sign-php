<?php

declare(strict_types=1);

namespace FancySign\Tests\Conformance;

use FancySign\Chain\EventChain;
use FancySign\Completion\Completion;
use FancySign\Config;
use FancySign\Data\CanonicalDocument;
use FancySign\Repository\InMemoryRepository;
use FancySign\Seal\Ed25519;
use FancySign\SignError;
use FancySign\Signing;
use FancySign\Support\CanonicalJson;
use FancySign\Support\Context;
use FancySign\Tests\Support\FakeMailer;
use FancySign\Tests\Support\FrozenClock;
use FancySign\Verify\Verifier;

/**
 * The exporter for the `sign/*` suites in fancy-conformance.
 *
 *     composer conformance:export            # writes ../fancy-conformance/suites/sign/
 *     php tests/Conformance/Export.php <dir> # writes <dir>/sign/
 *
 * PHP is the reference: every golden is the output of RUNNING this package,
 * never what a value obviously is. A port (fancy-sign-js) never regenerates
 * them. `DriftTest` re-exports to a temporary directory and fails if a byte
 * moved; `ConformanceTest` replays the installed rows through PHP.
 *
 * Rules a future edit must keep:
 *
 * - **Append, never insert.** Ids are `NNNN-slug`, numbered in the order rows
 *   are added below; they are never renumbered. A new row goes at the END of
 *   its suite.
 * - **Goldens are platform-stable.** Nothing here hashes a PDF fine-print
 *   wrote (its streams are deflated by the platform's zlib). sign/fields pins
 *   field lists and the PAGE each placement landed on, never rects or bytes;
 *   sign/otp pins event types and the data of otp.* and link.* events only.
 * - **Nothing random reaches a golden.** Completion records carry a fixed
 *   nonce; scenario envelopes have random ids, which no golden includes.
 *
 * `manifest.json` and `SPEC.md` are hand-written in fancy-conformance and are
 * never touched here.
 */
final class Export
{
    /** The fancy-conformance release these suites join. */
    public const SINCE = '0.51.0';

    /** The fixture release that added the index-key and error-envelope rows. */
    public const SINCE_052 = '0.52.0';

    /** The fixture release that added request changes (fancy-sign 0.3.0). */
    public const SINCE_053 = '0.53.0';

    /** The fixture release that adds data mode (CC-fancy-0052, fancy-sign 0.7.0). */
    public const SINCE_057 = '0.57.0';

    /** The fixture release that adds attachFinal (document.finalized and its two error codes). */
    public const SINCE_058 = '0.58.0';

    /** The `since` new rows get, while a later block is being added. */
    private ?string $since = null;

    public const SUITES = ['sign/chain', 'sign/completion', 'sign/data', 'sign/fields', 'sign/otp'];

    /** A fixed seal seed (32 bytes, hex) so seals are reproducible. Test data, never a real key. */
    public const SEED = '0707070707070707070707070707070707070707070707070707070707070707';

    /** The app key the OTP rows hash with. Test data. */
    public const APP_KEY = 'conformance-app-key-0123456789ab';

    /** @var array<string, list<array<string, mixed>>> */
    private array $rows = [];

    /** @var array<string, int> */
    private array $next = [];

    // ------------------------------------------------------------------ CLI

    /**
     * Write `cases.json` for every suite under `$suitesDir/sign/<name>/`.
     *
     * @return array<string, int> suite => row count
     */
    public static function write(string $suitesDir): array
    {
        $export = self::build();
        $counts = [];
        foreach (self::SUITES as $suite) {
            $dir = rtrim($suitesDir, '/\\').'/'.$suite;
            if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
                throw new \RuntimeException("cannot create {$dir}");
            }
            file_put_contents($dir.'/cases.json', self::json(['$schema' => '../../../schema/case-table.schema.json', 'suite' => $suite, 'cases' => $export->rows[$suite]]));
            $counts[$suite] = count($export->rows[$suite]);
        }

        return $counts;
    }

    public static function build(): self
    {
        $e = new self;
        $e->chain();
        $e->completion();
        $e->data();
        $e->fields();
        $e->otp();

        return $e;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $suite): array
    {
        return $this->rows[$suite] ?? [];
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
    }

    // ------------------------------------------------------------ the runner

    /**
     * Run one row through PHP. The exporter computes every golden with it, and
     * `ConformanceTest` replays the installed rows through it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function call(string $suite, string $fn, array $input): mixed
    {
        return match ("{$suite} {$fn}") {
            'sign/chain canonical' => self::guard(fn () => CanonicalJson::encode(self::objects($input['value'], $input['objects'] ?? []))),
            'sign/chain hash' => EventChain::hashOf((string) $input['prev_hash'], self::event(self::objects($input['event'], $input['objects'] ?? []))),
            'sign/chain append' => self::guard(function () use ($input) {
                // Empty data is the empty OBJECT on the wire (it hashes as {}); PHP holds it as [].
                $e = EventChain::append($input['last'] ?? null, self::event($input['event']));

                $e['data'] = $e['data'] === [] ? new \stdClass : $e['data'];

                return $e;
            }),
            'sign/chain verify' => EventChain::verify($input['rows']),
            'sign/completion canonical' => Completion::canonical($input['record']),
            'sign/completion token' => Completion::tokenFor($input['record']),
            'sign/completion seal' => Completion::seal($input['record'], self::secret((string) $input['seed'])),
            'sign/completion keyId' => Ed25519::keyId((string) $input['public_key']),
            'sign/completion publicKey' => Ed25519::publicFromSecret(self::secret((string) $input['seed'])),
            'sign/completion wellFormed' => Completion::wellFormed($input['record']),
            'sign/completion verify' => (new Verifier($input['public_keys']))->verify(
                (string) base64_decode((string) $input['pdf_base64'], true), $input['completion'], $input['events'] ?? null),
            'sign/data canonical' => self::guard(function () use ($input): array {
                $bytes = CanonicalDocument::canonical((string) $input['text']);

                return ['canonical' => $bytes, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
            }),
            'sign/fields fields' => self::fieldsOf($input),
            'sign/fields placement' => self::placementOf($input),
            'sign/otp codeDigest' => Signing::codeDigest((string) $input['app_key'], (string) $input['envelope_id'], (string) $input['signer_id'], (string) $input['purpose'], (string) $input['code']),
            'sign/otp errorCodes' => SignError::CODES,
            'sign/otp envelope' => (new SignError((string) $input['code'], (string) $input['message']))->envelope((string) $input['request_id']),
            'sign/otp scenario' => self::scenario($input),
        };
    }

    /** A refusal is a value, so a row can pin it. */
    private static function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\InvalidArgumentException) {
            return ['error' => 'refused'];
        }
    }

    /**
     * PHP decodes a JSON object as an array, so `{}` and `[]`, and `{"0": "a"}`
     * and `["a"]`, arrive identical. A row names the paths that are OBJECTS in
     * `objects` (dot paths, `""` for the root), and the runner turns those into
     * stdClass. A port that keeps JSON objects as objects can ignore it.
     *
     * @param  list<string>  $paths
     */
    private static function objects(mixed $value, array $paths, string $at = ''): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::objects($v, $paths, $at === '' ? (string) $k : $at.'.'.$k);
        }
        if (in_array($at, $paths, true)) {
            $object = new \stdClass;
            foreach ($value as $k => $v) {
                $object->{(string) $k} = $v;
            }

            return $object;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function event(array $e): array
    {
        return $e + ['ip' => null, 'user_agent' => null, 'data' => []];
    }

    private static function secret(string $seedHex): string
    {
        return base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) hex2bin($seedHex))));
    }

    /** @return array{signing: Signing, mailer: FakeMailer, clock: FrozenClock} */
    private static function harness(array $config = []): array
    {
        $mailer = new FakeMailer();
        $clock = new FrozenClock('2026-11-18 12:00:00');
        $signing = new Signing(
            repo: new InMemoryRepository(),
            mailer: $mailer,
            sealSecretKey: self::secret(self::SEED),
            appKey: self::APP_KEY,
            linkUrl: fn (string $token): string => 'https://app.example.test/sign/'.$token,
            config: new Config($config),
            clock: $clock,
        );

        return ['signing' => $signing, 'mailer' => $mailer, 'clock' => $clock];
    }

    /** Create (with an optional organisation setting) and answer the field list, or the refusal. */
    private static function fieldsOf(array $input): array
    {
        $h = self::harness();
        try {
            if (isset($input['org_per_page'])) {
                $h['signing']->setOrgSettings('org-1', ['per_page' => $input['org_per_page']], 'user:admin');
            }
            $env = $h['signing']->create(['title' => 'Conformance', 'document' => $input['document'], 'signers' => $input['signers'],
                'settings' => $input['settings'] ?? []], 'user:1', isset($input['org_per_page']) ? 'org-1' : null);
        } catch (SignError $e) {
            return ['error' => $e->errorCode];
        }

        return ['per_page' => $env['settings']['per_page'], 'fields' => array_map(fn (array $f) => [
            'id' => $f['id'], 'signer_id' => $f['signer_id'], 'kind' => $f['kind'], 'anchor' => $f['anchor'], 'required' => $f['required'], 'label' => $f['label'],
        ], $env['fields'])];
    }

    /** Send, and answer the page count and the pages each field landed on. */
    private static function placementOf(array $input): array
    {
        $h = self::harness();
        $env = $h['signing']->create(['title' => 'Conformance', 'document' => $input['document'], 'signers' => $input['signers'],
            'settings' => $input['settings'] ?? []], 'user:1');
        $sent = $h['signing']->send($env['id'], 'user:1');
        $pages = [];
        foreach ($sent['fields'] as $f) {
            $pages[$f['id']] = array_map(fn (array $p) => $p['page'], $f['placement']);
        }

        return ['pages' => $sent['document']['pages'], 'fields' => $pages];
    }

    /**
     * An OTP scenario: one envelope, one signer (alice), a frozen clock. Each
     * step answers its result (or the wire error code) and the events it
     * appended: type and source always, data for otp.* and link.* only.
     *
     * Steps: send | request {purpose, withSession?} | verify {code} | consent | open
     *      | advance {seconds} | sign {code}. A code is "right" (the latest
     *      mailed for that purpose), "stale" (the one before it), "view" (the
     *      latest view code, sent as an accept code), or six literal digits.
     */
    private static function scenario(array $input): array
    {
        $h = self::harness($input['config'] ?? []);
        $s = $h['signing'];
        $ctx = new Context('192.0.2.10', 'Conformance/1');
        $session = null;
        $token = null;
        $id = null;
        $out = [];
        $code = function (string $which, string $purpose) use ($h): string {
            $codes = array_values(array_map(fn (array $m) => (string) $m['code'], array_filter($h['mailer']->sent, fn (array $m) => $m['kind'] === 'code' && $m['purpose'] === $purpose)));
            if (preg_match('/^\d{6}$/', $which) === 1) {
                return $which;
            }

            return match ($which) {
                'right' => $codes[count($codes) - 1] ?? '000000',
                'stale' => $codes[count($codes) - 2] ?? '000000',
                'wrong' => ($codes[count($codes) - 1] ?? '000000') === '000000' ? '000001' : '000000',
            };
        };
        foreach ($input['steps'] as $step) {
            $before = $id === null ? 0 : count($s->events($id));
            try {
                $result = match ($step['op']) {
                    'send' => (function () use ($s, $h, &$id, &$token): array {
                        $env = $s->create(['title' => 'Quote', 'document' => ['title' => 'Quote', 'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'One widget.']]]]],
                            'signers' => [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com']]], 'user:1');
                        $id = $env['id'];
                        $s->send($id, 'user:1');
                        $token = $h['mailer']->token('alice@example.com');

                        return ['status' => 'sent'];
                    })(),
                    'request' => $s->requestCode((string) $token, (string) $step['purpose'], $ctx, ($step['withSession'] ?? true) ? $session : null),
                    'verify' => (function () use ($s, $token, $ctx, $code, $step, &$session): array {
                        $session = $s->verifyCode((string) $token, 'view', $code((string) $step['code'], 'view'), $ctx)['session'];

                        return ['session' => true];
                    })(),
                    'consent' => ['status' => $s->consent((string) $token, $session, $s->config()->consentVersion(), $ctx)['signer']['status']],
                    'open' => (function () use ($s, $token, $session, $ctx): array {
                        $s->document((string) $token, $session, $ctx);

                        return ['opened' => true];
                    })(),
                    'advance' => (function () use ($h, $step): array {
                        $h['clock']->advance((int) $step['seconds']);

                        return ['advanced' => (int) $step['seconds']];
                    })(),
                    'changes' => ['changes_requested' => $s->requestChanges((string) $token, $session, $step['text'] ?? null, $ctx)['changes_requested']],
                    'sign' => (function () use ($s, $token, $session, $ctx, $code, $step): array {
                        $view = $s->signerView((string) $token, $session);
                        $fields = [];
                        foreach ($view['fields'] as $f) {
                            $fields[$f['id']] = true;
                        }
                        $purpose = $step['code'] === 'view' ? 'view' : 'accept';

                        return ['status' => $s->sign((string) $token, $session, ['fields' => $fields,
                            'mark' => ['kind' => 'typed', 'text' => 'Alice Example'], 'adoption' => $s->config()->adoptionText(),
                            'code' => $code($step['code'] === 'view' ? 'right' : (string) $step['code'], $purpose)], $ctx)['signer']['status']];
                    })(),
                };
            } catch (SignError $e) {
                $result = ['error' => $e->errorCode, 'status' => $e->status];
            }
            $events = [];
            foreach ($id === null ? [] : array_slice($s->events($id), $before) as $e) {
                $row = ['type' => $e['type'], 'source' => $e['source']];
                if (str_starts_with($e['type'], 'otp.') || str_starts_with($e['type'], 'link.') || $e['type'] === 'signer.requested_changes') {
                    $row['data'] = $e['data'] === [] ? new \stdClass : $e['data'];
                }
                $events[] = $row;
            }
            $out[] = ['op' => $step['op'], 'result' => $result, 'events' => $events];
        }

        return ['steps' => $out];
    }

    // -------------------------------------------------------------- authoring

    private function add(string $suite, string $slug, string $title, string $fn, array $input, ?string $notes = null, array $tags = []): array
    {
        $n = $this->next[$suite] = ($this->next[$suite] ?? 0) + 1;
        // Run the row as a port will see it: through JSON, the way the loader hands it over.
        $expected = self::call($suite, $fn, json_decode(json_encode($input === [] ? new \stdClass : $input, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $row = ['id' => sprintf('%04d-%s', $n, $slug), 'title' => $title, 'since' => $this->since ?? self::SINCE];
        if ($tags !== []) {
            $row['tags'] = $tags;
        }
        $row += ['fn' => $fn, 'input' => $input === [] ? new \stdClass : $input, 'expected' => $expected];
        if ($notes !== null) {
            $row['notes'] = $notes;
        }
        $this->rows[$suite][] = $row;

        return is_array($expected) ? $expected : [];
    }

    /** @return list<array<string, mixed>> a chain of `$n` events, built by EventChain::append */
    private static function chainOf(int $n, string $envelope = '01JD0000000000000000000000'): array
    {
        $types = ['envelope.created', 'envelope.sent', 'email.sent', 'link.opened', 'otp.sent', 'otp.verified'];
        $rows = [];
        $last = null;
        for ($i = 0; $i < $n; $i++) {
            $last = EventChain::append($last, ['envelope_id' => $envelope, 'at' => sprintf('2026-11-18T12:00:%02dZ', $i), 'type' => $types[$i % count($types)],
                'actor' => $i < 3 ? 'user:1' : 'signer:alice', 'source' => 'server', 'ip' => $i < 3 ? null : '192.0.2.10',
                'user_agent' => $i < 3 ? null : 'Conformance/1', 'data' => $i === 1 ? ['as_sent_sha256' => str_repeat('a', 64), 'pages' => 2] : ($i >= 4 ? ['purpose' => 'view'] : [])]);
            $row = $last;
            $row['data'] = $row['data'] === [] ? new \stdClass : $row['data'];
            $rows[] = $row;
        }

        return $rows;
    }

    // ------------------------------------------------------------- sign/chain

    private function chain(): void
    {
        $c = 'sign/chain';
        $this->add($c, 'canonical-sorts-keys', 'object keys are sorted, recursively; list order is kept', 'canonical',
            ['value' => ['b' => 1, 'a' => ['z' => [3, 1, 2], 'y' => true], 'c' => null]], tags: ['canonical', 'control']);
        $this->add($c, 'canonical-no-escapes', 'no whitespace; slashes and non-ASCII are written as they are, never escaped', 'canonical',
            ['value' => ['url' => 'https://example.com/a/b', 'name' => "Zo\u{00EB} \u{00C5}ngstr\u{00F6}m", 'yen' => "\u{00A5}100"]], tags: ['canonical']);
        $this->add($c, 'canonical-line-separators', 'U+2028 and U+2029 are written raw, as JSON.stringify writes them', 'canonical',
            ['value' => ['text' => "one\u{2028}two\u{2029}three"]], notes: 'PHP escapes these by default; JSON.stringify does not. The flag JSON_UNESCAPED_LINE_TERMINATORS makes the two agree.', tags: ['canonical']);
        $this->add($c, 'canonical-control-escapes', 'control characters, quotes and backslashes are escaped the JSON way', 'canonical',
            ['value' => ['s' => "tab\there \"quoted\" back\\slash newline\n"]], tags: ['canonical']);
        $this->add($c, 'canonical-byte-order-keys', 'keys sort by UTF-8 BYTES: "Z" < "a" < "\u{00E9}" < "\u{FF61}" < an astral key', 'canonical',
            ['value' => ["\u{1F600}" => 5, "\u{FF61}" => 4, "\u{00E9}" => 3, 'a' => 2, 'Z' => 1]],
            notes: 'Discriminates a port that sorts with the default JavaScript comparison (UTF-16 code units), which puts the astral key (a surrogate pair, 0xD83D...) BEFORE U+FF61.', tags: ['canonical']);
        $this->add($c, 'canonical-refuses-float', 'a float is refused, because PHP and JavaScript do not print floats the same way', 'canonical',
            ['value' => ['amount' => 10.5]], tags: ['canonical', 'refusal']);
        $this->add($c, 'canonical-empty-object', 'an empty object stays {} and an empty list stays []', 'canonical',
            ['value' => ['data' => [], 'list' => []], 'objects' => ['data']], notes: 'JSON decoders in PHP lose the difference; `objects` names the paths that are objects.', tags: ['canonical']);
        $this->add($c, 'canonical-integers-and-literals', 'integers, booleans and null print as JSON literals', 'canonical',
            ['value' => ['n' => -42, 'big' => 9007199254740991, 't' => true, 'f' => false, 'z' => null]], tags: ['canonical']);

        $genesis = EventChain::GENESIS;
        $first = ['seq' => 1, 'envelope_id' => '01JD0000000000000000000000', 'at' => '2026-11-18T12:00:00Z', 'type' => 'envelope.created',
            'actor' => 'user:1', 'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => ['title' => 'Quote Q-1001']];
        $this->add($c, 'hash-first-event', 'hash = sha256(prev_hash + "\n" + canonical({seq, envelope_id, at, type, actor, source, ip, user_agent, data})), from the genesis of 64 zeros', 'hash',
            ['prev_hash' => $genesis, 'event' => $first], tags: ['hash', 'control']);
        $this->add($c, 'hash-empty-data', 'empty data hashes as an empty OBJECT, {}', 'hash',
            ['prev_hash' => $genesis, 'event' => ['data' => new \stdClass] + $first], notes: 'A port that hashes [] for empty data agrees with nothing PHP wrote.', tags: ['hash']);
        $this->add($c, 'hash-with-ip-and-agent', 'the IP and user agent are inside the hash', 'hash',
            ['prev_hash' => str_repeat('1', 64), 'event' => ['seq' => 2, 'type' => 'otp.sent', 'actor' => 'signer:alice', 'ip' => '192.0.2.10', 'user_agent' => 'Mozilla/5.0 (X11)', 'data' => ['purpose' => 'view']] + $first], tags: ['hash']);
        $this->add($c, 'hash-client-source', 'a client-reported page view hashes its source', 'hash',
            ['prev_hash' => str_repeat('2', 64), 'event' => ['seq' => 7, 'type' => 'page.viewed', 'actor' => 'signer:alice', 'source' => 'client', 'ip' => '192.0.2.10', 'data' => ['page' => 1, 'ms' => 2500]] + $first], tags: ['hash']);

        $base = ['envelope_id' => '01JD0000000000000000000000', 'at' => '2026-11-18T12:00:00Z', 'actor' => 'user:1', 'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => new \stdClass];
        $one = EventChain::append(null, ['type' => 'envelope.created', 'data' => []] + $base);
        $one['data'] = new \stdClass;
        $this->add($c, 'append-first', 'the first event has seq 1 and the genesis as prev_hash', 'append',
            ['last' => null, 'event' => ['type' => 'envelope.created'] + $base], tags: ['append', 'control']);
        $this->add($c, 'append-next', 'the next event takes seq + 1 and the previous hash', 'append',
            ['last' => $one, 'event' => ['type' => 'envelope.sent', 'data' => ['pages' => 2]] + $base], tags: ['append']);
        $this->add($c, 'append-refuses-unknown-type', 'a type outside the closed event set is refused', 'append',
            ['last' => null, 'event' => ['type' => 'signer.teleported'] + $base], tags: ['append', 'refusal']);
        $this->add($c, 'append-refuses-server-page-view', 'page.viewed must come from the client: a server page view is refused', 'append',
            ['last' => null, 'event' => ['type' => 'page.viewed'] + $base], tags: ['append', 'refusal']);
        $this->add($c, 'append-refuses-client-signature', 'every event but page.viewed is server-observed: a client signer.signed is refused', 'append',
            ['last' => null, 'event' => ['type' => 'signer.signed', 'source' => 'client'] + $base],
            notes: 'The evidence rule: a browser can claim it showed a page, never that someone signed.', tags: ['append', 'refusal']);

        $chain = self::chainOf(6);
        $this->add($c, 'verify-intact', 'an intact chain verifies and reports its head', 'verify', ['rows' => $chain], tags: ['verify', 'control']);
        $this->add($c, 'verify-empty', 'an empty chain is intact, with the genesis as its head', 'verify', ['rows' => []], tags: ['verify']);
        $gap = $chain;
        array_splice($gap, 2, 1);
        $this->add($c, 'verify-sequence-gap', 'a missing row is a sequence_gap at the seq found', 'verify', ['rows' => $gap], tags: ['verify']);
        $link = $chain;
        $link[3]['prev_hash'] = str_repeat('f', 64);
        $this->add($c, 'verify-broken-link', 'a prev_hash that is not the previous hash is a broken_link', 'verify', ['rows' => $link], tags: ['verify']);
        $data = $chain;
        $data[1]['data']['pages'] = 3;
        $this->add($c, 'verify-tampered-data', 'changed data is a hash_mismatch at that row', 'verify', ['rows' => $data], tags: ['verify']);
        $ip = $chain;
        $ip[4]['ip'] = '198.51.100.1';
        $this->add($c, 'verify-tampered-ip', 'a changed IP address is a hash_mismatch', 'verify', ['rows' => $ip], tags: ['verify']);
        $stringy = $chain;
        $stringy[1]['data'] = '{"as_sent_sha256":"'.str_repeat('a', 64).'","pages":2}';
        $this->add($c, 'verify-data-as-json-string', 'data read raw from a store as a JSON string verifies the same', 'verify', ['rows' => $stringy], tags: ['verify']);
        $mixed = array_merge(array_slice($chain, 0, 3), array_slice(self::chainOf(6, '01JD1111111111111111111111'), 3));
        $this->add($c, 'verify-other-envelope', 'a row from another envelope\'s chain fails (hash_mismatch or broken_link, never ok)', 'verify', ['rows' => $mixed], tags: ['verify']);
        $resealed = $chain;
        $resealed[2]['type'] = 'reminder.sent';
        $resealed[2]['hash'] = EventChain::hashOf($resealed[2]['prev_hash'], $resealed[2]);
        $this->add($c, 'verify-rehashed-row', 'rewriting a row and recomputing ITS hash breaks the link to the next row', 'verify', ['rows' => $resealed], tags: ['verify']);

        $this->since = self::SINCE_052;
        // 0.52.0: an object whose keys are "0", "1", ... is an OBJECT, never a list.
        // Inputs are authored from JSON text: a PHP array keyed 0..n-1 would be WRITTEN as a list.
        $this->add($c, 'canonical-index-keys-object', 'an object whose keys are "0", "1" stays an object', 'canonical',
            ['value' => json_decode('{"1":"b","0":"a"}'), 'objects' => ['']],
            notes: 'PHP\'s json_decode(assoc: true) turns this into the list ["a","b"]. Canonical JSON is over JSON values, so it must not.', tags: ['canonical', 'index-keys']);
        $this->add($c, 'canonical-index-keys-list', 'the list with the same values stays a list', 'canonical',
            ['value' => ['a', 'b']], tags: ['canonical', 'index-keys']);
        $this->add($c, 'canonical-index-keys-nested', 'index-keyed objects nested in objects and lists stay objects', 'canonical',
            ['value' => json_decode('{"data":{"0":{"0":1}},"list":[{"0":true}]}'), 'objects' => ['data', 'data.0', 'list.0']], tags: ['canonical', 'index-keys']);
        $this->add($c, 'hash-index-key-data', 'event data with index-like keys hashes as an object', 'hash',
            ['prev_hash' => $genesis, 'event' => ['data' => json_decode('{"0":"x","1":"y"}')] + $first, 'objects' => ['data']], tags: ['hash', 'index-keys']);
        $indexed = EventChain::append(null, ['envelope_id' => '01JD0000000000000000000000', 'at' => '2026-11-18T12:00:00Z', 'type' => 'envelope.created',
            'actor' => 'user:1', 'source' => 'server', 'ip' => null, 'user_agent' => null, 'data' => json_decode('{"0":"x","1":"y"}')]);
        $this->add($c, 'verify-index-key-data', 'a chain whose data is an index-keyed object, read raw as a JSON string, verifies', 'verify',
            ['rows' => [['data' => '{"0":"x","1":"y"}'] + $indexed]],
            notes: 'The raw-store form: data as the JSON text a database column holds. A reader that decodes it into a list computes another hash.', tags: ['verify', 'index-keys']);
        $this->since = null;

        // 0.53.0: signer.requested_changes joins the closed event set (fancy-sign 0.3.0).
        $this->since = self::SINCE_053;
        $this->add($c, 'append-requested-changes', 'signer.requested_changes is in the closed set, server-observed, with its text', 'append',
            ['last' => null, 'event' => ['type' => 'signer.requested_changes', 'actor' => 'signer:alice', 'data' => ['text' => 'Please itemise the delivery.']] + $base], tags: ['append', 'requested-changes']);
        $this->add($c, 'append-refuses-client-requested-changes', 'a request for changes reported by the client is refused: the server observes it', 'append',
            ['last' => null, 'event' => ['type' => 'signer.requested_changes', 'actor' => 'signer:alice', 'source' => 'client', 'data' => ['text' => 'x']] + $base], tags: ['append', 'refusal', 'requested-changes']);
        $this->since = null;

        // 0.57.0: data mode (fancy-sign 0.7.0). A rendering claim from the browser; each signature record's token.
        $this->since = self::SINCE_057;
        $rendered = ['data_sha256' => str_repeat('9', 64), 'renderer' => 'host quote-v7 @ host-app 2026.11.19', 'pages' => 2];
        $this->add($c, 'append-document-rendered-client', 'document.rendered is a client claim: accepted with source client', 'append',
            ['last' => null, 'event' => ['type' => 'document.rendered', 'actor' => 'signer:alice', 'source' => 'client', 'data' => $rendered] + $base],
            notes: 'Which presentation the signer\'s browser says it showed (data mode). A claim, like page.viewed.', tags: ['append', 'data-mode']);
        $this->add($c, 'append-refuses-server-document-rendered', 'document.rendered from the server is refused: only the browser makes that claim', 'append',
            ['last' => null, 'event' => ['type' => 'document.rendered', 'actor' => 'signer:alice', 'data' => $rendered] + $base], tags: ['append', 'refusal', 'data-mode']);
        $this->add($c, 'append-signature-sealed', 'signature.sealed puts a signature record\'s token on the chain, server-observed', 'append',
            ['last' => null, 'event' => ['type' => 'signature.sealed', 'actor' => 'signer:alice', 'data' => ['signer_id' => 'alice', 'token' => 'fsig_sig_v1_'.str_repeat('a', 64), 'key_id' => '0123456789abcdef']] + $base],
            tags: ['append', 'data-mode']);
        $this->add($c, 'append-refuses-client-signature-sealed', 'a client signature.sealed is refused', 'append',
            ['last' => null, 'event' => ['type' => 'signature.sealed', 'actor' => 'signer:alice', 'source' => 'client', 'data' => ['signer_id' => 'alice']] + $base], tags: ['append', 'refusal', 'data-mode']);
        $this->since = null;

        // 0.58.0: attachFinal. The host registers its final PDF's hash, once, server-observed.
        $this->since = self::SINCE_058;
        $finalized = ['final_sha256' => str_repeat('f', 64), 'final_bytes' => 48213];
        $this->add($c, 'append-document-finalized', 'document.finalized records the host\'s final PDF hash and length, server-observed', 'append',
            ['last' => null, 'event' => ['type' => 'document.finalized', 'actor' => 'user:1', 'data' => $finalized] + $base],
            notes: 'Data mode, after completion: attachFinal. The package never reads or stores the PDF.', tags: ['append', 'data-mode']);
        $this->add($c, 'append-refuses-client-document-finalized', 'a client document.finalized is refused', 'append',
            ['last' => null, 'event' => ['type' => 'document.finalized', 'actor' => 'user:1', 'source' => 'client', 'data' => $finalized] + $base], tags: ['append', 'refusal', 'data-mode']);
        $this->since = null;
    }

    // --------------------------------------------------------------- sign/data

    /**
     * fsig-data-1 (SPEC section 6.1): the thirteen vectors of the CC-fancy-0052
     * plan, read from tests/Conformance/fsig-data-1-vectors.json. Inputs are
     * JSON TEXT: the decoder is part of what is tested.
     */
    private function data(): void
    {
        $c = 'sign/data';
        $this->since = self::SINCE_057;
        $rows = [
            'd0001' => ['canonical-sorts-keys', 'keys sorted, list order kept, no whitespace', ['canonical', 'control']],
            'd0002' => ['canonical-floats', 'non-integral numbers print as ECMAScript Number::toString (shortest round trip)', ['canonical', 'numbers']],
            'd0003' => ['canonical-integral-numbers', 'an integral number prints as an integer whatever its source form; -0 is 0', ['canonical', 'numbers']],
            'd0004' => ['canonical-exponent-form', 'below 1e-6 the exponent form, as ECMAScript prints it (1e-7, never 1.0e-7)', ['canonical', 'numbers']],
            'd0005' => ['canonical-objects-stay-objects', 'an object is an object whatever its keys; {} and [] stay apart', ['canonical', 'index-keys']],
            'd0006' => ['canonical-byte-order-and-escapes', 'UTF-8 byte order for keys; slash, non-ASCII and U+2028 raw; controls escaped', ['canonical']],
            'd0007' => ['canonical-quote-data', 'opaque host data: a quote as the host stores it, floats and nested arrays', ['canonical', 'control']],
            'd0008' => ['refuses-unsafe-integer', 'refused: an integral number outside the safe-integer range', ['canonical', 'refusal']],
            'd0009' => ['refuses-unsafe-integral-float', 'refused: an integral number written as a float beyond 2^53', ['canonical', 'refusal']],
            'd0010' => ['refuses-lone-surrogate', 'refused: a lone surrogate', ['canonical', 'refusal']],
            'd0011' => ['refuses-non-object', 'refused: the top level is not an object', ['canonical', 'refusal']],
            'd0012' => ['canonical-nested-arrays', 'nested arrays of arrays with floats, mixed with objects and nulls', ['canonical', 'numbers']],
            'd0013' => ['canonical-empty-object', 'the empty object is valid data', ['canonical']],
        ];
        $vectors = json_decode((string) file_get_contents(__DIR__.'/fsig-data-1-vectors.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($vectors as $v) {
            [$slug, $title, $tags] = $rows[$v['id']];
            $this->add($c, $slug, $title, 'canonical', ['text' => $v['input']], notes: "Plan vector {$v['id']}: {$v['note']}.", tags: $tags);
        }
        $this->since = null;
    }

    // -------------------------------------------------------- sign/completion

    private function completion(): void
    {
        $c = 'sign/completion';
        $public = Ed25519::publicFromSecret(self::secret(self::SEED));
        $keyId = Ed25519::keyId($public);
        $asSent = "%PDF-1.7\n% as-sent document bytes\n%%EOF\n";
        $final = $asSent."% incremental update with the marks\n%%EOF\n";
        $chain = self::chainOf(6, '01JD2222222222222222222222');
        $record = [
            'type' => Completion::TYPE, 'version' => Completion::VERSION, 'envelope_id' => '01JD2222222222222222222222',
            'as_sent_sha256' => hash('sha256', $asSent), 'as_sent_bytes' => strlen($asSent), 'final_sha256' => hash('sha256', $final),
            'signers' => [['id' => 'alice', 'name' => "Zo\u{00EB} Example", 'email' => 'zoe@example.com', 'signed_at' => '2026-11-18T12:05:00Z', 'method' => 'otp', 'otp_seq' => 5, 'consent_seq' => 3]],
            'chain_head' => $chain[5]['hash'], 'nonce' => '00112233445566778899aabbccddeeff', 'key_id' => $keyId,
        ];
        $sealed = $record + ['token' => Completion::tokenFor($record)];
        $sealed['seal'] = Completion::seal($record, self::secret(self::SEED));
        $keys = [$keyId => $public];
        $pdf = base64_encode($final);

        $this->add($c, 'public-key-from-seed', 'the Ed25519 public key (base64) of the fixed test seed', 'publicKey', ['seed' => self::SEED], tags: ['seal', 'control']);
        $this->add($c, 'key-id', 'key_id = the first 16 hex characters of sha256(raw public key)', 'keyId', ['public_key' => $public], tags: ['seal']);
        $this->add($c, 'canonical-record', 'the canonical bytes: the record\'s closed key set only, keys sorted, no token or seal', 'canonical',
            ['record' => $sealed + ['extra' => 'ignored']], notes: 'A key outside Completion::KEYS never enters the bytes the token hashes and the seal signs.', tags: ['token']);
        $this->add($c, 'token', 'token = "fsig_v1_" + sha256(canonical bytes)', 'token', ['record' => $record], tags: ['token', 'control']);
        $this->add($c, 'seal', 'seal = base64 Ed25519 detached signature over the canonical bytes; deterministic, so the bytes are pinned', 'seal',
            ['record' => $record, 'seed' => self::SEED], tags: ['seal', 'control']);
        $this->add($c, 'well-formed', 'a complete version-1 record is well formed', 'wellFormed', ['record' => $sealed], tags: ['shape']);
        $this->add($c, 'not-well-formed-extra-key', 'an extra key makes a record not well formed', 'wellFormed', ['record' => $sealed + ['password' => 'x']],
            notes: 'The closed key set is what stops anything credential-shaped riding along in a sealed record.', tags: ['shape']);
        $noKey = $sealed;
        unset($noKey['nonce']);
        $this->add($c, 'not-well-formed-missing-key', 'a missing key makes a record not well formed', 'wellFormed', ['record' => $noKey], tags: ['shape']);
        $badSigner = $sealed;
        $badSigner['signers'][0]['ip'] = '192.0.2.10';
        $this->add($c, 'not-well-formed-signer-key', 'a signer entry with a key outside its closed set is refused', 'wellFormed', ['record' => $badSigner], tags: ['shape']);

        $verify = fn (string $slug, string $title, array $patch, ?string $notes = null) => $this->add($c, $slug, $title, 'verify',
            $patch + ['pdf_base64' => $pdf, 'completion' => $sealed, 'public_keys' => $keys, 'events' => $chain], $notes, ['verify']);
        $verify('verify-ok', 'the final PDF, its sealed record and its events verify', []);
        $verify('verify-ok-without-events', 'without events the chain check is skipped (null), and the rest passes', ['events' => null]);
        $verify('verify-completion-invalid', 'a record that is not well formed is completion_invalid', ['completion' => ['type' => 'something.else'] + $sealed]);
        $tokenOnly = $sealed;
        $tokenOnly['signers'][0]['name'] = 'Mallory';
        $verify('verify-token-mismatch', 'a record changed after sealing is token_mismatch', ['completion' => $tokenOnly]);
        $retoken = $tokenOnly;
        $retoken['token'] = Completion::tokenFor($retoken);
        $verify('verify-seal-invalid', 'a changed record with a RECOMPUTED token is seal_invalid', ['completion' => $retoken],
            'Recomputing the token is the forger\'s obvious move; only the seal stops it.');
        $verify('verify-seal-unknown-key', 'a key_id with no published key is seal_invalid', ['public_keys' => ['0000000000000000' => $public]]);
        $verify('verify-final-hash-mismatch', 'a different final PDF is final_hash_mismatch', ['pdf_base64' => base64_encode($final.'% one more update'."\n")]);
        $prefixRecord = $record;
        $otherSent = "%PDF-1.7\n% a different as-sent document\n%%EOF\n";
        $prefixRecord['as_sent_sha256'] = hash('sha256', $otherSent);
        $prefixSealed = $prefixRecord + ['token' => Completion::tokenFor($prefixRecord)];
        $prefixSealed['seal'] = Completion::seal($prefixRecord, self::secret(self::SEED));
        $verify('verify-prefix-mismatch', 'a sealed record whose as-sent hash is not the prefix of the final PDF is prefix_mismatch', ['completion' => $prefixSealed]);
        $broken = $chain;
        $broken[2]['data'] = ['tampered' => 'yes'];
        $verify('verify-chain-broken', 'events that do not form an intact chain are chain_broken', ['events' => $broken]);
        $verify('verify-chain-other-envelope', 'an intact chain of ANOTHER envelope is chain_broken', ['events' => self::chainOf(6)]);
        $verify('verify-chain-head-mismatch', 'an intact chain that never reached the sealed head is chain_head_mismatch', ['events' => array_slice($chain, 0, 4)]);
    }

    // ------------------------------------------------------------ sign/fields

    private function fields(): void
    {
        $c = 'sign/fields';
        $doc = ['title' => 'Quote', 'blocks' => [
            ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Quote Q-1001']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Three widgets at 10.00 each.']]],
            ['type' => 'signatureField', 'id' => 'accept-sig', 'signer' => 'alice'],
            ['type' => 'initialsField', 'id' => 'accept-ini', 'signer' => 'alice', 'required' => false, 'label' => 'Initial here'],
            ['type' => 'dateSignedField', 'id' => 'accept-date', 'signer' => 'bob'],
        ]];
        $two = [['id' => 'alice', 'name' => 'Alice Example', 'email' => 'alice@example.com'], ['id' => 'bob', 'name' => 'Bob Example', 'email' => 'bob@example.com']];
        $long = ['title' => 'Terms', 'blocks' => array_merge(
            array_map(fn (int $i) => ['type' => 'paragraph', 'runs' => [['text' => str_repeat("Clause {$i}: the supplier delivers the goods described in the schedule, on the dates agreed in writing. ", 6)]]], range(1, 24)),
            [['type' => 'signatureField', 'id' => 'end-sig', 'signer' => 'alice']],
        )];

        $this->add($c, 'fields-from-nodes', 'document field nodes become fields in document order; each signer gets a final-page signature and date', 'fields',
            ['document' => $doc, 'signers' => $two], tags: ['fields', 'control']);
        $this->add($c, 'fields-per-page-initials', 'per_page initials adds ONE every-page field per signer, anchored to every_page', 'fields',
            ['document' => $doc, 'signers' => $two, 'settings' => ['per_page' => 'initials']], tags: ['fields', 'per-page']);
        $this->add($c, 'fields-per-page-signature', 'per_page signature adds an every-page signature field per signer', 'fields',
            ['document' => $doc, 'signers' => $two, 'settings' => ['per_page' => 'signature']], tags: ['fields', 'per-page']);
        $this->add($c, 'fields-unknown-signer', 'a field naming a signer not on the envelope is refused', 'fields',
            ['document' => $doc, 'signers' => [$two[0]]], tags: ['fields', 'refusal']);
        $clash = $doc;
        $clash['blocks'][] = ['type' => 'signatureField', 'id' => 'final-signature-alice', 'signer' => 'alice'];
        $this->add($c, 'fields-generated-id-collision', 'a document field id that collides with a generated one is refused', 'fields',
            ['document' => $clash, 'signers' => $two], tags: ['fields', 'refusal']);
        $this->add($c, 'fields-org-per-page', 'an organisation\'s per_page applies to its envelope when the sender sets none', 'fields',
            ['document' => $doc, 'signers' => $two, 'org_per_page' => 'initials'], tags: ['fields', 'org']);
        $this->add($c, 'fields-org-per-page-override-refused', 'a sender setting per_page against the organisation is refused', 'fields',
            ['document' => $doc, 'signers' => $two, 'org_per_page' => 'initials', 'settings' => ['per_page' => 'none']], tags: ['fields', 'org', 'refusal']);
        $this->add($c, 'fields-bad-per-page', 'per_page outside none, initials and signature is refused', 'fields',
            ['document' => $doc, 'signers' => $two, 'settings' => ['per_page' => 'stamp']], tags: ['fields', 'refusal']);

        $this->add($c, 'placement-one-page', 'a short document: the fields land on page 1, the final page is page 2', 'placement',
            ['document' => $doc, 'signers' => $two], tags: ['placement', 'control']);
        $this->add($c, 'placement-per-page-one-authored-page', 'per-page initials on a ONE-page document land on page 1 only, never on the final page', 'placement',
            ['document' => $doc, 'signers' => $two, 'settings' => ['per_page' => 'initials']],
            notes: 'The edge the plan names: at one authored page, a port that counts the final page as authored puts a mark on it.', tags: ['placement', 'per-page']);
        $this->add($c, 'placement-per-page-long', 'per-page initials on a multi-page document land on every authored page and not on the final one', 'placement',
            ['document' => $long, 'signers' => [$two[0]], 'settings' => ['per_page' => 'initials']], tags: ['placement', 'per-page']);
    }

    // --------------------------------------------------------------- sign/otp

    private function otp(): void
    {
        $c = 'sign/otp';
        $this->add($c, 'error-codes', 'the closed set of wire error codes and their HTTP statuses', 'errorCodes', [],
            notes: 'otp_unknown, otp_expired and otp_wrong are NOT here: on the wire they are all otp_invalid (the precise reason is in the otp.failed event).', tags: ['errors', 'control']);
        $this->add($c, 'code-digest', 'a code is stored as HMAC-SHA256(app key, "envelope|signer|purpose|code"), lowercase hex', 'codeDigest',
            ['app_key' => self::APP_KEY, 'envelope_id' => '01JD0000000000000000000000', 'signer_id' => 'alice', 'purpose' => 'view', 'code' => '042137'], tags: ['digest', 'control']);
        $this->add($c, 'code-digest-purpose-bound', 'the same code for accept digests differently: purposes cannot stand in for each other', 'codeDigest',
            ['app_key' => self::APP_KEY, 'envelope_id' => '01JD0000000000000000000000', 'signer_id' => 'alice', 'purpose' => 'accept', 'code' => '042137'], tags: ['digest']);

        $this->add($c, 'view-right-code', 'request a view code and verify it: link.opened once, otp.sent, otp.verified, a session', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'right']]], tags: ['scenario', 'control']);
        $this->add($c, 'view-wrong-code-redacted', 'a wrong code answers otp_invalid; the event records otp_wrong', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'wrong'], ['op' => 'verify', 'code' => 'right']]],
            notes: 'And the right code still works after one wrong guess: a wrong guess puts the code back with one more attempt.', tags: ['scenario', 'redaction']);
        $this->add($c, 'view-expired-redacted', 'a code past its 10 minutes answers otp_invalid; the event records otp_expired', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'advance', 'seconds' => 600], ['op' => 'verify', 'code' => 'right']]], tags: ['scenario', 'redaction']);
        $this->add($c, 'view-replay-redacted', 'a code is consumed on use: the same code again answers otp_invalid, recorded as otp_unknown', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'right'], ['op' => 'verify', 'code' => 'right']]],
            notes: 'The second verify sends the SAME code that already opened a session (no new code was requested in between).', tags: ['scenario', 'redaction']);
        $this->add($c, 'view-locks-after-max-attempts', 'the fifth wrong code locks: otp_locked (distinct on the wire) and an otp.locked event', 'scenario',
            ['steps' => array_merge([['op' => 'send'], ['op' => 'request', 'purpose' => 'view']], array_fill(0, 5, ['op' => 'verify', 'code' => 'wrong']), [['op' => 'verify', 'code' => 'right']])],
            notes: 'Once locked, even the right code answers otp_locked, until a new code is requested.', tags: ['scenario', 'lock']);
        $this->add($c, 'view-new-code-replaces-old', 'a new code replaces the previous one: the earlier code no longer verifies', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'stale'], ['op' => 'verify', 'code' => 'right']]], tags: ['scenario']);
        $this->add($c, 'send-rate-limit', 'a sixth code request in the window is otp_rate_limited', 'scenario',
            ['steps' => array_merge([['op' => 'send']], array_fill(0, 6, ['op' => 'request', 'purpose' => 'view']))], tags: ['scenario', 'limits']);
        $this->add($c, 'limits-are-settings', 'every limit is a setting: max_attempts 2 locks on the second wrong code', 'scenario',
            ['config' => ['otp' => ['max_attempts' => 2]], 'steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'wrong'], ['op' => 'verify', 'code' => 'wrong']]], tags: ['scenario', 'limits']);
        $this->add($c, 'accept-needs-session', 'an accept code is only sent inside a signer session', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'accept', 'withSession' => false]]], tags: ['scenario', 'accept']);
        $ceremony = [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'right'], ['op' => 'consent'], ['op' => 'open']];
        $this->add($c, 'accept-signs', 'a fresh accept code signs: otp.verified for accept, then the signing events', 'scenario',
            ['steps' => array_merge($ceremony, [['op' => 'request', 'purpose' => 'accept'], ['op' => 'sign', 'code' => 'right']])], tags: ['scenario', 'accept', 'control']);
        $this->add($c, 'accept-view-code-refused', 'the view code cannot accept: otp_invalid, and nothing is signed', 'scenario',
            ['steps' => array_merge($ceremony, [['op' => 'request', 'purpose' => 'accept'], ['op' => 'sign', 'code' => 'view'], ['op' => 'sign', 'code' => 'right']])],
            notes: 'Purpose binding. The refused attempt costs the accept code one attempt, and the right accept code still signs.', tags: ['scenario', 'accept']);
        $this->add($c, 'accept-without-request', 'signing with no accept code ever sent is otp_invalid (otp_unknown in the event)', 'scenario',
            ['steps' => array_merge($ceremony, [['op' => 'sign', 'code' => '123456']])], tags: ['scenario', 'accept']);
        $this->add($c, 'sign-before-consent', 'the ceremony order holds: signing before consent is consent_required and burns no code', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'right'], ['op' => 'request', 'purpose' => 'accept'], ['op' => 'sign', 'code' => 'right']]], tags: ['scenario', 'order']);

        $this->since = self::SINCE_052;
        // 0.52.0: refusals are the kit-wide error envelope (CC-fancy-0031, suites/http/SPEC.md section 1).
        $this->add($c, 'envelope-422', 'a 422 is validation_failed, with the fancy-sign code as its one item', 'envelope',
            ['code' => 'otp_invalid', 'message' => 'That code is not valid. Request a new one if it has expired.', 'request_id' => 'req-0001'], tags: ['envelope']);
        $this->add($c, 'envelope-423', 'otp_locked keeps its code at the top level', 'envelope',
            ['code' => 'otp_locked', 'message' => 'Too many wrong codes. Request a new code.', 'request_id' => 'req-0002'], tags: ['envelope']);
        $this->add($c, 'envelope-404', 'link_invalid is a top-level 404 code, with the request id', 'envelope',
            ['code' => 'link_invalid', 'message' => 'This link is not valid.', 'request_id' => 'req-0003'], tags: ['envelope']);
        $this->since = null;

        // 0.53.0: request changes (fancy-sign 0.3.0). It does not end the envelope.
        $this->since = self::SINCE_053;
        $this->add($c, 'changes-then-sign', 'a signer asks for changes and can still sign: signer.requested_changes {text}, then the signing events', 'scenario',
            ['steps' => array_merge($ceremony, [['op' => 'changes', 'text' => '  Please itemise the delivery.  '], ['op' => 'request', 'purpose' => 'accept'], ['op' => 'sign', 'code' => 'right']])],
            notes: 'The text is trimmed before it is chained.', tags: ['scenario', 'requested-changes']);
        $this->add($c, 'changes-need-the-view-code', 'asking for changes needs a session, as decline does', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'changes', 'text' => 'Change it.']]], tags: ['scenario', 'requested-changes']);
        $this->add($c, 'changes-over-the-cap', 'the cap is a setting: max_change_requests 1 refuses the second request with limit_exceeded (429)', 'scenario',
            ['config' => ['max_change_requests' => 1], 'steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'right'],
                ['op' => 'changes', 'text' => 'First.'], ['op' => 'changes', 'text' => 'Second.']]], tags: ['scenario', 'requested-changes', 'limits']);
        $this->add($c, 'changes-text-required', 'an empty message is invalid_request and records nothing', 'scenario',
            ['steps' => [['op' => 'send'], ['op' => 'request', 'purpose' => 'view'], ['op' => 'verify', 'code' => 'right'], ['op' => 'changes', 'text' => '   ']]], tags: ['scenario', 'requested-changes']);
        $this->add($c, 'changes-after-signing', 'after the last signature completes the envelope, asking for changes is envelope_not_open', 'scenario',
            ['steps' => array_merge($ceremony, [['op' => 'request', 'purpose' => 'accept'], ['op' => 'sign', 'code' => 'right'], ['op' => 'changes', 'text' => 'Too late.']])], tags: ['scenario', 'requested-changes']);
        $this->since = null;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $target = $argv[1] ?? dirname(__DIR__, 3).'/fancy-conformance/suites';
    foreach (Export::write($target) as $suite => $count) {
        echo str_pad($suite, 20).$count." rows\n";
    }
}
