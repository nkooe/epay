<?php

if (!class_exists('aptos_chain_payment_core', false)) {
    abstract class aptos_chain_payment_core
    {
        const MAX_INCREMENT_STEPS = 10000;
        const PAYMENT_WINDOW_SECONDS = 600;
        const QUERY_GRACE_SECONDS = 900;


        abstract protected static function profile();
        abstract protected static function createClient($config);

        public static function submit()
        {
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
        }

        public static function mapi()
        {
            return self::submit();
        }

        public static function notify()
        {
            return ['type' => 'html', 'data' => 'success'];
        }

        public static function return()
        {
            return ['type' => 'page', 'page' => 'return'];
        }

        public static function query($order)
        {
            return ['status' => 0, 'api_trade_no' => '', 'money' => (float)($order['realmoney'] ?? 0)];
        }

        public static function qrcode()
        {
            global $order, $channel, $conf;
            $profile = static::profile();
            $title = ($order['typeshowname'] ?? $profile['showname']) . '-收银台';
            $browserTitle = !empty($conf['sitename']) ? '收银台-' . $conf['sitename'] : '收银台';
            $error_msg = '';
            $context = [];

            try {
                if ((int)($order['status'] ?? 0) !== 0) throw new RuntimeException('订单状态已改变，请返回订单页面查看结果');
                $config = static::channelConfig($channel);
                static::validateConfig($config);
                $existing = static::readExt($order['ext'] ?? '');
                if ($existing && !static::contextOwned($existing, $order)) throw new RuntimeException('订单支付上下文与当前插件不匹配');
                if (!$existing) {
                    $context = \lib\Payment::lockPayData((string)$order['trade_no'], function () use ($channel, $order, $config) {
                        return static::buildContext($channel, $order, $config);
                    });
                } else {
                    $context = $existing;
                }
                if (!static::contextValid($context, $order)) throw new RuntimeException('订单支付上下文不完整，请重新发起订单');
                if (time() > (int)$context['expire_at']) throw new RuntimeException('订单支付时间已过期，请重新发起');
                static::notifyNewOrderOnce($config, $order, $context);
            } catch (Throwable $e) {
                $error_msg = $e->getMessage();
            }

            $network = (string)($context['network'] ?? $profile['network']);
            $token = (string)($context['token'] ?? $profile['token']);
            $address = (string)($context['address'] ?? '');
            $display_amount = (string)($context['display_amount'] ?? '');
            $contract = (string)($context['contract'] ?? $profile['contract']);
            $payment_icon = '/plugins/' . $profile['plugin'] . '/' . $profile['icon'];
            $paytime = max(0, (int)($context['expire_at'] ?? time()) - time());
            if (!defined('IN_PLUGIN_PAGE')) define('IN_PLUGIN_PAGE', true);
            include PAY_ROOT . 'inc/qrcode.page.php';
            exit;
        }

        public static function pluginName()
        {
            $profile = static::profile();
            return (string)$profile['plugin'];
        }

        public static function channelConfig($channel)
        {
            $profile = static::profile();
            $rate = trim((string)($channel['appkey'] ?? ''));
            $decimals = trim((string)($channel['xiaoshu'] ?? ''));
            return [
                'address' => trim((string)($channel['appid'] ?? '')),
                'rate' => $rate === '' ? (float)$profile['default_rate'] : (float)$rate,
                'pay_time' => self::PAYMENT_WINDOW_SECONDS,
                'decimals' => $decimals === '' ? (int)$profile['default_decimals'] : (int)$decimals,
                'apikey' => trim((string)($channel['apikey'] ?? '')),
                'botid' => trim((string)($channel['botid'] ?? '')),
                'bottoken' => trim((string)($channel['bottoken'] ?? '')),
            ];
        }

        protected static function validateConfig($config)
        {
            $profile = static::profile();
            if (!static::validAddress($config['address'])) throw new RuntimeException($profile['network'] . ' 收款地址格式错误');
            if ((float)$config['rate'] <= 0) throw new RuntimeException('币种汇率必须大于 0');
            if ((int)$config['pay_time'] !== self::PAYMENT_WINDOW_SECONDS) throw new RuntimeException('支付有效期配置异常');
            if ((int)$config['decimals'] < 2 || (int)$config['decimals'] > 6) throw new RuntimeException('小数位数必须在 2-6 之间');
            if (!empty($profile['requires_api_key']) && $config['apikey'] === '') throw new RuntimeException($profile['network'] . ' API Key 不能为空');
        }

        protected static function validAddress($address)
        {
            $profile = static::profile();
            if (preg_match($profile['address_pattern'], (string)$address) !== 1) return false;
            if (($profile['address_checksum'] ?? '') === 'tron') return static::validTronAddress((string)$address);
            return true;
        }

        protected static function buildContext($channel, $order, $config)
        {
            global $DB;
            $profile = static::profile();
            $createdAt = strtotime((string)($order['addtime'] ?? ''));
            if ($createdAt === false) $createdAt = time();
            $fiat = (float)($order['realmoney'] ?? $order['money'] ?? 0);
            if ($fiat <= 0) throw new RuntimeException('订单金额无效');
            $decimals = (int)$config['decimals'];
            $scale = (int)pow(10, $decimals);
            $baseUnits = (int)round(($fiat / (float)$config['rate']) * $scale);
            if ($baseUnits < 1) throw new RuntimeException('换算后的链上金额过小');
            $expireAt = $createdAt + (int)$config['pay_time'];
            $lockName = 'chain:amount:' . substr(hash('sha256', $profile['plugin'] . "\0" . static::addressKey($config['address']) . "\0" . $profile['token']), 0, 45);
            if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,5)', [':lock_name' => $lockName]) !== 1) throw new RuntimeException('链上金额分配繁忙，请重试');
            try {
                $used = static::usedAmountUnits($profile['plugin'], $config['address'], $profile['token'], $createdAt, $expireAt, $decimals, (string)$order['trade_no']);
                $payUnits = 0;
                for ($i = 0; $i < self::MAX_INCREMENT_STEPS; $i++) {
                    $candidate = $baseUnits + $i;
                    if (!isset($used[$candidate])) { $payUnits = $candidate; break; }
                }
                if ($payUnits < 1) throw new RuntimeException('当前暂无可用金额，请稍后再试');
            } finally {
                $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
            }
            $amount = static::formatUnits($payUnits, $decimals);
            $context = [
                'provider' => $profile['plugin'], 'version' => 1, 'trade_no' => (string)$order['trade_no'],
                'channel_id' => (int)($order['channel'] ?? $channel['id'] ?? 0), 'subchannel_id' => (int)($order['subchannel'] ?? 0),
                'address' => $config['address'], 'network' => $profile['network'], 'token' => $profile['token'],
                'contract' => $profile['contract'], 'rate' => (float)$config['rate'], 'decimals' => $decimals,
                'asset_decimals' => (int)$profile['asset_decimals'],
                'fiat_amount' => $fiat, 'pay_units' => $payUnits, 'pay_amount' => $amount,
                $profile['amount_field'] => $amount, 'display_amount' => $amount,
                'created_at' => $createdAt, 'expire_at' => $expireAt, 'status' => 0, 'new_notified' => 0,
            ];
            return $context;
        }

        protected static function usedAmountUnits($plugin, $address, $token, $createdAt, $expireAt, $decimals, $excludeTradeNo)
        {
            global $DB;
            $rows = $DB->getAll(
                "SELECT A.trade_no,A.addtime,A.ext FROM pre_order A INNER JOIN pre_channel C ON A.channel=C.id WHERE C.plugin=:plugin AND A.trade_no<>:trade_no AND A.addtime>=DATE_SUB(NOW(),INTERVAL 3 HOUR)",
                [':plugin' => $plugin, ':trade_no' => $excludeTradeNo]
            );
            $used = [];
            foreach ($rows as $row) {
                $ext = static::readExt($row['ext'] ?? '');
                if (($ext['provider'] ?? '') !== $plugin || !static::addressEquals((string)($ext['address'] ?? ''), (string)$address) || strcasecmp((string)($ext['token'] ?? ''), (string)$token) !== 0) continue;
                $otherCreated = (int)($ext['created_at'] ?? strtotime((string)$row['addtime']));
                $otherExpire = (int)($ext['expire_at'] ?? 0);
                if ($otherCreated > $expireAt || $otherExpire < $createdAt) continue;
                $units = isset($ext['pay_units']) ? (int)$ext['pay_units'] : static::decimalToUnits((string)($ext['pay_amount'] ?? '0'), $decimals);
                if ($units > 0) $used[$units] = true;
            }
            return $used;
        }

        public static function monitorOnce(array $targets)
        {
            $groups = [];
            $summary = ['targets' => 0, 'orders' => 0, 'requests' => 0, 'settled' => 0, 'backoff' => 0, 'errors' => []];
            $summary['targets'] = count($targets);
            foreach ($targets as $target) {
                $channel = !empty($target['subchannel_id']) ? \lib\Channel::getSub((int)$target['subchannel_id']) : \lib\Channel::get((int)$target['channel_id']);
                if (!$channel || ($channel['plugin'] ?? '') !== static::pluginName()) continue;
                $config = static::channelConfig($channel);
                static::validateConfig($config);
                foreach (static::activeOrders($target) as $order) {
                    $ext = static::readExt($order['ext'] ?? '');
                    if (!static::contextValid($ext, $order) || time() > (int)$ext['expire_at'] + self::QUERY_GRACE_SECONDS) continue;
                    $key = hash('sha256', static::addressKey((string)$ext['address']) . "\0" . $config['apikey']);
                    if (!isset($groups[$key])) $groups[$key] = ['channel' => $channel, 'config' => $config, 'address' => (string)$ext['address'], 'orders' => [], 'start' => time()];
                    $order['_chain_context'] = $ext;
                    $order['_chain_channel'] = $channel;
                    $order['_chain_config'] = $config;
                    $groups[$key]['orders'][] = $order;
                    $groups[$key]['start'] = min($groups[$key]['start'], (int)$ext['created_at']);
                    $summary['orders']++;
                }
            }
            foreach ($groups as $key => $group) {
                \lib\Task\PaymentMonitor::progress();
                try {
                    $summary['requests']++;
                    $client = static::createClient($group['config']);
                    $transactions = $client->fetchTransactions($group['address'], $group['start'] - 30, time() + 30);
                } catch (Throwable $e) { throw $e; }
                foreach ($group['orders'] as $order) {
                    \lib\Task\PaymentMonitor::progress();
                    foreach ($transactions as $tx) {
                        if (!static::matchTransaction($order, $tx)) continue;
                        if (!method_exists($client, 'verifyTransaction')) continue;
                        try { $tx = $client->verifyTransaction($tx); }
                        catch (Throwable $e) { throw $e; }
                        if (!is_array($tx) || !static::matchTransaction($order, $tx, true)) continue;
                        if (static::settle($order, $tx, $order['_chain_config'], $order['_chain_channel'])) { $summary['settled']++; break; }
                    }
                }
            }
            return $summary;
        }

        protected static function printMonitorSummary($summary)
        {
            echo static::pluginName() . ' 检查完成：通道=' . (int)$summary['targets'] . '，有效未支付订单=' . (int)$summary['orders'] . '，接口请求=' . (int)$summary['requests'] . '，到账=' . (int)$summary['settled'] . PHP_EOL;
            foreach (array_unique((array)$summary['errors']) as $error) echo '错误：' . $error . PHP_EOL;
        }

        protected static function activeOrders($target)
        {
            global $DB;
            return $DB->getAll(
                "SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . (int)$target['channel_id'] . "' AND A.subchannel='" . (int)$target['subchannel_id'] . "' AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL 3 HOUR) ORDER BY A.addtime",
                []
            );
        }

        protected static function matchTransaction($order, $tx, $requireVerified = false)
        {
            $ext = $order['_chain_context'] ?? static::readExt($order['ext'] ?? '');
            if (!static::contextValid($ext, $order) || empty($tx['confirmed']) || trim((string)($tx['tx_id'] ?? '')) === '') return false;
            if ($requireVerified && empty($tx['verified'])) return false;
            $profile = static::profile();
            if ($requireVerified && !empty($profile['tx_pattern']) && preg_match($profile['tx_pattern'], (string)$tx['tx_id']) !== 1) return false;
            if (!static::addressEquals((string)($tx['to'] ?? ''), (string)$ext['address'])) return false;
            if (strcasecmp((string)($tx['token'] ?? ''), (string)$ext['token']) !== 0) return false;
            if ((string)$ext['contract'] !== '' && !static::contractEquals((string)($tx['contract'] ?? ''), (string)$ext['contract'])) return false;
            $timestamp = (int)($tx['timestamp'] ?? 0);
            if ($timestamp < (int)$ext['created_at'] || $timestamp > (int)$ext['expire_at']) return false;
            $assetDecimals = (int)($ext['asset_decimals'] ?? 6);
            $expectedRaw = static::decimalToRawUnits((string)$ext['pay_amount'], $assetDecimals);
            $actualRaw = static::normalizeRawUnits($tx['raw_amount'] ?? null);
            return $expectedRaw !== null && $expectedRaw !== '0' && $actualRaw !== null && hash_equals($expectedRaw, $actualRaw);
        }

        protected static function settle($order, $tx, $config, $runtimeChannel)
        {
            global $DB, $channel;
            $txId = trim((string)$tx['tx_id']);
            $lockName = 'chain:settle:' . substr(hash('sha256', static::pluginName() . "\0" . $txId), 0, 46);
            if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,3)', [':lock_name' => $lockName]) !== 1) return false;
            try {
                $fresh = $DB->getRow('SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$order['trade_no']]);
                if (!$fresh || (int)$fresh['status'] !== 0) return false;
                $fresh['_chain_context'] = static::readExt($fresh['ext'] ?? '');
                if (!static::matchTransaction($fresh, $tx, true)) return false;
                $used = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:tx_id AND trade_no<>:trade_no AND status=1 LIMIT 1', [':tx_id' => $txId, ':trade_no' => (string)$fresh['trade_no']]);
                if ($used !== false && $used !== null && $used !== '') return false;
                $fresh['plugin'] = static::pluginName();
                $channel = $runtimeChannel;
                \lib\Task\PaymentMonitor::notify($fresh, $txId, (string)($tx['from'] ?? ''), $txId, (string)($tx['token'] ?? ''), date('Y-m-d H:i:s', (int)$tx['timestamp']));
                if ((int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no', [':trade_no' => (string)$fresh['trade_no']]) !== 1) return false;
                $ext = $fresh['_chain_context'];
                $ext['status'] = 1; $ext['tx_id'] = $txId; $ext['sender'] = (string)($tx['from'] ?? ''); $ext['paid_at'] = (int)$tx['timestamp'];
                $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => (string)$fresh['trade_no']]);
                static::telegram($config, $fresh, $ext, $tx, true);
                return true;
            } finally {
                $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
            }
        }

        protected static function contextOwned($context, $order)
        {
            return is_array($context) && ($context['provider'] ?? '') === static::pluginName() && (string)($context['trade_no'] ?? '') === (string)($order['trade_no'] ?? '');
        }

        protected static function contextValid($context, $order)
        {
            $profile = static::profile();
            $decimals = (int)($context['decimals'] ?? 0);
            $assetDecimals = (int)($context['asset_decimals'] ?? -1);
            $payUnits = (int)($context['pay_units'] ?? 0);
            return static::contextOwned($context, $order)
                && static::validAddress((string)($context['address'] ?? ''))
                && (float)($context['pay_amount'] ?? 0) > 0
                && $payUnits > 0
                && $decimals >= 2 && $decimals <= 6
                && $assetDecimals === (int)$profile['asset_decimals']
                && static::decimalToUnits((string)$context['pay_amount'], $decimals) === $payUnits
                && (int)($context['created_at'] ?? 0) > 0
                && (int)($context['expire_at'] ?? 0) > (int)($context['created_at'] ?? 0)
                && (int)($context['channel_id'] ?? -1) === (int)($order['channel'] ?? -2)
                && (int)($context['subchannel_id'] ?? 0) === (int)($order['subchannel'] ?? 0)
                && strcasecmp((string)($context['token'] ?? ''), (string)$profile['token']) === 0
                && static::contractEquals((string)($context['contract'] ?? ''), (string)$profile['contract']);
        }

        protected static function readExt($value)
        {
            if (is_array($value)) return $value;
            $data = is_string($value) && $value !== '' ? @unserialize($value) : [];
            return is_array($data) ? $data : [];
        }

        protected static function formatUnits($units, $decimals)
        {
            $scale = (int)pow(10, $decimals); $whole = intdiv((int)$units, $scale); $fraction = (int)$units % $scale;
            return $whole . '.' . str_pad((string)$fraction, $decimals, '0', STR_PAD_LEFT);
        }

        protected static function decimalToUnits($value, $decimals)
        {
            $raw = static::decimalToRawUnits($value, $decimals);
            if ($raw === null || strlen($raw) > strlen((string)PHP_INT_MAX) || (strlen($raw) === strlen((string)PHP_INT_MAX) && strcmp($raw, (string)PHP_INT_MAX) > 0)) return -1;
            return (int)$raw;
        }

        protected static function decimalToRawUnits($value, $decimals)
        {
            $value = trim((string)$value); $decimals = (int)$decimals;
            if ($decimals < 0 || preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) return null;
            $fraction = (string)($matches[2] ?? '');
            if (strlen($fraction) > $decimals && trim(substr($fraction, $decimals), '0') !== '') return null;
            $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);
            $raw = ltrim($matches[1] . $fraction, '0');
            return $raw === '' ? '0' : $raw;
        }

        protected static function normalizeRawUnits($value)
        {
            if (is_int($value)) $value = (string)$value;
            if (!is_string($value)) return null;
            $value = trim($value);
            if ($value === '' || preg_match('/^\d+$/', $value) !== 1) return null;
            $value = ltrim($value, '0');
            return $value === '' ? '0' : $value;
        }

        protected static function addressEquals($left, $right)
        {
            $profile = static::profile();
            return !empty($profile['address_case_sensitive']) ? (string)$left === (string)$right : strcasecmp((string)$left, (string)$right) === 0;
        }

        protected static function contractEquals($left, $right)
        {
            $profile = static::profile();
            return !empty($profile['contract_case_sensitive']) ? (string)$left === (string)$right : strcasecmp((string)$left, (string)$right) === 0;
        }

        protected static function addressKey($address)
        {
            $profile = static::profile();
            return !empty($profile['address_case_sensitive']) ? (string)$address : strtolower((string)$address);
        }

        protected static function validTronAddress($address)
        {
            $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
            $bytes = [0];
            for ($i = 0, $length = strlen($address); $i < $length; $i++) {
                $value = strpos($alphabet, $address[$i]);
                if ($value === false) return false;
                $carry = $value;
                for ($j = 0, $size = count($bytes); $j < $size; $j++) {
                    $carry += $bytes[$j] * 58;
                    $bytes[$j] = $carry & 0xff;
                    $carry >>= 8;
                }
                while ($carry > 0) { $bytes[] = $carry & 0xff; $carry >>= 8; }
            }
            $decoded = '';
            for ($i = count($bytes) - 1; $i >= 0; $i--) $decoded .= chr($bytes[$i]);
            for ($i = 0; $i < strlen($address) && $address[$i] === '1'; $i++) $decoded = "\0" . $decoded;
            if (strlen($decoded) !== 25 || ord($decoded[0]) !== 0x41) return false;
            $payload = substr($decoded, 0, 21);
            $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
            return hash_equals($checksum, substr($decoded, 21, 4));
        }

        protected static function notifyNewOrderOnce($config, $order, $context)
        {
            global $DB;
            if ($config['botid'] === '' || $config['bottoken'] === '' || !empty($context['new_notified'])) return;
            $lockName = 'chain:notify:' . substr(hash('sha256', static::pluginName() . "\0" . (string)$order['trade_no']), 0, 46);
            if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lockName]) !== 1) return;
            $send = false; $fresh = [];
            try {
                $fresh = static::readExt($DB->getColumn('SELECT ext FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$order['trade_no']]));
                if (static::contextValid($fresh, $order) && empty($fresh['new_notified'])) {
                    $fresh['new_notified'] = 1;
                    $DB->update('order', ['ext' => serialize($fresh)], ['trade_no' => (string)$order['trade_no']]);
                    $send = true;
                }
            } finally {
                $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
            }
            if ($send) static::telegram($config, $order, $fresh, [], false);
        }

        protected static function telegram($config, $order, $context, $tx, $paid)
        {
            if ($config['botid'] === '' || $config['bottoken'] === '') return;
            $title = $paid ? '链上收款成功' : '新的链上订单';
            $lines = [$title, '网络：' . $context['network'], '金额：' . $context['display_amount'] . ' ' . $context['token'], '订单号：' . (string)$order['trade_no']];
            if ($paid && !empty($tx['tx_id'])) $lines[] = '交易：' . (string)$tx['tx_id'];
            $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($config['bottoken']) . '/sendMessage');
            if ($ch === false) return;
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $config['botid'], 'text' => implode("\n", $lines)]), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            @curl_exec($ch); curl_close($ch);
        }
    }
}

