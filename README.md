# DanceVault

[![DanceVault logo](https://raw.githubusercontent.com/kieransimkin/DanceVault/v0.1.2/docs/branding/logo.png)](https://kieransimkin.co.uk/danceflow/)

By **[Kieran Simkin](https://kieransimkin.co.uk/)** · [DanceFlow ecosystem](https://kieransimkin.co.uk/danceflow/) · [Vector logo and usage guide](docs/branding/README.md).

Experimental WordPress encrypted, password-controlled file delivery. Live acceptance pending. https://kieransimkin.co.uk/


DanceFlow's independent WordPress component for private, password-controlled file delivery. Experimental 0.1.2; live acceptance remains required before real private assets.

## Security model

Uploads never enter the public Media Library. PHP's uploaded temporary file is read into a versioned libsodium XChaCha20-Poly1305 secretstream container; only authenticated ciphertext is written to `uploads/dancevault-encrypted`. The random vault key and password hashes are WordPress options with autoload disabled. Passwords are not stored in plaintext or included in URLs. Use HTTPS. Keep passwords out of public reports and pages.

On Apache, a directory-scoped `.htaccess` adds direct-access denial. On nginx, ask the host to add the supplied scoped rule. Encryption is the confidentiality boundary even when server rules are absent: a direct static URL must never return the original bytes. This is not a claim that nginx reads `.htaccess`. No other uploads are restricted.

Downloads use an explicit WordPress POST handler, with no admin bypass, non-cacheable responses, generic errors, expiry, revocation, password hashing and 10 failed attempts per download/IP pseudonym per 15 minutes. No passwords, raw IPs or successful-download tracking are logged. Limits are best-effort WordPress transient controls, not a substitute for edge rate limiting. Shared NATs may share the failure limit. Distributed guessing and database compromise are outside this control; use strong generated passwords.

The full container and stored size/SHA-256 are verified before any plaintext is emitted. Decryption uses a private system temporary file that is closed/deleted on request completion. The hosting administrator must keep PHP upload/temp storage outside served web roots. No plaintext fallback; sodium/HTTPS failures disable uploads. Back up the database key together with encrypted files; losing it makes recovery impossible. Database compromise plus ciphertext compromise exposes files. This plugin is not protection against a compromised WordPress administrator, PHP host or recipient forwarding.

## Usage

Install the exact released `dancevault/` WordPress ZIP. Tools → DanceVault: choose a file, label, unique 16–128-character password and expiry (1–90 days, default 7). Record source SHA-256; compare it to the displayed upload hash. Copy the password-controlled URL into private correspondence, never a raw storage URL. Revoke access from Tools → DanceVault. Revocation/expiry blocks new requests, not a download already running or a recipient's local copy. Expired/revoked ciphertext is retained for explicit administrator cleanup; no automatic destructive deletion or uninstall purge.

Limit: 100 MiB, also subject to PHP `post_max_size`/`upload_max_filesize`, proxy limits, temporary disk and request timeout. No chunk uploader, resume/Range, email sending, public attachment, external storage or account creation. Server-native acceleration is deliberately omitted to avoid authentication bypass. No existing EPK changes.

## Acceptance and development

Run `php -l dancevault.php`, `php -l src/crypto.php`, `php tests/crypto.php`, `php tests/handlers.php`. Use only synthetic fixtures. Never commit songs, passwords, keys or live account records. The handler harness stubs WordPress APIs: it is not real WordPress, Apache or nginx integration evidence.

Before real use: exercise admin nonce/capability/HTTPS rejection, missing/wrong password, failure limit, expiry, revocation, truncated/corrupt containers, full-byte download identity and no-cache headers through a running WordPress instance. Independently test Apache and nginx; local crypto tests alone are not those integrations. Check signed-out direct ciphertext URLs cannot yield plaintext, and public EPK/downloads remain unchanged. Do not upload a real private asset until these gates pass. No external security audit has yet been performed.

Build with `powershell -File scripts/package.ps1`. The allowlisted package includes only plugin PHP, src, README, licence notice and server examples; archive timestamps are fixed. Match a source commit/tag and SHA256SUMS in a new GitHub release before installation.

Validation on 8 October 2026: PHP 8.0.11 with sodium; both implementation files pass syntax checks, 60 cryptographic assertions and 9 isolated download-handler contract cases pass. Live WordPress upload/admin controls, Apache and nginx integration, response headers and hosting temporary-directory isolation remain unverified. This plugin is experimental and must not handle real private assets until those acceptance gates pass.

## CI and release publication

`.github/workflows/ci-release.yml` validates every master push, pull request and manual run. It checks sodium, lints PHP, runs synthetic contracts, builds an allowlisted ZIP and verifies every packaged file against source and checksums. Checkout is pinned and credentials are not persisted; pull requests receive read-only access. Tests never need live site credentials or private files.

A fresh `vMAJOR.MINOR.PATCH` tag runs the same checks, requires the tag to match the plugin header, and publishes an experimental GitHub release with the exact ZIP and versioned checksum manifest. It downloads published assets again and verifies SHA-256. An existing release causes publication to fail rather than overwrite assets. Only the publication job receives repository write permission. Source commit identity is recorded in release notes. Branch pushes never publish releases; manual runs validate only. WordPress installation is deliberately manual and requires exact-package approval; no site credentials or automatic live deployment are configured. Real Apache/nginx integration is not yet part of this synthetic CI suite.
