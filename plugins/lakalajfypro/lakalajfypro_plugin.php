<?php

require_once __DIR__ . '/inc/client.php';

/**
 * 拉卡拉缴费易PRO。
 *
 * 旧版 epay 插件入口：下单由本插件生成缴费易收银台，查单由 受管收款监控
 * 按金额、备注、商户号和支付时间严格匹配。插件不写流程日志。
 */
class lakalajfypro_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('lakalajfypro', $channels, $context, static function($channel){
            require_once __DIR__.'/inc/client.php';
            $config=self::config($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['pid','merchant_no']);
        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\lakalajfypro\monitorTarget($target);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const EXPIRE_SECONDS = 300;
    const MATCH_SECONDS = 480;

    static public $info = [
        'name' => 'lakalajfypro',
        'showname' => '拉卡拉缴费易PRO',
        'author' => 'Coco',
        'link' => 'https://jfyh5.lakala.com/',
        'types' => ['wxpay', 'alipay', 'bank'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'pid' => ['name' => 'PID / 收款链接', 'type' => 'input', 'note' => '填写纯 PID 或缴费易 H5 收款链接'],
            'merchant_no' => ['name' => '商户号', 'type' => 'input', 'note' => '用于拉卡拉缴费易查单'],
            'store_name' => ['name' => '店铺名称', 'type' => 'input', 'note' => '收银台显示名称，可留空'],
            'proxy_mode' => ['name' => '代理模式', 'type' => 'select', 'options' => ['no_proxy' => '不使用代理', 'custom_proxy' => '自定义 Socks5/HTTP 代理', 'api' => '熊猫代理 API']],
            'proxy_custom' => ['name' => '代理地址', 'type' => 'input', 'note' => '例如 socks5://用户:密码@地址:端口 或 http://地址:端口'],
            'proxy_api' => ['name' => '代理 API 地址', 'type' => 'input', 'note' => '熊猫代理 API 完整地址，支持 TXT 或 JSON 返回'],
        ],
        'bindwxmp' => false,
        'bindwxa' => false,
        'note' => '<style>#form-info{text-align:left!important;overflow:visible}#form-info .form-group{clear:both}#form-info .lakalajfypro-hidden{display:none!important}.lakalajfypro-note{display:block;clear:both;margin-top:12px;line-height:1.8;color:#008000}</style><script>(function(){var f=document.getElementById("form-info"),m=f&&f.querySelector("select[name=\'config[proxy_mode]\']"),c=f&&f.querySelector("input[name=\'config[proxy_custom]\']"),a=f&&f.querySelector("input[name=\'config[proxy_api]\']");if(!f||!m)return;function row(e){return e&&e.closest?e.closest(".form-group"):null}var cr=row(c),ar=row(a);function setRow(e,visible){if(!e)return;e.classList.toggle("lakalajfypro-hidden",!visible);e.style.display=visible?"":"none"}function sync(){setRow(cr,m.value===\'custom_proxy\');setRow(ar,m.value===\'api\')}m.addEventListener(\'change\',sync);sync()}());</script>',
    ];

    static public function submit() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }
    static public function mapi() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }

    static public function qrcode()
    {
        global $order, $channel, $conf;
        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? '支付'));
        $storeName = trim((string)($channel['store_name'] ?? ''));
        $title = ($storeName !== '' ? $storeName : ($paymentName !== '' ? $paymentName : '支付')) . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $paymentIcon = '/assets/icon/' . (preg_match('/^[a-z0-9_-]+$/i', (string)($order['typename'] ?? '')) ? $order['typename'] : 'bank') . '.ico';
        $code_url = '';
        $error_msg = '';
        $context = self::readExt($order);
        $createdAt = self::orderTime($order);
        if ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif ($createdAt <= 0 || $createdAt + self::EXPIRE_SECONDS <= time()) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } else {
            try {
                $config = self::config($channel);
                if (self::contextOwned($context, $order) && !self::contextValid($context, $order, $config)) {
                    throw new RuntimeException('订单创建后通道配置已变更，请重新发起订单');
                }
                if (self::contextValid($context, $order, $config)) {
                    $code_url = (string)$context['pay_url'];
                } else {
                    $context = \lib\Payment::lockPayData(TRADE_NO, function () use ($channel, $order, $config) {
                        global $DB;
                        $current = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$order['trade_no']]);
                        $current = is_array($current) ? $current : $order;
                        $currentExt = self::readExt($current);
                        if (self::contextOwned($currentExt, $current) && !self::contextValid($currentExt, $current, $config)) {
                            throw new RuntimeException('订单创建后通道配置已变更，请重新发起订单');
                        }
                        if (self::contextValid($currentExt, $current, $config)) return $currentExt;
                        return lakalajfypro_client::createPayOrder($config, $current);
                    });
                    if (!self::contextValid($context, $order, $config)) {
                        throw new RuntimeException('订单支付上下文无效，请重新发起订单');
                    }
                    self::saveOrderContext($order, $context);
                    $code_url = (string)$context['pay_url'];
                }
            } catch (Throwable $e) {
                $error_msg = '拉卡拉缴费易下单失败：' . $e->getMessage();
            }
        }
        $paytime = max(0, $createdAt + self::EXPIRE_SECONDS - time());
        define('IN_PLUGIN_PAGE', true);
        include PAY_ROOT . 'inc/qrcode.page.php';
        exit;
    }

    static public function notify() { return ['type' => 'html', 'data' => 'success']; }
    static public function return() { return ['type' => 'page', 'page' => 'return']; }

    static public function query($order)
    {
        global $channel;
        $createdAt = self::orderTime($order);
        if ($createdAt <= 0 || $createdAt + self::MATCH_SECONDS <= time()) return ['status' => 0, 'api_trade_no' => '', 'money' => self::amount($order)];
        $context = self::readExt($order);
        if (($context['provider'] ?? '') !== 'lakalajfypro' || (string)($context['trade_no'] ?? '') !== (string)($order['trade_no'] ?? '')) throw new RuntimeException('订单不是拉卡拉缴费易PRO创建的订单');
        $config = self::config($channel);
        if ((string)($context['pid'] ?? '') !== lakalajfypro_client::extractPid($config['pid']) || (string)($context['merchant_no'] ?? '') !== $config['merchant_no']) throw new RuntimeException('订单配置与当前通道不一致');
        try {
            return lakalajfypro_client::queryOrder($config, $context, $order);
        } catch (Throwable $e) {
            return ['status' => 0, 'api_trade_no' => '', 'money' => self::amount($order), 'msg' => $e->getMessage()];
        }
    }

    static public function isExpired($order) { $time = self::orderTime($order); return $time <= 0 || $time + self::EXPIRE_SECONDS <= time(); }
    static public function isMatchExpired($order) { $time = self::orderTime($order); return $time <= 0 || $time + self::MATCH_SECONDS <= time(); }

    static public function markExpired($order)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0) return false;
        $ext = self::readExt($order);
        if (!empty($ext['lakalajfypro_expired'])) return true;
        $ext['lakalajfypro_expired'] = 1;
        $ext['lakalajfypro_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => (string)$order['trade_no']]) !== false;
    }

    private static function config($channel)
    {
        return [
            'pid' => trim((string)($channel['pid'] ?? '')),
            'merchant_no' => trim((string)($channel['merchant_no'] ?? '')),
            'store_name' => trim((string)($channel['store_name'] ?? '')),
            'proxy_mode' => trim((string)($channel['proxy_mode'] ?? 'no_proxy')) ?: 'no_proxy',
            'proxy_custom' => trim((string)($channel['proxy_custom'] ?? '')),
            'proxy_api' => trim((string)($channel['proxy_api'] ?? '')),
        ];
    }

    private static function readExt($order)
    {
        $value = $order['ext'] ?? '';
        if (!is_string($value) || $value === '') return [];
        $ext = @unserialize($value);
        return is_array($ext) ? $ext : [];
    }

    private static function saveOrderContext(&$order, $context)
    {
        global $DB;
        $ext = self::readExt($order);
        foreach ($context as $key => $value) $ext[$key] = $value;
        $serialized = serialize($ext);
        $apiTradeNo = (string)($context['order_no'] ?? $context['pay_order_no'] ?? '');
        if ($apiTradeNo !== '') @\lib\Payment::updateOrder((string)$order['trade_no'], $apiTradeNo);
        $order['ext'] = $serialized;
        $DB->update('order', ['ext' => $serialized], ['trade_no' => (string)$order['trade_no']]);
    }

    private static function contextValid($context, $order, $config)
    {
        return is_array($context)
            && ($context['provider'] ?? '') === 'lakalajfypro'
            && (string)($context['trade_no'] ?? '') === (string)($order['trade_no'] ?? '')
            && trim((string)($context['pay_url'] ?? '')) !== ''
            && (string)($context['pid'] ?? '') === lakalajfypro_client::extractPid($config['pid'])
            && (string)($context['merchant_no'] ?? '') === (string)$config['merchant_no']
            && (int)($context['amount_cents'] ?? 0) === (int)round((float)($order['realmoney'] ?? $order['money'] ?? 0) * 100);
    }

    private static function contextOwned($context, $order)
    {
        return is_array($context)
            && ($context['provider'] ?? '') === 'lakalajfypro'
            && (string)($context['trade_no'] ?? '') === (string)($order['trade_no'] ?? '')
            && trim((string)($context['pay_url'] ?? '')) !== '';
    }

    private static function orderTime($order)
    {
        $value = $order['addtime'] ?? '';
        if (is_numeric($value)) { $time = (int)$value; if ($time > 20000000000) $time = (int)floor($time / 1000); return $time; }
        $time = strtotime((string)$value);
        return $time === false ? 0 : $time;
    }

    private static function amount($order)
    {
        return number_format((float)($order['realmoney'] ?? $order['money'] ?? 0), 2, '.', '');
    }
}
