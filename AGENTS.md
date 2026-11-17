# Using particle-academy/fancy-sign (PHP) — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-sign
php artisan migrate
```

- **Requires:** PHP `^8.4` with ext-sodium. The Laravel bridge is for Laravel
  13; the framework-free `FancySign\Signing` service needs no framework.
- Set a seal key (a base64 Ed25519 secret key) as `FANCY_SIGN_SEAL_KEY`:

  ```bash
  php -r "echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;"
  ```

- Optional config: `php artisan vendor:publish --tag=fancy-sign-config`.
- The signer pages are yours: point `signer_url` at a page running
  `<SignerCeremony>` from `@particle-academy/fancy-sign-ui`.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). fancy-sign is not yet listed there; it appears once
published. Find it then with `search-backend-packages` (stack `php`).

## API essentials

- **Laravel routes** (JSON and PDF only, no pages) under the `fancy-sign`
  prefix: sender routes behind `sender_middleware` (default `['web', 'auth']`),
  signer routes under `/fancy-sign/s/{token}/...` with an
  `X-Fancy-Sign-Session` header after the view code, and public
  `POST /fancy-sign/verify` and `GET /fancy-sign/public-key`. The full list is
  in the README.
- **Errors** are the kit-wide error envelope, `{"error", "message",
  "requestId"}`, codes from the closed set `FancySign\SignError::CODES`. A 422
  code is `"error": "validation_failed"` with the code in `errors[0].error`:
  read it there.
- **Without Laravel:** `new Signing(repo:, mailer:, sealSecretKey:, appKey:, linkUrl:)`,
  then `create(...)` and `send(...)`. Implement `FancySign\Repository\Repository`
  for real storage (`InMemoryRepository` is for tests).
- **Per-page marks are an organisation setting.** `GET`/`PUT
  /fancy-sign/org/settings { per_page: none|initials|signature }`; only an admin
  named by `FancySign\Laravel\OrgAdminPolicy` may change it (the default names
  nobody, so bind one). When it is on, a sender's envelope that disagrees is
  refused with `invalid_request`. Framework-free: `orgSettings()` /
  `setOrgSettings()`.
- **Extension points:** bind `FancySign\Mail\SignMailer` (branded mail),
  `FancySign\Laravel\SenderPolicy` (teams; the default lets a sender reach only
  their own envelopes), `FancySign\StepUp\StepUpVerifier` (to allow
  `settings.step_up = passkey`).
- **Verify:** `verify(final.pdf, completion.json, events?)` names the first
  failure (`completion_invalid`, `token_mismatch`, `seal_invalid`,
  `final_hash_mismatch`, `prefix_mismatch`, `chain_broken`,
  `chain_head_mismatch`).

## Traps

- **Make no legal claim for it beyond the README's wording.** It is designed
  to support simple electronic signatures (SES); whether that suffices depends
  on the jurisdiction and the document type. Recommend legal review.
- **`settings.level` other than `ses` and `settings.seal` other than `none` are
  refused today.**
- **The seal is checked by this package's verifier**, with the host's public
  key. A PDF reader shows no signature panel for it.
- **`page.viewed` is reported by the signer's browser.** It is a claim, never
  proof of reading; label it so.
- **The ceremony order is enforced:** view code, consent, document opened, then
  sign (`consent_required`, `not_viewed`).
- **Unknown, expired and wrong codes all answer `otp_invalid`**; only
  `otp_locked` is distinct.
- **The verify endpoint takes the PDF as a multipart file**, not inside JSON.
- **IP addresses and user agents are stored** as evidence; they are personal
  data, so the host's privacy notice must list them.
- **Needs fine-print 0.8.0 or later** (signing fields and incremental updates); Composer installs it.
- **Pre-1.0:** breaking changes may land in MINOR releases.

## Agents

`registerSignBridge` in `@particle-academy/agent-integrations` (subpath
`/bridges/sign`) lets an agent manage envelopes: `sign_list_envelopes`,
`sign_get_envelope`, `sign_timeline`, `sign_verify`, field placement
(`sign_place_field`, `sign_move_field`, `sign_remove_field`),
`sign_set_signers`, `sign_set_settings`, and **proposals** a human confirms:
`sign_propose_send`, `sign_propose_void`, `sign_propose_reminder`.

No tool signs, verifies a code, accepts consent, adopts a mark or declines:
those acts belong to the signer.
