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
- Needs `particle-academy/fine-print` 0.8.0 or later (signing fields and
  incremental updates).
- Test dependency: `laravel/framework`, the framework this package integrates
  with (the RULES.md carve-out), so the bridge is tested on the real branch.

### Changed

- **Docs:** `AGENTS.md` is now the guide to using this package (install, how to find it on the Fancy MCP catalog server once it is listed, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
