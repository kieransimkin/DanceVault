"""Validate the complete server-tested package before any release can publish."""
from pathlib import Path
import hashlib
import json
import os
import re

root = Path(__file__).resolve().parents[1]
version = re.search(r'^ \* Version: (\d+\.\d+\.\d+)$', (root / 'dancevault.php').read_text(), re.M).group(1)
package = root / f'dist/dancevault-{version}-wordpress.zip'
digest = hashlib.sha256(package.read_bytes()).hexdigest()
reports = [json.loads(p.read_text()) for p in (root / 'hosting-evidence').glob('*/acceptance.json')]
expected = {('apache', '6.7', '8.0'), ('nginx', '6.7', '8.0'), ('apache', '7.1.3', '8.3'), ('nginx', '7.1.3', '8.3')}
actual = {(r['backend'], r['wordpress'], '.'.join(r['php'].split('.')[:2])) for r in reports}
assert len(reports) == 4 and actual == expected, 'Incomplete or unexpected hosting matrix'
required = {'anonymous exact full-byte authenticated round trip',
            'configured server blocks direct container URL',
            'missing server deny rule exposes ciphertext only',
            'revoked WordPress session cannot reuse its old upload nonce',
            'HTTP upload rejected with real authenticated admin and valid nonce',
            'corrupt: rejected before any plaintext response',
            'final state: no temporary-file residue'}
for report in reports:
    assert report['passed'] is True and report['count'] == len(report['checks']) and report['count'] >= 100
    assert all(c['passed'] is True for c in report['checks'])
    assert required <= {c['name'] for c in report['checks']}
    assert report['plugin_version'] == version and report['package_sha256'] == digest, 'Different package was tested'
source = os.environ.get('GITHUB_SHA', '')
assert re.fullmatch('[a-f0-9]{40}', source), 'Exact CI source identity required'
rows = '\n'.join(f"| {r['backend']} | {r['wordpress']} | {r['php']} | {r['count']} |" for r in sorted(reports, key=lambda r: (r['wordpress'], r['backend'])))
notes = f"""DanceVault {version}

Website: https://kieransimkin.co.uk/danceflow/

Validated source: {source}.

Real isolated WordPress/TLS acceptance of the exact attached ZIP:

| Server | WordPress | PHP | Passed checks |
| --- | --- | --- | --- |
{rows}

SHA256: `{digest}`. Crypto, malformed-request, packaging and all hosting gates passed.
The 1.0 series fixes array-valued request handling and retains the existing
encryption format. Host-specific configuration acceptance is required before
private assets; this is not a production installation or independent security
audit. See the included hosting guide and changelog.
"""
(root / 'dist/release-notes.md').write_text(notes)
(root / 'dist/acceptance-summary.json').write_text(json.dumps({'version': version, 'source': source,
    'zip_sha256': digest, 'hosting': [{key: r[key] for key in ('backend', 'wordpress', 'php', 'sodium', 'count')} for r in reports]}, indent=2) + '\n')
print(f'PASS exact {version} package: four complete hosting reports, {sum(r["count"] for r in reports)} HTTP/WordPress assertions')
