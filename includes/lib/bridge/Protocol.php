<?php

declare(strict_types=1);

namespace lib\bridge;

use RuntimeException;

final class Protocol
{
    public const MAX_BODY = 16384;
    public const MAX_RESPONSE = 65536;

    public static function signature(string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', "POST\n{$path}\n{$timestamp}\n{$nonce}\n" . hash('sha256', $body), $secret);
    }

    public static function verify(string $path, array $headers, string $body, array $config, callable $claimNonce): void
    {
        $timestamp = $headers['x-bridge-time'] ?? '';
        $nonce = $headers['x-bridge-nonce'] ?? '';
        $signature = $headers['x-bridge-signature'] ?? '';
        if (
            strlen($body) > self::MAX_BODY || strlen($config['secret'] ?? '') < 32
            || ($headers['x-bridge-app'] ?? '') !== $config['app_id']
            || !self::fresh($timestamp) || !self::hex($nonce, 32) || !self::hex($signature, 64)
            || !hash_equals(self::signature($path, $timestamp, $nonce, $body, $config['secret']), $signature)
        ) {
            throw new RuntimeException('桥接请求验签失败');
        }
        if (!$claimNonce(hash('sha256', $config['app_id'] . ':' . $nonce), time() + 600)) {
            throw new RuntimeException('桥接请求重复');
        }
    }

    public static function responseHeaders(string $path, string $nonce, int $status, string $body, array $config): array
    {
        if (!self::hex($nonce, 32) || strlen($body) > self::MAX_RESPONSE || strlen($config['secret'] ?? '') < 32) {
            throw new RuntimeException('桥接响应参数无效');
        }
        $timestamp = (string) time();
        return ['X-Bridge-Request-Nonce' => $nonce, 'X-Bridge-Response-Time' => $timestamp,
            'X-Bridge-Response-Signature' => self::responseSignature($path, $nonce, $status, $timestamp, $body, $config)];
    }

    public static function verifyResponse(string $path, string $nonce, int $status, array $headers, string $body, array $config): void
    {
        $timestamp = $headers['x-bridge-response-time'] ?? '';
        $signature = $headers['x-bridge-response-signature'] ?? '';
        if (
            strlen($body) > self::MAX_RESPONSE || strlen($config['secret'] ?? '') < 32
            || !self::hex($nonce, 32) || ($headers['x-bridge-request-nonce'] ?? '') !== $nonce
            || !self::fresh($timestamp) || !self::hex($signature, 64)
            || !hash_equals(self::responseSignature($path, $nonce, $status, $timestamp, $body, $config), $signature)
        ) {
            throw new RuntimeException('桥接响应验签失败');
        }
    }

    private static function responseSignature(string $path, string $nonce, int $status, string $timestamp, string $body, array $config): string
    {
        return hash_hmac('sha256', "RESPONSE\n{$config['app_id']}\n{$path}\n{$nonce}\n{$status}\n{$timestamp}\n"
            . hash('sha256', $body), $config['secret']);
    }

    private static function fresh($timestamp): bool
    {
        return is_string($timestamp) && preg_match('/^[0-9]{10}$/D', $timestamp) === 1
            && abs(time() - (int) $timestamp) <= 300;
    }

    private static function hex($value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && ctype_xdigit($value) && strtolower($value) === $value;
    }

    public static function post(string $base, string $path, array $data, array $config, bool $probe = false): array
    {
        $url = parse_url($base);
        $local = ($url['scheme'] ?? '') === 'http' && in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if (
            !$url || empty($url['host']) || (!$local && ($url['scheme'] ?? '') !== 'https')
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !preg_match('~^/[a-zA-Z0-9/_\-.]+$~D', $path) || strpos($path, '..') !== false
        ) {
            throw new RuntimeException('桥接接口必须使用 HTTPS');
        }
        if (strlen($config['secret'] ?? '') < 32) {
            throw new RuntimeException('尚未配置独立桥接密钥');
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > self::MAX_BODY) {
            throw new RuntimeException('桥接请求体过大');
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $response = '';
        $responseHeaders = [];
        $headerBytes = 0;
        $curl = curl_init(rtrim($base, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_PROTOCOLS => $local ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > self::MAX_BODY) {
                    return 0;
                }
                if (strpos($line, 'HTTP/') === 0) {
                    $responseHeaders = [];
                } elseif (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ',' . trim($value) : trim($value);
                }
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
                'X-Bridge-App: ' . $config['app_id'],
                'X-Bridge-Time: ' . $timestamp,
                'X-Bridge-Nonce: ' . $nonce,
                'X-Bridge-Signature: ' . self::signature($path, $timestamp, $nonce, $body, $config['secret']),
            ],
        ]);
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($success === false) {
            throw new RuntimeException('桥接连接失败或响应超限，请稍后重试');
        }
        self::verifyResponse($path, $nonce, $status, $responseHeaders, $response, $config);
        $result = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        // A read-only invalid-token quote is enough to verify connection identity in both directions.
        if($probe&&is_array($result)&&in_array($status,[200,400,404],true)&&array_key_exists('code',$result))return ['verified'=>true];
        if (!is_array($result) || $status !== 200 || ($result['code'] ?? -1) !== 0) {
            throw new RuntimeException($result['message'] ?? '桥接接口返回异常');
        }
        if (!is_array($result['data'] ?? null)) {
            throw new RuntimeException('桥接响应结构无效');
        }
        return $result['data'];
    }
}
