#!/usr/bin/env python3
"""Isolated PHP 8.2 HTTP regression tests; never use the deployed database.
Run: python3 tests/security_smoke.py --php /path/to/php
"""
import argparse
import base64
import hashlib
import concurrent.futures
import zlib
import hmac
import http.cookiejar
import http.server
import threading
import sys
import json
import os
from pathlib import Path
import re
import signal
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
args = parser.parse_args()
repo = Path(__file__).resolve().parents[1]
checks = []

def check(name, condition):
    if not condition:
        raise AssertionError(name)
    checks.append(name)

with tempfile.TemporaryDirectory(prefix='imgurl-regression-') as directory:
    root = Path(directory)
    app = root / 'app'
    app.mkdir()
    for name in ['application', 'system', 'static']:
        shutil.copytree(repo / name, app / name, ignore=shutil.ignore_patterns('._*', '__pycache__', 'log-*.php'))
    shutil.copyfile(repo / 'index.php', app / 'index.php')
    (app / 'data').mkdir()
    for name in ['imgurl-simple.db3', 'version.txt']:
        shutil.copyfile(repo / 'data' / name, app / 'data' / name)
    for name in ['json', 'pages']:
        shutil.copytree(repo / 'data' / name, app / 'data' / name)
    (app / 'imgs').mkdir()
    (app / 'application/controllers/Audit_profiler.php').write_text("<?php class Audit_profiler extends CI_Controller { public function index() { $this->output->enable_profiler(true); $this->output->set_output('profiler fixture'); } }")
    (app / 'application/controllers/Audit_compress.php').write_text("""<?php
require_once APPPATH.'controllers/Compress.php';
class Audit_compress extends Compress {
    protected function api_key(): string { return 'fixture'; }
    protected function compressed_content(string $path, string $key): string {
        $scenario = $this->input->post('scenario');
        if ($scenario === 'failure') { throw new RuntimeException('fixture service failure'); }
        return $scenario === 'invalid' ? 'not an image' : file_get_contents($path).'fixture-compression';
    }
}
""")
    (app / 'application/controllers/Audit_stale_delete.php').write_text("""<?php
class Audit_stale_delete extends CI_Controller {
    public function index() {
        $this->load->database();
        $this->load->model('query');
        $this->load->model('delete');
        $image = $this->query->onepic($this->input->get('imgid'));
        $this->db->where('imgid', $image->imgid)->update('images', ['token' => str_repeat('f', 16)]);
        $removed = $this->delete->remove_image($image);
        $this->db->where('imgid', $image->imgid)->update('images', ['token' => $image->token]);
        $this->output->set_content_type('application/json')->set_output(json_encode(['removed' => $removed]));
    }
}
""")
    router = root / 'router.php'
    router.write_text("<?php $_SERVER['CI_ENV']='development'; $_SERVER['SCRIPT_NAME']='/index.php'; chdir(__DIR__.'/app'); require 'index.php';")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    logpath = root / 'server.log'
    log = logpath.open('w')
    server = subprocess.Popen([args.php, '-d', 'memory_limit=256M', '-d', 'post_max_size=8M', '-d', 'upload_max_filesize=8M', '-S', f'127.0.0.1:{port}', '-t', str(app), str(router)], stdout=log, stderr=log, env={**os.environ, 'PHP_CLI_SERVER_WORKERS': '2', 'IMGURL_TINIFY_KEY': '', 'IMGURL_COOKIE_SECURE': '0'}, start_new_session=True)
    cookies = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
    anonymous = urllib.request.build_opener()

    def cookie(name):
        return next((item.value for item in cookies if item.name == name), '')

    def request(path, data=None, csrf=True, headers=None, anon=False, method=None):
        request_headers = {'User-Agent': 'ImgURL security regression'}
        request_headers.update(headers or {})
        if isinstance(data, dict):
            data = dict(data)
            if csrf and cookie('csrf_cookie_name'):
                data['csrf_test_name'] = cookie('csrf_cookie_name')
            data = urllib.parse.urlencode(data, doseq=True).encode()
        req = urllib.request.Request(origin + path, data=data, headers=request_headers, method=method)
        try:
            response = (anonymous if anon else client).open(req, timeout=15)
        except urllib.error.HTTPError as exc:
            response = exc
        body = response.read()
        text = body.decode('utf-8', errors='replace')
        if 'A PHP Error was encountered' in text or 'An uncaught Exception was encountered' in text:
            print(text[:10000])
        check('no PHP diagnostics: ' + path, not any(marker in text for marker in ['A PHP Error was encountered', 'An uncaught Exception was encountered', 'Fatal error:', 'Deprecated:']))
        return response.status, body, dict(response.headers)

    def post_image(image, name='audit.png', output='json', anon=False):
        boundary = 'imgurl-test-boundary'
        body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n').encode() + image + f'\r\n--{boundary}--\r\n'.encode()
        return request('/upload/localhost/' + output, body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary}, anon=anon)

    def login(password='audit-password', username='audit'):
        # Sign a known-answer challenge with the isolated fixture's own secret.
        request('/user/captcha')
        secret = (app / 'data/.login_security/.secret').read_text().strip().encode()
        statepath = app / 'data/.login_security' / ('attempt-' + hashlib.sha256(b'127.0.0.1').hexdigest() + '.json')
        state = json.loads(statepath.read_text()) if statepath.exists() else {'version': 0}
        payload = '.'.join([str(int(time.time()) + 120), 'a' * 32, hmac.new(secret, b'127.0.0.1', hashlib.sha256).hexdigest()[:24], str(state['version'])])
        token = payload + '.' + hmac.new(secret, ('ABCDE|' + payload).encode(), hashlib.sha256).hexdigest()
        return request('/user/verify', {'user': username, 'password': password, 'captcha': 'ABCDE'}, headers={'Cookie': '; '.join(f'{c.name}={c.value}' for c in cookies if c.name != 'imgurl_captcha') + '; imgurl_captcha=' + token})

    try:
        for attempt in range(100):
            try:
                status, body, _ = request('/install/?setup=1')
                break
            except OSError:
                time.sleep(.05)
        check('installer renders', status == 200)
        check('installer offers next step', b'/install/?setup=2' in body)
        status, body, _ = request('/api/v1/health', anon=True)
        check('API health before install is degraded JSON without creating database', status == 503 and json.loads(body)['data']['status'] == 'degraded' and not (app / 'data/imgurl.db3').exists())
        status, _, _ = request('/install/?setup=3', {'domain': origin, 'user': 'audit', 'pass1': 'audit-password', 'pass2': 'audit-password'}, csrf=False)
        check('installer rejects missing CSRF', status == 403)
        status, body, _ = request('/install/?setup=3', {'domain': origin, 'user': 'audit', 'pass1': 'audit-password', 'pass2': 'audit-password'})
        check('fresh installation succeeds', status == 200 and (app / 'data/install.lock').exists())
        db = sqlite3.connect(app / 'data/imgurl.db3')
        def userinfo():
            return json.loads(db.execute('SELECT "values" FROM img_options WHERE name=?', ('userinfo',)).fetchone()[0])
        check('new installations hash passwords', userinfo()['password'].startswith('$2y$'))
        oldhash = hashlib.md5(b'audit-passwordimgurl').hexdigest()
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({'username': 'audit', 'password': oldhash}), 'userinfo'))
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({'max_size': 1, 'limit': 100}), 'uplimit'))
        db.commit()
        status, _, _ = request('/admin', anon=True)
        check('anonymous admin access denied', status == 401)
        legacy = hashlib.md5(('audit' + oldhash + 'ImgURL security regression').encode()).hexdigest()
        status, _, _ = request('/admin', headers={'Cookie': 'user=audit; token=' + legacy}, anon=True)
        check('legacy deterministic cookie rejected', status == 401)
        request('/user/login')
        status, body, headers = login()
        check('legacy password login succeeds', status == 200 and json.loads(body)['code'] == 200)
        check('legacy password upgraded', userinfo()['password'].startswith('$2y$'))
        check('session cookie set', bool(cookie('imgurl_session')))
        check('session cookie HttpOnly and SameSite', any(c.name == 'imgurl_session' and c.has_nonstandard_attr('HttpOnly') and c.get_nonstandard_attr('SameSite') == 'Lax' for c in cookies))
        for path in ['/', '/user/login', '/home/multiple', '/found', '/api/found', '/page/use', '/admin', '/manage/images', '/setting/site', '/setting/uplimit', '/setting/security', '/audit_profiler']:
            status, _, _ = request(path)
            check('page renders: ' + path, status == 200)
        status, _, _ = request('/img/0000000000000000')
        check('missing image is 404', status == 404)
        status, _, _ = request('/set/uplimit', {'max_size': '1', 'limit': '100'}, csrf=False)
        check('admin mutation without CSRF rejected', status == 403)
        status, _, _ = request('/set/uplimit')
        check('admin mutation GET rejected', status == 405)
        status, _, _ = request('/set/uplimit', {'max_size': '1', 'limit': '100'})
        check('upload settings POST works', status == 200)
        status, _, _ = request('/set/site', {'logo': '/static/images/logo.png', 'title': 'Audit site', 'keywords': 'audit', 'description': 'fixture', 'analytics': ''})
        check('site settings POST works', status == 200)
        status, _, _ = request('/set/security', {'captcha_length': '5', 'captcha_ttl': '300', 'max_attempts': '5', 'attempt_window': '900', 'lockout': '900'})
        check('security settings POST works', status == 200)
        image = subprocess.check_output([args.php, '-r', '$im=imagecreatetruecolor(640,480); imagefilledrectangle($im,0,0,639,479,12345); imagepng($im);'])
        status, body, _ = post_image(image)
        uploaded = json.loads(body)
        check('PNG upload succeeds', status == 200 and uploaded['code'] == 200)
        # Site branding uses registered uploads, real dimensions, and independent copies.
        base_site = {'title': 'Audit site', 'keywords': 'audit', 'description': 'fixture', 'analytics': ''}
        def site_settings():
            return json.loads(db.execute('SELECT "values" FROM img_options WHERE name=?', ('site_setting',)).fetchone()[0])
        def fixture_image(width, height, name):
            content = subprocess.check_output([args.php, '-r', f'$im=imagecreatetruecolor({width},{height}); imagepng($im);'])
            status, body, _ = post_image(content, name)
            check('branding fixture upload: ' + name, status == 200 and json.loads(body)['code'] == 200)
            return json.loads(body)
        logo = fixture_image(360, 120, 'brand-logo.png')
        background = fixture_image(1280, 720, 'brand-background.png')
        icon = fixture_image(128, 128, 'brand-icon.png')
        status, _, _ = request('/setting/images?field=logo', anon=True)
        check('branding picker requires login', status == 401)
        status, _, _ = request('/set/site', {**base_site, 'logo_image': logo['imgid']}, anon=True)
        check('branding save requires login', status in [401, 403])
        status, _, _ = request('/set/site', {**base_site, 'logo_image': logo['imgid']}, csrf=False)
        check('branding save requires CSRF', status == 403)
        for query in ['field=invalid', 'field[]=logo', 'field=logo&page=0', 'field=logo&page[]=1', 'field=logo&search[]=bad']:
            status, _, _ = request('/setting/images?' + query)
            check('branding malformed picker query rejected: ' + query, status == 400)
        status, body, headers = request('/setting/images?field=favicon&search=brand-')
        items = json.loads(body)['items']
        check('branding picker searches filenames', status == 200 and len(items) == 3)
        check('branding picker reports size suitability', next(i for i in items if i['imgid'] == icon['imgid'])['eligible'] and not next(i for i in items if i['imgid'] == logo['imgid'])['eligible'])
        check('branding picker responses not cached', headers.get('Cache-Control') == 'no-store')
        status, body, _ = request('/setting/images?field=logo&search=' + logo['imgid'])
        check('branding picker searches IDs', len(json.loads(body)['items']) == 1)
        old_site = site_settings()
        for bad in ['0000000000000000', '/imgs/test.png', 'https://example.com/logo.png']:
            status, _, _ = request('/set/site', {**base_site, 'logo_image': bad})
            check('branding unregistered selection rejected: ' + bad, status == 400 and site_settings() == old_site)
        status, _, _ = request('/set/site', {**base_site, 'logo_image[]': logo['imgid']})
        check('branding array selection rejected', status == 400)
        icon_source = app / urllib.parse.urlparse(icon['url']).path.lstrip('/')
        icon_bytes = icon_source.read_bytes()
        icon_source.write_bytes(icon_bytes[:33])
        status, _, _ = request('/set/site', {**base_site, 'favicon_image': icon['imgid']})
        check('branding corrupted image rejected', status == 400 and site_settings() == old_site)
        icon_source.write_bytes(icon_bytes + b'X' * (10 * 1024 * 1024))
        status, _, _ = request('/set/site', {**base_site, 'favicon_image': icon['imgid']})
        check('branding 10MB file limit enforced', status == 400 and site_settings() == old_site)
        icon_source.write_bytes(icon_bytes)
        db.execute('UPDATE img_images SET path=? WHERE imgid=?', ('/imgs/../../sentinel.png', icon['imgid']))
        db.commit()
        status, _, _ = request('/set/site', {**base_site, 'favicon_image': icon['imgid']})
        check('branding cannot select traversal path', status == 400)
        db.execute('UPDATE img_images SET path=? WHERE imgid=?', (urllib.parse.urlparse(icon['url']).path, icon['imgid']))
        db.commit()
        status, _, _ = request('/set/site', {**base_site, 'background_image': logo['imgid']})
        check('branding undersized background rejected', status == 400 and site_settings() == old_site)
        # Metadata cannot be used to bypass validation of the actual file.
        db.execute('UPDATE img_imginfo SET width=256,height=256 WHERE imgid=?', (logo['imgid'],))
        db.commit()
        status, _, _ = request('/set/site', {**base_site, 'favicon_image': logo['imgid']})
        check('branding checks actual dimensions instead of database metadata', status == 400)
        db.execute('UPDATE img_imginfo SET width=360,height=120 WHERE imgid=?', (logo['imgid'],))
        db.execute("UPDATE img_images SET level='adult' WHERE imgid=?", (icon['imgid'],))
        db.commit()
        status, _, _ = request('/set/site', {**base_site, 'favicon_image': icon['imgid']})
        check('branding restricted image rejected', status == 400)
        db.execute("UPDATE img_images SET level='everyone' WHERE imgid=?", (icon['imgid'],))
        db.commit()
        status, _, _ = request('/set/site', {**base_site, 'background_image': background['imgid'], 'logo_image': logo['imgid'], 'favicon_image': icon['imgid']})
        branded = site_settings()
        check('branding all three assets saved together', status == 200 and all(branded[k].startswith('/imgs/branding/') for k in ['background', 'logo', 'favicon']))
        copies = [app / branded[k].lstrip('/') for k in ['background', 'logo', 'favicon']]
        check('branding independent copies created', all(p.is_file() for p in copies))
        icon_size = subprocess.check_output([args.php, '-r', 'echo json_encode(getimagesize($argv[1]));', str(copies[2])])
        check('branding favicon converted to 256px PNG', json.loads(icon_size)['0'] == 256 and json.loads(icon_size)['1'] == 256 and json.loads(icon_size)['mime'] == 'image/png')
        status, body, _ = request('/')
        check('branding homepage renders background logo and favicon', status == 200 and all(branded[k].encode() in body for k in ['background', 'logo', 'favicon']) and b'custom-home-background' in body)
        status, body, _ = request('/home/multiple')
        check('branding background limited to homepage', branded['background'].encode() not in body and branded['logo'].encode() in body)
        status, body, _ = request('/admin')
        check('branding favicon also appears in admin', branded['favicon'].encode() in body)
        status, _, _ = request('/set/site', {**base_site, 'title': 'Keep branding'})
        check('branding preserved when editing other settings', status == 200 and all(site_settings()[k] == branded[k] for k in ['background', 'logo', 'favicon']))
        # A later invalid selection rolls back copies and all other settings.
        before_files = set((app / 'imgs/branding').iterdir())
        status, _, _ = request('/set/site', {**base_site, 'background_image': background['imgid'], 'logo_image': icon['imgid'], 'favicon_image': logo['imgid']})
        check('branding validation failure leaves settings and files unchanged', status == 400 and site_settings()['title'] == 'Keep branding' and set((app / 'imgs/branding').iterdir()) == before_files)
        db.execute("CREATE TRIGGER audit_fail_branding BEFORE UPDATE ON img_options WHEN NEW.name='site_setting' BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        status, _, _ = request('/set/site', {**base_site, 'logo_image': icon['imgid']})
        check('branding database failure cleans new copy and preserves old assets', status == 500 and set((app / 'imgs/branding').iterdir()) == before_files and all(p.is_file() for p in copies))
        db.execute('DROP TRIGGER audit_fail_branding')
        db.commit()
        _, body, _ = request('/set/del_img', {'imgid': logo['imgid']})
        check('branding survives deletion of source image', json.loads(body)['code'] == 200 and copies[1].is_file())
        status, _, _ = request('/set/site', {**base_site, 'logo_image': logo['imgid']})
        check('branding deleted source cannot be selected', status == 400)
        for number in range(26):
            _, body, _ = post_image(icon_bytes + str(number).encode(), 'brand-page-' + str(number) + '.png')
            check('branding pagination fixture uploaded', json.loads(body)['code'] == 200)
        status, body, _ = request('/setting/images?field=favicon&search=brand-page-&page=1')
        first_page = json.loads(body)
        status, body, _ = request('/setting/images?field=favicon&search=brand-page-&page=2')
        second_page = json.loads(body)
        check('branding picker pagination is bounded and disjoint', len(first_page['items']) == 24 and first_page['more'] and len(second_page['items']) == 2 and not second_page['more'] and not ({i['imgid'] for i in first_page['items']} & {i['imgid'] for i in second_page['items']}))
        # Shared snapshots survive changing another field, and are removed when no longer used.
        status, _, _ = request('/set/site', {**base_site, 'logo_image': background['imgid']})
        check('branding replacement removes unused previous logo', status == 200 and not copies[1].exists() and copies[0].is_file())
        status, _, _ = request('/set/site', {**base_site, 'logo_image': 'default'})
        check('branding shared copy retained for background', status == 200 and copies[0].is_file())
        status, _, _ = request('/set/site', {**base_site, 'background_image': 'default', 'favicon_image': 'default'})
        check('branding reset restores defaults and cleans copies', status == 200 and site_settings()['background'] == '' and site_settings()['favicon'] == '' and not copies[0].exists() and not copies[2].exists())
        status, body, _ = request('/')
        check('branding default favicon and background restored', b'/favicon.ico' in body and b'custom-home-background' not in body)
        # Versioned API, backed by scoped opaque tokens and the same branding save service.
        def api(path, token=None, data=None, method='GET', headers=None, as_admin=False):
            h = dict(headers or {})
            if token is not None:
                h['Authorization'] = 'Bearer ' + token
            if data is not None:
                if not isinstance(data, bytes):
                    data = json.dumps(data).encode()
                h.setdefault('Content-Type', 'application/json')
            status, body, response_headers = request('/api/v1/' + path, data, headers=h, anon=not as_admin, csrf=False, method=method)
            parsed = json.loads(body)
            check('API JSON envelope: ' + path, isinstance(parsed.get('ok'), bool) and 'application/json' in response_headers.get('Content-Type', '') and response_headers.get('Cache-Control') == 'no-store')
            if status >= 400:
                check('API structured error: ' + path, parsed['ok'] is False and isinstance(parsed['error']['code'], str))
            return status, parsed, response_headers

        def token_records():
            row = db.execute('SELECT "values" FROM img_options WHERE name=?', ('api_tokens',)).fetchone()
            return json.loads(row[0]) if row else {}

        def store_tokens(records):
            db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps(records), 'api_tokens'))
            db.commit()

        def create_token(name, scopes):
            status, body, _ = request('/tokens/create', {'name': name, 'scopes[]': scopes, 'days': '30'})
            found = re.search(rb'id="api-created-token"[^>]*value="(imgurl_[a-f0-9]{64})"', body)
            check('admin creates API token: ' + name, status == 200 and found is not None)
            return found[1].decode()

        status, body, _ = api('health')
        check('public health exposes only aggregate state', status == 200 and body['data'] == {'status': 'ok', 'api_version': 'v1'})
        status, _, _ = request('/setting/tokens', anon=True)
        check('token management requires login', status == 401)
        status, _, _ = request('/tokens/create', {'name': 'unauthorized', 'scopes[]': ['images:read'], 'days': '30'}, anon=True)
        check('anonymous token creation rejected', status in [401, 403])
        status, _, _ = request('/tokens/create', {'name': 'no-csrf', 'scopes[]': ['images:read'], 'days': '30'}, csrf=False)
        check('token creation requires CSRF', status == 403)
        for data in [
            {'name': '', 'scopes[]': ['health:read'], 'days': '30'},
            {'name': 'bad scope', 'scopes[]': ['admin:all'], 'days': '30'},
            {'name': 'no scope', 'days': '30'},
            {'name': 'bad days', 'scopes[]': ['health:read'], 'days': '0'},
            {'name': 'bad name', 'scopes': 'health:read', 'days': '30'}
        ]:
            status, body, _ = request('/tokens/create', data)
            check('invalid token creation rejected', status == 400 and b'id="api-created-token"' not in body)
        full = create_token('full access', ['health:read', 'images:read', 'appearance:read', 'appearance:write'])
        readonly = create_token('read only', ['health:read', 'images:read', 'appearance:read'])
        writer = create_token('appearance only', ['appearance:write'])
        uploader = create_token('upload only', ['images:upload'])
        deleter = create_token('delete only', ['images:delete'])

        def api_upload(content, token=uploader, name='api-image.png', field='file', extra_file=False, form=None, as_admin=False):
            boundary = 'imgurl-v1-multipart-test'
            body = (f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n').encode() + content + b'\r\n'
            if extra_file:
                body += (f'--{boundary}\r\nContent-Disposition: form-data; name="other"; filename="extra.png"\r\n\r\n').encode() + content + b'\r\n'
            for key, value in (form or {}).items():
                body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode()
            body += (f'--{boundary}--\r\n').encode()
            return api('images', token=token, data=body, method='POST', headers={'Content-Type': 'multipart/form-data; boundary=' + boundary}, as_admin=as_admin)

        incoming = image + b'v1-upload'
        for credential, expected in [(None, 401), (readonly, 403), (full, 403), (deleter, 403)]:
            status, _, _ = api_upload(incoming, token=credential)
            check('upload requires its explicit scope; existing tokens are unchanged', status == expected)
        status, _, _ = api_upload(incoming, token=None, as_admin=True)
        check('admin cookie cannot authenticate v1 upload', status == 401)
        status, _, _ = api('images', token=uploader, data={}, method='POST')
        check('v1 upload requires multipart media type', status == 415)
        status, _, _ = api_upload(incoming, field='wrong')
        check('v1 upload requires file field', status == 400)
        status, _, _ = api_upload(incoming, field='file[]')
        check('v1 upload rejects array files', status == 400)
        status, _, _ = api_upload(incoming, extra_file=True)
        check('v1 upload rejects multiple files', status == 400)
        for invalid in [b'not an image', b'<svg xmlns="http://www.w3.org/2000/svg"></svg>', image[:33], b'']:
            status, _, _ = api_upload(invalid)
            check('v1 upload checks real decodable raster contents', status == 422)
        api_large_header = bytearray(image)
        api_large_header[16:24] = (100000).to_bytes(4, 'big') * 2
        api_large_header[29:33] = zlib.crc32(api_large_header[12:29]).to_bytes(4, 'big')
        status, _, _ = api_upload(bytes(api_large_header))
        check('v1 upload enforces pixel limit', status == 422)
        status, _, _ = api_upload(image + b'X' * (1024 * 1024 + 1 - len(image)))
        check('v1 upload enforces exact file size limit', status == 413)
        status, _, _ = api_upload(image + b'X' * (1100 * 1024))
        check('v1 upload bounds multipart request size', status == 413)
        boundary_content = image + b'B' * (1024 * 1024 - len(image))
        status, body, _ = api_upload(boundary_content)
        check('v1 upload accepts exact configured size boundary', status == 201 and body['data']['size_bytes'] == 1024 * 1024)
        api('images/' + body['data']['imgid'], token=deleter, method='DELETE')
        status, body, headers = api_upload(incoming, name='v1-photo.png', form={'user': 'visitor', 'path': '/tmp/must-not-be-used.png'})
        v1_uploaded = body['data']
        v1_id = v1_uploaded['imgid']
        v1_original = app / urllib.parse.urlparse(v1_uploaded['url']).path.lstrip('/')
        v1_thumb = app / urllib.parse.urlparse(v1_uploaded['thumbnail_url']).path.lstrip('/')
        check('v1 upload creates image and thumbnail with 201', status == 201 and not v1_uploaded['duplicate'] and v1_original.read_bytes() == incoming and v1_thumb.is_file() and v1_original != v1_thumb)
        check('v1 upload returns usable URLs and metadata', v1_uploaded['url'].startswith(origin + '/imgs/') and v1_uploaded['width'] == 640 and v1_uploaded['height'] == 480 and v1_uploaded['mime'] == 'image/png' and v1_uploaded['size_bytes'] == len(incoming) and headers.get('Location') == '/api/v1/images/' + v1_id)
        check('v1 upload does not disclose public delete keys', not ({'delete', 'token', 'ip', 'name'} & v1_uploaded.keys()) and v1_uploaded['delete_endpoint'] == '/api/v1/images/' + v1_id)
        check('v1 upload uses admin role, not request fields', db.execute('SELECT user FROM img_images WHERE imgid=?', (v1_id,)).fetchone()[0] == 'admin')
        status, _, _ = api('images/' + v1_id, token=uploader)
        check('upload permission does not grant image query permission', status == 403)
        status, body, _ = api_upload(incoming, name='renamed.png')
        check('v1 duplicate upload is 200 with same resource', status == 200 and body['data']['duplicate'] and body['data']['imgid'] == v1_id and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (v1_id,)).fetchone()[0] == 1)
        v1_numeric_id = db.execute('SELECT id FROM img_images WHERE imgid=?', (v1_id,)).fetchone()[0]
        status, _, _ = request('/audit_compress/img/' + str(v1_numeric_id), {'scenario': 'success'})
        served_bytes = v1_original.read_bytes()
        status, body, _ = api_upload(incoming)
        check('v1 repeat upload remains compatible with previously compressed images', status == 200 and body['data']['duplicate'] and body['data']['size_bytes'] == len(served_bytes))
        db.execute("UPDATE img_images SET level='adult' WHERE imgid=?", (v1_id,))
        db.commit()
        status, _, _ = api_upload(incoming)
        check('v1 duplicate upload cannot expose restricted image', status == 409)
        db.execute("UPDATE img_images SET level='unknown' WHERE imgid=?", (v1_id,))
        db.commit()
        for extension, encoder in [('jpg', 'imagejpeg'), ('gif', 'imagegif'), ('bmp', 'imagebmp'), ('webp', 'imagewebp')]:
            raster = subprocess.check_output([args.php, '-r', '$im=imagecreatetruecolor(640,480); ' + encoder + '($im);']) + ('api-' + extension).encode()
            status, body, _ = api_upload(raster, name='format.' + extension)
            check('v1 supports raster upload: ' + extension, status == 201 and body['data']['mime'].startswith('image/') and (app / urllib.parse.urlparse(body['data']['thumbnail_url']).path.lstrip('/')).is_file())
        status, body, _ = api_upload(image + b'v1-path-name', name='../../v1-path.png')
        check('v1 client filename cannot choose storage path', status == 201 and body['data']['url'].startswith(origin + '/imgs/') and '..' not in body['data']['url'])
        failed_content = image + b'v1-failed-insert'
        failed_upload_id = hashlib.md5(failed_content).hexdigest()[8:24]
        db.execute("CREATE TRIGGER audit_fail_api_upload BEFORE INSERT ON img_imginfo BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        status, _, _ = api_upload(failed_content)
        check('v1 failed upload rolls back rows and published files', status == 503 and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (failed_upload_id,)).fetchone()[0] == 0 and not list((app / 'imgs').rglob(failed_upload_id + '*')))
        db.execute('DROP TRIGGER audit_fail_api_upload')
        db.commit()
        upload_directory = v1_original.parent
        original_mode = upload_directory.stat().st_mode & 0o777
        upload_directory.chmod(0o555)
        try:
            denied_content = image + b'v1-write-denied'
            denied_id = hashlib.md5(denied_content).hexdigest()[8:24]
            status, _, _ = api_upload(denied_content)
            check('v1 unwritable storage returns JSON and leaves no rows', status == 503 and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (denied_id,)).fetchone()[0] == 0)
        finally:
            upload_directory.chmod(original_mode)
        saved_limits = db.execute('SELECT "values" FROM img_options WHERE name=?', ('uplimit',)).fetchone()[0]
        _, quota_fixture_body, _ = post_image(image + b'v1-visitor-quota', anon=True)
        check('visitor quota fixture uploaded', json.loads(quota_fixture_body)['code'] == 200)
        visitor_total = db.execute("SELECT count(*) FROM img_images WHERE user='visitor'").fetchone()[0]
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({'max_size': 1, 'limit': max(1, visitor_total)}), 'uplimit'))
        db.commit()
        _, exhausted_body, _ = post_image(image + b'v1-exhausted-visitor', anon=True)
        check('visitor quota is exhausted before token upload', json.loads(exhausted_body)['code'] == 0)
        status, body, _ = api_upload(image + b'v1-admin-quota')
        check('v1 upload uses admin authorization independently of visitor quota', status == 201)
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (saved_limits, 'uplimit'))
        db.commit()
        concurrent_content = image + b'v1-parallel'
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            new_parallel = list(pool.map(lambda _: api_upload(concurrent_content), range(3)))
        check('v1 concurrent uploads deduplicate atomically', sorted(r[0] for r in new_parallel) == [200, 200, 201] and len({r[1]['data']['imgid'] for r in new_parallel}) == 1)
        shared_content = image + b'v1-shared-with-legacy'
        _, legacy_body, _ = post_image(shared_content, anon=True)
        shared_legacy = json.loads(legacy_body)
        status, body, _ = api_upload(shared_content)
        check('v1 and legacy uploads share deduplication without promoting visitors', status == 200 and body['data']['duplicate'] and body['data']['imgid'] == shared_legacy['imgid'] and db.execute('SELECT user FROM img_images WHERE imgid=?', (shared_legacy['imgid'],)).fetchone()[0] == 'visitor')
        for credential, expected in [(None, 401), (readonly, 403), (uploader, 403), (full, 403)]:
            status, _, _ = api('images/' + v1_id, token=credential, method='DELETE')
            check('delete requires its explicit scope; old tokens cannot delete', status == expected and v1_original.is_file())
        status, _, headers = api('images/' + v1_id, token=deleter, method='POST', data={})
        check('v1 image detail rejects POST deletion', status == 405 and headers.get('Allow') == 'GET, DELETE')
        status, _, _ = api('images/not-an-id', token=deleter, method='DELETE')
        check('v1 deletion validates image ID', status == 400)
        status, _, _ = api('images/0000000000000000', token=deleter, method='DELETE')
        check('v1 absent deletion is 404', status == 404)
        status, stale_body, _ = request('/audit_stale_delete?imgid=' + v1_id)
        check('public deletion cannot use stale authorization after image token changes', status == 200 and not json.loads(stale_body)['removed'] and v1_original.is_file() and v1_thumb.is_file())
        db.execute("CREATE TRIGGER audit_fail_api_delete BEFORE DELETE ON img_images BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        status, _, _ = api('images/' + v1_id, token=deleter, method='DELETE')
        check('v1 failed deletion restores image thumbnail and metadata', status == 503 and v1_original.read_bytes() == served_bytes and v1_thumb.is_file() and db.execute('SELECT count(*) FROM img_imginfo WHERE imgid=?', (v1_id,)).fetchone()[0] == 1)
        db.execute('DROP TRIGGER audit_fail_api_delete')
        db.commit()
        delete_sentinel = root / 'api-delete-sentinel.txt'
        delete_sentinel.write_text('must survive')
        original_path = urllib.parse.urlparse(v1_uploaded['url']).path
        db.execute('UPDATE img_images SET path=? WHERE imgid=?', ('/imgs/../../api-delete-sentinel.txt', v1_id))
        db.commit()
        status, _, _ = api('images/' + v1_id, token=deleter, method='DELETE')
        check('v1 deletion rejects database traversal paths', status == 503 and delete_sentinel.read_text() == 'must survive' and v1_original.is_file())
        db.execute('UPDATE img_images SET path=? WHERE imgid=?', (original_path, v1_id))
        escape = app / 'imgs/api-delete-escape.png'
        escape.symlink_to(delete_sentinel)
        db.execute('UPDATE img_images SET thumb_path=? WHERE imgid=?', ('/imgs/api-delete-escape.png', v1_id))
        db.commit()
        status, _, _ = api('images/' + v1_id, token=deleter, method='DELETE')
        check('v1 deletion rejects symlink escapes and restores staged original', status == 503 and v1_original.is_file() and escape.is_symlink() and delete_sentinel.is_file())
        escape.unlink()
        db.execute('UPDATE img_images SET thumb_path=? WHERE imgid=?', (urllib.parse.urlparse(v1_uploaded['thumbnail_url']).path, v1_id))
        db.commit()
        status, body, _ = api('images/' + v1_id.upper(), token=deleter, method='DELETE', data={'path': str(delete_sentinel), 'imgid': uploaded['imgid']})
        check('v1 deletion removes only addressed image and thumbnail', status == 200 and body['data'] == {'imgid': v1_id, 'deleted': True} and not v1_original.exists() and not v1_thumb.exists() and delete_sentinel.is_file() and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (uploaded['imgid'],)).fetchone()[0] == 1)
        check('v1 deletion removes both metadata rows', db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (v1_id,)).fetchone()[0] == 0 and db.execute('SELECT count(*) FROM img_imginfo WHERE imgid=?', (v1_id,)).fetchone()[0] == 0)
        status, _, _ = api('images/' + v1_id, token=deleter, method='DELETE')
        check('v1 repeated deletion is 404', status == 404)
        tiny_image = subprocess.check_output([args.php, '-r', '$im=imagecreatetruecolor(3,3); imagepng($im);'])
        _, body, _ = api_upload(tiny_image)
        tiny_api = body['data']
        status, _, _ = api('images/' + tiny_api['imgid'], token=deleter, method='DELETE')
        check('v1 deletion handles identical original and thumbnail paths', status == 200 and tiny_api['url'] == tiny_api['thumbnail_url'])
        _, body, _ = api_upload(image + b'v1-missing-source')
        missing_api = body['data']
        for path in set([missing_api['url'], missing_api['thumbnail_url']]):
            (app / urllib.parse.urlparse(path).path.lstrip('/')).unlink()
        status, _, _ = api('images/' + missing_api['imgid'], token=deleter, method='DELETE')
        check('v1 deletion cleans metadata even when source files are missing', status == 200 and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (missing_api['imgid'],)).fetchone()[0] == 0)
        _, body, _ = api_upload(image + b'v1-restricted-delete')
        restricted_id = body['data']['imgid']
        db.execute("UPDATE img_images SET level='adult' WHERE imgid=?", (restricted_id,))
        db.commit()
        status, _, _ = api('images/' + restricted_id, token=deleter, method='DELETE')
        check('v1 delete scope permits removal of restricted images', status == 200)
        _, body, _ = api_upload(image + b'v1-concurrent-delete')
        deleting_id = body['data']['imgid']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            deletion_results = list(pool.map(lambda _: api('images/' + deleting_id, token=deleter, method='DELETE'), range(2)))
        check('v1 concurrent deletion is serialized with 200 and 404', sorted(r[0] for r in deletion_results) == [200, 404])
        _, body, _ = api_upload(image + b'v1-branding-source')
        branding_api_source = body['data']['imgid']
        status, _, _ = api('settings/appearance', token=writer, data={'logo': branding_api_source}, method='PATCH')
        source_snapshot = app / site_settings()['logo'].lstrip('/')
        status, _, _ = api('images/' + branding_api_source, token=deleter, method='DELETE')
        check('v1 deletion preserves independent branding snapshot', status == 200 and source_snapshot.is_file())
        api('settings/appearance', token=writer, data={'logo': 'default'}, method='PATCH')
        new_scope_records = token_records()
        upload_token_id = next(k for k, v in new_scope_records.items() if v['hash'] == hashlib.sha256(uploader.encode()).hexdigest())
        saved_upload_expiry = new_scope_records[upload_token_id]['expires_at']
        new_scope_records[upload_token_id]['expires_at'] = int(time.time()) - 1
        store_tokens(new_scope_records)
        status, _, _ = api_upload(image + b'v1-expired-upload')
        check('expired upload token cannot create an image', status == 401)
        new_scope_records[upload_token_id]['expires_at'] = saved_upload_expiry
        store_tokens(new_scope_records)
        revocable_delete = create_token('revoked delete', ['images:delete'])
        revocable_id = next(k for k, v in token_records().items() if v['hash'] == hashlib.sha256(revocable_delete.encode()).hexdigest())
        request('/tokens/revoke', {'id': revocable_id})
        status, _, _ = api('images/' + uploaded['imgid'], token=revocable_delete, method='DELETE')
        check('revoked delete token cannot remove an image', status == 401 and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (uploaded['imgid'],)).fetchone()[0] == 1)
        for extra, credential, expected in [([], '', 0), (['--images', '--appearance'], readonly, 0), (['--images'], writer, 1)]:
            probe = subprocess.run([sys.executable, str(repo / 'scripts/api_check.py'), '--base-url', origin, *extra], env={**os.environ, 'IMGURL_API_TOKEN': credential}, capture_output=True, text=True)
            check('production probe is read-only and reports permissions correctly', probe.returncode == expected and json.loads(probe.stdout)['ok'] == (expected == 0) and (not credential or credential not in probe.stdout + probe.stderr))
        redirects = []
        class RedirectFixture(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                redirects.append(self.path)
                self.send_response(302)
                self.send_header('Location', '/credential-must-not-reach-this-path')
                self.end_headers()
            def log_message(self, *args):
                pass
        redirect_server = http.server.HTTPServer(('127.0.0.1', 0), RedirectFixture)
        redirect_thread = threading.Thread(target=redirect_server.serve_forever, daemon=True)
        redirect_thread.start()
        try:
            probe = subprocess.run([sys.executable, str(repo / 'scripts/api_check.py'), '--base-url', 'http://127.0.0.1:' + str(redirect_server.server_port)], env={**os.environ, 'IMGURL_API_TOKEN': readonly}, capture_output=True, text=True)
            check('production probe never follows redirects with credentials', probe.returncode == 1 and redirects == ['/api/v1/health'] and readonly not in probe.stdout + probe.stderr)
        finally:
            redirect_server.shutdown()
            redirect_server.server_close()
            redirect_thread.join()
        records = token_records()
        full_id = next(k for k, v in records.items() if v['hash'] == hashlib.sha256(full.encode()).hexdigest())
        check('tokens stored only as hashes', full not in json.dumps(records) and readonly not in json.dumps(records) and len(records[full_id]['hash']) == 64)
        status, body, headers = request('/setting/tokens')
        check('token page does not redisplay secrets', status == 200 and full.encode() not in body and readonly.encode() not in body and headers.get('Cache-Control') == 'no-store')
        status, _, headers = api('images', as_admin=True)
        check('browser session never authenticates token API', status == 401 and headers.get('WWW-Authenticate', '').startswith('Bearer'))
        status, _, _ = api('images?token=' + readonly)
        check('query string cannot authenticate API', status == 401)
        status, _, _ = api('images', headers={'Cookie': 'api_token=' + readonly})
        check('cookie cannot authenticate API', status == 401)
        status, _, _ = api('images', token='invalid')
        check('invalid bearer token rejected', status == 401)
        status, _, _ = api('images', token=writer)
        check('scopes constrain image access', status == 403)
        status, _, _ = api('settings/appearance', token=writer)
        check('write permission does not grant read endpoint access', status == 403)
        status, body, _ = api('health', token=readonly)
        check('health scope returns safe diagnostics', status == 200 and all(body['data']['checks'].values()) and 'application_version' in body['data'] and str(app) not in json.dumps(body))
        status, _, _ = api('health', token=writer)
        check('health details require their own scope', status == 403)
        status, _, _ = api('unknown')
        check('unknown versioned endpoint is JSON 404', status == 404)
        status, _, headers = api('images', token=full, data={}, method='PUT')
        check('unsupported method is JSON 405 with Allow', status == 405 and headers.get('Allow') == 'GET, POST')
        status, _, _ = api('tokens', token=full, data={}, method='POST')
        check('API cannot create or manage tokens', status == 404)
        for query in ['page=0', 'page[]=1', 'per_page=51', 'per_page=-1', 'search[]=bad', 'usage=unknown']:
            status, _, _ = api('images?' + query, token=readonly)
            check('API image query validation: ' + query, status == 400)
        status, first, _ = api('images?search=brand-page-&per_page=24', token=readonly)
        status, second, _ = api('images?search=brand-page-&per_page=24&page=2', token=readonly)
        check('API images support pagination', len(first['data']['items']) == 24 and first['data']['has_more'] and len(second['data']['items']) == 2 and not second['data']['has_more'])
        status, body, _ = api('images?search=' + urllib.parse.quote("' OR 1=1--"), token=readonly)
        check('API search is parameterized', status == 200 and body['data']['items'] == [])
        status, body, _ = api('images?search=' + icon['imgid'] + '&usage=favicon', token=readonly)
        item = body['data']['items'][0]
        check('API images expose real dimensions, size, MIME and suitability', status == 200 and item['width'] == item['height'] == 128 and item['size_bytes'] == len(icon_bytes) and item['mime'] == 'image/png' and item['eligible'])
        check('API images omit IPs and deletion credentials', not ({'ip', 'token', 'delete', 'user', 'credential'} & item.keys()))
        db.execute('UPDATE img_imginfo SET width=999,height=999 WHERE imgid=?', (icon['imgid'],))
        db.commit()
        status, body, _ = api('images/' + icon['imgid'], token=readonly)
        check('API ignores forged size metadata', status == 200 and body['data']['width'] == 128)
        status, body, _ = api('images/' + icon['imgid'].upper(), token=readonly)
        check('API accepts uppercase hexadecimal image IDs', status == 200 and body['data']['imgid'] == icon['imgid'])
        db.execute('UPDATE img_imginfo SET width=128,height=128 WHERE imgid=?', (icon['imgid'],))
        db.execute("UPDATE img_images SET level='adult' WHERE imgid=?", (icon['imgid'],))
        db.commit()
        status, _, _ = api('images/' + icon['imgid'], token=readonly)
        check('API excludes restricted images', status == 404)
        db.execute("UPDATE img_images SET level='everyone' WHERE imgid=?", (icon['imgid'],))
        db.commit()
        status, _, _ = api('images/0000000000000000', token=readonly)
        check('API missing image is JSON 404', status == 404)
        status, _, _ = api('images/not-an-id', token=readonly)
        check('API invalid image ID is JSON 400', status == 400)
        icon_source.rename(root / 'api-image-temp.png')
        status, body, _ = api('images/' + icon['imgid'], token=readonly)
        check('API missing source is marked unavailable', status == 200 and not body['data']['available'] and body['data']['url'] is None)
        (root / 'api-image-temp.png').rename(icon_source)
        status, body, _ = api('settings/appearance', token=readonly)
        check('API appearance returns URLs and size requirements without other settings', status == 200 and set(body['data']) == {'background', 'logo', 'favicon', 'requirements'} and body['data']['requirements']['favicon']['output_width'] == 256)
        before = site_settings()
        status, _, _ = api('settings/appearance', token=readonly, data={'logo': icon['imgid']}, method='PATCH')
        check('read only API token cannot write appearance', status == 403 and site_settings() == before)
        for data in [b'{bad', b'[]', b'{}', b'null', {'title': 'blocked'}, {'logo': {'imgid': icon['imgid']}}, {'logo': '/imgs/test.png'}, {'logo': 'keep'}]:
            status, _, _ = api('settings/appearance', token=full, data=data, method='PATCH')
            check('API validates JSON and field allowlist', status == 400 and site_settings() == before)
        status, _, _ = api('settings/appearance', token=full, data=b'{}', headers={'Content-Type': 'text/plain'}, method='PATCH')
        check('API enforces JSON media type', status == 415)
        status, _, _ = api('settings/appearance', token=full, data=b' ' * 16385, method='PATCH')
        check('API bounds JSON body size', status == 413)
        status, _, _ = api('settings/appearance', token=full, data={'favicon': background['imgid']}, method='PATCH')
        check('API reuses image dimension validation', status == 422 and site_settings() == before)
        status, _, _ = api('settings/appearance', token=full, data={'logo': '0000000000000000'}, method='PATCH')
        check('API requires registered existing image', status == 422)
        status, body, _ = api('settings/appearance', token=writer, data={'background': background['imgid'], 'logo': icon['imgid'], 'favicon': icon['imgid']}, method='PATCH')
        api_branded = site_settings()
        check('API saves appearance through shared service', status == 200 and all(api_branded[k].startswith('/imgs/branding/') for k in ['background', 'logo', 'favicon']))
        check('API preserves unrelated site settings', all(api_branded[k] == v for k, v in before.items() if k not in ['background', 'logo', 'favicon']))
        before_files = set((app / 'imgs/branding').iterdir())
        status, _, _ = api('settings/appearance', token=full, data={'logo': uploaded['imgid'], 'favicon': background['imgid']}, method='PATCH')
        check('API invalid later selection rolls back files and settings', status == 422 and site_settings() == api_branded and set((app / 'imgs/branding').iterdir()) == before_files)
        db.execute("CREATE TRIGGER audit_fail_api BEFORE UPDATE ON img_options WHEN NEW.name='site_setting' BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        status, _, _ = api('settings/appearance', token=full, data={'logo': uploaded['imgid']}, method='PATCH')
        check('API storage failure is JSON and preserves existing state', status == 503 and site_settings() == api_branded and set((app / 'imgs/branding').iterdir()) == before_files)
        db.execute('DROP TRIGGER audit_fail_api')
        db.commit()
        status, _, _ = api('settings/appearance', token=full, data={'background': 'default', 'logo': 'default', 'favicon': 'default'}, method='PATCH')
        check('API can restore defaults', status == 200 and site_settings()['logo'] == '/static/images/logo.png')
        records = token_records()
        saved_expiry = records[full_id]['expires_at']
        records[full_id]['expires_at'] = int(time.time()) - 1
        store_tokens(records)
        status, _, _ = api('images', token=full)
        check('expired API token rejected', status == 401)
        records[full_id]['expires_at'] = saved_expiry
        store_tokens(records)
        saved_user = userinfo()
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({**saved_user, 'password': 'fixture-password-change'}), 'userinfo'))
        db.commit()
        status, _, _ = api('images', token=full)
        check('account credential change invalidates API tokens', status == 401)
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps(saved_user), 'userinfo'))
        db.commit()
        status, _, _ = request('/tokens/revoke', {'id': full_id}, csrf=False)
        check('token revocation requires CSRF', status == 403 and full_id in token_records())
        status, _, _ = request('/tokens/revoke')
        check('token revocation GET cannot mutate state', status == 405 and full_id in token_records())
        saved_site = site_settings()
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', ('{invalid', 'site_setting'))
        db.commit()
        status, body, _ = api('health')
        check('public health handles corrupt configuration without leaking diagnostics', status == 503 and 'checks' not in body['data'])
        status, _, _ = api('settings/appearance', token=full, data={'logo': icon['imgid']}, method='PATCH')
        check('API refuses to overwrite corrupted site settings', status == 503 and db.execute('SELECT "values" FROM img_options WHERE name=?', ('site_setting',)).fetchone()[0] == '{invalid')
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps(saved_site), 'site_setting'))
        db.commit()
        # Fixed-window counters are seeded only in this isolated test fixture.
        while time.time() % 60 > 57:
            time.sleep(.05)
        rate_dir = app / 'data/.api_rate'
        token_counter = rate_dir / (hashlib.sha256(('token:' + full_id).encode()).hexdigest() + '.json')
        token_counter.write_text(json.dumps({'window': int(time.time()) // 60, 'count': 119}))
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            limited = list(pool.map(lambda _: api('images', token=full), range(2)))
        check('API token rate limit is atomic across workers', sorted(r[0] for r in limited) == [200, 429] and all(int(r[2]['Retry-After']) > 0 for r in limited if r[0] == 429))
        token_counter.write_text(json.dumps({'window': int(time.time()) // 60, 'count': 0}))
        ip_counter = rate_dir / (hashlib.sha256(b'ip:127.0.0.1').hexdigest() + '.json')
        ip_counter.write_text(json.dumps({'window': int(time.time()) // 60, 'count': 300}))
        status, _, headers = api('health')
        check('public health and invalid auth attempts are IP rate limited', status == 429 and int(headers['Retry-After']) > 0)
        ip_counter.write_text(json.dumps({'window': int(time.time()) // 60, 'count': 0}))
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            concurrent_tokens = list(pool.map(lambda n: create_token('concurrent-' + str(n), ['health:read']), range(2)))
        check('concurrent token creation has no lost updates', all(hashlib.sha256(t.encode()).hexdigest() in {r['hash'] for r in token_records().values()} for t in concurrent_tokens))
        saved_records = token_records()
        full_record = saved_records[full_id]
        store_tokens({format(i, '016x'): {**full_record, 'id': format(i, '016x')} for i in range(50)})
        status, _, _ = request('/tokens/create', {'name': 'over capacity', 'scopes[]': ['health:read'], 'days': '30'})
        check('API token count is bounded', status == 400 and len(token_records()) == 50)
        store_tokens(saved_records)
        status, _, _ = request('/tokens/revoke', {'id': full_id})
        check('admin revokes API token', status == 200 and full_id not in token_records())
        status, _, _ = api('images', token=full)
        check('revoked API token immediately rejected', status == 401)
        # Unavailable health storage must still produce a JSON service error.
        rate_dir.rename(root / 'api-rate-saved')
        rate_dir.write_text('fixture blocks limiter directory creation')
        status, _, _ = api('health')
        check('API limiter storage failure stays JSON without warnings', status == 503)
        rate_dir.unlink()
        (root / 'api-rate-saved').rename(rate_dir)
        specification = json.loads((repo / 'static/openapi.json').read_text())
        check('OpenAPI documents upload and delete operations', specification['paths']['/api/v1/images']['post']['x-required-scopes'] == ['images:upload'] and specification['paths']['/api/v1/images/{imgid}']['delete']['x-required-scopes'] == ['images:delete'])
        check('OpenAPI documents all v1 operations', specification['openapi'] == '3.0.3' and all(path in specification['paths'] for path in ['/api/v1/health', '/api/v1/images', '/api/v1/images/{imgid}', '/api/v1/settings/appearance']))
        status, body, _ = request('/page/api')
        check('API human readable documentation renders', status == 200 and b'/api/v1/health' in body)
        thumb = app / urllib.parse.urlparse(uploaded['thumbnail_url']).path.lstrip('/')
        check('thumbnail generated', thumb.exists() and thumb.stat().st_size > 0)
        for mode in ['url', 'html', 'markdown', 'bbcode', 'json']:
            status, body, _ = post_image(image, output=mode)
            check('duplicate output clean: ' + mode, status == 200 and (json.loads(body)['code'] == 200 if mode == 'json' else b'"code":0' not in body))
        for extension, encoder in [('jpg', 'imagejpeg'), ('gif', 'imagegif'), ('bmp', 'imagebmp'), ('webp', 'imagewebp')]:
            content = subprocess.check_output([args.php, '-r', '$im=imagecreatetruecolor(640,480); ' + encoder + '($im);'])
            _, body, _ = post_image(content, 'format.' + extension)
            result = json.loads(body)
            check('raster format upload: ' + extension, result['code'] == 200)
            check('raster format thumbnail: ' + extension, (app / urllib.parse.urlparse(result['thumbnail_url']).path.lstrip('/')).is_file())
        _, body, _ = post_image(image[:33], 'truncated.png')
        check('truncated image rejected', json.loads(body)['code'] == 0)
        header = bytearray(image)
        header[16:24] = (100000).to_bytes(4, 'big') * 2
        header[29:33] = zlib.crc32(header[12:29]).to_bytes(4, 'big')
        _, body, _ = post_image(bytes(header), 'huge.png')
        check('excessive pixel count rejected', json.loads(body)['code'] == 0)
        # Anonymous requests run on multiple server workers and must deduplicate atomically.
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            parallel = list(pool.map(lambda _: json.loads(post_image(image + b'concurrent', anon=True)[1]), range(3)))
        check('parallel upload deduplicated', len({str(item['id']) for item in parallel}) == 1)
        check('parallel upload stored once', db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (parallel[0]['imgid'],)).fetchone()[0] == 1)
        large = image + b'X' * (1100 * 1024)
        _, body, _ = post_image(large)
        check('multipart size limit enforced', json.loads(body)['code'] == 0)
        _, body, _ = request('/upload/parse', {'content': base64.b64encode(large).decode()})
        check('paste size limit enforced', json.loads(body)['code'] == 0)
        _, body, _ = request('/upload/parse', {'content[]': 'invalid'})
        check('array paste input handled', json.loads(body)['code'] == 0)
        _, body, _ = request('/api/parse', {'content': 'data:image/png;base64,' + base64.b64encode(image + b'paste').decode()})
        check('public paste API remains compatible', json.loads(body)['code'] == 200)
        _, body, _ = post_image(image + b'xss', 'audit<img src=x onerror=__imgurl_audit=1>.png', anon=True)
        xss = json.loads(body)
        status, body, _ = request('/img/' + xss['imgid'])
        check('filename HTML escaped', b'<img src=x onerror=__imgurl_audit=1>' not in body and b'&lt;img' in body)
        status, body, _ = request('/manage/images')
        check('admin filename escaped', b'<img src=x onerror=' not in body and b'&lt;img' in body)
        status, _, _ = request('/manage/images/imgid/?value=' + urllib.parse.quote("' OR 1=1--abcdef"))
        check('invalid imgid filter rejected', status == 400)
        status, _, _ = request('/manage/images/all/?date=' + urllib.parse.quote("2026-01-01' OR 1=1--|2026-12-31"))
        check('invalid date filter rejected', status == 400)
        for path in ['/manage/images/all/?date=2026-01-01%7C2026-12-31', '/manage/images/visitor', '/manage/images/dubious']:
            status, _, _ = request(path)
            check('valid admin filter: ' + path, status == 200)
        status, _, _ = request('/found/all/160')
        check('public pagination bounded', status == 404)
        _, body, _ = request('/api/found')
        check('API includes newest picture', json.loads(body)[0]['imgid'] == xss['imgid'])
        _, ipbody, _ = request('/api/parse', {'content': base64.b64encode(image + b'ip-check').decode()}, headers={'X-Forwarded-For': '203.0.113.77'}, anon=True)
        iprow = json.loads(ipbody)
        check('spoofed upload IP ignored', db.execute('SELECT ip FROM img_images WHERE imgid=?', (iprow['imgid'],)).fetchone()[0] == '127.0.0.1')
        visitor_count = db.execute("SELECT count(*) FROM img_images WHERE user='visitor'").fetchone()[0]
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({'max_size': 1, 'limit': visitor_count + 1}), 'uplimit'))
        db.commit()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            quota = list(pool.map(lambda n: json.loads(request('/api/parse', {'content': base64.b64encode(image + ('quota-' + str(n)).encode()).decode()}, anon=True)[1]), range(2)))
        check('parallel visitor quota enforced', sorted(item['code'] for item in quota) == [0, 200])
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({'max_size': 1, 'limit': 100}), 'uplimit'))
        db.commit()
        status, _, _ = request('/compress/img/' + str(uploaded['id']), anon=True)
        check('anonymous compression denied', status == 401)
        status, _, _ = request('/compress/img/' + str(uploaded['id']), {})
        check('compression without key disabled', status == 503)
        original = app / urllib.parse.urlparse(uploaded['url']).path.lstrip('/')
        original_bytes = original.read_bytes()
        for scenario in ['failure', 'invalid']:
            status, _, _ = request('/audit_compress/img/' + str(uploaded['id']), {'scenario': scenario})
            check('compression preserves original on ' + scenario, status == 502 and original.read_bytes() == original_bytes)
            check('compression failure cleans temporary files: ' + scenario, not list(original.parent.glob('.compress-*')))
        db.execute("CREATE TRIGGER audit_fail_compress BEFORE UPDATE OF compression ON img_images BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        status, _, _ = request('/audit_compress/img/' + str(uploaded['id']), {'scenario': 'success'})
        check('compression database failure restores original', status == 502 and original.read_bytes() == original_bytes)
        db.execute('DROP TRIGGER audit_fail_compress')
        db.commit()
        status, body, _ = request('/audit_compress/img/' + str(uploaded['id']), {'scenario': 'success'})
        check('compression mock success updates bytes and status', status == 200 and json.loads(body)['code'] == 200 and original.read_bytes() != original_bytes and db.execute('SELECT compression FROM img_images WHERE id=?', (uploaded['id'],)).fetchone()[0] == 1)
        _, compressed_duplicate_body, _ = post_image(image)
        check('legacy duplicate upload remains compatible after compression', json.loads(compressed_duplicate_body)['code'] == 200 and original.read_bytes() != original_bytes)
        for url in ['http://127.0.0.1/image.png', 'http://169.254.169.254/latest/', 'file:///etc/passwd', 'http://[::1]/image.png', 'http://[::ffff:127.0.0.1]/a', 'http://100.64.0.1/a', 'http://224.0.0.1/a', 'http://198.18.0.1/a', 'http://192.0.0.1/a', 'http://203.0.113.1/a', 'http://example.com:8080/a', 'http://user:pass@example.com/a']:
            status, body, _ = request('/upload/url', {'url': url})
            check('remote unsafe target rejected: ' + url, status == 200 and json.loads(body)['code'] == 0)
        status, body, _ = request('/upload/url', {'url[]': 'invalid'})
        check('array URL input handled', status == 200 and json.loads(body)['code'] == 0)
        # A failed metadata insert must roll back both rows and remove published files.
        db.execute("CREATE TRIGGER audit_fail_info BEFORE INSERT ON img_imginfo BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        # CI development-mode SQL errors intentionally render an error page; request directly for this injected failure.
        data = {'content': base64.b64encode(image + b'failed-insert').decode()}
        req = urllib.request.Request(origin + '/upload/parse', data=urllib.parse.urlencode(data).encode())
        try:
            anonymous.open(req, timeout=15).read()
        except urllib.error.HTTPError:
            pass
        db.execute('DROP TRIGGER audit_fail_info')
        db.commit()
        failed_id = hashlib.md5(image + b'failed-insert').hexdigest()[8:24]
        check('failed insert rolls back image row', db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (failed_id,)).fetchone()[0] == 0)
        check('failed insert cleans files', not list((app / 'imgs').rglob(failed_id + '*')))
        sentinel = root / 'sentinel.txt'
        sentinel.write_text('must survive')
        status, _, _ = request('/set/del_img', {'imgid': '', 'path': str(sentinel), 'thumbnail_path': ''})
        check('invalid deletion cannot remove arbitrary file', status == 400 and sentinel.exists())
        db.execute('UPDATE img_images SET path=? WHERE imgid=?', ('/imgs/../../sentinel.txt', xss['imgid']))
        db.commit()
        _, body, _ = request('/set/del_img', {'imgid': xss['imgid']})
        check('database traversal path rejected', json.loads(body)['code'] == 0 and sentinel.exists())
        db.execute("CREATE TRIGGER audit_fail_delete BEFORE DELETE ON img_images BEGIN SELECT RAISE(FAIL, 'fixture failure'); END")
        db.commit()
        _, body, _ = request('/set/del_img', {'imgid': uploaded['imgid']})
        check('failed deletion restores files', json.loads(body)['code'] == 0 and thumb.exists())
        check('failed deletion restores metadata', db.execute('SELECT count(*) FROM img_imginfo WHERE imgid=?', (uploaded['imgid'],)).fetchone()[0] == 1)
        db.execute('DROP TRIGGER audit_fail_delete')
        db.commit()
        status, body, _ = request('/set/del_img', {'imgid': uploaded['imgid'], 'path': str(sentinel), 'thumbnail_path': str(sentinel)})
        check('deletion ignores client paths', status == 200 and json.loads(body)['code'] == 200 and sentinel.exists() and not thumb.exists())
        # Original and thumbnail can legitimately refer to the same small image.
        small = subprocess.check_output([args.php, '-r', '$im=imagecreatetruecolor(2,2); imagepng($im);'])
        _, body, _ = post_image(small)
        smallrow = json.loads(body)
        tokenpath = urllib.parse.urlparse(smallrow['delete']).path
        _, body, _ = request(tokenpath)
        check('delete link GET only confirms', b'<form' in body and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (smallrow['imgid'],)).fetchone()[0] == 1)
        status, _, _ = request(tokenpath, {})
        check('delete token POST succeeds', status == 200 and db.execute('SELECT count(*) FROM img_images WHERE imgid=?', (smallrow['imgid'],)).fetchone()[0] == 0)
        _, _, _ = request('/user/logout')
        status, _, _ = request('/admin')
        check('logout GET does not mutate session', status == 200)
        request('/user/logout', {})
        status, _, _ = request('/admin')
        check('logout invalidates server session', status == 401)
        status, body, _ = login()
        check('modern hash login succeeds', status == 200 and json.loads(body)['code'] == 200)
        long_password = 'A' * 72 + 'long-password-tail'
        db.execute('UPDATE img_options SET "values"=? WHERE name=?', (json.dumps({'username': 'audit', 'password': hashlib.md5((long_password + 'imgurl').encode()).hexdigest()}), 'userinfo'))
        db.commit()
        status, _, _ = request('/admin')
        check('password changes revoke prior sessions', status == 401)
        status, body, _ = login(long_password)
        check('long legacy password upgraded without truncation', status == 200 and json.loads(body)['code'] == 200 and userinfo()['password'].startswith('sha384:'))
        status, body, _ = login('A' * 72 + 'different-tail')
        check('long password suffix is authenticated', status == 200 and json.loads(body)['code'] == 0)
        status, body, _ = login(long_password)
        check('long upgraded password login succeeds', status == 200 and json.loads(body)['code'] == 200)
        code = "define('BASEPATH', 'test'); require '" + str(app / 'application/libraries/Auth.php') + "'; session_name('imgurl_session'); session_save_path('" + str(app / 'data/.sessions') + "'); session_id('" + cookie('imgurl_session') + "'); session_start(); $_SESSION['expires']=1; session_write_close();"
        subprocess.check_call([args.php, '-r', code])
        status, _, _ = request('/admin')
        check('expired server session rejected', status == 401)
        (app / 'data/password.txt').write_text('fixture recovery marker')
        request('/user/resetpass')
        status, body, _ = request('/deal/resetpass', {'password1': 'new-密码-123', 'password2': 'new-密码-123'})
        check('password recovery accepts modern password policy', status == 200 and json.loads(body)['code'] == 200 and not (app / 'data/password.txt').exists())
        status, body, _ = login('new-密码-123')
        check('recovered password login succeeds', status == 200 and json.loads(body)['code'] == 200)
        lintfiles = list((app).rglob('*.php'))
        for path in lintfiles:
            result = subprocess.run([args.php, '-d', 'error_reporting=-1', '-l', str(path)], capture_output=True, text=True)
            check('PHP syntax: ' + str(path.relative_to(app)), result.returncode == 0 and 'Deprecated:' not in result.stdout + result.stderr)
        db.close()
        print(json.dumps({'php': subprocess.check_output([args.php, '-v'], text=True).splitlines()[0], 'passed': len(checks), 'php_files': len(lintfiles), 'checks': checks}, ensure_ascii=False, indent=2))
    except Exception:
        print(logpath.read_text()[-10000:])
        raise
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=5)
        log.close()
