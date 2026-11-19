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
- **Preview before sending (CC-fancy-0047):** `Signing::preview($input, ?$orgId)`
  takes the same input as `create()` and returns the PDF bytes `send()` would
  render for that organisation now: signature page, per-page marks from
  `per_page`, every field in place. It stores nothing, records no event and
  sends no mail; bad input is refused with the same `SignError` as `create()`.
  The envelope reference and content hash lines read `PREVIEW, assigned when
  sent` (`Renderer::PREVIEW_PLACEHOLDER`). Show it to the sender before they
  approve a send; never hand it to a signer as the sent document. There is no
  HTTP route for it: call it from your own controller.
- **Preview as data (CC-fancy-0048):** `Signing::previewDocument($input, ?$orgId)`
  returns the as-sent document MODEL instead of PDF bytes, from the same build
  step `send()` uses: `document` (the fine-print document with the generated
  signature section appended as nodes), `signature_section: {placement, start}`
  (`start` is the index of the first generated block), `page_fields` (per-page
  marks as `initialsField`/`signatureField` nodes with `pages: "authored"`),
  `footer` (the reference lines when there is no section), `fields`, and the
  placeholder `reference` / `content_sha256`. Draw your review from it (e.g.
  last-word-ui's DocViewer); `(new Renderer())->render($model)['bytes']` is
  exactly `preview()`. Same guarantees as `preview()`: nothing stored, sent or
  recorded. Generated field nodes carry `keepNext`, which fine-print's
  authoring validator does not list: strip it before validating the model as
  an authored document.
- **Where the signature section goes: `settings.final_page`.** `always`
  (default, unchanged from 0.4.0): a page of its own. `auto`: nothing when every
  signer already has a required `signatureField` and `dateSignedField`;
  otherwise the section flows right after the content, moved whole to a new
  page only when it does not fit. `never`: nothing, and an envelope whose signer
  lacks a required `signatureField` is refused. Without a section, the envelope
  reference and content hash are a footer line on the last page. Generated
  fields keep their `final-signature-{signer}` / `final-date-{signer}` ids;
  under `auto` they are anchored `{signature_section: true}`. The certificate
  (PDF and JSON `reference`) says where the reference and hash are printed.
- **Per-page marks are an organisation setting.** `GET`/`PUT
  /fancy-sign/org/settings { per_page: none|initials|signature }`; only an admin
  named by `FancySign\Laravel\OrgAdminPolicy` may change it (the default names
  nobody, so bind one). When it is on, a sender's envelope that disagrees is
  refused with `invalid_request`. Framework-free: `orgSettings()` /
  `setOrgSettings()`. The same endpoint takes `final_page: always|auto|never`,
  the org's DEFAULT for `settings.final_page` (a sender may still choose per
  envelope); left out, it keeps its current value. Laravel hosts run
  `php artisan migrate` once for its column.
- **Extension points:** bind `FancySign\Mail\SignMailer` (branded mail),
  `FancySign\Laravel\SenderPolicy` (teams; the default lets a sender reach only
  their own envelopes), `FancySign\StepUp\StepUpVerifier` (to allow
  `settings.step_up = passkey`), `FancySign\Listener\SignListener` (the host
  hook, below).
- **Verify:** `verify(final.pdf, completion.json, events?)` names the first
  failure (`completion_invalid`, `token_mismatch`, `seal_invalid`,
  `final_hash_mismatch`, `prefix_mismatch`, `chain_broken`,
  `chain_head_mismatch`).

## React to what happens: the host hook (0.2.0)

Every event committed to an envelope's chain (the closed set:
`envelope.sent`, `document.opened`, `page.viewed`, `signer.signed`,
`signer.declined`, `envelope.completed`, `seal.applied`, `envelope.voided`, and
the rest) reaches the host as `FancySign\Events\EnvelopeEventRecorded`
`{envelopeId, eventType, seq, at, actor, source}`. Two ways to take it:

- **A Laravel listener** on `EnvelopeEventRecorded`, or
- **a `FancySign\Listener\SignListener`** you bind in the container.

Both run inside a queued job (`FancySign\Laravel\DeliverEnvelopeEvent`)
dispatched **after the database commit**, retried on failure (5 tries by
default, `config('fancy-sign.hook')`). Delivery is **at least once**: dedupe on
`$event->dedupeKey()` (envelope id and seq). Your listener throwing never fails
or rolls back the signer's request; on an async queue it is retried, on the
`sync` driver it is reported through your exception handler.

Closing a deal when the quote is signed:

```php
use FancySign\Events\EnvelopeEventRecorded;
use Illuminate\Support\Facades\Event;

Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e): void {
    // Once per event, possibly more than once: make it idempotent on the seq.
    if (! ProcessedSignEvent::firstOrCreate(['key' => $e->dedupeKey()])->wasRecentlyCreated) {
        return;
    }

    match ($e->eventType) {
        // One signer of several has signed: note it on the deal.
        'signer.signed' => Deal::where('envelope_id', $e->envelopeId)->first()?->noteSigned($e->actor, $e->at),
        // Every signer has signed and the record is sealed: close the deal.
        'envelope.completed' => Deal::where('envelope_id', $e->envelopeId)->first()?->closeWon(),
        'signer.declined', 'envelope.voided' => Deal::where('envelope_id', $e->envelopeId)->first()?->closeLost($e->eventType),
        // The signer asked for changes and the envelope is STILL OPEN: revise and resend, or wait.
        'signer.requested_changes' => Deal::where('envelope_id', $e->envelopeId)->first()?->flagChangesRequested($e->at),
        default => null,
    };
});
```

- **`envelope.completed` is the one to close on.** It means every signer
  signed; `signer.signed` arrives once per signer. Download the sealed PDF and
  the certificate from the package (`finalDocument()`, `certificate()`) when
  you need them.
- The event carries no `data`: never a code, a token or a signer's typed text.
  Read the envelope or its events through the package for details.
- **Reading an event's details:** `Signing::events($id)` returns each event
  as an array, and under the Laravel bridge's database store its `data` is an
  **object** (stdClass), on purpose: JSON objects are kept as objects so a
  chain verifies the same everywhere (`{"0": ..}` must never turn into a
  list). Read `$event['data']->reason` (a decline reason) or
  `$event['data']->purpose`, not `$event['data']['reason']`; `(array)
  $event['data']` if you want an array. The in-memory store hands back the
  arrays it was given.
- **Request changes (0.3.0).** `POST /fancy-sign/s/{token}/request-changes
  {text}` (framework-free: `Signing::requestChanges($token, $session, $text,
  $ctx)`) records `signer.requested_changes` with `data.text`
  (`$event['data']->text`). It needs the view code's session, as decline does,
  and is refused once the signer has signed or declined. It does NOT end the
  envelope, so do not treat it as a decline: the signer can still sign. Each
  signer may ask `limits.max_change_requests` times (default 5); the next is
  `limit_exceeded` (429). To act on it, revise the quote and send a new
  envelope, or void this one; the request text is signer-written, so show it as
  plain text.
- **Use a prompt queue.** Delivery jobs go to your default queue unless
  `config('fancy-sign.hook.queue')` (`FANCY_SIGN_HOOK_QUEUE`) names one. If your
  default queue is slow or busy, set it to a queue a worker drains promptly,
  so closing the deal does not wait behind other jobs.
- **Without Laravel**, pass `listener:` to `new Signing(...)`. It is called right
  after each event is stored, synchronously, and anything it throws is
  swallowed so the request stands; queue the work yourself if it can fail.

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
