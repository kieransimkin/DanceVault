"""Real HTTP/WordPress acceptance. Only disposable compose fixtures are supported."""
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import ssl
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BACKEND, STATE = sys.argv[1], Path(sys.argv[2])
assert BACKEND in ('apache', 'nginx')
ROOT = Path(__file__).resolve().parents[2]
BASE, HTTP, BYPASS = 'https://localhost:18843', 'http://localhost:18880', 'https://localhost:18844'
COMPOSE = ['docker', 'compose', '-f', str(ROOT / 'tests/integration/compose.yml'), '--profile', BACKEND]
SERVICE = 'apache' if BACKEND == 'apache' else 'php'
CHECKS = []
# Over two authenticated chunks; no song, private input or external recipient.
PAYLOAD = (b'SYNTHETIC-DANCEVAULT-ACCEPTANCE-NOT-PRIVATE\x00' * 60000) + bytes(range(256))
PASSWORD = 'synthetic-download-only-Strong-123'


def check(condition, label):
    CHECKS.append({'name': label, 'passed': bool(condition)})
    if not condition:
        raise AssertionError(label)


def ctl(operation, identifier=None):
    cmd = COMPOSE + ['exec', '-T', '--user', 'www-data', SERVICE, 'php', '/tools/wp-cli.phar',
                     '--path=/var/www/html', 'eval-file', '/harness/control.php', operation]
    if identifier:
        cmd.append(identifier)
    result = subprocess.run(cmd, check=True, capture_output=True, text=True)
    return json.loads(result.stdout)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


class Client:
    def __init__(self):
        context = ssl.create_default_context(cafile=str(STATE / 'tls/cert.pem'))
        self.opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def request(self, path, fields=None, upload=None, headers=None):
        url = path if path.startswith(('https://', 'http://')) else BASE + path
        assert urllib.parse.urlsplit(url).hostname == 'localhost'
        headers = dict(headers or {})
        body = None
        if fields is not None:
            if upload is None:
                body = urllib.parse.urlencode(fields).encode()
                headers['Content-Type'] = 'application/x-www-form-urlencoded'
            else:
                boundary = 'DanceVaultSyntheticFormBoundary'
                chunks = []
                for key, value in fields.items():
                    chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
                filename, contents = upload
                chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="vault_file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(), contents,
                           f'\r\n--{boundary}--\r\n'.encode()]
                body = b''.join(chunks)
                headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        request = urllib.request.Request(url, data=body, headers=headers)
        try:
            response = self.opener.open(request, timeout=90)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    def login(self, username, password):
        self.request('/wp-login.php')
        status, _, _ = self.request('/wp-login.php', {'log': username, 'pwd': password,
            'testcookie': '1', 'redirect_to': BASE + '/wp-admin/tools.php?page=dancevault'})
        check(status == 302, f'{username}: real WordPress login')


class Forms(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.forms, self.current = [], None
        self.feed(body.decode('utf-8'))

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.current = {}
            self.forms.append(self.current)
        elif tag == 'input' and self.current is not None and 'name' in attrs:
            self.current[attrs['name']] = attrs.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None


def current_form(client, action, identifier=None):
    status, _, body = client.request('/wp-admin/tools.php?page=dancevault')
    check(status == 200, f'{action}: administrator page loads')
    forms = [f for f in Forms(body).forms if f.get('action') == action and (identifier is None or f.get('id') == identifier)]
    check(len(forms) == 1 and bool(forms[0].get('_wpnonce')), f'{action}: actual form nonce available')
    return forms[0]


ADMIN, ANON, EDITOR = Client(), Client(), Client()


def create(label, payload=PAYLOAD):
    before = set(ctl('state')['records'])
    fields = current_form(ADMIN, 'dancevault_create')
    fields.update(label=label, vault_password=PASSWORD, days='7')
    status, headers, _ = ADMIN.request('/wp-admin/admin-post.php', fields, ('fixture.txt', payload))
    check(status == 302 and 'tools.php?page=dancevault' in headers.get('Location', ''), f'{label}: real multipart upload accepted')
    state = ctl('state')
    created = set(state['records']) - before
    check(len(created) == 1, f'{label}: exactly one record persisted')
    identifier = created.pop()
    record = state['records'][identifier]
    check(record['size'] == len(payload) and record['sha256'] == hashlib.sha256(payload).hexdigest(), f'{label}: persisted size/hash matches original')
    check(record['autoload'] in ('no', 'off', 'auto-off'), f'{label}: record not autoloaded')
    check(state['attachments'] == INITIAL['attachments'], f'{label}: no Media Library attachment')
    return identifier


def link(identifier):
    return '/wp-admin/admin-post.php?action=dancevault_download&id=' + identifier


def no_cache(headers, label):
    check('no-store' in headers.get('Cache-Control', '') and 'private' in headers.get('Cache-Control', ''), label + ': no-store/private')
    check(headers.get('X-Content-Type-Options') == 'nosniff', label + ': nosniff')
    check(headers.get('Referrer-Policy') == 'no-referrer', label + ': no referrer')
    check('noindex' in headers.get('X-Robots-Tag', ''), label + ': no indexing')
    check("form-action 'self'" in headers.get('Content-Security-Policy', ''), label + ': same-origin form policy')


def no_temp(label):
    state = ctl('state')
    check(state['temporary_files'] == 0 and state['upload_temporary_files'] == 0, label + ': no temporary-file residue')


def main():
    global INITIAL
    INITIAL = ctl('state')
    check(INITIAL['records'] == {}, 'empty disposable vault')
    for path in ('system_temp', 'upload_temp'):
        check(INITIAL[path].startswith('/tmp/dancevault-') and not INITIAL[path].startswith('/var/www'), path + ': outside served root')
    ADMIN.login('vault-admin', 'synthetic-admin-only-Strong-123')
    EDITOR.login('vault-editor', 'synthetic-editor-only-Strong-123')
    status, _, _ = EDITOR.request('/wp-admin/tools.php?page=dancevault')
    check(status == 403, 'editor cannot open vault administration')
    form = current_form(ADMIN, 'dancevault_create')
    valid = dict(form, label='negative fixture', vault_password=PASSWORD, days='7')
    for label, client, changes in [('missing nonce', ADMIN, {'_wpnonce': ''}),
                                   ('wrong nonce', ADMIN, {'_wpnonce': 'invalid'}),
                                   ('editor capability', EDITOR, {}), ('anonymous create', ANON, {})]:
        status, _, _ = client.request('/wp-admin/admin-post.php', dict(valid, **changes), ('fixture.txt', b'negative synthetic fixture'))
        check(status in (302, 400, 403), label + ': rejected')
        check(ctl('state')['records'] == {}, label + ': no persisted record')
    auth = ctl('http-auth')
    http_fields = dict(valid, _wpnonce=auth['nonce'])
    status, _, _ = ANON.request(HTTP + '/wp-admin/admin-post.php', http_fields, ('fixture.txt', b'http synthetic fixture'), {'Cookie': auth['cookie']})
    check(status == 400 and ctl('state')['records'] == {}, 'HTTP upload rejected with real authenticated admin and valid nonce')
    for name, changes, payload in [('short password', {'vault_password': 'short'}, b'x'),
                                    ('array password', {'vault_password[]': 'invalid'}, b'x'),
                                    ('array label', {'label[]': 'invalid'}, b'x'),
                                    ('array expiry', {'days[]': 'invalid'}, b'x'),
                                    ('invalid expiry', {'days': '91'}, b'x'),
                                    ('empty upload', {}, b''), ('oversized upload', {}, b'x' * (104857600 + 1))]:
        status, _, _ = ADMIN.request('/wp-admin/admin-post.php', dict(valid, **changes), ('fixture.txt', payload))
        check(status == 400 and ctl('state')['records'] == {}, name + ': rejected without record')
    identifier = create('round trip')
    state = ctl('state')
    check(state['key_autoload'] in ('no', 'off', 'auto-off'), 'vault key not autoloaded')
    status, headers, body = ANON.request(link(identifier))
    check(status == 200 and b'type="password"' in body and PAYLOAD[:100] not in body, 'anonymous gets password form only')
    no_cache(headers, 'password form')
    status, _, _ = ANON.request(HTTP + link(identifier))
    check(status == 400, 'HTTP recipient download rejected')
    status, _, body = ADMIN.request(link(identifier), {'password': ''})
    check(status == 403 and PAYLOAD[:100] not in body, 'administrator has no password bypass')
    status, _, body = ANON.request(link(identifier), {'password[]': PASSWORD})
    check(status == 403 and PAYLOAD[:100] not in body, 'malformed password denied')
    status, _, _ = ANON.request('/wp-admin/admin-post.php?action=dancevault_download&id[]=bad')
    check(status == 404, 'malformed ID denied')
    ctl('clear-rate')
    status, headers, body = ANON.request(link(identifier), {'password': PASSWORD})
    check(status == 200 and body == PAYLOAD, 'anonymous exact full-byte authenticated round trip')
    check(headers.get('Content-Length') == str(len(PAYLOAD)) and headers.get('Accept-Ranges') == 'none', 'full download length and no range acceleration')
    check(headers.get('Content-Type') == 'application/octet-stream' and headers.get('Content-Disposition', '').startswith('attachment;'), 'download has inert attachment headers')
    no_cache(headers, 'valid download')
    no_temp('successful download')
    raw = '/wp-content/uploads/dancevault-encrypted/' + identifier + '.dvlt'
    status, _, body = ANON.request(raw)
    check(status in (403, 404) and PAYLOAD[:100] not in body, 'configured server blocks direct container URL')
    ctl('readable', identifier)
    status, _, body = ANON.request(BYPASS + raw)
    check(status == 200 and body.startswith(b'DVLT0001') and PAYLOAD[:100] not in body, 'missing server deny rule exposes ciphertext only')
    ctl('private', identifier)
    for i in range(10):
        status, _, _ = ANON.request(link(identifier), {'password': 'wrong'})
        check(status == 403, f'password failure {i + 1} counted')
    status, headers, body = ANON.request(link(identifier), {'password': PASSWORD})
    check(status == 429 and PAYLOAD[:100] not in body, 'failure limit also blocks correct password')
    no_cache(headers, 'rate limit')
    check(ctl('expire-rate')['expired'] > 0, 'rate limit has a persisted WordPress expiry')
    status, _, body = ANON.request(link(identifier), {'password': PASSWORD})
    check(status == 200 and body == PAYLOAD, 'WordPress expires the past-due transient and restores access')
    revoke = current_form(ADMIN, 'dancevault_revoke', identifier)
    status, _, _ = ADMIN.request('/wp-admin/admin-post.php', dict(revoke, _wpnonce='bad'))
    check(status == 403 and not ctl('state')['records'][identifier]['revoked'], 'revocation requires real nonce')
    status, _, _ = EDITOR.request('/wp-admin/admin-post.php', revoke)
    check(status == 403 and not ctl('state')['records'][identifier]['revoked'], 'revocation requires administrator capability')
    malformed_revoke = {key: value for key, value in revoke.items() if key != 'id'}
    status, _, _ = ADMIN.request('/wp-admin/admin-post.php', dict(malformed_revoke, **{'id[]': 'invalid'}))
    check(status == 302 and not ctl('state')['records'][identifier]['revoked'], 'malformed revocation ID changes no record')
    status, _, _ = ADMIN.request('/wp-admin/admin-post.php', revoke)
    check(status == 302 and ctl('state')['records'][identifier]['revoked'], 'valid revocation persisted')
    status, _, body = ANON.request(link(identifier), {'password': PASSWORD})
    check(status == 404 and PAYLOAD[:100] not in body, 'revoked download denied')
    for operation in ('expire', 'corrupt', 'truncate', 'mismatch'):
        other = create(operation)
        ctl(operation, other)
        status, headers, body = ANON.request(link(other), {'password': PASSWORD})
        check(status == (404 if operation == 'expire' else 503) and PAYLOAD[:100] not in body,
              operation + ': rejected before any plaintext response')
        no_cache(headers, operation)
        no_temp(operation)
    expired_session_form = current_form(ADMIN, 'dancevault_create')
    before_session_test = set(ctl('state')['records'])
    ctl('end-admin-sessions')
    expired_session_form.update(label='expired session', vault_password=PASSWORD, days='7')
    status, _, _ = ADMIN.request('/wp-admin/admin-post.php', expired_session_form, ('fixture.txt', b'synthetic session rejection'))
    check(status in (302, 400, 403) and set(ctl('state')['records']) == before_session_test,
          'revoked WordPress session cannot reuse its old upload nonce')
    for path in ('/public-control.txt', '/wp-content/uploads/public-control.txt'):
        status, _, body = ANON.request(path)
        check(status == 200 and body == b'Public media control; must remain unchanged.\n', path + ': public content unaffected')
    status, _, body = ANON.request('/?page_id=' + str(INITIAL['public_page']))
    check(status == 200 and b'DanceVault public-page control marker.' in body, 'public WordPress page unaffected')
    logs = subprocess.run(COMPOSE + ['logs', '--no-color', SERVICE], check=True, capture_output=True, text=True).stdout
    check(not any(marker in logs for marker in ('PHP Warning', 'PHP Fatal', 'Uncaught')), 'no PHP warnings/fatals from malformed or normal requests')
    no_temp('final state')


if __name__ == '__main__':
    succeeded = False
    try:
        main()
        succeeded = True
    finally:
        runtime = INITIAL if 'INITIAL' in globals() else {}
        packages = list((ROOT / 'dist').glob('dancevault-*-wordpress.zip'))
        package_hash = hashlib.sha256(packages[0].read_bytes()).hexdigest() if len(packages) == 1 else None
        report = {'backend': BACKEND, 'wordpress': runtime.get('wordpress'), 'php': runtime.get('php'),
                  'plugin_version': runtime.get('plugin_version'), 'package_sha256': package_hash,
                  'sodium': runtime.get('sodium'), 'checks': CHECKS,
                  'passed': succeeded and all(c['passed'] for c in CHECKS), 'count': len(CHECKS),
                  'fixture_sha256': hashlib.sha256(PAYLOAD).hexdigest(), 'fixture_bytes': len(PAYLOAD),
                  'tls': 'Real local TLS, localhost SAN, certificate explicitly trusted; verification enabled',
                  'scope': 'Disposable WordPress, synthetic fixtures/accounts; not a production host or independent security audit'}
        (STATE / 'evidence/acceptance.json').write_text(json.dumps(report, indent=2) + '\n')
    print(f'PASS {BACKEND}: {len(CHECKS)} real WordPress/HTTP acceptance checks')
