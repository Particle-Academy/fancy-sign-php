# Changelog

All notable changes to `particle-academy/fancy-sign` are recorded here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**Pre-1.0: breaking changes may land in a MINOR release.** Every one is marked
BREAKING and says what a consumer must do.

## [Unreleased]

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
