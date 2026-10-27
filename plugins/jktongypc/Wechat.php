<?php

/** 微信明确拒绝的业务请求，区别于超时、无效响应等结果未知情况。 */
class jktongypc_wechat_rejected extends RuntimeException {}

/** 微信出码和 PC 心跳参数适配；只连接固定微信接口，入账仍以 PC 上报为准。 */
class jktongypc_wechat
{
    const RECEIPT_WX = 'https://payapp.wechatpay.cn/receiptwxmgr';
    const VERSION = '3.15.10';

    static private function object($value)
    {
        if (is_string($value)) $value = json_decode($value, true);
        return is_array($value) ? $value : [];
    }

    static private function nodes($value, $depth = 4)
    {
        $value = self::object($value);
        $nodes = [$value];
        if ($depth > 0) foreach (['ext_data', 'extData', 'heartbeat', 'heartbeat_request', 'heartbeat_data', 'request_data', 'request', 'body', 'params', 'query', 'data', 'sid_sessions', 'sidSessions'] as $key) {
            if (isset($value[$key])) $nodes = array_merge($nodes, self::nodes($value[$key], $depth - 1));
        }
        return $nodes;
    }

    static private function scalar($value, $max = 4096)
    {
        if (!is_scalar($value)) return '';
        $value = trim((string)$value);
        if (strlen($value) > $max) throw new RuntimeException('心跳参数长度超限', 400);
        return $value;
    }

    static public function heartbeatData($ext)
    {
        $status = null;
        $options = [];
        $nodes = self::nodes($ext);
        $kinds = [
            'personal-dynamic-code' => ['sid' => 'sid'],
            'payment-link' => ['sid' => 'payment_link_sid', 'shop_id' => 'payment_link_shop_id', 'shopId' => 'payment_link_shop_id'],
            'payment-order' => ['sid' => 'receipt_sid', 'shop_id' => 'receipt_shop_id', 'shopId' => 'receipt_shop_id'],
        ];
        foreach ($nodes as $node) {
            if (array_key_exists('status', $node)) {
                $candidate = strtolower(self::scalar($node['status'], 20));
                if (!in_array($candidate, ['online', 'offline'], true)) throw new RuntimeException('ext_data.status 只支持 online 或 offline', 400);
                if ($status !== 'offline') $status = $candidate;
            }
            foreach (['sid', 'payment_link_sid', 'payment_link_shop_id', 'receipt_sid', 'receipt_shop_id'] as $key) {
                $value = self::scalar($node[$key] ?? '');
                if ($value !== '' && !isset($options[$key])) $options[$key] = $value;
            }
        }
        // 每种登录态独立读取，不将通用 SID 猜测成其他模式的登录态。
        foreach ($nodes as $node) {
            // PC 端和参考 Lua 插件同时使用 snake_case 与 camelCase。
            $sessions = self::object($node['sid_sessions'] ?? ($node['sidSessions'] ?? []));
            foreach ($kinds as $kind => $mapping) {
                $session = self::object($sessions[$kind] ?? []);
                foreach ($mapping as $from => $to) {
                    $value = self::scalar($session[$from] ?? '');
                    if ($value !== '') $options[$to] = $value;
                }
            }
        }
        // 软件心跳不能证明收款账号在线，必须由客户端明确上报 online。
        return ['status' => $status ?? 'offline', 'options' => $options];
    }

    /** 本地缺项在写入出码标记前拒绝，补齐配置后可重试原订单。 */
    public function validate($config)
    {
        if ($config['pay_mode'] === 'platform') {
            $this->required($config['sid'], '请填写个人动态码 SID 或等待 PC 上报');
        } elseif ($config['pay_mode'] === 'smallbook') {
            $this->required($config['payment_link_sid'], '请填写收款链接 SID 或等待 PC 上报');
            $this->id($config['payment_link_shop_id'], '请输入店铺/商户ID或等待客户端自动上报');
        } elseif ($config['pay_mode'] === 'receipt') {
            $this->required($config['receipt_sid'], '请填写收款单 SID 或等待 PC 上报');
            if ($config['receipt_shop_id'] !== '') $this->id($config['receipt_shop_id'], '收款单店铺 ID 无效');
        } else {
            throw new RuntimeException('不支持的微信动态收款方式', 400);
        }
    }

    public function create($config, $order, $creationState = null)
    {
        $this->validate($config);
        if ($config['pay_mode'] === 'platform') {
            $sid = $this->required($config['sid'], '请填写个人动态码 SID 或等待 PC 上报');
            $data = $this->creationRequest('GET', 'https://smallbook.wxpapp.weixin.qq.com/qrappsl/profile/getpayqrcode', ['sid' => $sid, 'v' => '6.23.1'], null, 'wx28be8489b7a36aaa/1061', $creationState);
            return ['kind' => 'image', 'code' => self::image($data['data']['qrcode_content'] ?? '')];
        }
        if ($config['pay_mode'] === 'smallbook') return $this->link($config, $order, $creationState);
        if ($config['pay_mode'] === 'receipt') return $this->receipt($config, $order, $creationState);
        throw new RuntimeException('不支持的微信动态收款方式', 400);
    }

    private function link($config, $order, $creationState)
    {
        $sid = $this->required($config['payment_link_sid'], '请填写收款链接 SID 或等待 PC 上报');
        $shop = $this->id($config['payment_link_shop_id'], '请填写收款链接店铺 ID 或等待 PC 上报');
        $data = $this->creationRequest('POST', 'https://sjtmgr.wxpapp.weixin.qq.com/sjt/linkqrcode/linkqrcode/create', ['sid' => $sid, 'v' => '0.69.8'], [
            'v' => '0.69.8', 'shop_id' => (int)$shop,
            'link_qr_code' => ['remark' => substr($order['trade_no'], 0, 10), 'payfee_type' => 'E_LINK_QRCODE_PAY_FEE_TYPE_FIX', 'image_description' => ['image_url_list' => []]],
            'receipt_item' => [['desc' => substr($order['trade_no'], 10, 20), 'price' => (int)round((float)$order['realmoney'] * 100), 'quota_type' => 'E_LINK_QRCODE_QUOTATYPE_NO_LIMIT']],
            'option_type' => [], 'sid' => $sid,
        ], 'wx9291fe7dadf5a574/169', $creationState);
        $receipt = $this->required($data['data']['receipt_id'] ?? '', '微信未返回收款链接编号', 502);
        $code = $this->required($data['data']['qr_code_url'] ?? '', '微信未返回收款链接二维码', 502);
        if (!preg_match('#^(?:https?://|weixin://)#i', $code)) throw new RuntimeException('微信返回的收款链接格式无效', 502);
        return ['kind' => 'qrcode', 'code' => $code, 'receipt_id' => $receipt];
    }

    private function receipt($config, $order, $creationState)
    {
        $sid = $this->required($config['receipt_sid'], '请填写收款单 SID 或等待 PC 上报');
        $shop = $config['receipt_shop_id'];
        $accountRef = trim((string)($config['receipt_account_ref'] ?? ''));
        if ($accountRef !== '' && !preg_match('/^[13]:[1-9][0-9]{0,17}:[1-9][0-9]{0,17}$/D', $accountRef)) {
            throw new RuntimeException('收款单店铺选择已失效，请重新获取全部店铺', 400);
        }
        $accounts = $this->receiptAccounts($sid);
        if (!$accounts) throw new RuntimeException('收款单没有匹配的已激活账号或店铺/商户ID', 400);
        if ($accountRef !== '') {
            list($selectedType, $selectedId, $selectedShop) = explode(':', $accountRef);
            if ($shop !== '' && $shop !== $selectedShop) throw new RuntimeException('收款单店铺与所选账号不一致，请重新获取全部店铺', 400);
            $accounts = array_values(array_filter($accounts, function ($account) use ($selectedType, $selectedId) {
                return (string)$account['account_type'] === $selectedType && (string)$account['account_id'] === $selectedId;
            }));
            if (!$accounts) throw new RuntimeException('所选收款账号已失效，请重新获取全部店铺', 400);
        }

        // 店铺 ID 是账号选择条件。没有手填值时，只有一个账号且该账号只有一个店铺才允许自动采用；
        // 多账号、多店铺或同店铺多账号都必须让商户明确填写，不能按返回顺序兜底。
        $matches = [];
        $shopIds = [];
        foreach ($accounts as $account) {
            $gateway = self::receiptGateway((string)$account['account_type']);
            if ($gateway === '') continue;
            $details = $this->request('GET', $gateway . '/account/get', [
                'miniprogram_version' => self::VERSION,
                'account_id' => (string)$account['account_id'],
                'account_type' => (string)$account['account_type'],
                'sid' => $sid,
            ]);
            foreach (self::receiptList($details, ['auth_shop_list', 'authShopList']) as $candidate) {
                $candidateShop = self::receiptScalar($candidate, ['shop_id', 'shopId', 'id']);
                if ($candidateShop === '' || !preg_match('/^[1-9][0-9]{0,17}$/D', $candidateShop)) continue;
                $shopIds[$candidateShop] = true;
                if ($shop !== '' && $candidateShop !== $shop) continue;
                $key = (string)$account['account_id'] . ':' . (string)$account['account_type'] . ':' . $candidateShop;
                $ref = (string)$account['account_type'] . ':' . (string)$account['account_id'] . ':' . $candidateShop;
                if ($accountRef !== '' && $ref !== $accountRef) continue;
                $matches[$key] = ['account' => $account, 'shop_id' => $candidateShop];
            }
        }
        if (count($matches) !== 1) {
            $ids = array_keys($shopIds);
            sort($ids, SORT_STRING);
            if ($shop !== '') {
                throw new RuntimeException(count($matches) > 1 ? '店铺/商户ID匹配到多个收款账号，请填写可唯一匹配的店铺/商户ID' : '收款单没有匹配该店铺/商户ID的已激活账号', 400);
            }
            if (count($ids) > 1) {
                throw new RuntimeException('检测到多个收款账号，请填写可唯一匹配的店铺/商户ID：' . implode('、', $ids), 400);
            }
            throw new RuntimeException('收款单店铺为空或没有已授权店铺，请在后台填写店铺/商户ID', 400);
        }
        $selected = reset($matches);
        $account = $selected['account'];
        $shop = $selected['shop_id'];
        $aid = (string)$account['account_id'];
        $type = (string)$account['account_type'];
        $this->id($aid, '收款单账号 ID 无效');
        $gateway = self::receiptGateway($type);
        if ($gateway === '') throw new RuntimeException('收款单账号类型仅支持 1 或 3', 400);
        $this->id($shop, '收款单店铺 ID 无效');
        $common = ['account_type' => $type, 'account_id' => $aid, 'sid' => $sid];
        $response = $this->creationRequest('POST', $gateway . '/receipt/create', $common, [
            'miniprogram_version' => self::VERSION, 'fee' => (int)round((float)$order['realmoney'] * 100),
            'remark' => $order['trade_no'], 'remark_pic_urls' => '', 'option_list' => [], 'receipt_item_list' => [], 'shop_id' => (int)$shop, 'sid' => $sid,
        ], 'wx264e9b6d4d484f51/186', $creationState);
        $receipt = $this->required($response['data']['receipt']['receipt_id'] ?? '', '微信未返回收款单编号', 502);
        $response = $this->request('GET', $gateway . '/receipt/getwxacode', $common + ['miniprogram_version' => self::VERSION, 'wxacode_path_type' => 1, 'receipt_id' => $receipt]);
        return ['kind' => 'image', 'code' => self::image($response['data']['qrcode'] ?? ''), 'receipt_id' => $receipt];
    }

    /** 后台选择器使用：按 SID 拉取全部已激活账号及其授权店铺。 */
    public function receiptShops($sid)
    {
        $sid = $this->required($sid, '请填写收款单 SID 或等待 PC 上报');
        $accounts = $this->receiptAccounts($sid);
        if (!$accounts) throw new RuntimeException('收款单没有匹配的已激活账号或店铺/商户ID', 400);

        $shops = [];
        foreach ($accounts as $account) {
            $type = self::receiptScalar($account, ['account_type', 'accountType']);
            $aid = self::receiptScalar($account, ['account_id', 'accountId', 'id']);
            $gateway = self::receiptGateway($type);
            if ($gateway === '' || $aid === '') continue;
            $details = $this->request('GET', $gateway . '/account/get', [
                'miniprogram_version' => self::VERSION,
                'account_id' => $aid,
                'account_type' => $type,
                'sid' => $sid,
            ]);
            foreach (self::receiptList($details, ['auth_shop_list', 'authShopList']) as $candidate) {
                $shopId = self::receiptScalar($candidate, ['shop_id', 'shopId', 'id']);
                if ($shopId === '' || !preg_match('/^[1-9][0-9]{0,17}$/D', $shopId)) continue;
                $shopName = self::receiptScalar($candidate, ['shop_name', 'shopName', 'name', 'merchant_name', 'merchantName']);
                $key = $shopId . ':' . $aid . ':' . $type;
                $shops[$key] = [
                    'shop_id' => $shopId,
                    'shop_name' => $shopName,
                    'account_id' => $aid,
                    'account_type' => $type,
                    'ref' => $type . ':' . $aid . ':' . $shopId,
                    'account_name' => self::receiptScalar($account, ['account_name', 'accountName', 'name']),
                ];
            }
        }
        $shops = array_values($shops);
        usort($shops, function ($left, $right) {
            $shopCompare = strcmp((string)$left['shop_id'], (string)$right['shop_id']);
            if ($shopCompare !== 0) return $shopCompare;
            return strcmp((string)$left['account_id'], (string)$right['account_id']);
        });
        if (!$shops) throw new RuntimeException('收款单没有已授权店铺/商户ID', 400);
        return $shops;
    }

    private function receiptAccounts($sid)
    {
        $response = $this->request('GET', self::RECEIPT_WX . '/account/list', ['miniprogram_version' => self::VERSION, 'sid' => $sid]);
        $accounts = [];
        foreach (self::receiptList($response, ['account_list', 'accountList']) as $account) {
            $status = strtoupper(self::receiptScalar($account, ['account_status', 'accountStatus', 'status']));
            $type = self::receiptScalar($account, ['account_type', 'accountType']);
            if (!in_array($status, ['ACTIVATED', 'ACTIVE', '1'], true) || !in_array($type, ['1', '3'], true)) continue;
            $aid = self::receiptScalar($account, ['account_id', 'accountId', 'id']);
            if ($aid === '') continue;
            $accounts[$type . ':' . $aid] = $account;
        }
        return array_values($accounts);
    }

    /** 微信接口不同版本会把列表包在 data.data 或其它包装层中。 */
    static private function receiptList($value, $names, $depth = 6)
    {
        $value = self::object($value);
        if ($depth < 0) return [];
        foreach ($value as $key => $child) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$key));
            foreach ($names as $name) {
                if ($normalized === strtolower(preg_replace('/[^a-z0-9]/i', '', $name)) && is_array($child)) {
                    return array_values(array_filter($child, 'is_array'));
                }
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $found = self::receiptList($child, $names, $depth - 1);
                if ($found) return $found;
            }
        }
        return [];
    }

    static private function receiptScalar($value, $names)
    {
        $value = self::object($value);
        foreach ($value as $key => $item) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$key));
            foreach ($names as $name) {
                if ($normalized === strtolower(preg_replace('/[^a-z0-9]/i', '', $name)) && is_scalar($item)) {
                    return trim((string)$item);
                }
            }
        }
        return '';
    }

    static private function receiptGateway($type)
    {
        return ['1' => 'https://payapp.wechatpay.cn/receiptmdmgr', '3' => 'https://payapp.wechatpay.cn/receiptsjtmgr'][$type] ?? '';
    }

    /** 只在即将出码/创建收款单时落标记；查询阶段的失败不占用创建状态。 */
    private function creationRequest($method, $url, $query, $body, $referer, $creationState)
    {
        if ($creationState) $creationState('started');
        try {
            return $this->request($method, $url, $query, $body, $referer);
        } catch (jktongypc_wechat_rejected $e) {
            if ($creationState) $creationState('rejected');
            throw $e;
        }
    }

    private function required($value, $message, $code = 400)
    {
        if (!is_scalar($value) || trim((string)$value) === '') throw new RuntimeException($message, $code);
        return trim((string)$value);
    }

    private function id($value, $message)
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', (string)$value)) throw new RuntimeException($message, 400);
        return (string)$value;
    }

    static private function image($value)
    {
        if (!is_string($value) || strlen($value) > 2097152) throw new RuntimeException('微信未返回有效二维码图片', 502);
        $value = trim($value);
        $value = preg_replace('#^data:image/(?:png|jpe?g|gif|webp);base64,#i', '', $value);
        $bytes = base64_decode($value, true);
        $mime = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$mime || !in_array($mime['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) throw new RuntimeException('微信二维码图片格式无效', 502);
        return 'data:' . $mime['mime'] . ';base64,' . base64_encode($bytes);
    }

    /** 保留微信错误码和原因，SID 与带登录态的链接不进入页面或日志。 */
    static private function response($raw, $operation, $sid)
    {
        $rejected = false;
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $message = '微信' . $operation . '接口返回了无效 JSON';
        } elseif (!array_key_exists('errcode', $data) || !in_array($data['errcode'], [0, '0'], true)) {
            $code = isset($data['errcode']) && is_scalar($data['errcode']) && preg_match('/^-?[0-9]{1,12}$/D', (string)$data['errcode']) ? (string)$data['errcode'] : '未知';
            $rejected = $code !== '未知' && (int)$code !== 0;
            $reason = $data['msg'] ?? $data['errmsg'] ?? '';
            $reason = is_string($reason) ? $reason : '';
            if ($sid !== '') $reason = str_replace([$sid, rawurlencode($sid), urlencode($sid)], '[SID已隐藏]', $reason);
            $reason = preg_replace('#(?:https?://|weixin://)[^\s<>"\']+#iu', '[链接已隐藏]', $reason);
            $reason = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', strip_tags($reason)));
            if (strlen($reason) > 600) $reason = mb_substr($reason, 0, 150, 'UTF-8');
            $message = '微信' . $operation . '接口失败（错误码 ' . $code . '）' . ($reason !== '' ? '：' . $reason : '，未返回错误说明');
        } else {
            return $data;
        }
        error_log('[jktongypc] ' . $message);
        if ($rejected) throw new jktongypc_wechat_rejected($message, 502);
        throw new RuntimeException($message, 502);
    }

    /** 固定域名、TLS 校验、不跟随重定向；请求异常不回显含 SID 的 URL。 */
    protected function request($method, $url, $query = [], $body = null, $referer = 'wx264e9b6d4d484f51/186')
    {
        if (!extension_loaded('curl')) throw new RuntimeException('服务器缺少 cURL 扩展', 500);
        $ch = curl_init($url . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: */*', 'xweb_xhr: 1', 'Referer: https://servicewechat.com/' . $referer . '/page-frame.html', 'Accept-Language: zh-CN,zh;q=0.9'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 MicroMessenger/7.0.20.1781(0x6700143B) NetType/WIFI MiniProgramEnv/Windows WindowsWechat/WMPF WindowsWechat(0x63090c11)XWEB/11581',
        ]);
        if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status !== 200) throw new RuntimeException('微信接口请求失败，请检查网络或稍后重试（HTTP ' . $status . '）', 502);
        $operation = strpos($url, '/qrappsl/') !== false ? '个码动态' : (strpos($url, '/sjt/linkqrcode/') !== false ? '收款链接' : '收款单');
        return self::response($raw, $operation, (string)($query['sid'] ?? $body['sid'] ?? ''));
    }
}
