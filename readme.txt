=== CipherParcel ===
Contributors: kieransimkin0
Tags: encrypted downloads, password protection, private files, file sharing, download expiry
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Encrypted, password-controlled file downloads with expiry and revocation. https://kieransimkin.co.uk/

== Description ==

CipherParcel lets WordPress administrators share a file through a password-controlled download link, without creating a public Media Library attachment. Files are encrypted with PHP libsodium before storage. Recipients enter a separate password; passwords are never included in download URLs.

* Encrypted file storage using authenticated XChaCha20-Poly1305 secretstream containers.
* Administrator-only uploads and revocation, protected by WordPress capabilities and nonces.
* Recipient passwords, expiry from 1 to 90 days, and immediate revocation of future requests.
* Complete integrity verification before download bytes are sent, with SHA-256 shown to administrators.
* Private, non-cacheable download responses and best-effort failed-password throttling.
* Scoped Apache access protection and a supplied nginx configuration example.

The complete functionality is included. No paid upgrade, external account, telemetry, email delivery or external service is required. The plugin does not fetch executable code or remote assets.

PHP sodium, HTTPS, private PHP temporary directories and suitable host configuration are required. The maximum file size is 100 MiB, subject to lower host limits. Validate the destination host with a synthetic file before using private assets. The included hosting guide explains the security boundary and reproducible WordPress/Apache/nginx acceptance checks.

[Project website and DanceFlow ecosystem](https://kieransimkin.co.uk/danceflow/) | [Source and development](https://github.com/kieransimkin/DanceVault)

= Privacy and data retention =

Encrypted files stay on your WordPress host. The database stores a random vault key, password hashes and file records including label, filename, byte size, SHA-256, expiry and revocation state. These options are not autoloaded. Never put personal information in a label unless you intend to retain it on that host.

Failed password attempts use a short-lived keyed pseudonym derived from the download ID and request IP address. The plugin stores the pseudonym and failure count for up to 15 minutes; it does not store raw IP addresses, plaintext passwords or successful-download analytics. Your host or other plugins may log requests independently. No data is sent to the developer or another service.

Expired and revoked ciphertext and records remain for explicit administrator retention and cleanup. Deactivation and uninstall do not silently erase files or keys. Back up the database vault key with the encrypted files: losing it prevents recovery. Delete retained ciphertext and matching database records only through a reviewed administrator maintenance process. There is no automatic destructive purge.

= Agents and contributions =

Agents should inspect all relevant capabilities, use the tool where it fits, and improve reusable gaps where useful. Submit improvements as [pull requests to the CipherParcel source repository](https://github.com/kieransimkin/DanceVault/pulls), with relevant verification, updated documentation and a reproducible example. Read the included AGENTS.md and CONTRIBUTING.md. Do not place private files, passwords, keys or account data in source, issues or public reports.

== Installation ==

1. Install the matching CipherParcel release ZIP and activate CipherParcel. This does not upload any files automatically.
2. Require HTTPS and PHP sodium. Keep PHP upload and system temporary directories outside every served root. Keep PHP error display off and ensure enough private temporary disk space.
3. On Apache, permit the generated directory-scoped Require all denied rule or configure an equivalent. On nginx, adapt the rule in docs/hosting-acceptance.md to the actual uploads path, aliases and origins. Exclude the password/download endpoint from page, proxy and CDN caching.
4. Follow docs/hosting-acceptance.md using a synthetic file: verify byte/hash identity, wrong-password denial, expiry/revocation, direct-URL protection, temporary-file cleanup and unaffected public files.
5. Open Tools -> CipherParcel, choose a file, label, unique 16-128-character password and expiry. Share the recipient URL and password privately, preferably using separate channels.

== Frequently Asked Questions ==

= Can an administrator download without the file password? =

No. The recipient route always requires the correct file password. This is not protection against a compromised administrator, database or PHP host, which can access the vault key and files.

= Does expiry or revocation delete a recipient's existing copy? =

No. It denies future requests; it cannot stop a download already running, recover downloaded copies or prevent recipients forwarding them.

= Does nginx use the generated .htaccess file? =

No. Configure the scoped nginx rule documented in the packaged hosting guide and test every served alias/origin. Authenticated encryption protects container contents, while direct-access denial is additional protection.

= Are there limits on uploads or password attempts? =

Uploads are limited to 100 MiB, with no chunk/resume/Range support. Host limits may be lower. Ten failed password attempts per download/IP pseudonym deny access for up to 15 minutes. WordPress transients are best-effort limits, not atomic distributed or edge abuse controls; shared networks can share the limit.

= Has this received an independent security audit? =

No. The maintained suite verifies real isolated WordPress TLS/HTTP behaviour on Apache and nginx at the declared compatibility floor and current tested versions. This does not replace destination-host acceptance or an independent security audit.

== Changelog ==

= 1.0.3 =
* Use the verified WordPress.org author profile for the plugin Author URI.
* Keep host-specific server configuration examples in the source repository and out of the WordPress.org distribution ZIP.
* Document the required nginx rule in the packaged hosting guide and reject source-only server files during package verification.

= 1.0.2 =
* Adopt the distinct public name CipherParcel and requested directory slug cipherparcel after a fresh WordPress.org, GitHub and exact-web name check.
* Preserve the existing encrypted container format, database keys, record names, routes and stored files for DanceVault compatibility.
* Add a reusable project-local temporary-directory preflight to the build and release checks.

= 1.0.1 =
* Add directory metadata, translated interface strings, privacy/retention guidance, complete licence text and an official Plugin Check release gate.
* Preserve the encrypted container format, existing keys/records and tested authentication, expiry and revocation behaviour.

= 1.0.0 =
* Add real WordPress/Apache/nginx hosting acceptance, scalar request validation and private error-response cache headers.

== Upgrade Notice ==

= 1.0.3 =
Directory-package correction only. Runtime behaviour and existing DanceVault encrypted records remain unchanged.

= 1.0.2 =
Public identity changes to CipherParcel. Existing DanceVault encrypted files, database keys, record names and administrator routes remain compatible.

= 1.0.1 =
Directory preparation and interface translation support. Existing encrypted files and database keys remain compatible. Repeat destination-host acceptance before private-file use.
