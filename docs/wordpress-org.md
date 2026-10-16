# WordPress.org directory review

The directory package is the same immutable allowlisted ZIP tested and attached
to the matching GitHub release. It includes a standard readme.txt, complete GPLv2
licence with an or-later grant, explicit privacy/retention and host requirements,
the project website, source and agent contribution guidance. It has no external
runtime service, tracking, premium feature gate or remote executable dependency.

Before submission, verify the exact package/source/checksum, official Plugin Check
artifact, all four real hosting results and the current directory name/eligibility.
The `dancevault` slug is a request until WordPress.org assigns it; a name search
is not trademark clearance or approval. No submission or listing is claimed by
source metadata. Follow the account's active-review restrictions and do not create
another account, withdraw another plugin or change a reviewed name to bypass them.

## Reviewable scanner context

Run all official `plugin_repo` checks; no action-level ignored errors, excluded
checks or warning suppression is configured. Keep the raw result artifact.

Specific PHPCS annotations explain operations for which the suggested generic
alternative would weaken this plugin's contract:

- The independent crypto class requires bounded binary stream reads/writes and
  rejects trailing data. WordPress Filesystem exposes whole-file contents methods,
  not resource-handle streaming. The handler must create ciphertext exclusively,
  restrict its local permissions and close streams/private tmpfile handles.
  Replacing these with whole-file buffering would make memory scale with the
  upload and remove exclusive-create/cleanup guarantees. Annotations are scoped
  to each reviewed stream call, never a whole file or security category. The
  cryptographic algorithm/container format is unchanged; only explanatory
  annotations were added to that class.
- The prepared Options-table prefix query enumerates existing compatible record
  names because the Options API has no enumeration call. Its small result uses
  WordPress object caching and is invalidated after successful upload; record
  values continue through get_option. No public enumeration route is added.
- PHP upload temporary paths must retain their exact identity for is_uploaded_file;
  error, provenance, size and filename are separately validated. Never sanitize a
  temporary path into a different file. Stored bytes are encrypted containers.
- Public recipients authenticate with the per-file password. Their read-only
  password form and download route intentionally do not require a WordPress
  user-session nonce. Administrator create/revoke routes each call the WordPress
  nonce verifier explicitly, after capability/HTTPS checks. Nonces are not an
  alternative to the recipient password. Rate limits and private/no-store headers
  remain in force, and administrators have no recipient-password bypass.

The packaged AGENTS.md is intentional user-requested contribution guidance. If
Plugin Check reports an unexpected-markdown warning, retain it as context for
human review rather than hiding or excluding the file. Reviewers may request a
different treatment; evaluate the actual finding without bypassing checks.

## Publication after approval

Submission, automated scan, human approval, assigned SVN access, SVN upload and
public listing/download availability are separate events. Do not enable any SVN
publisher before actual approval and assigned access. Use encrypted scoped GitHub
secrets if publication is later configured; never put a credential in source or
copy another repository's secret. New account/legal/security requirements retain
their specific approval gates. Directory publication does not install the plugin
on a production website.

Website: https://kieransimkin.co.uk/danceflow/

## Potential problems

- A tested GitHub release alone is not a WordPress.org submission or approval.
- A readme stable tag differing from the PHP version can break directory updates;
  package verification rejects that mismatch before hosting or publication.
- A scanner recommendation to use whole-file filesystem methods does not supply
  a safe streaming primitive. Preserve authenticated bounded streaming, include
  narrow reasons and let the reviewer inspect the unmodified security model.
