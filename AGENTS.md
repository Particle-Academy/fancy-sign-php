# AGENTS.md - fancy-sign (PHP)

This file describes **this repository's code**: its API, its invariants and its
traps. Process rules (release lifecycle, publishing, version policy, backports)
live in the envelope's `RULES.md` and are never copied here.

Design: the envelope's `.ai/plans/fancy-sign.md` (CC-fancy-0036). This repo is
phase 1, SES. Wire contract: plan section 5.10, as implemented by the routes in
`routes/fancy-sign.php`.

## Layout

| where | what |
|---|---|
| `src/Signing.php` | the framework-free core: every sender and signer operation |
| `src/Chain/EventChain.php` | the per-envelope hash chain, the closed event set |
| `src/Completion/Completion.php` | the `fsig_v1_` record, token and Ed25519 seal |
| `src/Verify/Verifier.php` | verify(final, completion, events?) with named failures |
| `src/Pdf/Renderer.php` | as-sent PDF, final PDF (incremental update), certificate |
| `src/Repository/` | the storage contract and the in-memory store |
| `src/Laravel/` | provider, database store, mailer, sender policy, controllers |
| `src/Support/CanonicalJson.php` | change-council's canonical JSON, copied |

## Invariants

- **Standard library only.** sodium, `hash`, `hash_hmac`, `random_int`,
  `random_bytes`. A new dependency is an approval request, never a `require`.
- **Secrets are stored hashed.** Link tokens and session tokens as SHA-256;
  codes as `HMAC-SHA256(app key, envelope|signer|purpose|code)`. A code goes to
  the mailer and nowhere else: never an event, a log or a response.
  `HttpTest` pins that the stored rows never contain the code or the token.
- **Codes are consumed by pull.** `Repository::pullOtp()` removes the row in the
  same operation that reads it. A wrong guess puts it back with one more
  attempt; at the limit it stays, locked. The accept code is checked LAST in
  `sign()`, so a request refused for another reason does not burn it.
- **Redaction.** Unknown, expired and wrong codes are all `otp_invalid` on the
  wire; the reason is in the `otp.failed` event. Only `otp_locked` differs.
- **Order of the ceremony**: view code, consent, document opened, then sign.
  `consent_required` and `not_viewed` enforce it.
- **Canonical JSON refuses floats.** Anything hashed (event data, the
  completion record, the mark) holds integers or strings. A drawn mark is
  quantised to integers on `Renderer::GRID` before it is stored or hashed.
  Placement rects (floats) live on the envelope and never in an event.
- **`page.viewed` is the only client-sourced event**, and `EventChain::append`
  refuses any other pairing. Show it labelled as browser-reported.
- **The as-sent PDF is a byte-for-byte prefix of the final one.** The final
  PDF is made only by fine-print's `Incremental::overlay`. `as_sent_bytes` is in
  the sealed record, so the prefix check needs no file of ours.
- **Field placement comes from fine-print.** Node fields by id; the final-page
  fields are `final-signature-{signer}` and `final-date-{signer}`; per-page
  marks fold into one `every-page-{kind}-{signer}` field with many placements.
- **The sealed record has a closed key set** (`Completion::KEYS`,
  `SIGNER_KEYS`); `wellFormed()` refuses anything else.
- **Legal claims.** The plan's section 10 wording only. `ClaimsTest` greps the
  README, this file, the changelog and composer.json for forbidden phrases.

## Traps

- **fine-print 0.8.0 is not published yet** (signing fields and `Incremental`
  are on fine-print main). Locally, install with the gitignored
  `composer.local.json`, which points a path repository at `../fine-print`:
  `COMPOSER=composer.local.json composer update`. CI resolves the real
  constraint and stays red until fine-print 0.8.0 is on Packagist.
- **The Laravel suite boots a hand-built kernel** (`tests/Support/TestApp.php`),
  no testbench, on in-memory SQLite with the array mailer.
- **The verify endpoint takes the PDF as a multipart file.** A PDF is binary and
  cannot travel inside a JSON body.
