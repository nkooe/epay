<?php

/**
 * 拉卡拉缴费易 PRO 的 HTTP 客户端。
 *
 * 这里保留拉卡拉接口本身的业务校验；代理只负责本机到拉卡拉接口的出口，
 * 代理 API 请求本身直连供应商并缓存短期节点，不写入插件日志。
 */
class lakalajfypro_client
{
    const PROVIDER = 'lakalajfypro';
    const PAYMENT_WINDOW_SECONDS = 300;
    const QUERY_GRACE_SECONDS = 480;
    const TEMPLATE_URL = 'https://jfyconsole.lakala.com/manager/api/merchant/queryDefaultTemplate';
    const PAY_URL = 'https://jfyconsole.lakala.com/order/api/cashier/pay';
    const QUERY_URL = 'https://payment.lakala.com/m/ccss/counter/order/query';
    const QUERY_CHANNEL_ID = '95';
    const QUERY_VERSION = '1.0';
    const PROXY_CACHE_TTL = 240;

    public static function extractPid($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (preg_match('/^\d{8,32}$/', $value)) return $value;
        $parts = parse_url($value);
        if (!is_array($parts)) return '';
        foreach (['query', 'fragment'] as $part) {
            if (empty($parts[$part])) continue;
            $text = (string)$parts[$part];
            $pos = strpos($text, '?');
            if ($pos !== false) $text = substr($text, $pos + 1);
            $params = [];
            parse_str($text, $params);
            foreach (['pid', 'merchantId', 'merchant_id', 'merchId'] as $key) {
                if (isset($params[$key]) && preg_match('/^\d{8,32}$/', trim((string)$params[$key]))) return trim((string)$params[$key]);
            }
        }
        return '';
    }

    public static function createPayOrder($config, $order)
    {
        $pid = self::extractPid($config['pid']);
        if ($pid === '') throw new RuntimeException('拉卡拉缴费易 PID 无效');
        $merchantNo = trim((string)$config['merchant_no']);
        if ($merchantNo === '') throw new RuntimeException('未配置拉卡拉商户号');
        $tradeNo = trim((string)($order['trade_no'] ?? ''));
        if ($tradeNo === '') throw new RuntimeException('本站订单号不能为空');
        $amount = self::orderAmount($order);
        $template = self::fetchTemplate($config, $pid);
        $remark = substr($tradeNo, 0, 100);
        $payload = [
            'merchId' => $pid,
            'orderTemplateData' => self::buildTemplateData($template, $amount, $remark),
            'tradeAmount' => $amount,
            'remark' => $remark,
        ];
        $result = self::request('POST', self::PAY_URL, $payload, $config);
        if (!self::isSuccess($result)) throw new RuntimeException(self::message($result, '拉卡拉缴费易下单失败'));
        $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
        $payUrl = self::firstDeep($data, ['payUrl', 'pay_url', 'cashierUrl', 'cashier_url', 'url']);
        if ($payUrl === '') throw new RuntimeException('拉卡拉缴费易未返回支付链接');
        $ids = self::extractIdentifiers($data, $payUrl);
        if ($ids['order_no'] === '') throw new RuntimeException('下单未返回 sysTradeNo，无法可靠查单');
        $createdAt = self::parseTime($order['addtime'] ?? '') ?: time();
        return [
            'provider' => self::PROVIDER,
            'trade_no' => $tradeNo,
            'pid' => $pid,
            'merchant_no' => $merchantNo,
            'query_channel_id' => self::QUERY_CHANNEL_ID,
            'amount' => $amount,
            'amount_cents' => self::amountCents($amount),
            'remark' => $remark,
            'pay_url' => $payUrl,
            'pay_order_no' => $ids['pay_order_no'],
            'order_no' => $ids['order_no'],
            'out_order_no' => $ids['order_no'],
            'channel_trade_no' => $ids['channel_trade_no'],
            'cashier_order_id' => self::firstDeep($data, ['id']),
            'created_at' => $createdAt,
            'pay_expires_at' => $createdAt + self::PAYMENT_WINDOW_SECONDS,
            'query_expires_at' => $createdAt + self::QUERY_GRACE_SECONDS,
        ];
    }

    public static function queryOrder($config, $context, $order)
    {
        $outOrderNo = trim((string)($context['out_order_no'] ?? $context['order_no'] ?? ''));
        $merchantNo = trim((string)($context['merchant_no'] ?? $config['merchant_no'] ?? ''));
        if ($outOrderNo === '' || $merchantNo === '') throw new RuntimeException('查单上下文缺少订单号或商户号');
        $payload = [
            'reqTime' => date('YmdHis'),
            'version' => self::QUERY_VERSION,
            'reqData' => ['channelId' => self::QUERY_CHANNEL_ID, 'outOrderNo' => $outOrderNo, 'merchantNo' => $merchantNo],
        ];
        $result = self::request('POST', self::QUERY_URL, $payload, $config);
        if (($result['success'] ?? false) !== true || trim((string)($result['code'] ?? '')) !== '000000') {
            throw new RuntimeException(self::message($result, '拉卡拉缴费易查单失败'));
        }
        $data = $result['respData'] ?? null;
        if (!is_array($data)) throw new RuntimeException('拉卡拉缴费易查单缺少 respData');
        self::validateQuery($data, $context, $order);
        if (trim((string)($data['orderStatus'] ?? '')) !== '2') {
            return ['status' => 0, 'api_trade_no' => '', 'money' => self::orderAmount($order), 'provider_order_status' => (string)($data['orderStatus'] ?? '')];
        }
        $trade = self::successfulTrade($data);
        $paidAt = self::parseTime($trade['tradeTime'] ?? ($data['orderEfficientTime'] ?? ($data['payTime'] ?? '')));
        $createdAt = self::parseTime($order['addtime'] ?? '');
        if ($paidAt <= 0 || $createdAt <= 0 || $paidAt <= $createdAt || $paidAt > $createdAt + self::QUERY_GRACE_SECONDS) {
            throw new RuntimeException('查单支付时间不在本地订单匹配窗口内');
        }
        $payOrderNo = trim((string)($data['payOrderNo'] ?? ''));
        $apiTradeNo = trim((string)($trade['tradeNo'] ?? ''));
        if ($apiTradeNo === '') $apiTradeNo = $payOrderNo;
        if ($apiTradeNo === '') throw new RuntimeException('已支付但缺少 tradeNo/payOrderNo');
        return [
            'status' => 1,
            'api_trade_no' => $apiTradeNo,
            'money' => self::orderAmount($order),
            'buyer' => trim((string)($trade['userId1'] ?? $trade['userId2'] ?? '')),
            'bill_trade_no' => trim((string)($trade['accTradeNo'] ?? '')),
            'bill_mch_trade_no' => $payOrderNo !== '' ? $payOrderNo : trim((string)($data['outOrderNo'] ?? '')),
            'endtime' => self::formatProviderTime($trade['tradeTime'] ?? ($data['orderEfficientTime'] ?? '')),
        ];
    }

    private static function fetchTemplate($config, $pid)
    {
        $url = self::TEMPLATE_URL . '?' . http_build_query(['merchantId' => $pid], '', '&', PHP_QUERY_RFC3986);
        $result = self::request('GET', $url, null, $config);
        if (!self::isSuccess($result)) throw new RuntimeException(self::message($result, '读取拉卡拉缴费易模板失败'));
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $raw = $data['formScheme'] ?? $data['form_scheme'] ?? $data['scheme'] ?? null;
        if ($raw === null || $raw === '') throw new RuntimeException('拉卡拉缴费易模板为空，无法免输入下单');
        $scheme = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($scheme)) throw new RuntimeException('拉卡拉缴费易模板解析失败');
        return $scheme;
    }

    private static function buildTemplateData($scheme, $amount, $remark)
    {
        $result = [];
        $amountFilled = false;
        $remarkFilled = false;
        foreach (array_values($scheme) as $index => $item) {
            if (!is_array($item)) continue;
            $type = strtolower(trim((string)($item['type'] ?? $item['compType'] ?? '')));
            $label = self::fieldLabel($item);
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $body = is_array($item['body'] ?? null) ? $item['body'] : [];
            if (!$amountFilled && ($type === 'number' || self::containsAny($label, ['金额', '支付金额', '收款金额']))) {
                $item['state'] = true; $item['value'] = $amount;
                $options['content'] = $amount; $options['value'] = $amount;
                if ($body) { $body['content'] = $amount; $body['value'] = $amount; $body['readonly'] = true; }
                $amountFilled = true;
            } elseif (!$remarkFilled && ($type === 'textarea' || $type === 'input' || self::containsAny($label, ['备注', '订单', '说明']))) {
                $item['value'] = $remark; $options['content'] = $remark; $options['value'] = $remark;
                if ($body) { $body['content'] = $remark; $body['value'] = $remark; $body['readonly'] = true; }
                $remarkFilled = true;
            } elseif (self::requiredField($item) && !self::hasTemplateValue($item, $options, $body)) {
                throw new RuntimeException('模板存在必填字段，无法免输入：' . ($label !== '' ? $label : $type));
            }
            $item['index'] = $item['index'] ?? $index;
            $item['options'] = $options;
            if ($body) $item['body'] = $body;
            $result[] = $item;
        }
        if (!$amountFilled) throw new RuntimeException('模板没有可填充的金额字段');
        return $result;
    }

    private static function validateQuery($data, $context, $order)
    {
        if (trim((string)($data['channelId'] ?? '')) !== self::QUERY_CHANNEL_ID) throw new RuntimeException('查单 channelId 不匹配');
        if (trim((string)($data['outOrderNo'] ?? '')) !== trim((string)($context['out_order_no'] ?? ''))) throw new RuntimeException('查单 outOrderNo 不匹配');
        $expectedMerchant = trim((string)($context['merchant_no'] ?? ''));
        foreach (['merchantNo', 'transMerchantNo'] as $key) {
            $actual = trim((string)($data[$key] ?? ''));
            if ($actual !== '' && $actual !== $expectedMerchant) throw new RuntimeException('查单 merchantNo 不匹配');
        }
        $expectedRemark = trim((string)($context['remark'] ?? $context['trade_no'] ?? ''));
        $remark = trim((string)($data['counterRemark'] ?? ''));
        $orderInfo = trim((string)($data['orderInfo'] ?? ''));
        if ($expectedRemark === '' || ($remark !== $expectedRemark && $orderInfo !== $expectedRemark)) throw new RuntimeException('查单备注不匹配');
        if (self::providerAmountCents($data['totalAmount'] ?? null) !== self::amountCents(self::orderAmount($order))) throw new RuntimeException('查单金额不匹配');
    }

    private static function successfulTrade($data)
    {
        $trades = $data['orderTradeInfoList'] ?? [];
        if (!is_array($trades)) return [];
        foreach ($trades as $trade) {
            if (!is_array($trade)) continue;
            if (strtoupper(trim((string)($trade['tradeStatus'] ?? ''))) === 'S' && in_array(strtoupper(trim((string)($trade['tradeType'] ?? ''))), ['', 'PAY'], true)) return $trade;
        }
        return [];
    }

    private static function request($method, $url, $payload, $config)
    {
        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $proxy = self::proxy($config, $attempt > 0);
                return self::rawRequest($method, $url, $payload, $proxy);
            } catch (Throwable $e) {
                $last = $e;
                if (strtolower((string)($config['proxy_mode'] ?? '')) !== 'api' || $attempt > 0) break;
            }
        }
        throw $last ?: new RuntimeException('拉卡拉请求失败');
    }

    private static function rawRequest($method, $url, $payload, $proxy)
    {
        $headers = ['Accept: application/json, text/plain, */*', 'Content-Type: application/json;charset=UTF-8', 'Origin: https://jfyh5.lakala.com', 'Referer: https://jfyh5.lakala.com/', 'User-Agent: Mozilla/5.0 (Linux; Android 13; epay) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'];
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('无法初始化拉卡拉 HTTP 客户端');
        $options = [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        // 拉卡拉接口可能先返回 30x；保留原方法和 JSON 请求体，避免 POST 被 cURL 改成 GET 后落到接口返回 405。
        if (defined('CURLOPT_POSTREDIR') && defined('CURL_REDIR_POST_ALL')) $options[CURLOPT_POSTREDIR] = CURL_REDIR_POST_ALL;
        if (strtoupper($method) !== 'GET') $options[CURLOPT_POSTFIELDS] = json_encode($payload ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($proxy) {
            $host = (string)$proxy['host'];
            if (strpos($host, ':') !== false && substr($host, 0, 1) !== '[') $host = '[' . $host . ']';
            $options[CURLOPT_PROXY] = $host . ':' . (int)$proxy['port'];
            $options[CURLOPT_PROXYTYPE] = (int)$proxy['type'];
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
            if ((string)($proxy['user'] ?? '') !== '') $options[CURLOPT_PROXYUSERPWD] = (string)$proxy['user'] . ':' . (string)($proxy['pass'] ?? '');
        } elseif (defined('CURLOPT_NOPROXY')) {
            // 未配置代理时不继承服务器环境变量中的代理；显式代理不能设置 NOPROXY=*，否则会被绕过。
            $options[CURLOPT_NOPROXY] = '*';
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $error !== '') throw new RuntimeException('拉卡拉请求失败：' . ($error ?: 'unknown'));
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags((string)$raw)));
            if (function_exists('mb_substr')) $snippet = mb_substr($snippet, 0, 180);
            else $snippet = substr($snippet, 0, 180);
            throw new RuntimeException('拉卡拉返回非 JSON（HTTP ' . $status . ($snippet !== '' ? '：' . $snippet : '') . '）');
        }
        if ($status < 200 || $status >= 300) throw new RuntimeException(self::message($data, '拉卡拉 HTTP ' . $status));
        return $data;
    }

    private static function proxy($config, $forceRefresh = false)
    {
        $mode = strtolower(trim((string)($config['proxy_mode'] ?? 'no_proxy')));
        if ($mode === 'no_proxy' || $mode === '') return null;
        if ($mode === 'custom_proxy') {
            $raw = trim((string)($config['proxy_custom'] ?? ''));
            if ($raw === '') throw new RuntimeException('已选择代理但未填写代理地址');
            return self::parseProxyAddress($raw, 'socks5');
        }
        if ($mode !== 'api') throw new RuntimeException('代理模式无效');
        $api = trim((string)($config['proxy_api'] ?? ''));
        if ($api === '') throw new RuntimeException('API 模式未填写代理地址');
        $file = self::proxyCacheFile($api);
        if (!$forceRefresh && is_file($file)) {
            $cached = json_decode((string)@file_get_contents($file), true);
            if (is_array($cached) && (int)($cached['expires'] ?? 0) > time() && is_array($cached['proxy'] ?? null)) return $cached['proxy'];
        }
        $ch = curl_init($api);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Accept: text/plain, application/json', 'User-Agent: epay-lakalajfypro/1.0']]);
        $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $error !== '') throw new RuntimeException('代理 API 请求失败：' . ($error ?: 'unknown'));
        if ($status >= 400) throw new RuntimeException('代理 API HTTP 状态异常：' . $status);
        $proxy = self::parseProxyResponse((string)$raw, self::proxyTypeFromApi($api));
        if (!$proxy) throw new RuntimeException('代理 API 返回格式无法识别');
        @file_put_contents($file, json_encode(['expires' => time() + self::PROXY_CACHE_TTL, 'proxy' => $proxy], JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0600);
        return $proxy;
    }

    private static function parseProxyResponse($raw, $defaultType)
    {
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw));
        if ($raw === '') return null;
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $found = self::findProxyNode($json, $defaultType);
            if ($found) return $found;
        }
        foreach (preg_split('/\R+/', $raw) as $line) {
            $found = self::parseProxyLine(trim($line), $defaultType);
            if ($found) return $found;
        }
        return null;
    }

    private static function findProxyNode($value, $defaultType)
    {
        if (!is_array($value)) return null;
        $host = '';
        foreach (['ip', 'host', 'proxy_ip', 'proxyIp', 'server'] as $key) if (isset($value[$key]) && is_scalar($value[$key])) { $host = trim((string)$value[$key]); break; }
        $port = 0;
        foreach (['port', 'proxy_port', 'proxyPort'] as $key) if (isset($value[$key]) && is_numeric((string)$value[$key])) { $port = (int)$value[$key]; break; }
        if ($host !== '' && $port > 0) return self::buildProxy($host, $port, (string)($value['protocol'] ?? $value['proxyType'] ?? $defaultType), (string)($value['username'] ?? $value['user'] ?? $value['account'] ?? ''), (string)($value['password'] ?? $value['pass'] ?? ''));
        foreach ($value as $child) {
            if (is_string($child)) {
                $decoded = json_decode($child, true);
                $found = is_array($decoded) ? self::findProxyNode($decoded, $defaultType) : self::parseProxyLine($child, $defaultType);
                if ($found) return $found;
            }
            if (is_array($child)) { $found = self::findProxyNode($child, $defaultType); if ($found) return $found; }
        }
        return null;
    }

    private static function parseProxyLine($line, $defaultType)
    {
        if ($line === '') return null;
        if (strpos($line, '://') !== false || strpos($line, '@') !== false) {
            if (strpos($line, '://') === false) $line = $defaultType . '://' . $line;
            $parts = parse_url($line);
            if (!$parts || empty($parts['host']) || empty($parts['port'])) return null;
            return self::buildProxy($parts['host'], (int)$parts['port'], $parts['scheme'] ?? $defaultType, rawurldecode((string)($parts['user'] ?? '')), rawurldecode((string)($parts['pass'] ?? '')));
        }
        $parts = explode(':', $line, 4);
        if (count($parts) === 2) return self::buildProxy($parts[0], (int)$parts[1], $defaultType, '', '');
        if (count($parts) === 4) return self::buildProxy($parts[0], (int)$parts[1], $defaultType, $parts[2], $parts[3]);
        return null;
    }

    private static function buildProxy($host, $port, $scheme, $user, $pass)
    {
        $host = trim((string)$host); $port = (int)$port;
        if ($host === '' || $port < 1 || $port > 65535) return null;
        $scheme = strtolower(trim((string)$scheme));
        if (in_array($scheme, ['2', 'socks', 'socks5', 'socks5h', 'sk5'], true)) { $scheme = 'socks5'; $type = defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5; }
        else { $scheme = 'http'; $type = CURLPROXY_HTTP; }
        return ['host' => $host, 'port' => $port, 'scheme' => $scheme, 'user' => (string)$user, 'pass' => (string)$pass, 'type' => $type];
    }

    private static function parseProxyAddress($raw, $defaultScheme)
    {
        if (strpos($raw, '://') === false) $raw = $defaultScheme . '://' . $raw;
        $parts = parse_url($raw);
        if (!$parts || empty($parts['host']) || empty($parts['port'])) throw new RuntimeException('代理地址格式无效');
        return self::buildProxy($parts['host'], (int)$parts['port'], $parts['scheme'] ?? $defaultScheme, rawurldecode((string)($parts['user'] ?? '')), rawurldecode((string)($parts['pass'] ?? '')));
    }

    private static function proxyTypeFromApi($api)
    {
        $params = []; parse_str((string)(parse_url($api, PHP_URL_QUERY) ?? ''), $params);
        return (string)($params['proxyType'] ?? '') === '2' ? 'socks5' : 'http';
    }

    private static function proxyCacheFile($api)
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lakalajfypro_proxy_' . hash('sha256', $api) . '.json';
    }

    private static function isSuccess($result)
    {
        if (($result['success'] ?? true) === false) return false;
        if (!array_key_exists('code', $result) && !array_key_exists('respCode', $result)) return true;
        $code = strtoupper(trim((string)($result['code'] ?? $result['respCode'] ?? '')));
        return in_array($code, ['', '0', '00', '000000', '200', 'SUCCESS', 'OK'], true);
    }

    private static function message($data, $fallback)
    {
        foreach (['message', 'msg', 'respMsg', 'errorMsg'] as $key) if (isset($data[$key]) && trim((string)$data[$key]) !== '') return trim((string)$data[$key]);
        return $fallback;
    }

    private static function extractIdentifiers($data, $payUrl)
    {
        $result = ['pay_order_no' => self::firstDeep($data, ['payOrderNo', 'pay_order_no', 'payOrderId', 'pay_order_id']), 'order_no' => self::firstDeep($data, ['orderNo', 'order_no', 'mainOrderNo', 'main_order_no', 'sysTradeNo']), 'channel_trade_no' => self::firstDeep($data, ['channelTradeNo', 'channel_trade_no', 'tradeNo', 'trade_no'])];
        $parts = parse_url($payUrl);
        if (is_array($parts)) foreach (['query', 'fragment'] as $part) {
            if (empty($parts[$part])) continue;
            $variants = [(string)$parts[$part]];
            for ($i = 0; $i < 2; $i++) { $decoded = rawurldecode(end($variants)); if ($decoded === end($variants)) break; $variants[] = $decoded; }
            foreach ($variants as $text) { $parsed = []; parse_str(ltrim($text, '?'), $parsed); foreach ($parsed as $key => $value) if (is_scalar($value)) { foreach (['payOrderNo','pay_order_no','payOrderId','pay_order_id'] as $name) if ($result['pay_order_no'] === '' && $key === $name) $result['pay_order_no'] = (string)$value; foreach (['orderNo','order_no','mainOrderNo','main_order_no','sysTradeNo'] as $name) if ($result['order_no'] === '' && $key === $name) $result['order_no'] = (string)$value; foreach (['channelTradeNo','channel_trade_no','tradeNo','trade_no'] as $name) if ($result['channel_trade_no'] === '' && $key === $name) $result['channel_trade_no'] = (string)$value; } }
        }
        return $result;
    }

    private static function firstDeep($value, $keys)
    {
        if (!is_array($value)) return '';
        foreach ($value as $key => $item) {
            if (in_array((string)$key, $keys, true) && is_scalar($item) && trim((string)$item) !== '') return trim((string)$item);
            if (is_array($item)) { $found = self::firstDeep($item, $keys); if ($found !== '') return $found; }
        }
        return '';
    }

    private static function fieldLabel($item)
    {
        foreach ([$item['label'] ?? null, $item['fieldName'] ?? null, $item['options']['label'] ?? null, $item['options']['fieldName'] ?? null, $item['body']['label'] ?? null, $item['body']['fieldName'] ?? null] as $value) if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
        return '';
    }

    private static function requiredField($item)
    {
        foreach ([$item['required'] ?? null, $item['isRequired'] ?? null, $item['options']['required'] ?? null, $item['options']['isRequired'] ?? null, $item['body']['isRequired'] ?? null] as $value) if ($value === true || $value === 1 || $value === '1' || $value === 'true') return true;
        return false;
    }

    private static function hasTemplateValue($item, $options, $body)
    {
        foreach ([$item['value'] ?? null, $options['content'] ?? null, $options['value'] ?? null, $body['content'] ?? null, $body['value'] ?? null] as $value) if ($value !== null && $value !== '' && $value !== []) return true;
        return false;
    }

    private static function containsAny($value, $needles)
    {
        foreach ($needles as $needle) {
            if ($needle === '') continue;
            $found = function_exists('mb_strpos')
                ? mb_strpos((string)$value, $needle) !== false
                : strpos((string)$value, $needle) !== false;
            if ($found) return true;
        }
        return false;
    }

    private static function providerAmountCents($value)
    {
        if (!is_scalar($value) || trim((string)$value) === '') return null;
        $text = trim((string)$value);
        return preg_match('/^-?\d+$/', $text) ? (int)$text : (int)round((float)$text * 100);
    }

    private static function orderAmount($order)
    {
        $value = $order['realmoney'] ?? $order['money'] ?? $order['amount'] ?? 0;
        if (!is_numeric((string)$value) || (float)$value <= 0) throw new RuntimeException('订单金额无效');
        return number_format((float)$value, 2, '.', '');
    }

    private static function amountCents($value) { return (int)round((float)$value * 100); }

    private static function parseTime($value)
    {
        if (is_numeric($value)) { $time = (int)$value; if ($time > 20000000000) $time = (int)floor($time / 1000); return $time > 0 ? $time : 0; }
        $time = strtotime((string)$value); return $time === false ? 0 : $time;
    }

    private static function formatProviderTime($value)
    {
        $text = trim((string)$value); if ($text === '') return '';
        if (preg_match('/^\d{14}$/', $text)) { $date = DateTime::createFromFormat('!YmdHis', $text, new DateTimeZone('Asia/Shanghai')); return $date ? $date->format('Y-m-d H:i:s') : ''; }
        $time = strtotime($text); return $time ? date('Y-m-d H:i:s', $time) : '';
    }
}
