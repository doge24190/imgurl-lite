#!/usr/bin/env python3
"""Read-only production API checks. Credentials are read only from IMGURL_API_TOKEN."""
import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Never forward a Bearer credential to a different location.
        return None


def main():
    parser = argparse.ArgumentParser(description='Read-only ImgURL API v1 deployment checks')
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--images', action='store_true', help='also check images:read')
    parser.add_argument('--appearance', action='store_true', help='also check appearance:read')
    parser.add_argument('--timeout', type=int, default=10)
    args = parser.parse_args()
    try:
        parts = urllib.parse.urlsplit(args.base_url)
        valid = parts.scheme in ['http', 'https'] and parts.hostname and parts.username is None and parts.password is None
        valid = valid and not parts.query and not parts.fragment and parts.path in ['', '/']
        # Accessing port also validates its syntax.
        _ = parts.port
    except ValueError:
        valid = False
    if not valid:
        parser.error('--base-url must be an HTTP(S) site root without credentials or query parameters')
    if parts.scheme != 'https' and parts.hostname not in ['localhost', '127.0.0.1', '::1']:
        parser.error('use HTTPS for production; HTTP is allowed only on loopback test servers')
    if not 1 <= args.timeout <= 60:
        parser.error('--timeout must be 1–60 seconds')
    token = os.environ.get('IMGURL_API_TOKEN', '').strip()
    if any(ord(c) < 32 or ord(c) == 127 for c in token):
        parser.error('IMGURL_API_TOKEN contains invalid characters')
    if (args.images or args.appearance) and not token:
        parser.error('set IMGURL_API_TOKEN to use protected checks')
    headers = {'Accept': 'application/json', 'User-Agent': 'ImgURL-read-only-check/1'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    checks = [('health', lambda data: data.get('status') == 'ok' and data.get('api_version') == 'v1')]
    if args.images:
        checks.append(('images?per_page=1', lambda data: isinstance(data.get('items'), list)))
    if args.appearance:
        checks.append(('settings/appearance', lambda data: all(key in data for key in ['background', 'logo', 'favicon', 'requirements'])))
    opener = urllib.request.build_opener(NoRedirect())
    results = []
    for path, validate in checks:
        result = {'endpoint': '/api/v1/' + path, 'ok': False}
        request = urllib.request.Request(args.base_url.rstrip('/') + '/api/v1/' + path, headers=headers, method='GET')
        try:
            with opener.open(request, timeout=args.timeout) as response:
                result['http_status'] = response.status
                if response.headers.get_content_type() != 'application/json':
                    raise ValueError('unexpected response format')
                content = response.read(1024 * 1024 + 1)
                if len(content) > 1024 * 1024:
                    raise ValueError('response too large')
                payload = json.loads(content)
                data = payload.get('data')
                result['ok'] = payload.get('ok') is True and isinstance(data, dict) and validate(data)
                if not result['ok']:
                    result['error'] = 'unexpected_response'
        except urllib.error.HTTPError as error:
            result.update(http_status=error.code, error='http_error')
            error.close()
        except (OSError, ValueError, TypeError, AttributeError):
            result['error'] = 'connection_or_response_error'
        results.append(result)
    passed = all(item['ok'] for item in results)
    print(json.dumps({'ok': passed, 'checks': results}, ensure_ascii=False, indent=2))
    return 0 if passed else 1


if __name__ == '__main__':
    sys.exit(main())
