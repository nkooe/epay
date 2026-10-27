<?php
namespace lib\Complaint;

/** HTTPS transport for complaint APIs only; payment plugin transports are untouched. */
final class Transport
{
    public static function request($method, $url, array $headers, $body = null)
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', ['api.mch.weixin.qq.com', 'openapi.alipay.com'], true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \RuntimeException('投诉接口地址不正确');
        }
        $response = ''; $responseHeaders = ''; $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>25, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>static function ($ch, $line) use (&$responseHeaders) { $responseHeaders .= $line; return strlen($line); },
            CURLOPT_WRITEFUNCTION=>static function ($ch, $bytes) use (&$response, &$tooLarge) {
                if (strlen($response) + strlen($bytes) > 6291456) { $tooLarge = true; return 0; }
                $response .= $bytes; return strlen($bytes);
            }]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl);$errno=curl_errno($curl); curl_close($curl);
        if ($ok === false) {
            if(!$tooLarge&&in_array($errno,[5,6,7,18,28,52,55,56],true))throw new TransientFailure('投诉接口网络暂时不可用，请稍后重试');
            throw new \RuntimeException($tooLarge ? '投诉接口响应过大' : '投诉接口网络请求失败：'.$error);
        }
        if($status===429||$status>=500)throw new TransientFailure('投诉接口暂时繁忙，请稍后重试');
        return [$status, $responseHeaders, $response];
    }
}
