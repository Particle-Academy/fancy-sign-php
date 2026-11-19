# Changelog

All notable changes to `particle-academy/fancy-sign` are recorded here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**Pre-1.0: breaking changes may land in a MINOR release.** Every one is marked
BREAKING and says what a consumer must do.

## [Unreleased]

## [0.4.0] - 2026-11-19

### Added

- **`Signing::preview(array $input, ?string $orgId = null): string`**
  (CC-fancy-0047, owner-approved). The as-sent PDF exactly as `send()` would
  render it for the same input and organisation, before anything exists: the
  signature page, per-page initials or signatures from the organisation's
  `per_page` setting and every signature and date field in its place. It takes
  what `create()` takes and refuses bad input with the same `SignError`. No
  envelope, row, event or mail. The envelope reference and content hash lines
  read `PREVIEW, assigned when sent` (`Renderer::PREVIEW_PLACEHOLDER`), in the
  same position. `send()` and `preview()` share one render path, and a test
  holds the two PDFs to the same page count, content streams (every text
  position and field box) and text apart from those two lines. Additive;
  consumers do nothing.

### Changed

- CI: re-pin org third-party allowlist to 2614224

## [0.3.0] - 2026-11-18

### Added

- **Request changes** (owner-approved q_8c180eab). Beside Decline, the signer
  sends the sender a required message, bounded like a decline reason
  (`max_decline_reason`, 1000): `POST {prefix}/s/{token}/request-changes {text}`,
  `Signing::requestChanges()`. Recorded as a new event in the closed set,
  `signer.requested_changes` with `data.text`, chained, delivered through the
  0.2.0 host hook and readable from `events()`.
  - It does NOT end the envelope: the signer can still sign or decline.
  - It needs the view code's session, as decline does, and is refused after
    signing or declining.
  - A signer may ask up to `max_change_requests` times per envelope (default 5,
    a setting); the next is `limit_exceeded` (429) in the kit-wide error
    envelope.
  - The signer view carries `changes_requested` and `max_change_requests`.
  - Pinned by fancy-conformance 0.53.0 (`sign/chain` 0032-0033, `sign/otp`
    0020-0024).
- **Consumer action:** none required. A host closing deals from the hook should
  treat `signer.requested_changes` as "still open", not as a decline.

### Changed

- **CI: the lab-certificate gate verifies against the lab's rotated key list.**
  The lab's first signing key is retired: certificates it already issued still
  verify, and nothing new it signs ever can. Consumers do nothing.

- **Docs only (`AGENTS.md`):** an event's `data` from `Signing::events()` is an
  object under the database store (read `$event['data']->reason`), and the host
  hook should run on a prompt queue (`FANCY_SIGN_HOOK_QUEUE`) when the default
  one is slow. No code changed; nothing to do.

## [0.2.0] - 2026-11-18

### Added

- **The host hook** (owner-approved, q_c2b20c9e). Every event committed to an
  envelope's chain, every type in the closed set (`document.opened`,
  `signer.signed`, `signer.declined`, `envelope.completed`, `envelope.voided`
  and the rest), reaches the host as `FancySign\Events\EnvelopeEventRecorded`
  `{envelopeId, eventType, seq, at, actor, source}`, with `dedupeKey()`
  (envelope id and seq) for at-least-once delivery.
  - **Laravel:** dispatched as a Laravel event and passed to a bound
    `FancySign\Listener\SignListener`, both inside the queued job
    `FancySign\Laravel\DeliverEnvelopeEvent`, dispatched after the database
    commit (a rolled-back transaction delivers nothing) and retried (5 tries by
    default; `config('fancy-sign.hook')` sets connection, queue and tries).
  - **A listener's failure never fails or rolls back the request** that
    recorded the event: an async queue retries it; on the `sync` driver it is
    reported through the host's exception handler. Tested by a listener that
    throws during a whole signing ceremony, which still completes and chains.
  - **Without Laravel:** `new Signing(..., listener: $yourListener)`, called
    synchronously after each event is stored; what it throws is swallowed.
  - `AGENTS.md` shows closing a deal on `envelope.completed`.
- **Consumer action:** none. No job is queued until you listen for
  `EnvelopeEventRecorded` or bind a `SignListener`. Once you do, the delivery
  jobs run on your default queue (a queue worker must be running); with the
  `sync` driver they run inline.

## [0.1.0] - 2026-11-18

### Added

- The first release: designed to support simple electronic signatures (SES).
  Envelopes and signers; purpose-bound email one-time codes (view and accept);
  consent and intent captured as hashes; signature, initials and date fields
  from fine-print field nodes, a generated final signature page, and optional
  per-page initials or signatures that never land on the final page; a
  per-envelope hash-chained event log with engagement events; the final PDF as
  an incremental update over the as-sent one; an Ed25519-sealed `fsig_v1_`
  completion record; a certificate of completion (PDF and JSON); a verifier
  that names each failure. A Laravel bridge with migrations, sender, signer and
  public routes, and mail through the host's mailer.
- **Per-page initials or signatures are an organisation admin's setting.**
  `Signing::orgSettings()` / `setOrgSettings()`, `GET`/`PUT
  {prefix}/org/settings`, a `fancy_sign_org_settings` table, and
  `Laravel\OrgAdminPolicy` to name the admins (the default, `NoOrgAdmins`,
  names nobody). When an organisation turns it on, every envelope of that org
  carries it and a sender cannot override it (`invalid_request`); a draft takes
  the setting in force when it is sent, and a sent envelope keeps what it was
  sent with. A new error code, `forbidden` (403).
- **The reference for fancy-conformance's `sign/*` suites** (0.51.0): `sign/chain`,
  `sign/completion`, `sign/fields` and `sign/otp`, 73 rows exported from this
  package (`composer conformance:export`), replayed by `ConformanceTest` and
  guarded against drift by `DriftTest`. `Signing::codeDigest()` is public so the
  stored-code digest can be pinned for a port. Test dependency:
  `particle-academy/fancy-conformance` 0.52.0 (first-party).
- **Refusals are the kit-wide error envelope** (CC-fancy-0031, the shape
  fancy-http-guard emits): `{error, message, requestId}` plus the `x-request-id`
  header, and a 422 code as `validation_failed` with the code as its one item.
  fancy-http-guard's request id is reused when the host runs it. Pinned by
  `sign/otp` rows 0017-0019 in fancy-conformance 0.52.0.
- **Canonical JSON keeps an object an object whatever its keys**: `{"0": "a"}`
  is never hashed as `["a"]`. Event data read back from a store or a verify
  upload is decoded with objects kept as objects (`EventChain::rowsFromJson()`,
  the database store), so a chain verifies the same in PHP and in a Node port.
  Pinned by `sign/chain` rows 0027-0031.
- Needs `particle-academy/fine-print` 0.8.0 or later (signing fields and
  incremental updates).
- Test dependency: `laravel/framework`, the framework this package integrates
  with (the RULES.md carve-out), so the bridge is tested on the real branch.

### Changed

- **Docs:** `AGENTS.md` is now the guide to using this package (install, how to find it on the Fancy MCP catalog server once it is listed, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
