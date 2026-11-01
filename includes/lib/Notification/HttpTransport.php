<?php
namespace lib\Notification;

/** Only the fixed acknowledgement is persisted; signed URLs and response bodies stay out of logs. */
final class HttpTransport
{
    public function send($url, array $config)
    {
        $post = null;
        if (($config['proxy'] ?? 0) == 2) {
            if (empty($config['proxy_apiurl']) || empty($config['proxy_apikey'])) {
                return self::failure('proxy_config', '通知代理配置不完整');
            }
            $encoded = base64_encode($url);
            $timestamp = (string)time();
            $post = http_build_query(['url'=>$encoded, 'timestamp'=>$timestamp,
                'sign'=>md5($encoded.$timestamp.$config['proxy_apikey'])]);
            $url = $config['proxy_apiurl'];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return self::failure('invalid_url', '通知地址必须是有效的 HTTP/HTTPS 地址');
        }
        $ch = curl_init($url);
        $body = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>5,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'Epay-Notification/1.0', CURLOPT_HTTPHEADER=>['Accept: */*','Connection: close'],
            CURLOPT_WRITEFUNCTION=>static function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk;
                return strlen($chunk);
            }]);
        if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post]);
        if (($config['proxy'] ?? 0) == 1) {
            $types = ['http'=>CURLPROXY_HTTP, 'https'=>CURLPROXY_HTTPS, 'sock4'=>CURLPROXY_SOCKS4,
                'sock5'=>CURLPROXY_SOCKS5, 'sock5h'=>CURLPROXY_SOCKS5_HOSTNAME];
            if (empty($config['proxy_server']) || empty($config['proxy_port']) || !isset($types[$config['proxy_type'] ?? ''])) {
                curl_close($ch);
                return self::failure('proxy_config', '通知代理配置不完整');
            }
            curl_setopt_array($ch, [CURLOPT_PROXY=>$config['proxy_server'], CURLOPT_PROXYPORT=>(int)$config['proxy_port'],
                CURLOPT_PROXYTYPE=>$types[$config['proxy_type']]]);
            if (!empty($config['proxy_user'])) curl_setopt($ch, CURLOPT_PROXYUSERPWD, $config['proxy_user'].':'.($config['proxy_pwd'] ?? ''));
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $elapsed = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        curl_close($ch);
        if ($ok === false) {
            $result = self::failure($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport',
                $errno === CURLE_OPERATION_TIMEDOUT ? '通知请求超时，接收结果未确认' : '通知连接失败（网络错误 '.$errno.'）');
        } elseif ($http < 200 || $http >= 300) {
            $result = self::failure('http_status', '商户接口返回 HTTP '.$http);
        } elseif (strcasecmp(trim($body), 'success') !== 0) {
            $result = self::failure('acknowledgement', '商户接口未返回 success');
        } else {
            $result = ['delivered'=>true, 'error_code'=>null, 'error'=>null];
        }
        return $result + ['http_status'=>$http ?: null, 'duration_ms'=>$elapsed];
    }

    private static function failure($code, $message)
    {
        return ['delivered'=>false, 'error_code'=>$code, 'error'=>$message];
    }
}
