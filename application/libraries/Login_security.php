<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Stateless CAPTCHA tokens plus a file-backed, per-IP login rate limiter.
 *
 * CAPTCHA answers are authenticated with a server-only key. The token also
 * contains a monotonically increasing version from the IP state file, making
 * every submitted challenge single-use without storing the plaintext answer.
 */
class Login_security {
    protected $CI;
    protected $storage_path;
    protected $captcha_length;
    protected $captcha_ttl;
    protected $max_attempts;
    protected $attempt_window;
    protected $lockout;
    protected $secret;

    public function __construct(){
        $this->CI =& get_instance();
        $this->CI->config->load('login_security', TRUE);

        $config = $this->CI->config->item('login_security');
        $this->storage_path = rtrim($config['login_security_storage_path'], '/\\');
        $this->captcha_length = (int)$config['login_security_captcha_length'];
        $this->captcha_ttl = (int)$config['login_security_captcha_ttl'];
        $this->max_attempts = (int)$config['login_security_max_attempts'];
        $this->attempt_window = (int)$config['login_security_attempt_window'];
        $this->lockout = (int)$config['login_security_lockout'];

        $this->ensure_storage();
        $this->secret = $this->load_secret();
    }

    /**
     * Create a CAPTCHA word and an authenticated token bound to an IP.
     */
    public function create_challenge($ip){
        $state = $this->read_state($ip);
        $word = $this->random_word($this->captcha_length);
        $expires = time() + $this->captcha_ttl;
        $nonce = bin2hex($this->random_bytes_compat(16));
        $ip_hash = substr(hash_hmac('sha256', $ip, $this->secret), 0, 24);
        $payload = $expires.'.'.$nonce.'.'.$ip_hash.'.'.(int)$state['version'];
        $digest = hash_hmac('sha256', strtoupper($word).'|'.$payload, $this->secret);

        return array(
            'word' => $word,
            'token' => $payload.'.'.$digest,
            'expires' => $expires
        );
    }

    /**
     * Validate and consume a CAPTCHA. Invalid CAPTCHA checks count as failed
     * login attempts. The state-file lock prevents parallel token replay.
     */
    public function consume_challenge($ip, $answer, $token){
        $handle = $this->open_state($ip);
        $state = $this->read_locked_state($handle);
        $this->prune_state($state);

        $retry_after = $this->retry_after($state);
        if($retry_after > 0){
            $this->write_locked_state($handle, $state);
            $this->close_state($handle);
            return array('valid' => FALSE, 'locked' => TRUE, 'retry_after' => $retry_after);
        }

        $valid = $this->token_matches($ip, $answer, $token, $state['version']);

        // Consume the current token even when it is malformed or incorrect.
        $state['version'] = (int)$state['version'] + 1;

        if( ! $valid){
            $this->add_failure($state);
        }

        $retry_after = $this->retry_after($state);
        $this->write_locked_state($handle, $state);
        $this->close_state($handle);

        return array(
            'valid' => $valid,
            'locked' => ($retry_after > 0),
            'retry_after' => $retry_after
        );
    }

    /**
     * Record a username/password failure after a CAPTCHA has been consumed.
     */
    public function record_failure($ip){
        $handle = $this->open_state($ip);
        $state = $this->read_locked_state($handle);
        $this->prune_state($state);
        $this->add_failure($state);
        $retry_after = $this->retry_after($state);
        $this->write_locked_state($handle, $state);
        $this->close_state($handle);

        return $retry_after;
    }

    public function clear_failures($ip){
        $path = $this->state_path($ip);
        if(is_file($path)){
            @unlink($path);
        }
    }

    /**
     * Render the challenge directly to a PNG resource.
     */
    public function render_captcha($word){
        if( ! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')){
            return FALSE;
        }

        $width = 160;
        $height = 50;
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 244, 247, 250);
        $text = imagecolorallocate($image, 31, 45, 61);
        $noise_one = imagecolorallocate($image, 155, 181, 202);
        $noise_two = imagecolorallocate($image, 201, 214, 225);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        for($i = 0; $i < 8; $i++){
            imageline(
                $image,
                mt_rand(0, $width),
                mt_rand(0, $height),
                mt_rand(0, $width),
                mt_rand(0, $height),
                ($i % 2 === 0) ? $noise_one : $noise_two
            );
        }

        for($i = 0; $i < 90; $i++){
            imagesetpixel($image, mt_rand(0, $width - 1), mt_rand(0, $height - 1), $noise_one);
        }

        $font = FCPATH.'system/fonts/texb.ttf';
        $used_ttf = FALSE;
        if(function_exists('imagettftext') && is_file($font)){
            $step = 27;
            $start_x = 13;
            for($i = 0; $i < strlen($word); $i++){
                $result = @imagettftext(
                    $image,
                    mt_rand(20, 24),
                    mt_rand(-18, 18),
                    $start_x + ($i * $step),
                    mt_rand(35, 43),
                    $text,
                    $font,
                    $word[$i]
                );
                if($result === FALSE){
                    $used_ttf = FALSE;
                    break;
                }
                $used_ttf = TRUE;
            }
        }

        // Some GD builds expose imagettftext() without FreeType support.
        if( ! $used_ttf){
            imagestring($image, 5, 22, 17, $word, $text);
        }

        return $image;
    }

    protected function token_matches($ip, $answer, $token, $current_version){
        if( ! is_string($answer) || ! is_string($token) || ! preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{24})\.(\d+)\.([a-f0-9]{64})$/', $token, $matches)){
            return FALSE;
        }

        $expires = (int)$matches[1];
        if($expires < time() || $expires > time() + $this->captcha_ttl + 5){
            return FALSE;
        }

        if((int)$matches[4] !== (int)$current_version){
            return FALSE;
        }

        $expected_ip_hash = substr(hash_hmac('sha256', $ip, $this->secret), 0, 24);
        if( ! hash_equals($expected_ip_hash, $matches[3])){
            return FALSE;
        }

        $payload = $matches[1].'.'.$matches[2].'.'.$matches[3].'.'.$matches[4];
        $expected = hash_hmac('sha256', strtoupper(trim((string)$answer)).'|'.$payload, $this->secret);

        return hash_equals($expected, $matches[5]);
    }

    protected function add_failure(&$state){
        $state['failures'][] = time();
        if(count($state['failures']) >= $this->max_attempts){
            $state['locked_until'] = time() + $this->lockout;
        }
    }

    protected function retry_after($state){
        return max(0, (int)$state['locked_until'] - time());
    }

    protected function prune_state(&$state){
        $cutoff = time() - $this->attempt_window;
        $failures = array();
        foreach($state['failures'] as $failed_at){
            if((int)$failed_at >= $cutoff){
                $failures[] = (int)$failed_at;
            }
        }
        $state['failures'] = $failures;
        if((int)$state['locked_until'] <= time()){
            $state['locked_until'] = 0;
        }
    }

    protected function read_state($ip){
        $handle = $this->open_state($ip);
        $state = $this->read_locked_state($handle);
        $this->prune_state($state);
        $this->write_locked_state($handle, $state);
        $this->close_state($handle);
        return $state;
    }

    protected function open_state($ip){
        $handle = @fopen($this->state_path($ip), 'c+');
        if($handle === FALSE || ! flock($handle, LOCK_EX)){
            throw new RuntimeException('Unable to open login security state.');
        }
        return $handle;
    }

    protected function read_locked_state($handle){
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = json_decode($raw, TRUE);
        if( ! is_array($state)){
            $state = array();
        }

        return array(
            'failures' => (isset($state['failures']) && is_array($state['failures'])) ? $state['failures'] : array(),
            'locked_until' => isset($state['locked_until']) ? (int)$state['locked_until'] : 0,
            'version' => isset($state['version']) ? (int)$state['version'] : 0
        );
    }

    protected function write_locked_state($handle, $state){
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state));
        fflush($handle);
    }

    protected function close_state($handle){
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    protected function state_path($ip){
        return $this->storage_path.'/attempt-'.hash('sha256', $ip).'.json';
    }

    protected function ensure_storage(){
        if( ! is_dir($this->storage_path) && ! @mkdir($this->storage_path, 0700, TRUE)){
            throw new RuntimeException('Unable to create login security storage.');
        }
        if( ! is_writable($this->storage_path)){
            throw new RuntimeException('Login security storage is not writable.');
        }
    }

    protected function load_secret(){
        $path = $this->storage_path.'/.secret';
        if(is_file($path)){
            $secret = trim((string)@file_get_contents($path));
            if(strlen($secret) >= 64){
                return $secret;
            }
        }

        $secret = bin2hex($this->random_bytes_compat(32));
        $handle = @fopen($path, 'x');
        if($handle !== FALSE){
            fwrite($handle, $secret);
            fclose($handle);
            @chmod($path, 0600);
            return $secret;
        }

        $existing = trim((string)@file_get_contents($path));
        if(strlen($existing) >= 64){
            return $existing;
        }

        throw new RuntimeException('Unable to initialize login security key.');
    }

    protected function random_word($length){
        // Ambiguous characters (0/O, 1/I/L) are intentionally omitted.
        $pool = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $bytes = $this->random_bytes_compat($length);
        $word = '';
        for($i = 0; $i < $length; $i++){
            $word .= $pool[ord($bytes[$i]) % strlen($pool)];
        }
        return $word;
    }

    protected function random_bytes_compat($length){
        if(function_exists('random_bytes')){
            return random_bytes($length);
        }

        $bytes = $this->CI->security->get_random_bytes($length);
        if($bytes !== FALSE && strlen($bytes) === $length){
            return $bytes;
        }

        if(function_exists('openssl_random_pseudo_bytes')){
            $strong = FALSE;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if($bytes !== FALSE && strlen($bytes) === $length){
                return $bytes;
            }
        }

        throw new RuntimeException('No cryptographically secure random source is available.');
    }
}
