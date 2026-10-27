<?php

/**
 * 收钱吧 App API 客户端。
 *
 * 登录态由旧系统通道配置保存并按账号凭据共享；runtime 目录只放并发锁和代理缓存。
 * 订单扩展数据不保存账号、密码或 Token。
 */
class shouqianbapro_client
{
    private const API_BASE = 'https://mapi.shouqianba.com';
    private const CLIENT_VERSION = '7.3.0';
    private const TOKEN_REFRESH_AHEAD = 43200;
    private const PROXY_CACHE_TTL = 240;
    private const PROXY_REFRESH_AHEAD = 15;

    private $config;
    private $runtimePath;
    private $transport;
    private $runtimeLockHandle = null;
    private $deviceIdOverride = null;
    private $proxySettings = null;

    public function __construct(array $config, ?callable $transport = null, ?string $runtimePath = null)
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->runtimePath = $runtimePath ?: $this->defaultRuntimePath();
    }

    public function getRuntime(bool $forceLogin = false, ?string $failedToken = null): array
    {
        if (is_resource($this->runtimeLockHandle)) {
            return $this->getRuntimeUnlocked($forceLogin, $failedToken);
        }

        $dir = dirname($this->runtimePath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建收钱吧运行目录');
        }

        $lockPath = $this->runtimePath . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException('无法锁定收钱吧账号运行态');
        }
        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            throw new RuntimeException('无法获取收钱吧账号运行态锁');
        }

        $this->runtimeLockHandle = $handle;
        try {
            return $this->getRuntimeUnlocked($forceLogin, $failedToken);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $this->runtimeLockHandle = null;
        }
    }

    private function getRuntimeUnlocked(bool $forceLogin, ?string $failedToken = null): array
    {
        $username = $this->username();
        $passwordMd5 = md5($this->password());
        $fingerprint = hash('sha256', $username . "\0" . $passwordMd5);
        $runtime = $this->readRuntime();

        if (!empty($runtime['device_id'])) {
            $this->deviceIdOverride = trim((string)$runtime['device_id']) ?: null;
        }

        if (
            $forceLogin
            && $failedToken !== null
            && $failedToken !== ''
            && ($runtime['credential_fingerprint'] ?? '') === $fingerprint
            && !empty($runtime['token'])
            && (string)$runtime['token'] !== $failedToken
            && (int)($runtime['expire_at'] ?? 0) > time() + 60
        ) {
            return $runtime;
        }

        if (
            !$forceLogin
            && ($runtime['credential_fingerprint'] ?? '') === $fingerprint
            && !empty($runtime['token'])
            && (int)($runtime['expire_at'] ?? 0) > time() + self::TOKEN_REFRESH_AHEAD
            && !empty($runtime['merchant_id'])
            && !empty($runtime['store_id'])
            && !empty($runtime['mall_sn'])
        ) {
            return $runtime;
        }

        if (
            !$forceLogin
            && ($runtime['credential_fingerprint'] ?? '') === $fingerprint
            && !empty($runtime['token'])
            && (int)($runtime['expire_at'] ?? 0) > time() + 60
        ) {
            try {
                $refreshed = $this->refreshToken($runtime);
                if ($refreshed !== null) {
                    return $refreshed;
                }
            } catch (\Throwable $e) {
                // 续期失败直接走账号密码重新登录。
            }
        }

        return $this->loginAndLoadContext($fingerprint, $passwordMd5);
    }

    public function accountBookRecords(int $startMs, int $endMs, array $runtime = []): array
    {
        $runtime = $runtime ?: $this->getRuntime();
        $params = [
            'merchant_user_id' => $runtime['merchant_user_id'],
            'account_id' => $runtime['uc_user_id'],
            'role' => $runtime['role'] ?: 'super_admin',
            'device_model' => '1',
            'operator_id' => $runtime['merchant_user_id'],
            'os_type' => '0',
            'merchant_id' => $runtime['merchant_id'],
            'real_store_id' => $runtime['store_id'],
            'client_version' => self::CLIENT_VERSION,
            'uc_user_id' => $runtime['uc_user_id'],
            'deviceId' => $runtime['device_id'],
            'token' => $runtime['token'],
            'lastRecordTime' => $endMs,
            'startTime' => $startMs,
            'endTime' => $endMs,
            'upayQueryType' => '0',
            'isConditionQuery' => '0',
            'permissionType' => '1',
            'offsetHour' => '0',
        ];

        $response = $this->authenticatedRequest(
            'POST',
            '/v4/accountBook/getAccountBookRecords',
            [],
            $params,
            false,
            $runtime
        );

        $groups = $this->findFirst($response, ['data']);
        if (is_array($groups) && isset($groups['data']) && is_array($groups['data'])) {
            $groups = $groups['data'];
        }

        $transactions = [];
        if (is_array($groups)) {
            foreach ($groups as $group) {
                if (!is_array($group) || !isset($group['transactions']) || !is_array($group['transactions'])) {
                    continue;
                }
                foreach ($group['transactions'] as $transaction) {
                    if (is_array($transaction)) {
                        $transactions[] = $transaction;
                    }
                }
            }
        }

        return [
            'transactions' => $transactions,
            'last_record_time' => (int)($this->findFirst($response, ['lastRecordTime']) ?? 0),
        ];
    }

    public function createPreOrder(int $amountCents, string $tradeNo, array $runtime = []): string
    {
        if ($amountCents <= 0) {
            throw new RuntimeException('收钱吧订单金额无效');
        }

        $runtime = $runtime ?: $this->getRuntime();
        $scenes = json_encode([
            [
                'type' => 'textarea',
                'fieldName' => '备注',
                'isFieldRequired' => false,
                'icon' => '',
                'tag' => 'common',
                'form' => 'basic',
                'label' => 'basic',
                'body' => [
                    'id' => null,
                    'type' => 'textarea',
                    'content' => $tradeNo,
                    'isRequired' => false,
                    'pattern' => [
                        'regexp' => '',
                        'msg' => '',
                        'type' => null,
                    ],
                    'placeholder' => '',
                    'isSearch' => null,
                    'patternList' => null,
                    'readonly' => true,
                ],
                'compType' => 'textarea',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($scenes === false) {
            throw new RuntimeException('生成收钱吧订单备注失败');
        }

        $response = $this->authenticatedRequest(
            'POST',
            '/module/preOrder/createPreOrder',
            [],
            [
                'amount' => ['oriAmount' => $amountCents],
                'checkout' => ['type' => 2],
                'mallID' => [
                    'mallSn' => $runtime['mall_sn'],
                    'signature' => $runtime['mall_signature'],
                ],
                'scenes' => $scenes,
                'source' => 0,
            ],
            true,
            $runtime,
            true
        );

        $preOrderId = (string)($this->findFirst($response, ['preOrderId']) ?? '');
        if ($preOrderId === '') {
            throw new RuntimeException('收钱吧创建预订单未返回 preOrderId');
        }
        return $preOrderId;
    }

    public function generateH5Link(string $preOrderId, array $runtime = []): string
    {
        $runtime = $runtime ?: $this->getRuntime();
        $response = $this->authenticatedRequest(
            'POST',
            '/module/preOrder/generatePreOrderH5Link',
            [],
            [
                'preOrderId' => $preOrderId,
                'pageType' => 5,
            ],
            true,
            $runtime
        );
        $link = (string)($this->findFirst($response, ['preOrderH5Link']) ?? '');
        if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('收钱吧未返回有效支付链接');
        }
        return $link;
    }

    public function queryPreOrder(string $preOrderId, array $runtime = []): array
    {
        $runtime = $runtime ?: $this->getRuntime();
        $response = $this->authenticatedRequest(
            'POST',
            '/module/preOrder/queryPreOrderForSeller',
            [],
            ['preOrderId' => $preOrderId],
            true,
            $runtime
        );
        $data = $this->findFirst($response, ['data']);
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (!is_array($data)) {
            throw new RuntimeException('收钱吧预订单查询返回格式错误');
        }
        return $data;
    }

    public function orderDetail(string $orderSn, string $orderSignature, array $runtime = []): array
    {
        $runtime = $runtime ?: $this->getRuntime();
        $response = $this->authenticatedRequest(
            'POST',
            '/trade/order/detail',
            [],
            [
                'orderID' => [
                    'sn' => $orderSn,
                    'signature' => $orderSignature,
                ],
            ],
            true,
            $runtime
        );
        $data = $this->findFirst($response, ['data']);
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (!is_array($data)) {
            throw new RuntimeException('收钱吧订单详情返回格式错误');
        }
        return $data;
    }

    public function getTransactionDetail(string $transactionId, int $ctime, array $runtime = []): array
    {
        $runtime = $runtime ?: $this->getRuntime();
        $response = $this->authenticatedRequest(
            'POST',
            '/v4/accountBook/getTransactionDetailById',
            [],
            [
                'token' => $runtime['token'],
                'ctime' => $ctime,
                'transactionId' => $transactionId,
                'upayQueryType' => 0,
                'merchantIdForGroupQuery' => $runtime['merchant_id'],
            ],
            true,
            $runtime
        );
        $data = $this->findFirst($response, ['data']);
        return is_array($data) ? $data : [];
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->username() . "\0" . md5($this->password()));
    }

    private function loginAndLoadContext(string $fingerprint, string $passwordMd5): array
    {
        $deviceId = $this->deviceId();
        $response = $this->request(
            'POST',
            '/v6/appUser/loginWithPassword',
            [
                'device_model' => '1',
                'client_version' => self::CLIENT_VERSION,
                'deviceId' => $deviceId,
                'os_type' => '0',
            ],
            [
                'ip' => $this->clientIp(),
                'password' => $passwordMd5,
                'uc_device' => [
                    'device_brand' => 'Android',
                    'device_fingerprint' => $deviceId,
                    'device_model' => 'epay_pro',
                    'device_name' => 'epay_pro',
                    'device_type' => 1,
                    'platform' => '收钱吧APP',
                ],
                'username' => $this->username(),
            ],
            true
        );

        $tokenInfo = $this->findFirst($response, ['mchUserTokenInfo']);
        $loginUsers = $this->findFirst($response, ['loginUserInfos']);
        if (!is_array($tokenInfo) || empty($tokenInfo['token']) || !is_array($loginUsers) || empty($loginUsers[0])) {
            throw new RuntimeException($this->apiMessage($response, '收钱吧账号密码登录失败'));
        }

        $loginUser = $loginUsers[0];
        $runtime = [
            'credential_fingerprint' => $fingerprint,
            'token' => (string)$tokenInfo['token'],
            'expire_at' => (int)floor(((int)($tokenInfo['expire_time'] ?? 0)) / 1000),
            'valid_time' => (int)($tokenInfo['valid_time'] ?? 0),
            'device_id' => $deviceId,
            'uc_user_id' => (string)($response['data']['uc_user_id'] ?? $response['uc_user_id'] ?? ''),
            'merchant_user_id' => (string)($loginUser['merchant_user_id'] ?? ''),
            'role' => (string)($loginUser['role'] ?? 'super_admin'),
            'merchant_sn' => (string)($loginUser['merchant_sn'] ?? ''),
        ];

        if ($runtime['uc_user_id'] === '' || $runtime['merchant_user_id'] === '') {
            throw new RuntimeException('收钱吧登录成功但缺少商户身份信息');
        }

        $account = $this->account($runtime);
        $admin = is_array($account['admin'] ?? null) ? $account['admin'] : [];
        $merchant = is_array($account['merchant'] ?? null) ? $account['merchant'] : [];
        $stores = is_array($account['store_list'] ?? null) ? $account['store_list'] : [];
        $store = $stores[0] ?? [];

        $runtime['merchant_id'] = (string)($merchant['id'] ?? $admin['merchant_id'] ?? '');
        $runtime['merchant_sn'] = (string)($merchant['sn'] ?? $runtime['merchant_sn']);
        $runtime['store_id'] = (string)($store['storeId'] ?? $admin['store_id'] ?? '');
        $runtime['store_sn'] = (string)($store['sn'] ?? '');
        if ($runtime['store_id'] === '' || $runtime['store_sn'] === '' || $runtime['merchant_id'] === '') {
            throw new RuntimeException('收钱吧账户信息缺少商户或门店');
        }

        $mall = $this->mall($runtime);
        $runtime['mall_sn'] = (string)($mall['mallSn'] ?? '');
        $runtime['mall_signature'] = (string)($mall['signature'] ?? '');
        if ($runtime['mall_sn'] === '' || $runtime['mall_signature'] === '') {
            throw new RuntimeException('收钱吧未找到可用的开单商城');
        }

        $this->writeRuntime($runtime);
        return $runtime;
    }

    private function account(array $runtime): array
    {
        $response = $this->authenticatedRequest(
            'GET',
            '/V2/Account/get',
            [],
            [],
            false,
            $runtime
        );
        $data = $this->findFirst($response, ['data']);
        return is_array($data) ? $data : [];
    }

    private function mall(array $runtime): array
    {
        $response = $this->authenticatedRequest(
            'POST',
            '/optimus/mall/pagingMallList',
            [],
            [
                'filter' => [
                    'industryCode' => 'product_sales',
                    'appType' => 0,
                    'seller' => ['storeSnList' => [$runtime['store_sn']]],
                    'tags' => ['preOrder', 'onlineStore', 'amount'],
                ],
                'sort' => [
                    'sortFields' => [
                        ['sortField' => 'TOP_TIME', 'sort' => 'DESC'],
                        ['sortField' => 'ID', 'sort' => 'DESC'],
                    ],
                ],
                'paging' => ['currentPage' => 1, 'pageSize' => 10],
            ],
            true,
            $runtime
        );
        $data = $this->findFirst($response, ['data']);
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (!is_array($data)) {
            return [];
        }
        foreach ($data as $mall) {
            if (
                is_array($mall)
                && (int)($mall['state'] ?? 0) === 1
                && (int)($mall['isDelete'] ?? 0) === 0
                && !empty($mall['mallSn'])
                && !empty($mall['signature'])
            ) {
                return $mall;
            }
        }
        return [];
    }

    private function refreshToken(array $runtime): ?array
    {
        $response = $this->request(
            'GET',
            '/v5/ucToken/refreshToken',
            $this->authQuery($runtime),
            [],
            false
        );
        $tokenInfo = $this->findFirst($response, ['mchUserTokenInfo']);
        if (!is_array($tokenInfo) || empty($tokenInfo['token'])) {
            return null;
        }
        $runtime['token'] = (string)$tokenInfo['token'];
        $runtime['expire_at'] = (int)floor(((int)($tokenInfo['expire_time'] ?? 0)) / 1000);
        $runtime['valid_time'] = (int)($tokenInfo['valid_time'] ?? 0);
        $this->writeRuntime($runtime);
        return $runtime;
    }

    private function authenticatedRequest(
        string $method,
        string $path,
        array $query,
        array $body,
        bool $json,
        array $runtime,
        bool $retryAuth = true
    ): array {
        try {
            $requestQuery = array_merge($query, $this->authQuery($runtime));
            return $this->request($method, $path, $requestQuery, $body, $json);
        } catch (\Throwable $e) {
            if (!$retryAuth || !$this->looksLikeAuthFailure($e->getMessage())) {
                throw $e;
            }
            $fresh = $this->getRuntime(true, (string)($runtime['token'] ?? ''));
            $requestQuery = array_merge($query, $this->authQuery($fresh));
            return $this->request($method, $path, $requestQuery, $body, $json);
        }
    }

    private function request(string $method, string $path, array $query = [], array $body = [], bool $json = true): array
    {
        $url = self::API_BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . ($json ? 'Mozilla/5.0 (Linux; Android 13; epay_pro) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36' : 'okhttp/4.8.1'),
            'device_id: ' . $this->deviceId(),
            'device_brand: Android',
            'device_type: epay_pro',
        ];
        if ($json) {
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
            $headers[] = 'X-Requested-With: com.wosai.cashbar';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $payload = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        }
        if ($payload === false) {
            throw new RuntimeException('生成收钱吧请求体失败');
        }

        if ($this->transport !== null) {
            $raw = ($this->transport)($method, $url, $headers, $payload);
        } else {
            $proxy = $this->proxySettings();
            try {
                $raw = $this->curlRequest($method, $url, $headers, $payload, $proxy);
            } catch (\Throwable $e) {
                if ($proxy === null || $this->proxyMode() !== 'api' || strpos($e->getMessage(), '网络请求失败') === false) {
                    throw $e;
                }

                // 动态代理失效时换一次出口，避免整个登录或查单流程直接中断。
                $this->invalidateProxyCache();
                $this->proxySettings = null;
                $raw = $this->curlRequest($method, $url, $headers, $payload, $this->proxySettings());
            }
        }
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('收钱吧接口返回为空');
        }
        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RuntimeException('收钱吧接口返回不是 JSON');
        }

        $code = (string)($response['code'] ?? $response['data']['code'] ?? '');
        if ($code !== '' && !in_array($code, ['0000', '10000', '0'], true)) {
            throw new RuntimeException($this->apiMessage($response, '收钱吧接口请求失败') . ' [code=' . $code . ']');
        }
        if (($response['success'] ?? true) === false || ($response['data']['success'] ?? true) === false) {
            throw new RuntimeException($this->apiMessage($response, '收钱吧接口处理失败'));
        }
        return $response;
    }

    private function curlRequest(string $method, string $url, array $headers, string $payload, ?array $proxy = null): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('无法初始化收钱吧 HTTP 客户端');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : $payload,
        ];
        if ($proxy !== null) {
            $proxyHost = (string)$proxy['host'];
            if (strpos($proxyHost, ':') !== false && strpos($proxyHost, '[') !== 0) {
                $proxyHost = '[' . $proxyHost . ']';
            }
            $options[CURLOPT_PROXY] = $proxyHost . ':' . $proxy['port'];
            $options[CURLOPT_PROXYTYPE] = $this->curlProxyType((string)($proxy['type'] ?? 'http'));
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
            if (($proxy['username'] ?? '') !== '') {
                $options[CURLOPT_PROXYUSERPWD] = (string)$proxy['username'] . ':' . (string)($proxy['password'] ?? '');
            }
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('收钱吧网络请求失败：' . ($error ?: 'unknown'));
        }
        if ($status >= 400) {
            throw new RuntimeException('收钱吧 HTTP 状态异常：' . $status);
        }
        return (string)$raw;
    }

    private function proxySettings(): ?array
    {
        $mode = $this->proxyMode();
        if ($mode === 'no_proxy') {
            return null;
        }
        if ($mode === 'custom_proxy') {
            if (is_array($this->proxySettings)) return $this->proxySettings;
            $value = trim((string)($this->config['proxy_custom'] ?? ''));
            if ($value === '') throw new RuntimeException('代理模式为自定义代理，但未填写代理地址');
            $proxy = $this->parseProxyLine($value, 'http');
            if ($proxy === null) throw new RuntimeException('自定义代理地址格式错误');
            $this->proxySettings = $proxy;
            return $proxy;
        }

        $api = $this->proxyApi();
        if ($api === '') throw new RuntimeException('代理模式为代理 API，但未填写代理 API 地址');

        if (
            is_array($this->proxySettings)
            && (int)($this->proxySettings['_expire_at'] ?? 0) > time() + self::PROXY_REFRESH_AHEAD
        ) {
            return $this->proxySettings;
        }

        $cachePath = $this->proxyCachePath($api);
        $lock = @fopen($cachePath . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('无法锁定收钱吧代理运行态');
        }
        if (!@flock($lock, LOCK_EX)) {
            @fclose($lock);
            throw new RuntimeException('无法获取收钱吧代理运行态锁');
        }

        try {
            $cached = $this->readJsonFile($cachePath);
            if (
                ($cached['api_fingerprint'] ?? '') === hash('sha256', $api)
                && is_array($cached['proxy'] ?? null)
                && (int)($cached['expire_at'] ?? 0) > time() + self::PROXY_REFRESH_AHEAD
            ) {
                $proxy = $cached['proxy'];
                $proxy['_expire_at'] = (int)$cached['expire_at'];
                $this->proxySettings = $proxy;
                return $proxy;
            }

            $proxy = $this->fetchProxy($api);
            $expireAt = time() + $this->proxyCacheTtl($api);
            $cache = [
                'api_fingerprint' => hash('sha256', $api),
                'fetched_at' => time(),
                'expire_at' => $expireAt,
                'proxy' => $proxy,
            ];
            $this->writeJsonFile($cachePath, $cache);
            $proxy['_expire_at'] = $expireAt;
            $this->proxySettings = $proxy;
            return $proxy;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function fetchProxy(string $api): array
    {
        $ch = curl_init($api);
        if ($ch === false) {
            throw new RuntimeException('无法初始化熊猫代理 API 客户端');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/plain, application/json',
                'User-Agent: epay_pro-shouqianbapro/1.0',
            ],
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('熊猫代理 API 请求失败：' . ($error ?: 'unknown'));
        }
        if ($status >= 400) {
            throw new RuntimeException('熊猫代理 API HTTP 状态异常：' . $status);
        }

        $proxy = $this->parseProxyResponse((string)$raw, $api);
        if ($proxy === null) {
            throw new RuntimeException('熊猫代理 API 未返回有效代理地址');
        }
        return $proxy;
    }

    private function parseProxyResponse(string $raw, string $api): ?array
    {
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? '');
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $candidate = $this->findProxyCandidate($decoded, $api);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        foreach (preg_split('/\R+/', $raw) ?: [] as $line) {
            $candidate = $this->parseProxyLine(trim($line), $this->proxyTypeFromApi($api));
            if ($candidate !== null) {
                return $candidate;
            }
        }
        return null;
    }

    private function findProxyCandidate(array $value, string $api): ?array
    {
        $host = '';
        foreach (['ip', 'host', 'proxy_ip', 'proxyIp', 'server'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $host = trim((string)$value[$key]);
                break;
            }
        }
        $port = 0;
        foreach (['port', 'proxy_port', 'proxyPort'] as $key) {
            if (isset($value[$key]) && is_numeric((string)$value[$key])) {
                $port = (int)$value[$key];
                break;
            }
        }
        if ($host !== '' && $port > 0) {
            return $this->buildProxy(
                $host,
                $port,
                (string)($value['username'] ?? $value['user'] ?? $value['account'] ?? ''),
                (string)($value['password'] ?? $value['pass'] ?? ''),
                $this->proxyTypeFromApi($api)
            );
        }

        foreach ($value as $child) {
            if (is_string($child)) {
                $candidate = $this->parseProxyLine($child, $this->proxyTypeFromApi($api));
                if ($candidate !== null) {
                    return $candidate;
                }
            } elseif (is_array($child)) {
                $candidate = $this->findProxyCandidate($child, $api);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function parseProxyLine(string $line, string $defaultType): ?array
    {
        $line = trim($line, " \t\r\n\"'");
        if ($line === '') {
            return null;
        }

        if (preg_match('/^(https?|socks5h?|socks4a?):\/\//i', $line)) {
            $parts = parse_url($line);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
                return null;
            }
            $scheme = strtolower((string)($parts['scheme'] ?? $defaultType));
            $type = strpos($scheme, 'socks') === 0 ? $scheme : 'http';
            return $this->buildProxy(
                (string)$parts['host'],
                (int)$parts['port'],
                isset($parts['user']) ? rawurldecode((string)$parts['user']) : '',
                isset($parts['pass']) ? rawurldecode((string)$parts['pass']) : '',
                $type
            );
        }

        if (preg_match('/^([^@\s]+)@([^\s]+)$/', $line, $match)) {
            $auth = explode(':', $match[1], 2);
            $endpoint = $this->parseProxyLine($match[2], $defaultType);
            if ($endpoint !== null) {
                $endpoint['username'] = rawurldecode($auth[0]);
                $endpoint['password'] = rawurldecode($auth[1] ?? '');
            }
            return $endpoint;
        }

        if (preg_match('/^\[([^\]]+)\]:(\d+)(?::([^:]*):(.*))?$/', $line, $match)) {
            return $this->buildProxy(
                $match[1],
                (int)$match[2],
                (string)($match[3] ?? ''),
                (string)($match[4] ?? ''),
                $defaultType
            );
        }

        if (preg_match('/^([^:\s]+):(\d+)(?::([^:]*):(.*))?$/', $line, $match)) {
            return $this->buildProxy(
                $match[1],
                (int)$match[2],
                (string)($match[3] ?? ''),
                (string)($match[4] ?? ''),
                $defaultType
            );
        }
        return null;
    }

    private function buildProxy(string $host, int $port, string $username, string $password, string $type): ?array
    {
        $host = trim($host);
        if (
            $host === ''
            || $port < 1
            || $port > 65535
            || !filter_var($host, FILTER_VALIDATE_IP)
                && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host)
        ) {
            return null;
        }
        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'type' => $type,
        ];
    }

    private function proxyApi(): string
    {
        $api = trim((string)(
            $this->config['proxy_api']
            ?? $this->config['proxyApi']
            ?? $this->config['proxy_url']
            ?? ''
        ));
        if ($api === '') {
            return '';
        }
        $scheme = strtolower((string)(parse_url($api, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('熊猫代理 API 只支持 http/https 地址');
        }
        return $api;
    }

    private function proxyMode(): string
    {
        $mode = trim((string)($this->config['proxy_mode'] ?? ''));
        if ($mode === '') {
            if (trim((string)($this->config['proxy_custom'] ?? '')) !== '') return 'custom_proxy';
            if (trim((string)($this->config['proxy_api'] ?? '')) !== '') return 'api';
            return 'no_proxy';
        }
        if (!in_array($mode, ['no_proxy', 'custom_proxy', 'api'], true)) {
            throw new RuntimeException('未知代理模式：' . $mode);
        }
        return $mode;
    }

    private function proxyTypeFromApi(string $api): string
    {
        $query = [];
        parse_str((string)(parse_url($api, PHP_URL_QUERY) ?? ''), $query);
        $value = strtolower(trim((string)($query['proxyType'] ?? '1')));
        if (in_array($value, ['2', 'socks5', 'socks5h'], true)) return 'socks5h';
        if (in_array($value, ['3', 'socks4', 'socks4a'], true)) return 'socks4a';
        return 'http';
    }

    private function curlProxyType(string $type): int
    {
        $type = strtolower($type);
        if (in_array($type, ['socks5', 'socks5h'], true)) {
            return defined('CURLPROXY_SOCKS5_HOSTNAME')
                ? CURLPROXY_SOCKS5_HOSTNAME
                : (defined('CURLPROXY_SOCKS5') ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP);
        }
        if (in_array($type, ['socks4', 'socks4a'], true)) {
            return defined('CURLPROXY_SOCKS4A')
                ? CURLPROXY_SOCKS4A
                : (defined('CURLPROXY_SOCKS4') ? CURLPROXY_SOCKS4 : CURLPROXY_HTTP);
        }
        return CURLPROXY_HTTP;
    }

    private function proxyCacheTtl(string $api): int
    {
        $query = [];
        parse_str((string)(parse_url($api, PHP_URL_QUERY) ?? ''), $query);
        $validTime = (int)($query['validTime'] ?? 0);
        return $validTime > 0 ? max(60, min($validTime, 3600)) : self::PROXY_CACHE_TTL;
    }

    private function proxyCachePath(string $api): string
    {
        $accountKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->fingerprint());
        $apiKey = substr(hash('sha256', $api), 0, 16);
        return dirname($this->runtimePath) . '/proxy-account-' . $accountKey . '-' . $apiKey . '.json';
    }

    private function invalidateProxyCache(): void
    {
        $api = $this->proxyApi();
        if ($api !== '') {
            @unlink($this->proxyCachePath($api));
        }
    }

    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建收钱吧代理运行目录');
        }
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $tmp = $path . '.tmp.' . getmypid();
        if ($content === false || @file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('无法保存收钱吧代理运行态');
        }
        @chmod($path, 0600);
    }

    private function authQuery(array $runtime): array
    {
        return [
            'token' => $runtime['token'],
            'client_version' => self::CLIENT_VERSION,
            'device_model' => '1',
            'deviceId' => $runtime['device_id'],
            'os_type' => '0',
        ];
    }

    private function readRuntime(): array
    {
        if (isset($this->config['runtime_loader']) && is_callable($this->config['runtime_loader'])) {
            $runtime = call_user_func($this->config['runtime_loader']);
            return is_array($runtime) ? $runtime : [];
        }
        if (!is_file($this->runtimePath)) {
            return [];
        }
        $content = @file_get_contents($this->runtimePath);
        if (!is_string($content) || $content === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function writeRuntime(array $runtime): void
    {
        if (isset($this->config['runtime_saver']) && is_callable($this->config['runtime_saver'])) {
            if (call_user_func($this->config['runtime_saver'], $runtime) === false) {
                throw new RuntimeException('无法广播保存收钱吧登录态');
            }
            return;
        }
        $dir = dirname($this->runtimePath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建收钱吧运行目录');
        }
        $tmp = $this->runtimePath . '.tmp.' . getmypid();
        $content = json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($content === false || @file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('无法保存收钱吧运行态');
        }
        @rename($tmp, $this->runtimePath);
        @chmod($this->runtimePath, 0600);
    }

    private function defaultRuntimePath(): string
    {
        $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->fingerprint());
        return $this->runtimeDirectory() . '/runtime-account-' . $key . '.json';
    }

    private function runtimeDirectory(): string
    {
        return \lib\Task\PluginRuntime::directory('shouqianbapro');
    }

    private function username(): string
    {
        $value = $this->config['username'] ?? $this->config['account'] ?? $this->config['appid'] ?? '';
        $value = trim((string)$value);
        if ($value === '') {
            throw new RuntimeException('未配置收钱吧账号');
        }
        return $value;
    }

    private function password(): string
    {
        $value = (string)($this->config['password'] ?? $this->config['appkey'] ?? '');
        if ($value === '') {
            throw new RuntimeException('未配置收钱吧密码');
        }
        return $value;
    }

    private function clientIp(): string
    {
        global $clientip;
        $ip = isset($clientip) ? (string)$clientip : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    private function deviceId(): string
    {
        if ($this->deviceIdOverride !== null) {
            return $this->deviceIdOverride;
        }

        $seed = 'shouqianbapro:account:' . $this->fingerprint();
        $hex = substr(hash('md5', $seed), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function findFirst($value, array $keys)
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        }
        foreach ($value as $child) {
            $found = $this->findFirst($child, $keys);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function apiMessage(array $response, string $fallback): string
    {
        $message = $this->findFirst($response, ['msg', 'message']);
        return is_scalar($message) && trim((string)$message) !== '' ? trim((string)$message) : $fallback;
    }

    private function looksLikeAuthFailure(string $message): bool
    {
        $message = strtolower($message);
        return strpos($message, 'token') !== false
            || strpos($message, '登录') !== false
            || strpos($message, 'auth') !== false
            || strpos($message, '401') !== false
            || strpos($message, '403') !== false;
    }

}


