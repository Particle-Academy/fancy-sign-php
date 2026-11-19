# fancy-sign (PHP)

`particle-academy/fancy-sign`: e-signature envelopes for PHP, with a Laravel bridge.

Designed to support simple electronic signatures (SES): consent and intent
captured, signer verified by email one-time code, a hash-chained audit trail,
and a sealed final document.

Whether an electronic signature is appropriate for a given document, and which
level it needs, depends on the jurisdiction and the document type.
**Have it reviewed by a lawyer before relying on it.**

Standard library only: SHA-256, HMAC, `random_int` and Ed25519 through
ext-sodium. The PDF engine is [`particle-academy/fine-print`](https://github.com/Particle-Academy/fine-print).
No third-party code.

<!-- fancy-agents-note:start -->
> **For agents.** This package is not listed on the Fancy MCP catalog server
> yet. The server, `https://ui.particle.academy/mcp` (streamable HTTP, no API key; MCP id
> `fancy-ui`), lists the released Fancy packages with install commands and
> docs. How an agent installs and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-sign-php/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

## What it does

- **Envelopes and signers.** A sender builds an envelope from a fine-print
  document (the last-word JSON model) and a list of signers. Customers have no
  accounts.
- **Fields.** `signatureField`, `initialsField` and `dateSignedField` nodes in
  the document. fine-print lays them out and reports where each landed, so no
  coordinate is ever stored by hand.
- **A final signature page**, generated and appended, with a signature and a
  date-signed field per signer. It is part of the document the signer sees.
  `settings.final_page` decides where it goes: `always` (the default) appends
  it as its own page; `auto` appends nothing when every signer already has a
  required signature and date field in the document, and otherwise lets the
  section flow straight after the content, moving it whole to a new page only
  when it does not fit; `never` appends nothing and refuses an envelope whose
  signer has no required signature field. Without a generated section, the
  envelope reference and content hash are printed in the last page's footer.
  An organisation admin can set the org's default.
- **Per-page initials or signatures**, an organisation admin's setting
  (`PUT /fancy-sign/org/settings { per_page }`). When it is on, every envelope
  of that organisation carries it, and a sender cannot turn it off; drafts take
  the setting in force when they are sent. An envelope with no organisation
  uses its own `settings.per_page`. The marks go on the authored pages only and
  never on the final page.
- **Email one-time codes, purpose-bound.** A `view` code opens a short signer
  session; a separate, fresh `accept` code is sent with the signature. Six
  digits from a CSPRNG, stored only as an HMAC, never logged or written to an
  event, expiring after 10 minutes, locking after 5 wrong tries, and consumed
  on use so a replay fails. Unknown, expired and wrong codes all answer
  `otp_invalid`; only `otp_locked` is distinct.
- **Consent and intent.** Consent to sign electronically comes before the
  document is shown; the event records the disclosure's SHA-256 and version.
  At signing the signer adopts a mark with the adoption text (hashed into
  `mark.adopted`), completes every required field and presses Sign with the
  fresh code.
- **Drawn signatures are strokes**, JSON points, drawn as vector paths. No
  image from the browser is ever decoded.
- **A per-envelope hash-chained event log** (change-council's scheme):
  `hash = sha256(prev_hash + "\n" + canonical({seq, envelope_id, at, type, actor, source, ip, user_agent, data}))`.
- **Engagement events:** sent (`envelope.sent`, `email.sent`), opened
  (`document.opened`, observed by the server), per-page viewed (`page.viewed`,
  **reported by the signer's browser** and labelled so everywhere), signed
  (`signer.signed`) and declined (`signer.declined`, with the reason).
- **Request changes.** Beside Decline, the signer can send the sender a
  required message (bounded like a decline reason), recorded as
  `signer.requested_changes` with its text. It does not end the envelope: the
  signer can still sign or decline, and can ask again up to
  `limits.max_change_requests` times (5 by default).
- **The final PDF** is the as-sent PDF plus one incremental update with the
  marks. The as-sent file stays a byte-for-byte prefix: truncate the final file
  at `as_sent_bytes` and hash it to prove the signed content did not change,
  with no tool of ours.
- **An Ed25519-sealed completion record**, `fsig_v1_<sha256>`, naming the
  envelope, both document hashes, each signer, and the chain head.
- **A certificate of completion**, PDF and JSON, listing every event with its
  source, the signers' IP addresses and user agents, and the seal.
- **`verify(final.pdf, completion.json, events?)`** names the first failure:
  `completion_invalid`, `token_mismatch`, `seal_invalid`, `final_hash_mismatch`,
  `prefix_mismatch`, `chain_broken`, `chain_head_mismatch`.

### Honest limits

- The seal is checked by **our** verifier with the host's public key
  (`GET /fancy-sign/public-key`). A PDF reader shows no signature panel for it.
  A PAdES seal that any reader can check is a later phase, as are advanced
  signatures through a trust service. `settings.level` other than `ses` and
  `settings.seal` other than `none` are refused today.
- `page.viewed` is what the signer's browser says. It is a claim, never proof
  of reading.
- IP addresses and user agents are personal data. They are kept because the
  evidence asks for them; list them in your privacy notice.

## Laravel

```bash
composer require particle-academy/fancy-sign
php artisan migrate
```

Set a seal key (a base64 Ed25519 secret key) in `.env` as `FANCY_SIGN_SEAL_KEY`:

```bash
php -r "echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;"
```

Publish the config to change anything (`php artisan vendor:publish --tag=fancy-sign-config`).
Every limit has a secure default and can be overridden under `limits`.

| Setting | Default |
|---|---|
| `prefix` | `fancy-sign` |
| `sender_middleware` | `['web', 'auth']` |
| `signer_url` | `{app.url}/sign/{token}`: your page running `<SignerCeremony>` from `@particle-academy/fancy-sign-ui` |
| `limits.otp.ttl_seconds` / `max_attempts` / `sends_per_window` / `sends_per_ip` | 600 / 5 / 5 / 30 |
| `limits.session_ttl_seconds` | 1800 |
| `limits.mark.max_strokes` / `max_points` / `max_typed` | 64 / 2048 / 128 |
| `limits.page_views.per_page` / `per_signer` | 20 / 1000 |
| `verify_max_bytes` | 20 MB |

Bind your own `FancySign\Mail\SignMailer` for branded mail, your own
`FancySign\Laravel\SenderPolicy` for teams (the default lets a sender reach only
the envelopes they created; its `orgId()` names the sender's organisation),
your own `FancySign\Laravel\OrgAdminPolicy` to say who may change an
organisation's signing settings (the default names nobody), and a
`FancySign\StepUp\StepUpVerifier` to allow
`settings.step_up = passkey` (for example with fancy-passkeys).

### Routes

The package registers JSON and PDF endpoints only, no pages.

```
Sender (your auth):
  GET  /fancy-sign/envelopes                  POST /fancy-sign/envelopes
  GET  /fancy-sign/envelopes/{id}             PUT  /fancy-sign/envelopes/{id}   (draft)
  POST /fancy-sign/envelopes/{id}/send        POST /fancy-sign/envelopes/{id}/void
  POST /fancy-sign/envelopes/{id}/remind      GET  /fancy-sign/envelopes/{id}/events
  GET  /fancy-sign/envelopes/{id}/certificate (?format=json)
  GET  /fancy-sign/envelopes/{id}/document
  GET  /fancy-sign/org/settings               PUT  /fancy-sign/org/settings  { per_page, final_page }  (org admin)

Signer (link token, then X-Fancy-Sign-Session):
  POST /fancy-sign/s/{token}/otp          { purpose: view|accept }
  POST /fancy-sign/s/{token}/otp/verify   { purpose: view, code }  -> { session }
  GET  /fancy-sign/s/{token}/envelope     POST /fancy-sign/s/{token}/consent { version }
  GET  /fancy-sign/s/{token}/document     POST /fancy-sign/s/{token}/events  { type: page.viewed, page, ms }
  POST /fancy-sign/s/{token}/sign         { fields, mark, adoption, code }
  POST /fancy-sign/s/{token}/decline      { reason }
  POST /fancy-sign/s/{token}/request-changes  { text }   (envelope stays open; 429 limit_exceeded over the cap)

Public:
  POST /fancy-sign/verify                 multipart pdf + completion (+ events)
  GET  /fancy-sign/public-key
```

Errors are the kit-wide error envelope (the same one `fancy-http-guard` emits):
`{"error", "message", "requestId"}` with the request id also in the
`x-request-id` header, and codes from a closed set (`FancySign\SignError::CODES`).
A code whose status is 422 (`invalid_request`, `otp_invalid`,
`fields_incomplete`, `mark_invalid`) arrives as `"error": "validation_failed"`
with the code as its one item in `errors`. When the host runs fancy-http-guard,
its request id is reused.

## Without Laravel

```php
use FancySign\Signing;
use FancySign\Repository\InMemoryRepository; // implement FancySign\Repository\Repository for real storage

$signing = new Signing(
    repo: new InMemoryRepository(),
    mailer: $myMailer,                         // FancySign\Mail\SignMailer
    sealSecretKey: $sealKeyBase64,
    appKey: $thirtyTwoSecretBytes,
    linkUrl: fn (string $token) => "https://app.example.com/sign/{$token}",
);
$env = $signing->create(['title' => 'Quote', 'document' => $doc, 'signers' => [['id' => 'alice', 'name' => 'Alice', 'email' => 'alice@example.com']]], 'user:1');
$signing->send($env['id'], 'user:1');
```

### Preview before sending

`Signing::preview(array $input, ?string $orgId = null): string` returns the PDF
bytes `send()` would produce for the same input (what `create()` takes) and
organisation: the signature page, per-page initials or signatures from the
organisation's `per_page` setting, and every signature and date field in its
place. Nothing is stored, no event is recorded and no mail is sent. Invalid
input is refused with the same `SignError` as `create()`.

The envelope reference and the content hash are not assigned before sending, so
those two lines read `PREVIEW, assigned when sent`
(`Renderer::PREVIEW_PLACEHOLDER`). Everything else matches the sent document.

```php
$pdf = $signing->preview(['title' => 'Quote', 'document' => $doc, 'signers' => $signers], $orgId);
```

To draw the review from data rather than a PDF,
`Signing::previewDocument(array $input, ?string $orgId = null): array` returns
the as-sent document model from the same build step: the document with the
generated signature section as nodes, the per-page fields as field nodes, the
footer line when there is no section, and the fields. It has the same
guarantees as `preview()`, and `(new Renderer())->render($model)['bytes']` is
exactly the `preview()` PDF.

```php
$model = $signing->previewDocument(['title' => 'Quote', 'document' => $doc, 'signers' => $signers, 'settings' => ['final_page' => 'auto']], $orgId);
$model['document'];                        // render it in your own viewer
$model['signature_section']['placement']; // signature_page, after_content or footer
```

## Reacting to signatures

Every event committed to an envelope's chain reaches the host as
`FancySign\Events\EnvelopeEventRecorded` (envelope id, event type, seq, time):
a Laravel event, and a call to a `FancySign\Listener\SignListener` if you bind
one. Under Laravel it is delivered by a queued job after the database commit,
retried on failure, at least once (dedupe on the seq), and a failing listener
never fails the signer's request. Close a deal on `envelope.completed`;
`AGENTS.md` has the example.

## Agents

The `registerSignBridge` in `@particle-academy/agent-integrations` lets an agent
manage envelopes: read them, place fields, set signers and settings, and
**propose** sending, voiding or a reminder for a human to confirm. No tool
signs, verifies a code, accepts consent, adopts a mark or declines: those acts
belong to the signer.

## License

MIT.
