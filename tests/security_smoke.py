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

    def request(path, data=None, csrf=True, headers=None, anon=False):
        request_headers = {'User-Agent': 'ImgURL security regression'}
        request_headers.update(headers or {})
        if isinstance(data, dict):
            data = dict(data)
            if csrf and cookie('csrf_cookie_name'):
                data['csrf_test_name'] = cookie('csrf_cookie_name')
            data = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(origin + path, data=data, headers=request_headers)
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
