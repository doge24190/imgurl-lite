<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Admin login protection
| -------------------------------------------------------------------------
|
| A CAPTCHA is required for every admin login. Failed CAPTCHA and password
| checks share the same per-IP rate limit so attackers cannot bypass the
| throttle by continuously requesting new challenges.
|
| The storage directory must be writable by PHP. It is kept under data/
| because that directory is already writable in the supported deployments.
*/
$config['login_security_storage_path'] = FCPATH.'data/.login_security';
$config['login_security_captcha_length'] = 5;
$config['login_security_captcha_ttl'] = 300;
$config['login_security_max_attempts'] = 5;
$config['login_security_attempt_window'] = 900;
$config['login_security_lockout'] = 900;
