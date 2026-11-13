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

## What it does

- **Envelopes and signers.** A sender builds an envelope from a fine-print
  document (the last-word JSON model) and a list of signers. Customers have no
  accounts.
- **Fields.** `signatureField`, `initialsField` and `dateSignedField` nodes in
  the document. fine-print lays them out and reports where each landed, so no
  coordinate is ever stored by hand.
- **A final signature page**, generated and appended, with a signature and a
  date-signed field per signer. It is part of the document the signer sees.
- **Per-page initials or signatures** (`settings.per_page`), an admin option.
  They go on the authored pages only and never on the final page.
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
the envelopes they created), and a `FancySign\StepUp\StepUpVerifier` to allow
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

Signer (link token, then X-Fancy-Sign-Session):
  POST /fancy-sign/s/{token}/otp          { purpose: view|accept }
  POST /fancy-sign/s/{token}/otp/verify   { purpose: view, code }  -> { session }
  GET  /fancy-sign/s/{token}/envelope     POST /fancy-sign/s/{token}/consent { version }
  GET  /fancy-sign/s/{token}/document     POST /fancy-sign/s/{token}/events  { type: page.viewed, page, ms }
  POST /fancy-sign/s/{token}/sign         { fields, mark, adoption, code }
  POST /fancy-sign/s/{token}/decline      { reason }

Public:
  POST /fancy-sign/verify                 multipart pdf + completion (+ events)
  GET  /fancy-sign/public-key
```

Errors are `{"error": {"code", "message"}}`, with codes from a closed set
(`FancySign\SignError::CODES`).

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

## Agents

The `registerSignBridge` in `@particle-academy/agent-integrations` lets an agent
manage envelopes: read them, place fields, set signers and settings, and
**propose** sending, voiding or a reminder for a human to confirm. No tool
signs, verifies a code, accepts consent, adopts a mark or declines: those acts
belong to the signer.

## License

MIT.
