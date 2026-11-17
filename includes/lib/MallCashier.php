<?php

declare(strict_types=1);

namespace lib;

use lib\bridge\Protocol;
use RuntimeException;

/** The gateway hosts the cashier; the mall supplies signed product/order data. */
final class MallCashier
{
    public static function url(array $session): string
    {
        return rtrim(MallCheckout::config()['gateway_url'], '/') . '/pay/jspay/'
            . $session['trade_no'] . '/?cashier=' . $session['token'];
    }

    public static function request(array $session, string $action, int $typeId = 0): array
    {
        if (!in_array($action, ['reserve', 'quote', 'order'], true)) {
            throw new RuntimeException('未知收银台操作');
        }
        $result = Protocol::post(MallCheckout::config()['mall_url'], '/api/bridge/cashier', [
            'action' => $action, 'token' => $session['token'], 'type_id' => $typeId,
        ], MallCheckout::config());
        if ($action !== 'quote' && !isset($result['association'])) {
            throw new RuntimeException('商城未返回已签名的订单关联');
        }
        if (isset($result['association'])) {
            $association = $result['association'];
            if (
                !is_array($association) || ($association['trade_no'] ?? '') !== $session['trade_no']
                || ($association['action'] ?? '') !== ($action === 'order' ? 'ready' : 'reserve')
                || ($action === 'order' && ($association['type_id'] ?? 0) !== $typeId)
            ) {
                throw new RuntimeException('订单关联响应不一致');
            }
            $association['product_snapshot'] = $result['product'] ?? [];
            $linked = $action === 'order' ? MallCheckout::ready($association) : MallCheckout::reserve($association);
            unset($result['association']);
            if ($action === 'order') {
                return $linked;
            }
        }
        return $result;
    }

    private static function cookieName(array $session, string $purpose): string
    {
        return 'mall_' . $purpose . '_' . substr(hash('sha256', $session['token']), 0, 24);
    }

    private static function cookieKey(array $session): string
    {
        return hash_hmac('sha256', 'mall-cashier:' . $session['token'], SYS_KEY);
    }

    private static function cookie(string $name, string $value, int $expiry): void
    {
        setcookie($name, $value, ['expires' => $expiry, 'path' => '/', 'secure' => true,
            'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[$name] = $value;
    }

    public static function identity(array $session, string $appId): ?string
    {
        $encoded = $_COOKIE[self::cookieName($session, 'wechat')] ?? '';
        if (!is_string($encoded) || strlen($encoded) > 4096) {
            return null;
        }
        $plain = authcode($encoded, 'DECODE', self::cookieKey($session));
        $identity = json_decode($plain, true);
        if (
            !is_array($identity) || ($identity['app_id'] ?? '') !== $appId
            || ($identity['expires_at'] ?? 0) <= time()
            || !is_string($identity['openid'] ?? null)
            || !preg_match('/^[a-zA-Z0-9_-]{10,128}$/D', $identity['openid'])
        ) {
            return null;
        }
        return $identity['openid'];
    }

    /** Warm only a pinned channel or an unambiguous official JSAPI application. */
    public static function application(array $session, array $quote): ?array
    {
        global $DB;
        $order = $DB->getRow(
            'SELECT channel,subchannel,uid FROM pre_order WHERE trade_no=:trade',
            [':trade' => $session['trade_no']]
        );
        if (!$order) {
            return null;
        }
        $typeIds = array_column(array_filter($quote['methods'], static function (array $method): bool {
            return $method['name'] === 'wxpay';
        }), 'id');
        if (!$typeIds) {
            return null;
        }
        $channels = [];
        if ((int) $order['channel'] > 0) {
            $channelInfo = $DB->getColumn('SELECT channelinfo FROM pre_user WHERE uid=:uid', [':uid' => $order['uid']]);
            $channel = (int) $order['subchannel'] > 0
                ? Channel::getSub((int) $order['subchannel']) : Channel::get((int) $order['channel'], $channelInfo);
            if ($channel) {
                $channels[] = $channel;
            }
        } else {
            // Let the selected plugin authorize after route selection; never inspect other merchants' applications.
            return null;
        }
        $applications = [];
        foreach ($channels as $channel) {
            $types = is_array($channel['apptype']) ? $channel['apptype'] : explode(',', $channel['apptype']);
            if (
                !in_array((int) $channel['type'], $typeIds, true)
                || !in_array($channel['plugin'], ['wxpay', 'wxpayn'], true)
                || !in_array('2', $types, true) || (int) $channel['appwxmp'] <= 0
            ) {
                continue;
            }
            $application = Channel::getWeixin((int) $channel['appwxmp']);
            if ($application) {
                $applications[$application['appid']] = $application;
            }
        }
        return count($applications) === 1 ? reset($applications) : null;
    }

    /** Resolve the WeChat identity before rendering the product cashier, without a payment call. */
    public static function authorize(array $session, array $application): void
    {
        global $conf;
        $appId = $application['appid'];
        if (self::identity($session, $appId) !== null) {
            return;
        }
        $stateName = self::cookieName($session, 'oauth');
        if (isset($_GET['code'])) {
            $state = $_GET['state'] ?? '';
            if (
                !is_string($state) || !preg_match('/^[a-f0-9]{64}$/D', $state)
                || !hash_equals((string) ($_COOKIE[$stateName] ?? ''), $state)
                || !is_string($_GET['code']) || strlen($_GET['code']) > 256
            ) {
                throw new RuntimeException('微信授权状态无效，请重新扫码');
            }
            $oauth = new \lib\wechat\WechatOauth($appId, $application['appsecret']);
            $openid = $oauth->GetOpenidFromMp($_GET['code']);
            if (!preg_match('/^[a-zA-Z0-9_-]{10,128}$/D', $openid)) {
                throw new RuntimeException('微信授权未返回有效身份');
            }
            $expiry = (int) $session['expires_at'];
            $identity = json_encode(['app_id' => $appId, 'openid' => $openid, 'expires_at' => $expiry], JSON_THROW_ON_ERROR);
            self::cookie(self::cookieName($session, 'wechat'), authcode($identity, 'ENCODE', self::cookieKey($session), max(1, $expiry - time())), $expiry);
            self::cookie($stateName, '', time() - 3600);
            // Render this response directly. The cashier strips OAuth query parameters with replaceState.
            return;
        }
        $state = bin2hex(random_bytes(32));
        self::cookie($stateName, $state, min((int) $session['expires_at'], time() + 600));
        $openUrl = rtrim(($conf['wx_open_url'] ?? '') ?: 'https://open.weixin.qq.com', '/');
        header('Location: ' . $openUrl . '/connect/oauth2/authorize?' . http_build_query([
            'appid' => $appId, 'redirect_uri' => self::url($session), 'response_type' => 'code',
            'scope' => 'snsapi_base', 'state' => $state,
        ]) . '#wechat_redirect');
        exit;
    }

    public static function directIdentity(array $channel, array $order): ?array
    {
        if (
            !checkwechat() || !in_array($channel['plugin'], ['wxpay', 'wxpayn'], true)
            || !in_array('2', $channel['apptype'], true) || (int) $channel['appwxmp'] <= 0
        ) {
            return null;
        }
        $session = MallCheckout::mapping((string) $order['trade_no']);
        if (!$session || !$session['ready'] || $session['canceled']) {
            return null;
        }
        $application = Channel::getWeixin((int) $channel['appwxmp']);
        $openid = $application ? self::identity($session, $application['appid']) : null;
        return $openid === null ? null : ['openid' => $openid, 'app_id' => $application['appid']];
    }
}
