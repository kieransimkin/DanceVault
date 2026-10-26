# Hosting acceptance and operating requirements

The maintained integration suite installs the exact allowlisted package into
disposable WordPress sites backed by MySQL. It uses real Apache/mod_php and
nginx/PHP-FPM, actual WordPress login cookies, nonces, roles and session storage,
multipart HTTP uploads, libsodium containers and full-byte recipient downloads.
See the workflow's individual results for the tested source revision.

## Reproduce

Build and verify the ZIP using `scripts/package.ps1` and
`scripts/verify-package.ps1`. On an isolated Linux host with Docker Compose,
PHP CLI, Python 3, curl, unzip, OpenSSL and sudo, run:

```sh
bash tests/integration/run.sh apache 8.0 6.7
bash tests/integration/run.sh nginx 8.0 6.7
bash tests/integration/run.sh apache 8.3 7.1.3
bash tests/integration/run.sh nginx 8.3 7.1.3
```

Run one combination at a time; localhost ports 18880, 18843 and 18844 must be
free. CI jobs use separate runners. WordPress core files and the pinned official
WP-CLI PHAR are checksum verified before use. The localhost certificate has an
explicit SAN and is trusted only by the test client; verification is never
disabled. No real account, song, delivery password or recipient is required.
The old compatibility floor is test-only, not a recommendation to use old PHP.

The test script stops its own compose project on exit. Ignored `.state/` data
is synthetic and remains local to the test host; it includes temporary database
configuration and a test TLS key, and must not be uploaded as an artifact.
Only the `evidence/` reports/logs are retained by CI. Failed or interrupted runs
cannot report successful acceptance.

## What the tests establish

- Real uploads require an administrator, a current WordPress nonce and HTTPS.
  Missing/wrong nonces, other roles, anonymous requests and revoked sessions
  cannot persist a new record. Invalid fields, empty and oversized files fail.
- Recipient access still requires the file password when signed in as an
  administrator. Arrays are invalid request values, without PHP warnings.
- Plaintext size/SHA-256 and all downloaded bytes match a multi-chunk binary
  fixture. No Media Library attachment is created; keys/records are not autoloaded.
- Missing/wrong passwords, the failure quota, actual WordPress transient expiry,
  explicit revocation and a past-due record expiry deny access appropriately.
- Corruption after a complete first chunk, truncation and metadata hash mismatch
  produce an error before **any plaintext response**, with temporary files cleaned.
- Password forms, downloads and error responses carry private/no-store and
  browser security headers. Downloads are inert attachments with explicit size.
- Apache's generated `.htaccess` and the shipped nginx rule deny raw containers.
  A deliberately unprotected, loopback-only listener independently demonstrates
  that exposed container bytes are authenticated ciphertext, not original data.
- Public WordPress page content and ordinary public download URLs still work.
  PHP upload and decryption temporary paths lie outside the served web root.

Forced past-due timestamps are controlled expiry fixtures; the suite does not
wait 15 minutes or claim a production timing/load test. It does not demonstrate
every third-party cache, object store, reverse proxy, concurrent upload pattern,
server variant or physical host, and is not an independent security audit.

## Before each production installation

Use the exact released ZIP and verify its checksum. Preserve the database vault
key with the encrypted files in backups and retain a rollback package. Losing
the key loses access; a compromised administrator/database/PHP host is outside
this model. Recipient forwarding and existing downloaded copies cannot be revoked.

Require HTTPS, PHP sodium, and private PHP upload/system temporary directories
outside **every** served root, alias and public storage mapping. Ensure adequate
temporary disk and PHP/proxy request limits. A plugin cannot undo exposure by a
host that puts incoming PHP upload temporary files in a public directory before
the plugin runs. Keep PHP error display off and operational logs private.

On Apache, allow the directory's `Require all denied` rule (or configure the
equivalent explicitly). On nginx, adapt this scoped rule to the site's actual
uploads location and any aliases/subdirectory:

```nginx
location ^~ /wp-content/uploads/dancevault-encrypted/ {
    return 404;
}
```

The reusable `server/nginx.conf.example` remains in the source repository for
contributors and integration tests but is deliberately excluded from the
WordPress.org distribution ZIP. The rule is defence in depth; nginx does not read
`.htaccess`. Do not expose an alternate unprotected listener, direct origin or
public storage alias. The integration listener on 18844 is an intentional
negative fixture, **not** deployment configuration.

Exclude the password/download endpoint from proxy/CDN/page caching and honour
`Cache-Control: private, no-store`. Independently repeat the small synthetic
upload, byte/hash download, wrong-password, revocation/expiry, direct-URL,
temporary-file and public-content checks on the destination host before real
private assets. A CI pass does not supply production credentials, host approval,
or permission to deploy. No production installation or upload occurs automatically.

Best-effort WordPress transient rate limits are not atomic distributed throttles.
Use long generated unique passwords and host-level abuse controls. Expired and
revoked ciphertext remains for explicit administrator retention/cleanup; there
is no automatic destructive purge or email service.

Website: https://kieransimkin.co.uk/danceflow/

## Potential problems

- A MySQL process ping is not application readiness. The compose health check
  executes a TCP query with the actual disposable database/user, after image
  initialization; WordPress installation must not race the bootstrap server.
- Apache's enabled SSL module already declares port 443. The fixture adds only
  the separate negative-test listener 444; duplicating 443 prevents startup.
- WP-CLI `eval-file` wraps source in an eval scope. The CLI-only fixture helper
  therefore omits a leading `strict_types` declaration; production files retain
  strict typing. Empty record maps are explicitly JSON objects, not lists.
- Use the official versioned WP-CLI release asset and verify its pinned checksum.
  A missing download or failed WordPress core checksum stops acceptance before
  loading the plugin. Neither is a passing application test.
