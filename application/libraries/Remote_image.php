<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Bounded HTTPS/HTTP downloads with DNS pinning and no redirects or proxy bypass. */
class Remote_image {
    public function download(string $url, int $maxBytes): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || $maxBytes < 1) {
            throw new InvalidArgumentException('不是有效的 HTTP/HTTPS 图片地址。');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('远程图片只允许使用 80 或 443 端口。');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) {
                $addresses[] = $record['ip'] ?? $record['ipv6'] ?? '';
            }
        }
        if (!$addresses) {
            throw new RuntimeException('远程域名解析失败。');
        }
        foreach ($addresses as $address) {
            if (!$this->public_ip($address)) {
                throw new InvalidArgumentException('不允许下载内网或保留地址的图片。');
            }
        }
        $ip = $addresses[0];
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            (defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS) => defined('CURLOPT_PROTOCOLS_STR') ? 'HTTP,HTTPS' : CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ImgURL/2 PHP8',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }
        ]);
        // Pin domain requests to the validated address. Literal IPs need no DNS lookup.
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            curl_setopt($curl, CURLOPT_RESOLVE, [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]);
        }
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200 || $body === '') {
            throw new RuntimeException('远程图片下载失败、发生重定向或超过大小限制。');
        }
        return $body;
    }

    private function public_ip(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            // Only globally allocated unicast; rejects mapped IPv4, NAT64, local and transition ranges.
            $packed = inet_pton($ip);
            return $packed !== false && (ord($packed[0]) & 0xe0) === 0x20
                && bin2hex(substr($packed, 0, 4)) !== '20010000'
                && !str_starts_with(strtolower($ip), '2002:');
        }
        return (int)explode('.', $ip)[0] < 224; // FILTER_FLAG_GLOBAL_RANGE alone still permits IPv4 multicast.
    }
}
