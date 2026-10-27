<?php

/** 收钱吧小店独立客户端：账号级登录态由旧系统通道配置保存并广播。 */
class shouqianbaxd_client
{
    const WEB_BASE = 'https://web-platforms-msp.shouqianba.com';
    const APP_BASE = 'https://mapi.shouqianba.com';
    const CLIENT_VERSION = '7.3.0';
    const PROXY_CACHE_TTL = 240;
    const PROXY_REFRESH_AHEAD = 15;

    private $config;
    private $runtimePath;
    private $runtimeLockHandle;
    private $proxySettings;

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
        $this->runtimePath = $this->runtimeDirectory() . '/account-' . $this->fingerprint();
        $this->runtimeLockHandle = null;
        $this->proxySettings = null;
    }

    public function fingerprint()
    {
        return hash('sha256', $this->username() . "\0" . md5($this->password()));
    }

    public function getRuntime($forceLogin = false, $failedToken = null)
    {
        if (is_resource($this->runtimeLockHandle)) return $this->getRuntimeUnlocked($forceLogin, $failedToken);
        $dir = $this->runtimeDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('无法创建收钱吧小店运行目录');
        $handle = @fopen($this->runtimePath . '.lock', 'c');
        if ($handle === false || !@flock($handle, LOCK_EX)) throw new RuntimeException('无法锁定收钱吧小店账号登录态');
        $this->runtimeLockHandle = $handle;
        try {
            return $this->getRuntimeUnlocked($forceLogin, $failedToken);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $this->runtimeLockHandle = null;
        }
    }

    private function getRuntimeUnlocked($forceLogin, $failedToken)
    {
        $runtime = $this->readRuntime();
        $fingerprint = $this->fingerprint();
        if (
            $forceLogin && $failedToken !== null && $failedToken !== ''
            && ($runtime['credential_fingerprint'] ?? '') === $fingerprint
            && !empty($runtime['token']) && (string)$runtime['token'] !== (string)$failedToken
            && (int)($runtime['expire_at'] ?? 0) > time() + 60
        ) return $runtime;
        if (
            !$forceLogin && ($runtime['credential_fingerprint'] ?? '') === $fingerprint
            && !empty($runtime['token']) && (int)($runtime['expire_at'] ?? 0) > time() + 60
            && !empty($runtime['merchant_sn']) && !empty($runtime['merchant_user_id'])
        ) return $runtime;
        return $this->login($runtime);
    }

    private function login($previous)
    {
        $deviceId = trim((string)($previous['device_id'] ?? '')) ?: $this->deviceId();
        $body = [
            'username' => $this->username(),
            'password' => md5($this->password()),
            'uc_device' => [
                'device_type' => 2,
                'default_device' => 0,
                'platform' => '商户服务平台',
                'device_fingerprint' => $deviceId,
                'device_name' => '收钱吧商户平台',
                'device_model' => 'Windows',
                'device_brand' => 'Chrome',
            ],
        ];
        $response = $this->requestJson(self::WEB_BASE . '/api/login/ucUser/login', $body, $this->webHeaders(''));
        if (!$this->webSuccess($response)) throw new RuntimeException($this->message($response, '收钱吧账号密码登录失败'));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $tokenInfo = is_array($data['mchUserTokenInfo'] ?? null) ? $data['mchUserTokenInfo'] : [];
        $users = is_array($data['loginUserInfos'] ?? null) ? $data['loginUserInfos'] : [];
        $user = isset($users[0]) && is_array($users[0]) ? $users[0] : [];
        $token = trim((string)($tokenInfo['token'] ?? ''));
        $merchantSn = trim((string)($user['merchant_sn'] ?? ''));
        $merchantUserId = trim((string)($user['merchant_user_id'] ?? ''));
        if ($token === '' || $merchantSn === '' || $merchantUserId === '') throw new RuntimeException('收钱吧登录成功但缺少 Token 或商户身份');
        $expireAt = (int)($tokenInfo['expire_time'] ?? 0);
        if ($expireAt > 20000000000) $expireAt = (int)floor($expireAt / 1000);
        if ($expireAt <= time() + 60) $expireAt = time() + 3540;
        $runtime = [
            'credential_fingerprint' => $this->fingerprint(),
            'token' => $token,
            'expire_at' => $expireAt,
            'valid_time' => (int)($tokenInfo['valid_time'] ?? 0),
            'device_id' => $deviceId,
            'uc_user_id' => (string)($data['uc_user_id'] ?? ''),
            'merchant_sn' => $merchantSn,
            'merchant_user_id' => $merchantUserId,
            'role' => (string)($user['role'] ?? 'super_admin'),
        ];
        $this->writeRuntime($runtime);
        return $runtime;
    }

    public function findTransactions($startTs, $endTs, $runtime = [])
    {
        $runtime = $runtime ?: $this->getRuntime();
        $records = []; $seen = [];
        for ($page = 1; $page <= 10; $page++) {
            $body = [
                'date_end' => (int)$endTs * 1000,
                'date_start' => (int)$startTs * 1000,
                'page' => $page,
                'page_size' => 100,
                'upayQueryType' => 0,
                'show_fund_state' => true,
                'status' => '2000',
                'type' => '30',
            ];
            $response = $this->webAuthenticated('/api/transaction/findTransactions', $body, $runtime);
            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $pageRecords = isset($data['records']) && is_array($data['records']) ? $data['records'] : [];
            foreach ($pageRecords as $record) {
                if (!is_array($record)) continue;
                $key = trim((string)($record['order_sn'] ?? ''));
                if ($key === '') $key = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                if (isset($seen[$key])) continue;
                $seen[$key] = true; $records[] = $record;
            }
            if (count($pageRecords) < 100) break;
        }
        return $records;
    }

    public function createPreOrder($amountCents, $tradeNo, $mallSn, $runtime = [])
    {
        if ((int)$amountCents <= 0 || trim((string)$tradeNo) === '' || trim((string)$mallSn) === '') throw new InvalidArgumentException('收钱吧小店开单参数不完整');
        $runtime = $runtime ?: $this->getRuntime();
        $signature = $this->mallSignature($mallSn, $runtime);
        $scenes = json_encode([[
            'type' => 'textarea', 'fieldName' => '备注', 'isFieldRequired' => false, 'icon' => '',
            'tag' => 'common', 'form' => 'basic', 'label' => 'basic',
            'body' => ['id' => null, 'type' => 'textarea', 'content' => (string)$tradeNo, 'isRequired' => false, 'placeholder' => '请填写'],
            'compType' => 'textarea',
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($scenes === false) throw new RuntimeException('生成收钱吧小店订单备注失败');
        $body = [
            'merchantSn' => $runtime['merchant_sn'],
            'amount' => ['oriAmount' => (int)$amountCents],
            'mallID' => ['mallSn' => (string)$mallSn, 'signature' => $signature],
            'checkout' => ['type' => 2],
            'source' => 1,
            'reflect' => (string)$tradeNo,
            'remark' => (string)$tradeNo,
            'scenes' => $scenes,
        ];
        $response = $this->appAuthenticated('/module/preOrder/createPreOrder', $body, $runtime);
        $payload = $this->appPayload($response, '创建收钱吧小店预订单失败');
        $preOrderId = trim((string)($payload['preOrderId'] ?? ''));
        if ($preOrderId === '') throw new RuntimeException('创建收钱吧小店预订单未返回 preOrderId');
        $query = http_build_query(['msn' => $runtime['merchant_sn'], 'mallSn' => (string)$mallSn, 'signature' => $signature, 'preOrderId' => $preOrderId, 'pageType' => 5], '', '&', PHP_QUERY_RFC3986);
        return [
            'pre_order_id' => $preOrderId,
            'mall_signature' => $signature,
            'merchant_sn' => (string)$runtime['merchant_sn'],
            'pay_url' => 'https://m.sqbe.cn/a0?' . $query,
        ];
    }

    public function mallSignature($mallSn, $runtime = [])
    {
        $mallSn = trim((string)$mallSn);
        $runtime = $runtime ?: $this->getRuntime();
        $response = $this->appAuthenticated('/optimus/mall/pagingMallList', [
            'paging' => ['currentPage' => 1, 'pageSize' => 100],
            'filter' => new stdClass(),
            'sort' => ['sortField' => 'id', 'sort' => 'DESC'],
        ], $runtime);
        $payload = $this->appPayload($response, '获取收钱吧小店列表失败');
        foreach ($payload as $item) {
            if (is_array($item) && (string)($item['mallSn'] ?? '') === $mallSn && trim((string)($item['signature'] ?? '')) !== '') return (string)$item['signature'];
        }
        throw new RuntimeException('当前账号未找到小店编号：' . $mallSn);
    }

    public function refund($order, $refundPassword, $runtime = [])
    {
        $runtime = $runtime ?: $this->getRuntime();
        $payTsn = trim((string)($order['api_trade_no'] ?? ''));
        if ($payTsn === '' || trim((string)$refundPassword) === '') throw new InvalidArgumentException('退款流水号或退款密码不能为空');
        $response = $this->appAuthenticated('/trade/order/list', [
            'filter' => ['payTsn' => $payTsn],
            'cursor' => ['cursorField' => 'order_id', 'count' => 20],
            'sort' => ['sortField' => 'order_id'],
        ], $runtime, '7.0.0');
        $rows = $this->appPayload($response, '查询退款原订单失败');
        $row = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
        $orderSn = trim((string)($row['orderSn'] ?? ''));
        $signature = trim((string)($row['signature'] ?? ''));
        if ($orderSn === '' || $signature === '') throw new RuntimeException('未查询到交易流水号对应的收钱吧订单');

        $response = $this->appAuthenticated('/v5/managerPassword/checkPassword', [
            'merchant_id' => $runtime['merchant_user_id'],
            'password' => (string)$refundPassword,
            'scene' => 'optimus',
        ], $runtime, '7.0.0');
        $passwordData = $this->appPayload($response, '校验收钱吧退款密码失败');
        $passwordToken = trim((string)($passwordData['pwd_token'] ?? ''));
        if ($passwordToken === '') throw new RuntimeException('收钱吧退款密码接口未返回凭证');

        $response = $this->appAuthenticated('/aftersale/operation/applyRefundForSeller', [
            'source' => 'MSP',
            'requestId' => 's-optimus-' . substr(md5(uniqid('', true)), 0, 20),
            'isSkipPermission' => false,
            'orderID' => ['sn' => $orderSn, 'signature' => $signature],
            'refundToken' => $passwordToken,
            'refundInfo' => ['type' => 2, 'applyAmount' => (int)round((float)$order['refundmoney'] * 100), 'refundReason' => '顾客协商退款'],
        ], $runtime, '7.0.0');
        $this->appPayload($response, '收钱吧退款失败');
        return ['code' => 0, 'trade_no' => $orderSn, 'refund_fee' => (float)$order['refundmoney']];
    }

    private function webAuthenticated($path, $body, &$runtime)
    {
        $response = $this->requestJson(self::WEB_BASE . $path . '?client_version=7.0.0&token=' . rawurlencode((string)$runtime['token']), $body, $this->webHeaders($runtime['token']));
        if ($this->authFailure($response)) {
            $runtime = $this->getRuntime(true, (string)$runtime['token']);
            $response = $this->requestJson(self::WEB_BASE . $path . '?client_version=7.0.0&token=' . rawurlencode((string)$runtime['token']), $body, $this->webHeaders($runtime['token']));
        }
        if (!$this->webSuccess($response)) throw new RuntimeException($this->message($response, '收钱吧商户平台接口请求失败'));
        return $response;
    }

    private function appAuthenticated($path, $body, $runtime, $version = null)
    {
        $version = $version ?: self::CLIENT_VERSION;
        $url = self::APP_BASE . $path . '?client_version=' . rawurlencode($version) . '&token=' . rawurlencode((string)$runtime['token']);
        $response = $this->requestJson($url, $body, $this->appHeaders());
        if ($this->authFailure($response)) {
            $runtime = $this->getRuntime(true, (string)$runtime['token']);
            $url = self::APP_BASE . $path . '?client_version=' . rawurlencode($version) . '&token=' . rawurlencode((string)$runtime['token']);
            $response = $this->requestJson($url, $body, $this->appHeaders());
        }
        return $response;
    }

    private function appPayload($response, $fallback)
    {
        if ((string)($response['code'] ?? '') !== '10000') throw new RuntimeException($this->message($response, $fallback));
        $data = $response['data'] ?? [];
        if (!is_array($data)) throw new RuntimeException($fallback . '：返回格式错误');
        if (isset($data['code']) && (string)$data['code'] !== '0000') throw new RuntimeException($this->message($data, $fallback));
        return isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    }

    private function requestJson($url, $body, $headers)
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) throw new RuntimeException('生成收钱吧请求数据失败');
        $raw = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $proxy = null;
            try {
                $proxy = $this->proxySettings();
                $raw = $this->curl($url, $payload, $headers, $proxy);
                break;
            } catch (Throwable $e) {
                if (!$this->isTransientRequestFailure($e) || $attempt >= 3) {
                    $suffix = $attempt > 1 ? '（已自动重试' . ($attempt - 1) . '次）' : '';
                    throw new RuntimeException($e->getMessage() . $suffix, 0, $e);
                }
                if ($this->proxyMode() === 'api') {
                    $this->invalidateProxyCache();
                    $this->proxySettings = null;
                }
                usleep($attempt * 250000);
            }
        }
        if (!is_array($raw)) throw new RuntimeException('收钱吧网络请求失败：未取得响应');
        $response = json_decode($raw['body'], true);
        if (!is_array($response)) throw new RuntimeException('收钱吧接口返回不是 JSON');
        $response['_http'] = $raw['http'];
        return $response;
    }

    private function isTransientRequestFailure($error)
    {
        $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
        return strpos($message, '网络请求失败') !== false
            || strpos($message, '代理 API 请求失败') !== false
            || strpos($message, 'HTTP 状态异常：5') !== false;
    }

    private function curl($url, $payload, $headers, $proxy)
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('无法初始化收钱吧网络请求');
        $options = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (is_array($proxy)) {
            $host = (string)$proxy['host'];
            if (strpos($host, ':') !== false && strpos($host, '[') !== 0) $host = '[' . $host . ']';
            $options[CURLOPT_PROXY] = $host . ':' . (int)$proxy['port'];
            $options[CURLOPT_PROXYTYPE] = $this->curlProxyType($proxy['type'] ?? 'http');
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
            if (($proxy['username'] ?? '') !== '') $options[CURLOPT_PROXYUSERPWD] = (string)$proxy['username'] . ':' . (string)($proxy['password'] ?? '');
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            $target = (string)(parse_url($url, PHP_URL_HOST) ?: '未知主机') . (string)(parse_url($url, PHP_URL_PATH) ?: '/');
            $route = is_array($proxy) ? '代理' : '直连';
            throw new RuntimeException('收钱吧网络请求失败（' . $target . '，' . $route . '）：' . ($error ?: 'unknown'));
        }
        if ($http >= 500) throw new RuntimeException('收钱吧 HTTP 状态异常：' . $http);
        return ['body' => (string)$body, 'http' => $http];
    }

    private function webHeaders($token)
    {
        $headers = ['Origin: https://s.shouqianba.com/', 'Referer: https://s.shouqianba.com/', 'Content-Type: application/json; charset=UTF-8', 'Accept: application/json', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'];
        if ((string)$token !== '') $headers[] = 'Authorization: Bearer ' . (string)$token;
        return $headers;
    }

    private function appHeaders()
    {
        return ['Origin: https://app-optimus.shouqianba.com', 'Referer: https://app-optimus.shouqianba.com/', 'X-Requested-With: com.wosai.cashbar', 'Content-Type: application/json; charset=UTF-8', 'Accept: application/json', 'User-Agent: Mozilla/5.0 (Linux; Android 16; epay_xd) AppleWebKit/537.36 Chrome/150 Mobile Safari/537.36'];
    }

    private function webSuccess($response) { return (string)($response['code'] ?? '') === '50000'; }

    private function authFailure($response)
    {
        $http = (int)($response['_http'] ?? 0);
        if ($http === 401 || $http === 403) return true;
        $text = strtolower(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        foreach (['token invalid', 'token expired', 'invalid token', 'unauthorized', '登录态失效', '登录已失效', '登录过期', '请重新登录', '未登录', '令牌失效', '令牌过期'] as $needle) if (strpos($text, strtolower($needle)) !== false) return true;
        $code = (string)($response['code'] ?? $response['data']['code'] ?? '');
        return in_array($code, ['401', '403', '50201'], true);
    }

    private function message($value, $fallback)
    {
        if (is_array($value)) {
            foreach (['msg', 'message', 'errorMessage', 'error_msg'] as $key) if (isset($value[$key]) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') return trim((string)$value[$key]);
            foreach ($value as $child) if (is_array($child)) { $message = $this->message($child, ''); if ($message !== '') return $message; }
        }
        return (string)$fallback;
    }

    private function proxySettings()
    {
        $mode = $this->proxyMode();
        if ($mode === 'no_proxy') return null;
        if ($mode === 'custom_proxy') {
            if (is_array($this->proxySettings)) return $this->proxySettings;
            $proxy = $this->parseProxyLine(trim((string)($this->config['proxy_custom'] ?? '')), 'http');
            if ($proxy === null) throw new RuntimeException('自定义代理地址格式错误');
            return $this->proxySettings = $proxy;
        }
        $api = trim((string)($this->config['proxy_api'] ?? ''));
        if (!filter_var($api, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($api, PHP_URL_SCHEME)), ['http', 'https'], true)) throw new RuntimeException('熊猫代理 API 地址无效');
        if (is_array($this->proxySettings) && (int)($this->proxySettings['_expire_at'] ?? 0) > time() + self::PROXY_REFRESH_AHEAD) return $this->proxySettings;
        $path = $this->runtimeDirectory() . '/proxy-' . substr(hash('sha256', $this->fingerprint() . "\0" . $api), 0, 32) . '.json';
        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false || !@flock($handle, LOCK_EX)) throw new RuntimeException('无法锁定收钱吧代理缓存');
        try {
            $cached = $this->readJson($path);
            if (($cached['api_fingerprint'] ?? '') === hash('sha256', $api) && is_array($cached['proxy'] ?? null) && (int)($cached['expire_at'] ?? 0) > time() + self::PROXY_REFRESH_AHEAD) {
                $proxy = $cached['proxy']; $proxy['_expire_at'] = (int)$cached['expire_at']; return $this->proxySettings = $proxy;
            }
            $proxy = $this->fetchProxy($api);
            $expires = time() + $this->proxyTtl($api);
            $this->writeJson($path, ['api_fingerprint' => hash('sha256', $api), 'expire_at' => $expires, 'proxy' => $proxy]);
            $proxy['_expire_at'] = $expires;
            return $this->proxySettings = $proxy;
        } finally { @flock($handle, LOCK_UN); @fclose($handle); }
    }

    private function fetchProxy($api)
    {
        $ch = curl_init($api);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Accept: text/plain, application/json', 'User-Agent: epay-shouqianbaxd/1.0']]);
        $raw = curl_exec($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false) throw new RuntimeException('熊猫代理 API 请求失败：' . ($error ?: 'unknown'));
        if ($http >= 400) throw new RuntimeException('熊猫代理 API HTTP 状态异常：' . $http);
        $type = $this->proxyTypeFromApi($api);
        $json = json_decode((string)$raw, true);
        $proxy = is_array($json) ? $this->findProxy($json, $type) : null;
        if ($proxy === null) foreach (preg_split('/\R+/', trim((string)$raw)) ?: [] as $line) { $proxy = $this->parseProxyLine($line, $type); if ($proxy !== null) break; }
        if ($proxy === null) throw new RuntimeException('熊猫代理 API 未返回有效代理地址');
        return $proxy;
    }

    private function findProxy($value, $type)
    {
        $host = '';
        foreach (['ip','host','proxy_ip','proxyIp','server'] as $key) if (isset($value[$key]) && is_scalar($value[$key])) { $host = trim((string)$value[$key]); break; }
        $port = 0;
        foreach (['port','proxy_port','proxyPort'] as $key) if (isset($value[$key]) && is_numeric((string)$value[$key])) { $port = (int)$value[$key]; break; }
        if ($host !== '' && $port > 0) return $this->buildProxy($host, $port, (string)($value['username'] ?? $value['user'] ?? $value['account'] ?? ''), (string)($value['password'] ?? $value['pass'] ?? ''), $type);
        foreach ($value as $child) {
            $proxy = is_array($child) ? $this->findProxy($child, $type) : (is_string($child) ? $this->parseProxyLine($child, $type) : null);
            if ($proxy !== null) return $proxy;
        }
        return null;
    }

    private function parseProxyLine($line, $defaultType)
    {
        $line = trim((string)$line, " \t\r\n\"'");
        if ($line === '') return null;
        if (preg_match('/^(https?|socks5h?|socks4a?):\/\//i', $line)) {
            $parts = parse_url($line);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) return null;
            $scheme = strtolower((string)($parts['scheme'] ?? $defaultType));
            return $this->buildProxy($parts['host'], (int)$parts['port'], rawurldecode((string)($parts['user'] ?? '')), rawurldecode((string)($parts['pass'] ?? '')), strpos($scheme, 'socks') === 0 ? $scheme : 'http');
        }
        if (preg_match('/^([^@\s]+)@([^\s]+)$/', $line, $match)) {
            $auth = explode(':', $match[1], 2); $proxy = $this->parseProxyLine($match[2], $defaultType);
            if ($proxy !== null) { $proxy['username'] = rawurldecode($auth[0]); $proxy['password'] = rawurldecode($auth[1] ?? ''); }
            return $proxy;
        }
        if (preg_match('/^\[([^\]]+)\]:(\d+)(?::([^:]*):(.*))?$/', $line, $match)) return $this->buildProxy($match[1], (int)$match[2], $match[3] ?? '', $match[4] ?? '', $defaultType);
        if (preg_match('/^([^:\s]+):(\d+)(?::([^:]*):(.*))?$/', $line, $match)) return $this->buildProxy($match[1], (int)$match[2], $match[3] ?? '', $match[4] ?? '', $defaultType);
        return null;
    }

    private function buildProxy($host, $port, $username, $password, $type)
    {
        $host = trim((string)$host); $port = (int)$port;
        if ($host === '' || $port < 1 || $port > 65535 || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host))) return null;
        return ['host' => $host, 'port' => $port, 'username' => (string)$username, 'password' => (string)$password, 'type' => (string)$type];
    }

    private function proxyMode()
    {
        $mode = trim((string)($this->config['proxy_mode'] ?? 'no_proxy')) ?: 'no_proxy';
        if (!in_array($mode, ['no_proxy','custom_proxy','api'], true)) throw new RuntimeException('未知代理模式：' . $mode);
        return $mode;
    }

    private function proxyTypeFromApi($api)
    {
        $query = []; parse_str((string)(parse_url($api, PHP_URL_QUERY) ?? ''), $query); $type = strtolower(trim((string)($query['proxyType'] ?? '1')));
        if (in_array($type, ['2','socks5','socks5h'], true)) return 'socks5h';
        if (in_array($type, ['3','socks4','socks4a'], true)) return 'socks4a';
        return 'http';
    }

    private function curlProxyType($type)
    {
        $type = strtolower((string)$type);
        if (in_array($type, ['socks5','socks5h'], true)) return defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
        if (in_array($type, ['socks4','socks4a'], true)) return defined('CURLPROXY_SOCKS4A') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4;
        return CURLPROXY_HTTP;
    }

    private function proxyTtl($api)
    {
        $query = []; parse_str((string)(parse_url($api, PHP_URL_QUERY) ?? ''), $query); $value = (int)($query['validTime'] ?? 0);
        return $value > 0 ? max(60, min(3600, $value)) : self::PROXY_CACHE_TTL;
    }

    private function invalidateProxyCache()
    {
        $api = trim((string)($this->config['proxy_api'] ?? ''));
        if ($api !== '') @unlink($this->runtimeDirectory() . '/proxy-' . substr(hash('sha256', $this->fingerprint() . "\0" . $api), 0, 32) . '.json');
    }

    private function readJson($path)
    {
        $raw = is_file($path) ? @file_get_contents($path) : ''; $data = is_string($raw) ? json_decode($raw, true) : [];
        return is_array($data) ? $data : [];
    }

    private function writeJson($path, $data)
    {
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $tmp = $path . '.tmp.' . getmypid();
        if ($raw === false || @file_put_contents($tmp, $raw, LOCK_EX) === false || !@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('无法保存收钱吧代理缓存'); }
        @chmod($path, 0600);
    }

    private function readRuntime()
    {
        if (!empty($this->config['runtime_loader']) && is_callable($this->config['runtime_loader'])) {
            $runtime = call_user_func($this->config['runtime_loader']); return is_array($runtime) ? $runtime : [];
        }
        return [];
    }

    private function writeRuntime($runtime)
    {
        if (empty($this->config['runtime_saver']) || !is_callable($this->config['runtime_saver']) || call_user_func($this->config['runtime_saver'], $runtime) === false) throw new RuntimeException('无法广播保存收钱吧小店登录态');
    }

    private function username()
    {
        $value = trim((string)($this->config['username'] ?? ''));
        if ($value === '') throw new InvalidArgumentException('未配置收钱吧登录账号');
        return $value;
    }

    private function password()
    {
        $value = (string)($this->config['password'] ?? '');
        if ($value === '') throw new InvalidArgumentException('未配置收钱吧登录密码');
        return $value;
    }

    private function deviceId()
    {
        $hex = substr(hash('sha256', 'shouqianbaxd|' . $this->fingerprint()), 0, 32);
        return substr($hex,0,8) . '-' . substr($hex,8,4) . '-4' . substr($hex,13,3) . '-a' . substr($hex,17,3) . '-' . substr($hex,20,12);
    }

    private function runtimeDirectory() { return \lib\Task\PluginRuntime::directory('shouqianbaxd'); }
}
