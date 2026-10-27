<?php

require_once __DIR__ . '/inc/client.php';

/** 收钱吧小店（旧版 epay 独立适配）。 */
class shouqianbaxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('shouqianbaxd', $channels, $context, static function($channel){
            $config=self::clientConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['username','password']);
        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach(\EpayMonitor\shouqianbaxd\groupShouqianbaxdTargets($targets) as $group){
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\shouqianbaxd\monitorShouqianbaxdGroup($group);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const PAYMENT_WINDOW_SECONDS = 300;
    const QUERY_GRACE_SECONDS = 480;
    const WECHAT_APP_ID = 'wx36e68952a60af089';
    const WECHAT_PAGE_PATH = 'KQPUJDAZVXH7/y4otuwrldsgy/index';
    const ALIPAY_APP_ID = '2019012963170386';
    const ALIPAY_PAGE_PATH = 'KQPUJDAZVXH7/y4otuwrldsgy/index';
    const MOBILE_PAGE_TYPE = 5;

    const ADMIN_NOTE = <<<'HTML'
<style>
#form-info{text-align:left!important;overflow:visible}#form-info .form-group{clear:both}#form-info .shouqianbaxd-hidden{display:none!important}
#form-info .shouqianbaxd-password{display:flex;align-items:center;gap:10px;width:100%}#form-info .shouqianbaxd-password input{flex:1 1 auto;min-width:0;width:auto!important}
#form-info #shouqianbaxd-sync{flex:0 0 78px;width:78px;min-height:40px;margin:0;white-space:nowrap}.shouqianbaxd-note{display:block;clear:both;margin-top:12px;line-height:1.8;color:#008000}
</style>
<script>
(function(){
var f=document.getElementById('form-info');if(!f)return;
var q=function(n){return f.querySelector('[name="config['+n+']"]')},u=q('username'),p=q('password'),mall=q('mall_sn'),t=q('token'),r=q('runtime_json'),c=q('login_checked_at'),m=q('proxy_mode'),pc=q('proxy_custom'),pa=q('proxy_api'),status=document.getElementById('shouqianbaxd-status'),save=f.querySelector('#save'),loginLive=false,initialUsername=u?u.value.trim():'',initialPassword=p?p.value:'';
['token','runtime_json','login_checked_at'].forEach(function(n){var e=q(n),g=e&&e.closest?e.closest('.form-group'):null;if(g){g.classList.add('shouqianbaxd-hidden');g.style.display='none'}});try{var localRuntime=JSON.parse(r&&r.value?r.value:'{}');loginLive=!!localRuntime.token&&Number(localRuntime.expire_at||0)>Math.floor(Date.now()/1000)+60}catch(_){loginLive=false}if(p)p.type='password';
function row(e){return e&&e.closest?e.closest('.form-group'):null}var pcr=row(pc),par=row(pa);function show(e,v){if(!e)return;e.classList.toggle('shouqianbaxd-hidden',!v);e.style.display=v?'':'none'}function proxy(){if(!m)return;show(pcr,m.value==='custom_proxy');show(par,m.value==='api')}if(m){m.addEventListener('change',proxy);proxy()}
function say(ok,msg){msg=msg||(ok?'操作成功':'操作失败');if(status){status.textContent=msg;status.style.color=ok?'#16803c':'#c62828'}else if(window.layer&&layer.msg){layer.msg(msg,{icon:ok?1:2,time:2500})}else{alert(msg)}}
var endpoint='./plugin_page.php?channel=[channel]&func=adminLogin';function post(action,data,keepalive){data=data||{};data.action=action;return fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:!!keepalive,headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(data)}).then(function(x){return x.text()}).then(function(x){var box=document.createElement('div');box.innerHTML=x;var node=box.querySelector('#shouqianbaxd-response');if(!node)throw Error('后台响应格式错误');return JSON.parse(node.value||node.textContent||'{}')})}function apply(x){if(t)t.value=x.token||'';if(r)r.value=x.runtime_json||'';if(c)c.value=x.login_checked_at||'';if(mall&&!mall.value.trim()&&x.mall_sn)mall.value=x.mall_sn}function alertResult(ok,msg){if(window.layer&&layer.alert){layer.alert(msg,{icon:ok?1:2})}else{alert(msg)}}function saved(needsLogin){if(needsLogin)post('activate',{},true).then(function(x){if(x.code!==0&&window.layer&&layer.msg)layer.msg('配置已保存，后台登录失败：'+(x.msg||'未知错误'),{icon:2,time:5000})}).catch(function(e){if(window.layer&&layer.msg)layer.msg('配置已保存，后台登录请求失败：'+(e.message||'未知错误'),{icon:2,time:5000})});var msg=needsLogin?'配置已保存，插件正在后台登录':'配置已保存，现有登录态继续使用';if(window.layer&&layer.closeAll){layer.closeAll();setTimeout(function(){layer.msg(msg,{icon:1,time:2000})},0)}else{alert(msg);location.reload()}}
var b=document.createElement('button');b.type='button';b.id='shouqianbaxd-sync';b.className='btn btn-success';b.textContent='同步';b.onclick=function(){if(!u||!u.value.trim()||!p||!p.value){say(false,'请先填写账号和密码');return}b.disabled=true;post('sync',{username:u.value,password:p.value}).then(function(x){if(x.code!==0)throw Error(x.msg||'没有可同步的登录态');apply(x);loginLive=true;say(true,x.msg||'已取得同账号登录态，保存后使用')}).catch(function(e){say(false,e.message||'同步失败')}).then(function(){b.disabled=false})};
var pg=p&&p.closest?p.closest('.form-group'):null;if(pg){var line=document.createElement('div');line.className='shouqianbaxd-password';pg.insertBefore(line,p);line.appendChild(p);line.appendChild(b)}
if(save){save.removeAttribute('onclick');save.onclick=function(){if(!u||!u.value.trim()||!p||!p.value){say(false,'账号和密码不能为空');return}var needsLogin=!loginLive||u.value.trim()!==initialUsername||p.value!==initialPassword;save.disabled=true;save.textContent='保存配置中';fetch('ajax_pay.php?act=saveChannelInfo&id=[channel]',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(new FormData(f))}).then(function(x){return x.json()}).then(function(x){if(x.code!==0)throw Error(x.msg||'保存配置失败');saved(needsLogin)}).catch(function(e){alertResult(false,e.message||'保存配置失败');save.disabled=false;save.textContent='保存'})}}
post('state').then(function(x){if(x.code===0){apply(x);loginLive=!!x.live;if(status)say(loginLive,x.msg)}}).catch(function(){loginLive=false;if(status)say(false)});
})();
</script>
HTML;

    static public $info = [
        'name' => 'shouqianbaxd',
        'showname' => '收钱吧小店',
        'author' => 'Coco',
        'link' => 'https://www.shouqianba.com/',
        'types' => ['wxpay', 'alipay', 'bank'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'username' => ['name' => '登录账号', 'type' => 'input', 'note' => '填写收钱吧商户平台账号'],
            'password' => ['name' => '登录密码', 'type' => 'input', 'note' => '保存后立即登录，同账号自动广播登录态'],
            'mall_sn' => ['name' => '小店编号', 'type' => 'input', 'note' => '可复用同账号已有编号，也可为当前通道单独填写'],
            'refund_password' => ['name' => '退款密码', 'type' => 'input', 'note' => '6位管理密码；留空时不能通过本插件退款'],
            'proxy_mode' => ['name' => '代理模式', 'type' => 'select', 'options' => ['no_proxy' => '不使用代理', 'custom_proxy' => '自定义 Socks5/HTTP 代理', 'api' => '熊猫代理 API']],
            'proxy_custom' => ['name' => '代理地址', 'type' => 'input', 'note' => '例如 socks5://用户:密码@地址:端口 或 http://地址:端口'],
            'proxy_api' => ['name' => '代理 API 地址', 'type' => 'input', 'note' => '完整 http/https 地址，支持 TXT 或 JSON 返回'],
            'token' => ['name' => '登录态 Token', 'type' => 'input', 'note' => '自动维护，请勿手动修改'],
            'runtime_json' => ['name' => '登录运行态', 'type' => 'textarea', 'note' => '自动维护，请勿手动修改'],
            'login_checked_at' => ['name' => '登录态同步时间', 'type' => 'input', 'note' => '自动维护'],
        ],
        'bindwxmp' => false,
        'bindwxa' => false,
        'note' => self::ADMIN_NOTE,
    ];

    static public function submit() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }
    static public function mapi() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }

    static public function qrcode()
    {
        global $order, $channel, $conf;
        $title = trim((string)($order['typeshowname'] ?? $order['typename'] ?? '收钱吧小店支付')) . '-收银台';
        $browserTitle = !empty($conf['sitename']) ? '收银台-' . $conf['sitename'] : '收银台';
        $paymentType = self::paymentType($order);
        $paymentIcon = '/assets/icon/' . ($paymentType === 'wxpay' ? 'wxpay' : ($paymentType === 'alipay' ? 'alipay' : 'bank')) . '.ico';
        $code_url = $open_url = $wechat_url = $alipay_url = '';
        $error_msg = '';
        $createdAt = self::orderCreatedAt($order);
        if ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif (!self::isPaymentWindowOpen($createdAt)) {
            $error_msg = '订单支付时间已过期，请重新发起';
        } else {
            try {
                $config = self::config($channel);
                $context = self::readOrderExt($order['ext'] ?? '');
                if (self::contextOwned($context, $order) && !self::contextValid($context, $order, $config)) throw new RuntimeException('订单创建后小店配置已改变，请重新发起订单');
                if (!self::contextValid($context, $order, $config)) {
                    $context = \lib\Payment::lockPayData(TRADE_NO, function () use ($channel, $order, $config, $createdAt) {
                        $client = self::createClient($channel);
                        $runtime = $client->getRuntime();
                        $amountCents = self::amountCents(self::orderAmount($order));
                        $created = $client->createPreOrder($amountCents, (string)$order['trade_no'], $config['mall_sn'], $runtime);
                        return [
                            'provider' => 'shouqianbaxd', 'trade_no' => (string)$order['trade_no'],
                            'pre_order_id' => (string)$created['pre_order_id'], 'pay_url' => (string)$created['pay_url'],
                            'amount_cents' => $amountCents, 'merchant_sn' => (string)$created['merchant_sn'],
                            'mall_sn' => (string)$config['mall_sn'], 'mall_signature' => (string)$created['mall_signature'],
                            'created_at' => $createdAt, 'pay_expires_at' => $createdAt + self::PAYMENT_WINDOW_SECONDS,
                            'query_expires_at' => $createdAt + self::QUERY_GRACE_SECONDS,
                        ];
                    });
                }
                if (!self::contextValid($context, $order, $config)) throw new RuntimeException('收钱吧小店未返回有效支付上下文');
                $links = self::mobileLaunchLinks($context);
                $code_url = $open_url = (string)$context['pay_url'];
                $wechat_url = $links['wechat_url'];
                $alipay_url = $links['alipay_url'];
            } catch (Throwable $e) { $error_msg = '收钱吧小店下单失败：' . $e->getMessage(); }
        }
        $paytime = max(0, $createdAt + self::PAYMENT_WINDOW_SECONDS - time());
        define('IN_PLUGIN_PAGE', true);
        include PAY_ROOT . 'inc/qrcode.page.php';
        exit;
    }

    static public function notify() { return ['type' => 'html', 'data' => 'success']; }
    static public function return() { return ['type' => 'page', 'page' => 'return']; }

    static public function query($order)
    {
        global $channel;
        $money = self::orderAmount($order);
        $createdAt = self::orderCreatedAt($order);
        if (!self::isQueryWindowOpen($createdAt)) return ['status' => 0, 'api_trade_no' => '', 'money' => $money];
        $context = self::readOrderExt($order['ext'] ?? '');
        self::assertOrderContext($order, $context);
        $client = self::createClient($channel);
        $runtime = $client->getRuntime();
        if ((string)$runtime['merchant_sn'] !== (string)$context['merchant_sn']) throw new RuntimeException('订单与当前收钱吧商户不一致');
        $records = $client->findTransactions($createdAt - 60, min(time() + 60, $createdAt + self::QUERY_GRACE_SECONDS), $runtime);
        return self::matchTransaction($order, $records);
    }

    static public function matchTransaction($order, $records)
    {
        $money = self::orderAmount($order);
        $context = self::readOrderExt($order['ext'] ?? '');
        self::assertOrderContext($order, $context);
        $createdAt = self::orderCreatedAt($order);
        foreach ((array)$records as $item) {
            if (!is_array($item) || (int)($item['status'] ?? 0) !== 2000) continue;
            if ((string)($item['reflect'] ?? '') !== (string)$order['trade_no']) continue;
            if ((int)($item['original_amount'] ?? 0) !== (int)$context['amount_cents']) continue;
            $finishedAt = self::timestamp($item['finish_time'] ?? ($item['ctime'] ?? 0));
            if ($finishedAt <= 0 || $finishedAt < $createdAt - 60 || $finishedAt > $createdAt + self::QUERY_GRACE_SECONDS) continue;
            $flowId = trim((string)($item['order_sn'] ?? ''));
            if ($flowId === '') continue;
            return [
                'status' => 1, 'api_trade_no' => $flowId, 'money' => $money,
                'buyer' => $item['buyer_uid'] ?? null, 'bill_trade_no' => $item['trade_no'] ?? null,
                'bill_mch_trade_no' => (string)$context['pre_order_id'], 'endtime' => date('Y-m-d H:i:s', $finishedAt),
            ];
        }
        return ['status' => 0, 'api_trade_no' => '', 'money' => $money];
    }

    static public function refund($order)
    {
        global $channel;
        try {
            $password = trim((string)($channel['refund_password'] ?? ''));
            if ($password === '') throw new RuntimeException('未配置收钱吧退款密码');
            return self::createClient($channel)->refund($order, $password);
        } catch (Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage()]; }
    }

    static public function adminLogin($channel)
    {
        $action = (string)($_REQUEST['action'] ?? 'state');
        self::adminResponse(self::appLogin($channel, $action, $_POST + $_GET));
    }

    static public function appLogin($channel, $action, array $input = [])
    {
        if ($action === 'state') {
            $config = self::clientConfig($channel);
            $runtime = self::loadRuntimeForAccount($channel, $config['username'], $config['password']);
            $live = !empty($runtime['token']) && (int)($runtime['expire_at'] ?? 0) > time() + 60;
            return ['code' => 0, 'live' => $live, 'msg' => $live ? '登录态正常' : '登录态失效', 'token' => (string)($runtime['token'] ?? ''), 'runtime_json' => $runtime ? json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '', 'login_checked_at' => (string)($channel['login_checked_at'] ?? ''), 'mall_sn' => self::sharedMallSn($config['username'], $config['password'])];
        }
        if ($action === 'sync') {
            $config = self::clientConfig($channel);
            $username = trim((string)($input['username'] ?? $config['username']));
            $password = (string)($input['password'] ?? $config['password']);
            if ($username === '' || $password === '') return ['code' => -1, 'msg' => '请先填写账号和密码'];
            $runtime = self::loadRuntimeForAccount($channel, $username, $password);
            if (empty($runtime['token']) || (int)($runtime['expire_at'] ?? 0) <= time() + 60) return ['code' => -1, 'msg' => '没有找到同账号可用的登录态，请重新保存配置自动登录'];
            $checkedAt = date('Y-m-d H:i:s');
            $count = self::broadcastRuntime($username, $password, $runtime, $checkedAt);
            if ($count < 1) return ['code' => -1, 'msg' => '同步登录态失败'];
            return ['code' => 0, 'live' => true, 'msg' => '已取得同账号登录态，小店编号为空时同时复用已有编号', 'token' => $runtime['token'], 'runtime_json' => json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'login_checked_at' => $checkedAt, 'mall_sn' => self::sharedMallSn($username, $password)];
        }
        if ($action !== 'activate') return ['code' => -1, 'msg' => '未知操作'];
        $runtimeChannel = !empty($channel['subid']) ? \lib\Channel::getSub((int)$channel['subid']) : \lib\Channel::get((int)$channel['id']);
        if (!$runtimeChannel) return ['code' => -1, 'msg' => '通道不存在'];
        $config = self::clientConfig($runtimeChannel);
        if ($config['username'] === '' || $config['password'] === '') return ['code' => -1, 'msg' => '账号和密码不能为空'];
        try {
            $mallSn = trim((string)($runtimeChannel['mall_sn'] ?? ''));
            if ($mallSn === '') $mallSn = self::sharedMallSn($config['username'], $config['password']);
            if ($mallSn === '') throw new RuntimeException('小店编号不能为空，且同账号没有可复用编号');
            $client = self::createClient($runtimeChannel);
            $runtime = $client->getRuntime(false);
            $client->mallSignature($mallSn, $runtime);
            $checkedAt = date('Y-m-d H:i:s');
            $updates = [
                'username' => $config['username'], 'password' => $config['password'], 'mall_sn' => $mallSn,
                'proxy_mode' => $config['proxy_mode'], 'proxy_custom' => $config['proxy_custom'], 'proxy_api' => $config['proxy_api'],
                'token' => (string)$runtime['token'], 'runtime_json' => json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'login_checked_at' => $checkedAt,
            ];
            if (!self::saveRuntimeConfig($channel, $updates)) throw new RuntimeException('登录成功，但保存当前通道配置失败');
            $count = self::broadcastRuntime($config['username'], $config['password'], $runtime, $checkedAt);
            return ['code' => 0, 'live' => true, 'msg' => '后台登录成功，登录态已同步到 ' . max(1, $count) . ' 个同账号通道', 'token' => $runtime['token'], 'runtime_json' => $updates['runtime_json'], 'login_checked_at' => $checkedAt, 'mall_sn' => $mallSn];
        } catch (Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage()]; }
    }

    static public function clientConfig($channel)
    {
        return [
            'channel_id' => $channel['id'] ?? 'default', 'subchannel_id' => $channel['subid'] ?? 0,
            'username' => trim((string)($channel['username'] ?? ($channel['account'] ?? ''))), 'password' => (string)($channel['password'] ?? ''),
            'proxy_mode' => trim((string)($channel['proxy_mode'] ?? 'no_proxy')) ?: 'no_proxy',
            'proxy_custom' => trim((string)($channel['proxy_custom'] ?? '')), 'proxy_api' => trim((string)($channel['proxy_api'] ?? '')),
        ];
    }

    static public function createClient($channel)
    {
        $config = self::clientConfig($channel); $username = $config['username']; $password = $config['password'];
        $config['runtime_loader'] = function () use ($channel, $username, $password) { return self::loadRuntimeForAccount($channel, $username, $password); };
        $config['runtime_saver'] = function ($runtime) use ($username, $password) { return self::broadcastRuntime($username, $password, $runtime, date('Y-m-d H:i:s')) >= 0; };
        return new shouqianbaxd_client($config);
    }

    static public function isPaymentWindowOpen($createdAt, $now = null) { $now = $now === null ? time() : (int)$now; return (int)$createdAt > 0 && (int)$createdAt + self::PAYMENT_WINDOW_SECONDS > $now; }
    static public function isQueryWindowOpen($createdAt, $now = null) { $now = $now === null ? time() : (int)$now; return (int)$createdAt > 0 && (int)$createdAt + self::QUERY_GRACE_SECONDS > $now; }

    static private function config($channel)
    {
        $config = self::clientConfig($channel); $config['mall_sn'] = trim((string)($channel['mall_sn'] ?? ''));
        if ($config['username'] === '') throw new RuntimeException('未配置收钱吧账号');
        if ($config['password'] === '') throw new RuntimeException('未配置收钱吧密码');
        if ($config['mall_sn'] === '') throw new RuntimeException('未配置收钱吧小店编号');
        return $config;
    }

    static private function paymentType($order)
    {
        $type = strtolower(trim((string)($order['typename'] ?? 'bank')));
        if (in_array($type, ['wx','wechat','weixin'], true)) return 'wxpay'; if ($type === 'ali') return 'alipay';
        return in_array($type, ['wxpay','alipay','bank'], true) ? $type : 'bank';
    }

    /** 根据预订单参数直接唤起对应支付 App，避免移动端先进入收钱吧 H5 中转页。 */
    static private function mobileLaunchLinks($context)
    {
        $mallSn = trim((string)($context['mall_sn'] ?? ''));
        $signature = trim((string)($context['mall_signature'] ?? ''));
        $preOrderId = trim((string)($context['pre_order_id'] ?? ''));
        if ($mallSn === '' || $signature === '' || $preOrderId === '') throw new RuntimeException('收钱吧小店缺少移动端唤起参数');
        $query = http_build_query(['mallSn' => $mallSn, 'signature' => $signature, 'pageType' => self::MOBILE_PAGE_TYPE, 'preOrderId' => $preOrderId], '', '&', PHP_QUERY_RFC3986);
        $wechat = 'weixin://dl/business/?appid=' . self::WECHAT_APP_ID . '&path=' . self::WECHAT_PAGE_PATH . '&query=' . rawurlencode($query) . '&env_version=release';
        $alipayInner = 'alipays://platformapi/startapp?appId=' . self::ALIPAY_APP_ID . '&page=' . self::ALIPAY_PAGE_PATH . '?' . rawurlencode($query);
        return ['wechat_url' => $wechat, 'alipay_url' => 'https://ds.alipay.com/?scheme=' . rawurlencode($alipayInner)];
    }

    static private function contextOwned($context, $order) { return is_array($context) && ($context['provider'] ?? '') === 'shouqianbaxd' && (string)($context['trade_no'] ?? '') === (string)($order['trade_no'] ?? ''); }
    static private function contextValid($context, $order, $config)
    {
        if (!self::contextOwned($context, $order)) return false;
        foreach (['pre_order_id','pay_url','merchant_sn','mall_sn','mall_signature'] as $key) if (trim((string)($context[$key] ?? '')) === '') return false;
        return (string)$context['mall_sn'] === (string)$config['mall_sn'] && (int)$context['amount_cents'] === self::amountCents(self::orderAmount($order));
    }

    static private function assertOrderContext($order, $context)
    {
        if (!self::contextOwned($context, $order) || trim((string)($context['pre_order_id'] ?? '')) === '' || trim((string)($context['merchant_sn'] ?? '')) === '' || (int)($context['amount_cents'] ?? 0) !== self::amountCents(self::orderAmount($order))) throw new RuntimeException('订单不是收钱吧小店创建的有效订单');
    }

    static private function readOrderExt($value) { $data = is_string($value) && $value !== '' ? @unserialize($value) : []; return is_array($data) ? $data : []; }
    static private function orderAmount($order) { $amount = $order['realmoney'] ?? ($order['money'] ?? 0); if (!is_numeric((string)$amount) || (float)$amount <= 0) throw new RuntimeException('订单金额无效'); return number_format((float)$amount, 2, '.', ''); }
    static private function amountCents($amount) { return (int)round((float)$amount * 100); }
    static private function orderCreatedAt($order) { return self::timestamp($order['addtime'] ?? 0); }
    static private function timestamp($value) { if (is_numeric($value)) { $time = (int)$value; if ($time > 20000000000) $time = (int)floor($time / 1000); return $time; } $time = strtotime((string)$value); return $time === false ? 0 : $time; }

    static private function loadRuntimeForAccount($channel, $username, $password)
    {
        $fingerprint = hash('sha256', trim((string)$username) . "\0" . md5((string)$password)); $targets = [$channel];
        foreach (self::accountTargets($username, $password) as $target) $targets[] = $target;
        $best = []; $now = time();
        foreach ($targets as $target) { $runtime = json_decode((string)($target['runtime_json'] ?? ''), true); if (!is_array($runtime) || !hash_equals($fingerprint, (string)($runtime['credential_fingerprint'] ?? ''))) continue; if (!$best || self::runtimeRank($runtime, $now) > self::runtimeRank($best, $now)) $best = $runtime; }
        return $best;
    }

    static private function runtimeRank($runtime, $now)
    {
        $usable = trim((string)($runtime['token'] ?? '')) !== '' && (int)($runtime['expire_at'] ?? 0) > (int)$now + 60;
        return sprintf('%d:%020d:%020d:%s', $usable ? 1 : 0, max(0, (int)($runtime['expire_at'] ?? 0)), max(0, (int)($runtime['updated_at'] ?? 0)), hash('sha256', json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
    }

    static private function broadcastRuntime($username, $password, $runtime, $checkedAt)
    {
        if (!is_array($runtime) || trim((string)($runtime['token'] ?? '')) === '') return -1;
        $runtime['credential_fingerprint'] = hash('sha256', trim((string)$username) . "\0" . md5((string)$password)); $runtime['updated_at'] = time();
        $json = json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($json === false) return -1;
        $targets = self::accountTargets($username, $password); $saved = 0;
        foreach ($targets as $target) if (self::saveRuntimeConfig($target, ['token' => (string)$runtime['token'], 'runtime_json' => $json, 'login_checked_at' => (string)$checkedAt])) $saved++;
        return $saved === count($targets) ? $saved : -1;
    }

    static private function sharedMallSn($username, $password)
    {
        foreach (self::accountTargets($username, $password) as $target) { $value = trim((string)($target['mall_sn'] ?? '')); if ($value !== '') return $value; }
        return '';
    }

    static private function accountTargets($username, $password)
    {
        global $DB; $username = trim((string)$username); $password = (string)$password; if ($username === '' || $password === '') return [];
        $targets = [];
        foreach ($DB->getAll("SELECT id FROM pre_channel WHERE plugin='shouqianbaxd' ORDER BY id ASC") as $row) {
            $main = \lib\Channel::get((int)$row['id']); if ($main && hash_equals($username, trim((string)($main['username'] ?? ''))) && hash_equals($password, (string)($main['password'] ?? ''))) $targets[] = $main;
            foreach ($DB->getAll("SELECT id FROM pre_subchannel WHERE channel='" . (int)$row['id'] . "' ORDER BY id ASC") as $subrow) { $sub = \lib\Channel::getSub((int)$subrow['id']); if ($sub && hash_equals($username, trim((string)($sub['username'] ?? ''))) && hash_equals($password, (string)($sub['password'] ?? ''))) $targets[] = $sub; }
        }
        return $targets;
    }

    static private function saveRuntimeConfig($channel, $updates)
    {
        global $DB; $channelId = (int)($channel['id'] ?? 0); $subchannelId = (int)($channel['subid'] ?? 0); if ($channelId <= 0) return false;
        if ($subchannelId <= 0) {
            $row = $DB->getRow("SELECT config FROM pre_channel WHERE id='" . $channelId . "' LIMIT 1"); if (!$row) return false; $config = json_decode((string)($row['config'] ?? ''), true); if (!is_array($config)) $config = [];
            foreach ($updates as $key => $value) $config[$key] = (string)$value;
            return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
        }
        $row = $DB->getRow("SELECT A.plugin,A.config,B.info FROM pre_subchannel B INNER JOIN pre_channel A ON B.channel=A.id WHERE B.id='" . $subchannelId . "' LIMIT 1"); if (!$row || (string)$row['plugin'] !== 'shouqianbaxd') return false;
        $parent = json_decode((string)($row['config'] ?? ''), true); $info = json_decode((string)($row['info'] ?? ''), true); if (!is_array($parent)) $parent = []; if (!is_array($info)) $info = [];
        $parentDirty = $infoDirty = false;
        foreach ($updates as $key => $value) { $raw = (string)($parent[$key] ?? ''); if (strlen($raw) >= 3 && $raw[0] === '[' && substr($raw, -1) === ']') { $mapped = substr($raw, 1, -1); if ($mapped !== '') { $info[$mapped] = (string)$value; $infoDirty = true; continue; } } $parent[$key] = (string)$value; $parentDirty = true; }
        $ok = true; if ($parentDirty) $ok = $DB->update('channel', ['config' => json_encode($parent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false; if ($infoDirty) $ok = $DB->update('subchannel', ['info' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $subchannelId]) !== false && $ok;
        return $ok;
    }

    static private function adminResponse($data) { echo '<textarea id="shouqianbaxd-response" style="display:none">' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</textarea>'; exit; }
}
