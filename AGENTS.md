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
- **Who signs when: `settings.order`** (0.6, CC-fancy-0051). `parallel`
  (default, unchanged from 0.5.0): every signer is mailed at send and any may
  sign first; a signer's `order` is its list position and any `order` in the
  input is ignored. `sequential`: give each signer an `order` (a whole number
  from 1; left out, its list position). `send()` mails only the lowest-order
  group; everyone else is `status: "waiting"` with no link. When every signer
  of a group has signed, the next group is mailed (`email.sent`, actor
  `system`). Equal orders sign in parallel within their group. `signer.signed`
  fires per signature and `envelope.completed` only after the last. A waiting
  signer's link is refused with `not_your_turn` (409) before anything is shown
  or recorded. A decline ends the envelope and nobody later is invited; a
  request for changes holds the sequence at that signer. `remind()` mails only
  invited signers who have not finished; `void()` works as ever. Countersign a
  quote: customer `order: 1`, the sender's countersigner `order: 2`.
- **Per-page marks are an organisation setting.** `GET`/`PUT
  /fancy-sign/org/settings { per_page: none|initials|signature }`; only an admin
  named by `FancySign\Laravel\OrgAdminPolicy` may change it (the default names
  nobody, so bind one). When it is on, a sender's envelope that disagrees is
  refused with `invalid_request`. Framework-free: `orgSettings()` /
  `setOrgSettings()`. The same endpoint takes `final_page: always|auto|never`,
  the org's DEFAULT for `settings.final_page` (a sender may still choose per
  envelope); left out, it keeps its current value. Likewise `order:
  parallel|sequential`, the org's DEFAULT for `settings.order`. Laravel hosts
  run `php artisan migrate` for their columns (`final_page`, `signing_order`).
- **Extension points:** bind `FancySign\Mail\SignMailer` (branded mail),
  `FancySign\Laravel\SenderPolicy` (teams; the default lets a sender reach only
  their own envelopes), `FancySign\StepUp\StepUpVerifier` (to allow
  `settings.step_up = passkey`), `FancySign\Listener\SignListener` (the host
  hook, below).
- **Verify:** `verify(final.pdf, completion.json, events?)` names the first
  failure (`completion_invalid`, `token_mismatch`, `seal_invalid`,
  `final_hash_mismatch`, `prefix_mismatch`, `chain_broken`,
  `chain_head_mismatch`).

## Data mode: the package signs data, the host does the rest (0.7.0)

`settings.mode: "data"` (default `pdf`). Use it when the host renders its own
page and owns its documents. **In data mode the package seals the data and the
signing evidence; how the data was displayed to each signer, and any document
made from it, are the host application's.** Never describe it as proving what a
signer was shown.

- **Create:** `data` (JSON text, or a tree from `json_decode($json, false)`;
  an associative array is refused because it cannot tell `{}` from `[]`),
  `data_sha256` = `FancySign\Data\CanonicalDocument::sha256($json)` (required),
  `fields` `[{id, signer, kind: signature|initials|date_signed, required?,
  label?, anchor?: {pointer}}]`, optional `data_profile: "last-word"`. Every
  signer needs a required `signature` field. `previewData()` answers the same
  facts without storing anything.
- **Over HTTP** send `data` as a JSON object in the body; the routes read it
  from the raw body with objects kept.
- **`send()` mails no one.** Call `invite($id, $signerId, $actor)` (`POST
  envelopes/{id}/signers/{signerId}/invite`) per signer, when your workflow
  decides. Inviting again replaces the link. Under `settings.order:
  sequential` a later group is refused (`not_your_turn`) until the group before
  it has signed, and nobody is mailed automatically: you invite the next group.
- **The signer page** (fancy-sign-ui) fetches the canonical bytes, checks
  their hash, renders them with the host's template, and posts a
  `document.rendered` claim `{data_sha256, renderer, pages}`. Put the template
  AND your app release in `renderer`, e.g. `"quote-v7 @ shop 2026.11.19"`.
  `sign()` must carry `data_sha256`.
- **React on `signer.signed` / `signature.sealed`** (the host hook), then read
  `signatures($id)` (`GET envelopes/{id}/signatures`): each sealed record and
  the adopted mark, for your own final document. `envelope.completed` means
  everyone has signed; what happens next is yours.
- **Optional: register your final PDF's hash** once everyone has signed:
  `attachFinal($id, $sha256, $bytes, $actor)` (`POST envelopes/{id}/final`
  `{sha256, bytes}`). Send the hash, never the PDF; the package stores no
  document. Once and idempotent: the same hash and length return the stored
  `fancy-sign.final` record (`fsig_fin_v1_`, on `envelope.final`); a different
  one is `final_already_attached` (409); before completion it is
  `envelope_not_completed` (409). It proves you registered that hash for that
  signing, not that the PDF shows the data faithfully.
- **Verify:** `Verifier::verifySignature($dataJson, $record, $events?)` for one
  signature, `Verifier::verifyData($dataJson, $completion, $events?,
  $signatures?, $finalPdf?, $final?)` for the envelope. `$dataJson` may be any
  export of the same data. A final adds `final_invalid`,
  `final_not_of_completion` and `final_hash_mismatch`. `POST verify` takes a
  version-2 `completion` with `data`, and optionally `final_pdf` and `final`.
- **What the host should do:** keep every template release it has served and
  name it in `renderer`; keep a template's meaning stable across releases
  (layout may change, terms may not); render deterministically for a given data
  and release; print `data_sha256` and the completion token in any final PDF
  you make; inline anything that carries meaning, because a URL is hashed as a
  URL. The package does not check `renderer` against your releases; that is
  yours to keep.
- **The package makes no document in data mode:** `preview()` and
  `previewDocument()` refuse. The certificate (`certificate($id, 'pdf'|'json')`)
  is made only when asked and describes the signing: the data hash, each
  signature record, each signer's rendering claim labelled as browser-reported,
  and a registered final.

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
- **`page.viewed` and `document.rendered` are reported by the signer's
  browser.** They are claims, never proof of reading; label them so.
- **Data mode: changing the data means voiding and sending a new envelope.**
  The data is fixed at send; layout and the signing page stay live.
- **The ceremony order is enforced:** view code, consent, document opened, then
  sign (`consent_required`, `not_viewed`).
- **Under `settings.order: sequential` a stalled signer blocks everyone after
  them.** Nothing times out on its own: remind them, or void and resend.
  Signer `order` is honoured ONLY under `sequential`.
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
