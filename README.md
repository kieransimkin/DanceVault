# CipherParcel

[![CipherParcel logo](docs/branding/logo.png)](https://kieransimkin.co.uk/danceflow/)

By **[Kieran Simkin](https://kieransimkin.co.uk/)** · [DanceFlow ecosystem](https://kieransimkin.co.uk/danceflow/) · [Vector logo and usage guide](docs/branding/README.md).

Encrypted, password-controlled file downloads with expiry and revocation. https://kieransimkin.co.uk/


DanceFlow's independent WordPress component for private, password-controlled file delivery. Version 1.0.2 adopts the distinct public name CipherParcel and `cipherparcel` package slug while preserving the established DanceVault storage, route and encrypted-container compatibility identifiers. Install the matching versioned release ZIP and validate your actual host before private assets.

## Before every new workflow

Check the latest stable official release and the actual installed/imported version before starting; do not infer currency from a directory name or a development checkout. Read the current release notes, tagged capability documentation and relevant Potential problems. Use the [DanceFlow release preflight](https://github.com/kieransimkin/DanceFlow/blob/main/docs/workflows/release-preflight.md) and [complete capability references](https://github.com/kieransimkin/DanceFlow/blob/main/docs/capabilities/index.md).

People and agents should consider the full ecosystem, use all capabilities that materially serve the task, compose supported files/APIs, and improve a demonstrated reusable gap in its canonical tool. Record why no worthwhile change is justified when that is the result. Keep independent tool identities, compatibility, source clocks, privacy, model/download costs and publication/deployment permissions intact. Store weights and model-specific caches in the owning project's ignored `models/`.

## Find your route

[Usage](#usage) · [Security model](#security-model) · [Acceptance and development](#acceptance-and-development) · [CI and release publication](#ci-and-release-publication) · [Agents and contributors](#agents-and-contributors)

## Usage

Install the exact released `cipherparcel/` WordPress ZIP. Tools → CipherParcel: choose a file, label, unique 16–128-character password and expiry (1–90 days, default 7). Record source SHA-256; compare it to the displayed upload hash. Copy the password-controlled URL into private correspondence, never a raw storage URL. Revoke access from Tools → CipherParcel. Revocation/expiry blocks new requests, not a download already running or a recipient's local copy. Expired/revoked ciphertext is retained for explicit administrator cleanup; no automatic destructive deletion or uninstall purge.

Limit: 100 MiB, also subject to PHP `post_max_size`/`upload_max_filesize`, proxy limits, temporary disk and request timeout. No chunk uploader, resume/Range, email sending, public attachment, external storage or account creation. Server-native acceleration is deliberately omitted to avoid authentication bypass. No existing EPK changes.

## Security model

Uploads never enter the public Media Library. PHP's uploaded temporary file is read into a versioned libsodium XChaCha20-Poly1305 secretstream container; only authenticated ciphertext is written to `uploads/dancevault-encrypted`. The random vault key and password hashes are WordPress options with autoload disabled. Passwords are not stored in plaintext or included in URLs. Use HTTPS. Keep passwords out of public reports and pages.

On Apache, a directory-scoped `.htaccess` adds direct-access denial. On nginx, ask the host to add the supplied scoped rule. Encryption is the confidentiality boundary even when server rules are absent: a direct static URL must never return the original bytes. This is not a claim that nginx reads `.htaccess`. No other uploads are restricted.

Downloads use an explicit WordPress POST handler, with no admin bypass, non-cacheable responses, generic errors, expiry, revocation, password hashing and 10 failed attempts per download/IP pseudonym per 15 minutes. No passwords, raw IPs or successful-download tracking are logged. Limits are best-effort WordPress transient controls, not a substitute for edge rate limiting. Shared NATs may share the failure limit. Distributed guessing and database compromise are outside this control; use strong generated passwords.

Malformed array-valued labels, passwords and IDs are treated as invalid fields,
without PHP array-to-string warnings. A download submission such as
`password[]=invalid` is denied; it cannot supply a recipient password or bypass
authorization. Valid scalar form values keep the same behavior.
Download error handling also preserves `Cache-Control: private, no-store` when
WordPress sends its own error-page headers, including on WordPress 6.7. This
request-scoped protection does not change the cache policy of public pages.

The full container and stored size/SHA-256 are verified before any plaintext is emitted. Decryption uses a private system temporary file that is closed/deleted on request completion. The hosting administrator must keep PHP upload/temp storage outside served web roots. No plaintext fallback; sodium/HTTPS failures disable uploads. Back up the database key together with encrypted files; losing it makes recovery impossible. Database compromise plus ciphertext compromise exposes files. This plugin is not protection against a compromised WordPress administrator, PHP host or recipient forwarding.

## Acceptance and development

The WordPress.org [readme.txt](readme.txt) describes installation, host requirements,
privacy, retained data and the complete included functionality. Interface strings use
the `cipherparcel` text domain and escaped output; WordPress can supply language packs.
The administrator record-name list uses a short object-cache entry, invalidated by
successful uploads. Actual records still use the Options API and are read fresh.
Directory submission, human approval and a public listing are separate states; a
GitHub release does not establish any of them. See [directory review](docs/wordpress-org.md).

See [hosting acceptance and operating requirements](docs/hosting-acceptance.md)
for the complete test contract, reproducible matrix and destination-host checks.

The maintained HTTP acceptance suite runs the exact built WordPress ZIP behind
both Apache and nginx/PHP-FPM, at WordPress 6.7/PHP 8.0 and WordPress 7.1.3/PHP 8.3.
The former verifies the declared compatibility floor, not a recommendation to
deploy obsolete PHP. Run
`bash tests/integration/run.sh apache` and `bash tests/integration/run.sh nginx`
on Linux with Docker Compose, PHP CLI, Python 3, OpenSSL, curl, unzip and sudo,
after building/verifying `dist/`. It creates disposable databases, synthetic
accounts/files and a localhost TLS certificate explicitly trusted by the client;
TLS verification remains enabled. Ports 18880, 18843 and 18844 must be free.
It stops its own containers on exit and stores reports under the ignored
`tests/integration/.state/`. This is isolated host integration, not an independent
security audit or permission to install on a production site. The intentionally
unprotected port 18844 exists only to verify the ciphertext boundary; do not use
the test configuration on a real host. Both server checks are release gates.
Optional second/third arguments select PHP and WordPress, for example
`bash tests/integration/run.sh apache 8.0 6.7`. Reports record the actual runtime
versions and container image identities; an interrupted run cannot report a pass.

Run `php -l dancevault.php`, `php -l src/crypto.php`, `php tests/crypto.php`, `php tests/handlers.php`, `php tests/interface.php`, and `php tests/identity.php`. Before build or release work, run `pwsh ./scripts/test-temp-access.ps1 -Parent ./dist`; it verifies create, read, atomic rename and cleanup with project-local temporary data. Use only synthetic fixtures. Never commit songs, passwords, keys or live account records. The handler/interface harnesses stub WordPress APIs: they are not real WordPress, Apache or nginx integration evidence.

Before real use: exercise admin nonce/capability/HTTPS rejection, missing/wrong password, failure limit, expiry, revocation, truncated/corrupt containers, full-byte download identity and no-cache headers through a running WordPress instance. Independently test Apache and nginx; local crypto tests alone are not those integrations. Check signed-out direct ciphertext URLs cannot yield plaintext, and public EPK/downloads remain unchanged. Do not upload a real private asset until these gates pass. No external security audit has yet been performed.

Build with `powershell -File scripts/package.ps1`. The 15-file allowlisted package includes plugin PHP, directory/readme and contribution guidance, complete licence text, changelog, hosting guide, server example and original branding; archive timestamps are fixed. Tests, build tools, credentials and CI state are excluded. Match a source commit/tag and SHA256SUMS in a new GitHub release before installation.

Historical baseline on 8 October 2026: PHP 8.0.11 with sodium; syntax checks, 60 cryptographic assertions and nine stubbed handler cases passed, while real hosting acceptance was pending.

Validation on 16 October 2026: all four real TLS/HTTP hosting combinations passed
142 checks each (568 total): Apache and nginx/PHP-FPM on WordPress 6.7/PHP
8.0.30 and WordPress 7.1.3/PHP 8.3.35, with sodium 1.0.18. The 60 crypto and
11 handler cases pass too. CI records the actual versions, image identities,
exact source and tested ZIP hash; the release-evidence job verifies all four
complete reports. These are isolated synthetic WordPress integrations. Repeat
destination-host acceptance before real private files; no external security
audit or production installation is claimed.

## CI and release publication

`.github/workflows/ci-release.yml` validates every master push, pull request and manual run. It checks sodium, lints PHP, runs synthetic contracts, builds an allowlisted ZIP and verifies every packaged file against source and checksums. Official WordPress Plugin Check scans that exact extracted package using the plugin-repository category. Its results are retained, including contextual warnings. Checkout is pinned and credentials are not persisted; pull requests receive read-only access. Tests never need live site credentials or private files.

A fresh `vMAJOR.MINOR.PATCH` tag runs the same checks and must match the plugin
header and directory readme's stable tag. Official Plugin Check, all four hosting jobs and the complete-report/package-hash gate must
pass before publication. The publisher downloads that verified bundle and
publishes its exact tested ZIP, checksum manifest and acceptance summary.
Versions below 1.0 remain prereleases; the 1.0 series publishes stable releases.
Published assets are downloaded again and SHA-256 checked. Existing releases
fail closed rather than being overwritten. Only the publisher receives write
permission, and source identity appears in the release evidence.

Branch pushes never publish releases; manual runs validate only. WordPress
installation remains manual and requires exact-package approval. No site
credentials or automatic production deployment are configured. A source commit
or passing CI artifact alone is not a published GitHub release.

## Agents and contributors

Agents using CipherParcel should inspect its available capabilities, use them fully where they fit the task, and look for useful improvements wherever possible. When a required feature is missing, or a reusable reliability, usability, performance or documentation gap is found, improve this tool rather than maintaining a separate workaround. If no worthwhile change is justified, explain why.

Submit every improvement as a pull request to the [CipherParcel source repository](https://github.com/kieransimkin/DanceVault/pulls), with relevant tests, documentation and a reproducible example. Follow [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md) for the contribution and validation workflow.

Project website: [https://kieransimkin.co.uk/danceflow/](https://kieransimkin.co.uk/danceflow/).
