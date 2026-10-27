<?php

/**
 * 微信云端开单收款-XD。
 *
 * 这是旧版支付系统插件：云端负责扫码托管账号和获取小程序 code，
 * 开单、支付页、流水和取消请求由本机直接请求微信接口。代理配置只通过
 * X-Proxy-Cluster-* 请求头提交给云端，由云端使用代理访问微信。
 */
class yunkdskxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('yunkdskxd', $channels, $context, static function($channel){
            $config=self::getConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['gateway', 'sid', 'book_sid', 'shop_id', 'login_mode']);

        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\yunkdskxd\monitorTarget($target,$state);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const EXPIRE_SECONDS = 300;
    const FLOW_GRACE_SECONDS = 480;
    const RECEIPT_CANCEL_RETRY_SECONDS = 30;
    const FLOAT_STEP = 0.01;

    const LINK_APP_ID = 'wx9291fe7dadf5a574';
    const BOOK_APP_ID = 'wxe34a700d03e194f2';
    const LINK_HOST = 'https://sjtmgr.wxpapp.weixin.qq.com';
    const PAYAPP_HOST = 'https://payapp.weixin.qq.com';
    const LOGIN_VERSION = '1.15.1';
    const CREATE_VERSION = '1.31.1';
    const LIST_VERSION = '1.21.12';
    const SHOP_VERSION = '0.69.7';
    const ACCOUNT_TYPE = 'E_MINI_RECEIPT_EACCOUNT_TYPE_SJT';
    const ACCOUNT_TYPE_SHORT = 'E_ACCOUNT_TYPE_SJT';
    const OPERATOR_MANAGER = 'E_LINK_QRCODE_OPERATOR_ROLE_MANAGER';
    const OPERATOR_MERCHANT = 'E_MINI_RECEIPT_OPERATOR_ROLE_MCH';
    const SOURCE_TYPE = 'E_ORDER_TRADE_FROM_TYPE_ORDER_FOR_CUSTOMER';

    static public $info = [
        'name' => 'yunkdskxd',
        'showname' => '微信云端开单收款-XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['wxpay'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'gateway' => [
                'name' => '网关地址',
                'type' => 'input',
                'note' => '填写云端网关完整 URL',
            ],
            'login_mode' => [
                'name' => '登录方式',
                'type' => 'select',
                'options' => ['yyb' => '应用宝', 'guanjia' => '电脑管家'],
            ],
            'sid' => [
                'name' => '收款链接 SID',
                'type' => 'input',
                'note' => '扫码登录后自动获取',
            ],
            'book_sid' => [
                'name' => '开单 SID',
                'type' => 'input',
                'note' => '扫码登录后自动获取',
            ],
            'shop_id' => [
                'name' => '店铺 ID',
                'type' => 'input',
                'note' => '扫码登录后自动同步',
            ],
            'proxy_mode' => [
                'name' => '是否使用代理',
                'type' => 'select',
                'options' => ['no_proxy' => '否', 'custom_proxy' => '是'],
            ],
            'proxy' => [
                'name' => '代理地址',
                'type' => 'input',
                'note' => 'socks5://用户:密码@地址:端口 或 http://地址:端口',
            ],
        ],
        'bindwxmp' => false,
        'bindwxa' => false,
        'note' => '<style>
        form#form-info{overflow:visible;text-align:left!important}
        form#form-info #save,form#form-info .yunkdskxd-login{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;margin:0 0 12px!important;vertical-align:top}
        form#form-info #save{margin-left:4%!important}
        .yunkdskxd-login{text-align:center;line-height:20px;padding:10px 16px;background:#07c160;color:#fff!important;border-radius:4px;text-decoration:none}
        .yunkdskxd-login:hover{background:#06ad56;text-decoration:none}
        #form-info input[name="config[sid]"],#form-info input[name="config[book_sid]"],#form-info input[name="config[shop_id]"]{background:#f5f7fa;cursor:not-allowed}
        </style>
        <a class="yunkdskxd-login" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,\'yunkdskxd_login_[channel]\',\'width=470,height=720,resizable=yes,scrollbars=yes\');return false;">扫码登录</a>
        <span style="display:block;clear:both;line-height:1.8">点击“扫码登录”后自动获取收款链接 SID、开单 SID 和店铺 ID。登录态只有新扫码成功并完成全部同步后才替换旧登录态。</span>
        <script>(function(){
        var f=document.getElementById("form-info"),a=document.querySelector(".yunkdskxd-login"),s=document.getElementById("save");
        if(f&&a&&s){f.insertBefore(a,s)}
        ["sid","book_sid","shop_id"].forEach(function(k){var e=document.querySelector("input[name=\'config["+k+"]\']");if(e){e.readOnly=true;e.setAttribute("aria-readonly","true")}});
        var m=document.querySelector("select[name=\'config[proxy_mode]\']"),p=document.querySelector("input[name=\'config[proxy]\']");
        if(m&&p){var b=p.closest(".form-group");var sync=function(){if(b)b.style.display=m.value==="custom_proxy"?"":"none"};m.addEventListener("change",sync);sync()}
        }());</script>',
    ];

    static public function submit()
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    static public function mapi()
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    static public function qrcode()
    {
        global $order, $channel, $conf;
        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? '微信支付'));
        $title = $paymentName . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $paymentIcon = '/assets/icon/wxpay.ico';
        $error_msg = '';
        $code_url = '';

        if ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif (self::isExpired($order)) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } else {
            $status = self::checkChannelStatus($channel);
            if ((int)$status['code'] !== 1) {
                $error_msg = $status['msg'];
            } elseif (!self::reserveAmount($channel, $order)) {
                $error_msg = '金额分配失败，请稍后重试';
            } else {
                $created = self::createCode($channel, $order);
                if ((int)$created['code'] === 0) {
                    $code_url = $created['qrcode'];
                } else {
                    $error_msg = $created['msg'];
                }
            }
        }

        $paytime = max(0, strtotime((string)$order['addtime']) + self::EXPIRE_SECONDS - time());
        define('IN_PLUGIN_PAGE', true);
        include __DIR__ . '/inc/qrcode.page.php';
        exit;
    }

    static public function notify()
    {
        return ['type' => 'error', 'msg' => '本插件由 受管收款监控 轮询微信流水，不接收 HTTP 回调'];
    }

    /** Native administrator adapter; no HTML, exit, cookies or client-selected method. */
    static public function appLogin($channel, $action, array $input = [])
    {
        if ($action === 'start') return self::beginLogin($channel);
        if ($action === 'poll') return self::pollLogin($channel, (string)($input['session_id'] ?? ''));
        return ['code' => -1, 'msg' => '不支持的登录操作'];
    }

    static public function adminLogin($channel)
    {
        $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
        if ($action === 'start') self::adminResponse(self::beginLogin($channel));
        if ($action === 'poll') self::adminResponse(self::pollLogin($channel, (string)($_GET['session_id'] ?? '')));
        if ($action === 'state') self::adminResponse(['code' => 0, 'data' => self::getConfig($channel)]);

        $cfg = self::getConfig($channel);
        $endpoint = './plugin_page.php?channel=' . (int)$channel['id'] . '&func=adminLogin';
        $state = $cfg['sid'] !== '' ? '已保存登录态' : '尚未登录';
        $mode = $cfg['login_mode'] === 'guanjia' ? '电脑管家' : '应用宝';
        ?>
        <style>
        .yunkdskxd-login-box{max-width:430px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}
        .yunkdskxd-login-box h3{margin:0 0 10px}.yunkdskxd-login-meta{color:#666;line-height:1.8}.yunkdskxd-qr{width:260px;height:260px;margin:16px auto;border:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;justify-content:center;color:#888}.yunkdskxd-qr img{max-width:240px;max-height:240px}.yunkdskxd-login-state{min-height:24px;margin:8px 0;color:#1677ff;font-weight:600}.yunkdskxd-login-state.ok{color:#16803c}.yunkdskxd-login-state.bad{color:#c62828}.yunkdskxd-msg{min-height:24px;margin:10px 0;color:#555}.yunkdskxd-msg.ok{color:#16803c}.yunkdskxd-msg.bad{color:#c62828}.yunkdskxd-login-box button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}
        </style>
        <div class="yunkdskxd-login-box">
        <h3>微信云端开单收款-XD</h3>
        <div class="yunkdskxd-login-meta">通道：<?php echo htmlspecialchars((string)$channel['name'], ENT_QUOTES, 'UTF-8'); ?><br>登录方式：<?php echo $mode; ?><br>状态：<?php echo $state; ?></div>
        <div class="yunkdskxd-qr" id="yunkdskxd-qr">正在准备二维码…</div>
        <div class="yunkdskxd-login-state" id="yunkdskxd-login-state">状态：准备二维码</div>
        <div class="yunkdskxd-msg" id="yunkdskxd-msg"></div>
        <button type="button" id="yunkdskxd-start">重新获取二维码</button>
        </div>
        <script>
        (function(){var ep=<?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES); ?>,box=document.getElementById('yunkdskxd-qr'),state=document.getElementById('yunkdskxd-login-state'),msg=document.getElementById('yunkdskxd-msg'),btn=document.getElementById('yunkdskxd-start'),timer=0;
        function parse(t){var d=new DOMParser().parseFromString(t,'text/html'),n=d.querySelector('#yunkdskxd-response');if(!n)throw Error('后台响应格式错误');return JSON.parse(n.value||n.textContent||'{}')}
        function req(u){return fetch(u,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text()}).then(parse)}
        function say(t,c){msg.className='yunkdskxd-msg '+(c||'');msg.textContent=t||''}
        function setState(t,c){state.className='yunkdskxd-login-state '+(c||'');state.textContent='状态：'+(t||'')}
        function draw(v,k){box.innerHTML='';if(k==='image'||v.indexOf('data:image/')===0||/^https?:\/\//.test(v)&&v.indexOf('://')>0&&v.indexOf('qrcode')>=0){var i=document.createElement('img');i.src=v;i.alt='登录二维码';box.appendChild(i)}else if(window.jQuery&&jQuery.fn.qrcode){jQuery(box).qrcode({text:v,width:240,height:240,typeNumber:-1})}else{box.textContent=v}}
        function poll(id){req(ep+'&action=poll&session_id='+encodeURIComponent(id)).then(function(d){if(d.code===0){setState('登录成功','ok');say(d.msg||'账号信息已同步','ok');btn.disabled=false;try{if(window.opener&&!window.opener.closed)window.opener.location.reload()}catch(e){}return}if(d.code===201){setState(d.state==='scanned'?'已扫码，等待微信确认':'等待扫码');say(d.msg||'');timer=setTimeout(function(){poll(id)},2000);return}setState('登录失败','bad');say(d.msg||'登录失败','bad');btn.disabled=false}).catch(function(){setState('连接重试中','bad');say('后台请求失败，正在重试','bad');timer=setTimeout(function(){poll(id)},3000)})}
        function start(){clearTimeout(timer);btn.disabled=true;setState('等待扫码');say('');req(ep+'&action=start').then(function(d){if(d.code!==0){setState('获取二维码失败','bad');say(d.msg||'获取二维码失败','bad');btn.disabled=false;return}draw(d.qrcode,d.qrcode_type||'text');setState('等待扫码');poll(d.session_id)}).catch(function(e){setState('获取二维码失败','bad');say(e.message||'后台请求失败','bad');btn.disabled=false})}
        btn.onclick=start;start();}());
        </script>
        <?php
    }

    static private function adminResponse($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<textarea id="yunkdskxd-response" style="display:none">' . htmlspecialchars($json ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>';
        exit;
    }

    static public function beginLogin($channel)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址并保存'];
        if (!in_array($cfg['login_mode'], ['yyb', 'guanjia'], true)) return ['code' => -1, 'msg' => '请选择登录方式并保存'];
        if ($cfg['login_mode'] === 'yyb') {
            $r = self::requestJson('POST', $cfg['gateway'] . '/yyb/qr', [], $cfg, true);
            $data = self::payload($r);
            if ($r['code'] !== 0) return $r;
            return self::loginQrResult($data, (string)($data['session_id'] ?? ''), $cfg);
        }
        $r = self::requestJson('POST', $cfg['gateway'] . '/guanjia/api/qrcode', ['appid' => self::LINK_APP_ID], $cfg, true);
        $data = self::payload($r);
        if ($r['code'] !== 0) return $r;
        return self::loginQrResult($data, (string)($data['uuid'] ?? ''), $cfg);
    }

    static private function loginQrResult($data, $session, $cfg)
    {
        if ($session === '') return ['code' => -1, 'msg' => '云端未返回登录会话'];
        foreach (['image', 'image_base64', 'image_url', 'qrcode_image'] as $imageKey) {
            if (!isset($data[$imageKey]) || (string)$data[$imageKey] === '') continue;
            $value = (string)$data[$imageKey];
            if (in_array($imageKey, ['image_url', 'qrcode_image'], true) && strpos($value, '/') === 0) {
                $value = rtrim($cfg['gateway'], '/') . '/' . ltrim($value, '/');
            }
            return ['code' => 0, 'session_id' => $session, 'qrcode' => self::normalizeQr($value), 'qrcode_type' => 'image'];
        }
        $qrcode = self::first($data, ['qrcode', 'qr', 'qr_code', 'url', 'login_url']);
        if ($qrcode === '') return ['code' => -1, 'msg' => '云端未返回登录二维码'];
        $isImage = self::isImageValue($qrcode) || (bool)preg_match('/\.(?:png|jpe?g|gif|webp)(?:\?|$)/i', $qrcode);
        return ['code' => 0, 'session_id' => $session, 'qrcode' => self::normalizeQr($qrcode), 'qrcode_type' => $isImage ? 'image' : 'text'];
    }

    static public function pollLogin($channel, $session)
    {
        $cfg = self::getConfig($channel);
        if ($session === '') return ['code' => -1, 'msg' => '登录会话为空'];
        $ref = '';
        $code = '';
        if ($cfg['login_mode'] === 'yyb') {
            $r = self::requestJson('GET', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/poll', null, $cfg, true);
            $data = self::payload($r);
            if ($r['code'] !== 0) return $r;
            $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? '')));
            if ($status !== 'authorized') return self::loginPending($status, '应用宝');
            $confirmed = self::requestJson('POST', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/confirm', [], $cfg, true);
            $account = self::payload($confirmed);
            if ($confirmed['code'] !== 0) return $confirmed;
            $ref = self::first($account, ['ref', 'openid', 'uin', 'id']);
            if ($ref === '') return ['code' => -1, 'msg' => '应用宝未返回账号标识'];
        } else {
            $r = self::requestJson('GET', $cfg['gateway'] . '/guanjia/api/status?uuid=' . rawurlencode($session), null, $cfg, true);
            $data = self::payload($r);
            if ($r['code'] !== 0) return $r;
            $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? '')));
            if ($status !== 'authorized') return self::loginPending($status, '电脑管家');
            $account = is_array($data['result'] ?? null) ? $data['result'] : $data;
            $ref = self::first($account, ['ref', 'bind_account', 'openid', 'uin', 'id']);
            $code = self::first($account, ['code', 'js_code', 'jscode']);
            if ($ref === '') return ['code' => -1, 'msg' => '电脑管家未返回账号标识'];
        }

        if ($code === '') {
            $code = self::getCloudCode($cfg, $ref, self::LINK_APP_ID);
            if ($code['code'] !== 0) return $code;
            $code = (string)$code['value'];
        }
        $link = self::linkLogin($code, $cfg);
        if ($link['code'] !== 0) return ['code' => -1, 'msg' => '收款链接登录失败：' . $link['msg']];
        $sid = self::extract($link['data'], ['sid', 'Sid', 'receipt_sid', 'receiptSid']);
        if ($sid === '') return ['code' => -1, 'msg' => '收款链接登录未返回 SID'];

        $bookCode = self::getCloudCode($cfg, $ref, self::BOOK_APP_ID);
        if ($bookCode['code'] !== 0) return $bookCode;
        $book = self::bookLogin((string)$bookCode['value'], $cfg);
        if ($book['code'] !== 0) return ['code' => -1, 'msg' => '开单登录失败：' . $book['msg']];
        $bookSid = self::extract($book['data'], ['sid', 'Sid', 'book_sid', 'session_id']);
        if ($bookSid === '') return ['code' => -1, 'msg' => '开单登录未返回 SID'];

        $shop = self::getShopInfo($sid, $cfg);
        if ($shop['code'] !== 0) return $shop;
        $updates = [
            'sid' => $sid,
            'book_sid' => $bookSid,
            'cloud_ref' => $ref,
            'sid_login_mode' => $cfg['login_mode'],
            'shop_id' => (string)$shop['shop_id'],
        ];
        if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '登录成功，但写入通道配置失败'];
        return ['code' => 0, 'msg' => '登录成功，SID 和店铺已同步'];
    }

    static private function loginPending($status, $mode)
    {
        $status = strtolower(trim((string)$status));
        if (in_array($status, ['scanned', 'scaned', 'confirmed', 'confirming', 'ready_confirm'], true)) {
            return ['code' => 201, 'state' => 'scanned', 'msg' => '已扫码，请在微信中确认'];
        }
        if (in_array($status, ['expired', 'timeout', 'cancelled', 'canceled', 'failed', 'rejected'], true)) {
            return ['code' => -1, 'state' => 'failed', 'msg' => $mode . '二维码已失效，请重新获取'];
        }
        return ['code' => 201, 'state' => 'waiting', 'msg' => '等待扫码'];
    }

    static private function getCloudCode($cfg, $ref, $appid)
    {
        $path = $cfg['login_mode'] === 'yyb' ? '/yyb/wxapp/getCode' : '/guanjia/api/get-code';
        $body = $cfg['login_mode'] === 'yyb' ? ['ref' => $ref, 'app_id' => $appid] : ['ref' => $ref, 'appid' => $appid];
        $r = self::requestJson('POST', $cfg['gateway'] . $path, $body, $cfg, true);
        if ($r['code'] !== 0) return $r;
        $value = self::extract(self::payload($r), ['code', 'js_code', 'jscode', 'Code']);
        return $value === '' ? ['code' => -1, 'msg' => '云端未返回小程序登录 code'] : ['code' => 0, 'value' => $value];
    }

    static private function linkLogin($code, $cfg)
    {
        $url = self::LINK_HOST . '/sjt/linkqrcode/linkqrcodesource/getshopinfo?jscode=' . rawurlencode($code) . '&v=' . self::LOGIN_VERSION;
        return self::requestJson('POST', $url, ['v' => self::LOGIN_VERSION, 'account_type' => 'E_ACCOUNT_TYPE_F2F', 'operator_role' => self::OPERATOR_MANAGER, 'merchant_identifier' => '', 'jscode' => $code], $cfg, false, self::LINK_APP_ID);
    }

    static private function bookLogin($code, $cfg)
    {
        return self::requestJson('GET', self::PAYAPP_HOST . '/sjtbook/user/login?jscode=' . rawurlencode($code), null, $cfg, false, self::BOOK_APP_ID);
    }

    static private function getShopInfo($sid, $cfg)
    {
        $url = self::PAYAPP_HOST . '/sjtaccount/getopenshoplist?sid=' . rawurlencode($sid) . '&v=' . self::SHOP_VERSION;
        $r = self::requestJson('GET', $url, null, $cfg, false);
        if ($r['code'] !== 0) return ['code' => -1, 'msg' => '获取店铺信息失败：' . $r['msg']];
        $data = self::payload($r);
        $shopId = (int)self::first($data, ['default_shop_id', 'shop_id', 'shopId']);
        $rows = self::arrayList($data, ['shop_info_list', 'shop_list', 'list']);
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if ($shopId <= 0) $shopId = (int)self::first($row, ['shop_id', 'shopId', 'id']);
            if ($shopId > 0) break;
        }
        if ($shopId <= 0) return ['code' => -1, 'msg' => '登录成功但未获取到店铺 ID'];
        return ['code' => 0, 'shop_id' => $shopId];
    }

    static private function arrayList($value, $keys)
    {
        if (!is_array($value)) return [];
        foreach ($keys as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $list = $value[$key];
                // 单个账号/店铺对象也统一成列表。
                if (self::first($list, ['shop_id', 'shopId', 'id']) !== '') return [$list];
                return $list;
            }
        }
        foreach (['data', 'result', 'account', 'user'] as $child) {
            if (!isset($value[$child])) continue;
            $nested = self::arrayList($value[$child], $keys);
            if ($nested !== []) return $nested;
        }
        return [];
    }

    static public function checkChannelStatus($channel)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['gateway'] === '' || $cfg['cloud_ref'] === '' || $cfg['sid'] === '') return ['code' => 0, 'msg' => '尚未扫码登录云端，已禁止发起订单'];
        $path = $cfg['login_mode'] === 'guanjia' ? '/guanjia/api/accounts' : '/yyb/accounts';
        $url = $cfg['gateway'] . $path . '?ref=' . rawurlencode($cfg['cloud_ref']);
        $r = self::requestJson('GET', $url, null, $cfg, true);
        if ($r['code'] !== 0) return ['code' => -1, 'msg' => '云端状态检查失败，已禁止发起订单：' . $r['msg']];
        $accounts = self::payload($r);
        if (!is_array($accounts)) $accounts = [];
        if (isset($accounts['accounts']) && is_array($accounts['accounts'])) $accounts = $accounts['accounts'];
        if (isset($accounts['list']) && is_array($accounts['list'])) $accounts = $accounts['list'];
        foreach ($accounts as $account) {
            if (!is_array($account)) continue;
            $refs = [$account['ref'] ?? '', $account['bind_account'] ?? '', $account['openid'] ?? '', $account['uin'] ?? '', $account['id'] ?? ''];
            if (!in_array($cfg['cloud_ref'], array_map('strval', $refs), true)) continue;
            $status = strtolower(trim((string)($account['status'] ?? $account['state'] ?? '')));
            if (in_array($status, ['online', 'alive', 'active', '1', 'ok'], true)) return ['code' => 1, 'msg' => '云端在线'];
            if (in_array($status, ['offline', 'dead', 'logout', 'logged_out', 'expired', '0'], true)) return ['code' => 0, 'msg' => '云端明确返回离线，请重新扫码登录'];
            return ['code' => -1, 'msg' => '云端未返回可识别的在线状态，已禁止发起订单'];
        }
        return ['code' => 0, 'msg' => '云端未找到当前登录账号，请重新扫码登录'];
    }

    /**
     * 微信接口把 SID 失效统一返回成业务错误文本，HTTP 状态可能仍为 200。
     * 这里只用于决定是否刷新对应登录态，不把普通业务错误当成鉴权错误。
     */
    static private function sidInvalidText($value)
    {
        $text = strtolower(trim((string)$value));
        foreach (['用户鉴权错误', '用户登录态鉴权失败', '鉴权失败', '登录态失效', '登录态无效', '登录失败', '会话失效', 'session invalid', 'invalid sid'] as $needle) {
            if (strpos($text, strtolower($needle)) !== false) return true;
        }
        return false;
    }

    /** 只刷新开单小程序 SID，成功后才替换数据库中的旧 book_sid。 */
    static private function refreshBookSid($channel, $cfg)
    {
        if (trim((string)$cfg['cloud_ref']) === '') return ['code' => -1, 'msg' => '缺少云端账号标识，无法刷新开单 SID'];
        $code = self::getCloudCode($cfg, $cfg['cloud_ref'], self::BOOK_APP_ID);
        if ((int)$code['code'] !== 0) return ['code' => -1, 'msg' => '获取开单登录 code 失败：' . $code['msg']];
        $login = self::bookLogin((string)$code['value'], $cfg);
        if ((int)$login['code'] !== 0) return ['code' => -1, 'msg' => '刷新开单 SID 失败：' . $login['msg']];
        $sid = self::extract($login['data'], ['sid', 'Sid', 'book_sid', 'session_id']);
        if ($sid === '') return ['code' => -1, 'msg' => '刷新开单 SID 失败：接口未返回 SID'];
        if (!self::saveConfig((int)$channel['id'], ['book_sid' => $sid])) return ['code' => -1, 'msg' => '刷新开单 SID 成功，但写入配置失败'];
        return ['code' => 0, 'sid' => $sid, 'msg' => ''];
    }

    /** 只刷新收款链接小程序 SID，成功后才替换数据库中的旧 sid。 */
    static private function refreshLinkSid($channel, $cfg)
    {
        if (trim((string)$cfg['cloud_ref']) === '') return ['code' => -1, 'msg' => '缺少云端账号标识，无法刷新收款链接 SID'];
        $code = self::getCloudCode($cfg, $cfg['cloud_ref'], self::LINK_APP_ID);
        if ((int)$code['code'] !== 0) return ['code' => -1, 'msg' => '获取收款链接登录 code 失败：' . $code['msg']];
        $login = self::linkLogin((string)$code['value'], $cfg);
        if ((int)$login['code'] !== 0) return ['code' => -1, 'msg' => '刷新收款链接 SID 失败：' . $login['msg']];
        $sid = self::extract($login['data'], ['sid', 'Sid', 'receipt_sid', 'receiptSid']);
        if ($sid === '') return ['code' => -1, 'msg' => '刷新收款链接 SID 失败：接口未返回 SID'];
        if (!self::saveConfig((int)$channel['id'], ['sid' => $sid])) return ['code' => -1, 'msg' => '刷新收款链接 SID 成功，但写入配置失败'];
        return ['code' => 0, 'sid' => $sid, 'msg' => ''];
    }

    static public function createCode($channel, &$order)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['sid'] === '' || $cfg['book_sid'] === '' || $cfg['shop_id'] === '') return ['code' => -1, 'msg' => '登录态配置不完整，请重新扫码登录'];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = self::createCodeOnce($channel, $order, $cfg);
            if ((int)$result['code'] === 0) return $result;
            if ($attempt !== 1 || ($result['auth_scope'] ?? '') !== 'book') return ['code' => -1, 'msg' => $result['msg']];
            $refresh = self::refreshBookSid($channel, $cfg);
            if ((int)$refresh['code'] !== 0) return ['code' => -1, 'msg' => $result['msg'] . '；' . $refresh['msg']];
            $cfg['book_sid'] = $refresh['sid'];
        }
        return ['code' => -1, 'msg' => '创建开单商品失败'];
    }

    static private function createCodeOnce($channel, &$order, $cfg)
    {
        $amount = (int)round((float)$order['realmoney'] * 100);
        if ($amount <= 0) return ['code' => -1, 'msg' => '收款金额无效'];
        $itemName = self::orderTail($order['trade_no']);
        $base = self::LINK_HOST . '/order4customer/manage/';
        $common = ['v' => self::CREATE_VERSION, 'comm_req' => ['shop_id' => (int)$cfg['shop_id'], 'account_type' => self::ACCOUNT_TYPE_SHORT], 'idempotent_id' => $itemName];
        $create = self::requestJson('POST', $base . 'createreceiptitems?sid=' . rawurlencode($cfg['book_sid']) . '&v=' . self::CREATE_VERSION, $common + ['category_list' => [['receipt_item_list' => [['name' => $itemName, 'amount' => $amount]]]], 'sid' => $cfg['book_sid']], $cfg, false);
        if ($create['code'] !== 0) return self::bookAuthResult('创建开单商品失败：' . $create['msg']);
        $listQuery = http_build_query(['v' => self::CREATE_VERSION, 'comm_req' => json_encode($common['comm_req'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'sid' => $cfg['book_sid']]);
        $listed = self::requestJson('GET', $base . 'listcategorieswithitems?' . $listQuery, null, $cfg, false);
        if ($listed['code'] !== 0) return self::bookAuthResult('读取开单商品失败：' . $listed['msg']);
        $item = self::findItem(self::payload($listed), $itemName);
        if (!$item) return ['code' => -1, 'msg' => '未找到刚创建的开单商品'];
        $submit = self::requestJson('POST', $base . 'submitorder?sid=' . rawurlencode($cfg['book_sid']) . '&v=' . self::CREATE_VERSION, $common + ['receipt_item_list' => [['id' => $item['id'], 'name' => $item['name'], 'amount' => $item['amount'], 'quantity' => 1]], 'total_amount' => $item['amount'], 'total_quantity' => 1, 'sid' => $cfg['book_sid']], $cfg, false);
        if ($submit['code'] !== 0) { if (self::sidInvalidText($submit['msg'])) self::deleteItem($cfg, (int)$item['id']); return self::bookAuthResult('提交开单订单失败：' . $submit['msg']); }
        $data = self::payload($submit);
        $miniId = self::first($data, ['mini_receipt_order_id', 'miniReceiptOrderId']);
        if ($miniId === '') return ['code' => -1, 'msg' => '开单接口未返回订单号'];
        $orderId = preg_replace('/_\d+$/', '', $miniId);
        $outOrderNo = self::first($data, ['out_order_no', 'outOrderNo', 'order_no', 'orderNo']);
        if ($outOrderNo === '') $outOrderNo = self::orderTail($order['trade_no']);
        if (!self::saveReceiptMeta($order, $miniId, $orderId, $outOrderNo)) {
            self::cancelReceiptById($cfg, $miniId, $outOrderNo);
            return ['code' => -1, 'msg' => '小票单已创建，但本地订单未保存小票单号'];
        }
        $commReq = json_encode(['account_type' => self::ACCOUNT_TYPE_SHORT, 'shop_id' => (int)$cfg['shop_id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $page = self::requestJson('GET', $base . 'getpaymentpageinfo?v=' . self::LIST_VERSION . '&comm_req=' . rawurlencode($commReq) . '&order_id=' . rawurlencode($orderId) . '&sid=' . rawurlencode($cfg['sid']), null, $cfg, false);
        if ($page['code'] !== 0 && self::sidInvalidText($page['msg'])) {
            $refresh = self::refreshLinkSid($channel, $cfg);
            if ((int)$refresh['code'] !== 0) return ['code' => -1, 'msg' => '获取支付二维码失败：' . $page['msg'] . '；' . $refresh['msg']];
            $cfg['sid'] = $refresh['sid'];
            $page = self::requestJson('GET', $base . 'getpaymentpageinfo?v=' . self::LIST_VERSION . '&comm_req=' . rawurlencode($commReq) . '&order_id=' . rawurlencode($orderId) . '&sid=' . rawurlencode($cfg['sid']), null, $cfg, false);
        }
        if ($page['code'] !== 0) return ['code' => -1, 'msg' => '获取支付二维码失败：' . $page['msg']];
        $qr = self::first(self::payload($page), ['qr_code_url', 'qrcode_url', 'pay_url', 'url']);
        if ($qr === '') return ['code' => -1, 'msg' => '支付接口未返回二维码地址'];
        self::deleteItem($cfg, (int)$item['id']);
        return ['code' => 0, 'qrcode' => self::normalizeQr($qr), 'third_id' => $miniId];
    }

    static private function bookAuthResult($message)
    {
        return ['code' => -1, 'msg' => $message, 'auth_scope' => self::sidInvalidText($message) ? 'book' : ''];
    }

    static private function findItem($data, $name)
    {
        $categories = $data['category_list'] ?? [];
        foreach ($categories as $category) foreach (($category['receipt_item_list'] ?? []) as $item) if (is_array($item) && (string)($item['name'] ?? '') === $name) return ['id' => (int)($item['id'] ?? 0), 'name' => $name, 'amount' => (int)($item['amount'] ?? 0)];
        return null;
    }

    static private function deleteItem($cfg, $id)
    {
        if ($id <= 0) return;
        self::requestJson('POST', self::LINK_HOST . '/order4customer/manage/deletereceiptitem?sid=' . rawurlencode($cfg['book_sid']) . '&v=' . self::CREATE_VERSION, ['v' => self::CREATE_VERSION, 'comm_req' => ['shop_id' => (int)$cfg['shop_id'], 'account_type' => self::ACCOUNT_TYPE_SHORT], 'receipt_item_id' => $id, 'sid' => $cfg['book_sid']], $cfg, false);
    }

    static private function saveReceiptMeta(&$order, $miniId, $orderId, $outOrderNo)
    {
        global $DB;
        $tradeNo = trim((string)($order['trade_no'] ?? ''));
        if ($tradeNo === '' || trim((string)$miniId) === '') return false;
        $ext = self::readExt($order);
        $ext['yunkdskxd_mini_receipt_order_id'] = trim((string)$miniId);
        $ext['yunkdskxd_receipt_order_id'] = trim((string)$orderId);
        $ext['yunkdskxd_out_order_no'] = trim((string)$outOrderNo) !== '' ? trim((string)$outOrderNo) : self::orderTail($tradeNo);
        $ext['yunkdskxd_receipt_created_at'] = date('Y-m-d H:i:s');
        unset($ext['yunkdskxd_receipt_cancelled'], $ext['yunkdskxd_receipt_cancelled_at'], $ext['yunkdskxd_receipt_cancel_error']);
        $serialized = serialize($ext);
        $order['ext'] = $serialized;
        return $DB->update('order', ['ext' => $serialized], ['trade_no' => $tradeNo]) !== false;
    }

    /**
     * 取消已经超过 5 分钟支付期且超过 8 分钟流水匹配窗口的云端小票单。
     * 只处理未支付本地订单；取消成功后写入私有标记，失败按 30 秒间隔重试。
     */
    static public function cancelExpiredReceipt($channel, $order)
    {
        global $DB;
        if (!$channel || (int)($order['status'] ?? 0) !== 0) return ['code' => 0, 'skipped' => true, 'msg' => '订单已支付或不存在'];
        $created = !empty($order['addtime']) ? strtotime((string)$order['addtime']) : 0;
        if (!$created || time() < $created + self::FLOW_GRACE_SECONDS) return ['code' => 0, 'skipped' => true, 'msg' => '尚未到取消时间'];
        $ext = self::readExt($order);
        $miniId = trim((string)($ext['yunkdskxd_mini_receipt_order_id'] ?? ''));
        if ($miniId === '') return ['code' => 0, 'skipped' => true, 'msg' => '订单未保存云端小票单号'];
        if (!empty($ext['yunkdskxd_receipt_cancelled'])) return ['code' => 0, 'skipped' => true, 'msg' => '云端小票单已取消'];
        $lastAttempt = !empty($ext['yunkdskxd_receipt_cancel_attempt_at']) ? strtotime((string)$ext['yunkdskxd_receipt_cancel_attempt_at']) : 0;
        if ($lastAttempt > 0 && time() - $lastAttempt < self::RECEIPT_CANCEL_RETRY_SECONDS) return ['code' => 0, 'skipped' => true, 'msg' => '等待下一次取消重试'];

        $lock = 'yunkdskxd_cancel_' . sha1((string)$order['trade_no']);
        if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lock]) !== 1) return ['code' => 0, 'skipped' => true, 'msg' => '取消任务已被其它监控占用'];
        try {
            $fresh = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$order['trade_no']]);
            if (!$fresh || (int)$fresh['status'] !== 0) return ['code' => 0, 'skipped' => true, 'msg' => '订单已支付或不存在'];
            $ext = self::readExt($fresh);
            if (!empty($ext['yunkdskxd_receipt_cancelled'])) return ['code' => 0, 'skipped' => true, 'msg' => '云端小票单已取消'];
            $lastAttempt = !empty($ext['yunkdskxd_receipt_cancel_attempt_at']) ? strtotime((string)$ext['yunkdskxd_receipt_cancel_attempt_at']) : 0;
            if ($lastAttempt > 0 && time() - $lastAttempt < self::RECEIPT_CANCEL_RETRY_SECONDS) return ['code' => 0, 'skipped' => true, 'msg' => '等待下一次取消重试'];

            $cfg = self::getConfig($channel);
            $result = self::cancelReceiptById($cfg, $miniId, (string)($ext['yunkdskxd_out_order_no'] ?? self::orderTail($fresh['trade_no'])));
            $ext['yunkdskxd_receipt_cancel_attempt_at'] = date('Y-m-d H:i:s');
            if ((int)$result['code'] === 0) {
                $ext['yunkdskxd_receipt_cancelled'] = 1;
                $ext['yunkdskxd_receipt_cancelled_at'] = date('Y-m-d H:i:s');
                unset($ext['yunkdskxd_receipt_cancel_error']);
            } else {
                $ext['yunkdskxd_receipt_cancel_error'] = (string)$result['msg'];
            }
            $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $fresh['trade_no']]);
            return $result;
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lock]);
        }
    }

    static private function cancelReceiptById($cfg, $miniId, $outOrderNo)
    {
        if (trim((string)$miniId) === '' || $cfg['sid'] === '' || $cfg['shop_id'] === '') return ['code' => -1, 'msg' => '取消小票单缺少 SID、店铺 ID 或小票单号'];
        $url = self::LINK_HOST . '/sjtminireceipt/shelforder/cancel?sid=' . rawurlencode($cfg['sid']) . '&v=' . self::LIST_VERSION;
        $body = [
            'v' => self::LIST_VERSION,
            'account_type' => self::ACCOUNT_TYPE,
            'operator_role' => self::OPERATOR_MERCHANT,
            'shop_id' => (int)$cfg['shop_id'],
            'mini_receipt_order_id' => trim((string)$miniId),
            'source_type' => self::SOURCE_TYPE,
            'out_order_no' => trim((string)$outOrderNo),
            'sid' => $cfg['sid'],
        ];
        $result = self::requestJson('POST', $url, $body, $cfg, false);
        return $result['code'] === 0 ? ['code' => 0, 'msg' => '云端小票单已取消'] : ['code' => -1, 'msg' => '取消云端小票单失败：' . $result['msg']];
    }

    static public function getFlows($channel)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['sid'] === '' || $cfg['shop_id'] === '') return ['code' => -1, 'msg' => '登录态配置不完整', 'items' => []];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $url = self::LINK_HOST . '/sjtminireceipt/order/list?sid=' . rawurlencode($cfg['sid']) . '&v=' . self::LIST_VERSION;
            $body = ['v' => self::LIST_VERSION, 'account_type' => self::ACCOUNT_TYPE, 'operator_role' => self::OPERATOR_MERCHANT, 'shop_id' => (int)$cfg['shop_id'], 'page_size' => 20, 'page_number' => 1, 'stats_period_type' => 'E_DAY_STAT', 'source_type' => self::SOURCE_TYPE, 'sid' => $cfg['sid']];
            $r = self::requestJson('POST', $url, $body, $cfg, false);
            if ($r['code'] === 0) {
                $data = self::payload($r);
                return ['code' => 0, 'msg' => '', 'items' => $data['order_biz_info_list'] ?? $data['list'] ?? []];
            }
            if ($attempt === 1 && self::sidInvalidText($r['msg'])) {
                $refresh = self::refreshLinkSid($channel, $cfg);
                if ((int)$refresh['code'] === 0) {
                    $cfg['sid'] = $refresh['sid'];
                    continue;
                }
                return ['code' => -1, 'msg' => $r['msg'] . '；' . $refresh['msg'], 'items' => []];
            }
            return ['code' => -1, 'msg' => $r['msg'], 'items' => []];
        }
        return ['code' => -1, 'msg' => '查询流水失败', 'items' => []];
    }

    static public function flowValues($row)
    {
        if (!is_array($row)) return ['id' => '', 'amount' => 0, 'remark' => '', 'buyer' => '', 'time' => 0];
        $info = is_array($row['mini_receipt_order_info'] ?? null) ? $row['mini_receipt_order_info'] : $row;
        $items = is_array($row['payment_item_info'] ?? null) ? $row['payment_item_info'] : (is_array($info['payment_item_info'] ?? null) ? $info['payment_item_info'] : []);
        $item = isset($items[0]) && is_array($items[0]) ? $items[0] : [];
        $status = strtoupper((string)($info['order_status'] ?? $info['state'] ?? $row['order_status'] ?? $row['state'] ?? ''));
        $transactionId = (string)($info['transaction_id'] ?? $info['transactionId'] ?? $info['trans_id'] ?? $row['transaction_id'] ?? $row['trans_id'] ?? '');
        $paid = $transactionId !== '' && ($status === '' || strpos($status, 'SUCCESS') !== false || strpos($status, 'PAY_SUCC') !== false || strpos($status, 'PAID') !== false);
        $amount = $info['total_fee'] ?? $info['paid_fee'] ?? $info['transaction_fee'] ?? $info['actual_fee'] ?? $info['total_amount'] ?? $info['amount'] ?? 0;
        $remark = (string)($item['desc'] ?? $item['description'] ?? $item['name'] ?? $info['out_order_no'] ?? $row['out_order_no'] ?? '');
        $time = self::timestamp($info['pay_time'] ?? $info['pay_succ_time'] ?? $info['update_time'] ?? $info['create_time'] ?? $row['create_time'] ?? 0);
        return ['id' => $transactionId !== '' ? $transactionId : (string)($info['mini_receipt_order_id'] ?? ''), 'amount' => $amount, 'remark' => $remark, 'buyer' => (string)($info['payer_nick_name'] ?? $info['payer_user_name'] ?? ''), 'time' => $time, 'paid' => $paid];
    }

    static public function markExpired($order)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0 || self::isExpiredFlag($order)) return false;
        $ext = self::readExt($order);
        $ext['yunkdskxd_expired'] = 1;
        $ext['yunkdskxd_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static public function isExpired($order)
    {
        return self::isExpiredFlag($order) || (!empty($order['addtime']) && strtotime($order['addtime']) + self::EXPIRE_SECONDS <= time());
    }

    static private function isExpiredFlag($order)
    {
        $ext = self::readExt($order);
        return !empty($ext['yunkdskxd_expired']);
    }

    static private function reserveAmount($channel, &$order)
    {
        global $DB;
        $base = round((float)($order['money'] ?? $order['realmoney'] ?? 0), 2);
        if ($base <= 0 || empty($order['trade_no'])) return false;
        $ext = self::readExt($order);
        if (!empty($ext['yunkdskxd_amount'])) {
            $order['realmoney'] = number_format((float)$ext['yunkdskxd_amount'], 2, '.', '');
            return true;
        }
        $lock = 'yunkdskxd_float_' . (int)$channel['id'] . '_' . number_format($base, 2, '.', '_');
        if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name, 5)', [':lock_name' => $lock]) !== 1) return false;
        try {
            $tradeNo = daddslashes((string)$order['trade_no']);
            $rows = $DB->getAll("SELECT realmoney,ext FROM pre_order WHERE channel='" . (int)$channel['id'] . "' AND status=0 AND trade_no<>'" . $tradeNo . "' AND addtime>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
            $used = [];
            foreach ($rows as $row) {
                $e = !empty($row['ext']) ? @unserialize($row['ext']) : [];
                $rowBase = is_array($e) && isset($e['yunkdskxd_base']) ? (float)$e['yunkdskxd_base'] : (float)$row['realmoney'];
                if (abs($rowBase - $base) > 0.001 || (is_array($e) && !empty($e['yunkdskxd_expired']))) continue;
                $value = is_array($e) && isset($e['yunkdskxd_amount']) ? (float)$e['yunkdskxd_amount'] : (float)$row['realmoney'];
                $used[number_format($value, 2, '.', '')] = true;
            }
            $assigned = $base;
            while (isset($used[number_format($assigned, 2, '.', '')])) $assigned = round($assigned + self::FLOAT_STEP, 2);
            $ext['yunkdskxd_base'] = $base;
            $ext['yunkdskxd_amount'] = $assigned;
            $old = (float)($order['realmoney'] ?? $base);
            $order['realmoney'] = number_format($assigned, 2, '.', '');
            $data = ['realmoney' => $order['realmoney'], 'ext' => serialize($ext)];
            if (isset($order['getmoney'])) $data['getmoney'] = number_format(max(0, (float)$order['getmoney'] + $assigned - $old), 2, '.', '');
            $updated = $DB->update('order', $data, ['trade_no' => $order['trade_no']]) !== false;
            if ($updated) $order['ext'] = $data['ext'];
            return $updated;
        } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lock]); }
    }

    static private function requestJson($method, $url, $body, $cfg, $gateway, $appId = null)
    {
        $proxy = null;
        try { $proxy = self::proxyParts($cfg); } catch (Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []]; }
        $headers = ['Accept: application/json, */*', 'Content-Type: application/json', 'xweb_xhr: 1', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122 Safari/537.36 MicroMessenger/7.0.20.1781'];
        $headers[] = 'Referer: https://servicewechat.com/' . ($appId ?: self::LINK_APP_ID) . '/1/page-frame.html';
        if ($gateway && $proxy) {
            $headers[] = 'X-Proxy-Cluster-URL: ' . $proxy['host_header'] . ':' . $proxy['port'];
            $headers[] = 'X-Proxy-Cluster-Type: ' . $proxy['scheme'];
            $headers[] = 'X-Proxy-Cluster-User: ' . $proxy['user'];
            $headers[] = 'X-Proxy-Cluster-Pass: ' . $proxy['pass'];
        }
        $ch = curl_init($url);
        // 只有扫码状态轮询是长轮询；网关状态、二维码和普通登录接口不能被 70 秒拖住监控循环。
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $longPoll = $gateway && (bool)preg_match('#/yyb/qr/[^/]+/poll$#', $path);
        $timeout = $longPoll ? 70 : ($gateway ? 20 : 35);
        $connectTimeout = $longPoll ? 10 : 6;
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => $connectTimeout, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        // 代理只提交给云端网关，由云端使用代理访问微信。
        // 本机直请求微信接口（登录、开单、支付页、流水、取消）必须走本地网络，
        // 因此这里始终禁止 cURL 代理，避免把插件服务器的出口错误地套上代理。
        $opt[CURLOPT_NOPROXY] = '*';
        if (strtoupper($method) === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($body ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $error !== '') return ['code' => -1, 'msg' => '请求接口失败：' . $error, 'data' => []];
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) return ['code' => -1, 'msg' => '接口返回不是 JSON（HTTP ' . $status . '）', 'data' => []];
        if ($status < 200 || $status >= 300) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? $data['error'] ?? ('HTTP ' . $status)), 'data' => $data];
        foreach (['errcode', 'retcode'] as $key) if (isset($data[$key]) && (int)$data[$key] !== 0) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data];
        if (array_key_exists('ok', $data) && !$data['ok']) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data];
        if (isset($data['code']) && is_numeric($data['code']) && !in_array((int)$data['code'], [0, 200], true)) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data];
        return ['code' => 0, 'msg' => '', 'data' => $data];
    }

    static private function payload($result)
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (isset($data['data']) && is_array($data['data'])) return $data['data'];
        return $data;
    }

    static private function extract($value, $keys)
    {
        if (is_string($value)) { $decoded = json_decode($value, true); return is_array($decoded) ? self::extract($decoded, $keys) : ''; }
        if (!is_array($value)) return '';
        foreach ($keys as $key) if (isset($value[$key]) && !is_array($value[$key]) && (string)$value[$key] !== '') return (string)$value[$key];
        foreach (['data', 'result', 'account', 'user'] as $child) if (isset($value[$child])) { $found = self::extract($value[$child], $keys); if ($found !== '') return $found; }
        return '';
    }

    static private function first($array, $keys)
    {
        if (!is_array($array)) return '';
        foreach ($keys as $key) if (isset($array[$key]) && (string)$array[$key] !== '') return (string)$array[$key];
        return '';
    }

    static private function getConfig($channel)
    {
        $keys = ['gateway','login_mode','sid','book_sid','shop_id','cloud_ref','sid_login_mode','proxy_mode','proxy'];
        $cfg = [];
        foreach ($keys as $key) $cfg[$key] = trim((string)($channel[$key] ?? ''));
        $cfg['gateway'] = rtrim($cfg['gateway'], '/');
        if (!in_array($cfg['login_mode'], ['yyb', 'guanjia'], true)) $cfg['login_mode'] = '';
        if ($cfg['proxy_mode'] === '') $cfg['proxy_mode'] = 'no_proxy';
        return $cfg;
    }

    static private function saveConfig($channelId, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yunkdskxd'))return \lib\AdminApp\PluginConfig::capture('yunkdskxd',$channelId,$updates);
        if(\lib\Task\PaymentMonitor::activeFor('yunkdskxd'))return \lib\Task\PaymentMonitor::saveCredentials($channelId,$updates,['sid', 'book_sid']);
        global $DB;
        $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$channelId . "' LIMIT 1");
        if (!$row || $row['plugin'] !== 'yunkdskxd') return false;
        $config = json_decode((string)$row['config'], true); if (!is_array($config)) $config = [];
        foreach ($updates as $key => $value) $config[$key] = (string)$value;
        return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }

    static private function readExt($order)
    {
        if (empty($order['ext'])) return [];
        $ext = @unserialize($order['ext']); return is_array($ext) ? $ext : [];
    }

    static private function proxyParts($cfg)
    {
        $mode = strtolower(trim((string)($cfg['proxy_mode'] ?? 'no_proxy')));
        if (in_array($mode, ['', 'no_proxy', 'none', '0'], true)) return null;
        if ($mode !== 'custom_proxy') throw new InvalidArgumentException('代理模式无效，已拒绝直连');
        $raw = trim((string)($cfg['proxy'] ?? '')); if ($raw === '') throw new InvalidArgumentException('已开启代理但未填写代理地址，已拒绝直连');
        if (strpos($raw, '://') === false) $raw = 'socks5://' . $raw;
        $parts = parse_url($raw); if (!$parts || empty($parts['host']) || empty($parts['port'])) throw new InvalidArgumentException('代理地址格式无效，已拒绝直连');
        $scheme = strtolower((string)($parts['scheme'] ?? '')); if (!in_array($scheme, ['socks5','socks5h','http','https'], true)) throw new InvalidArgumentException('代理协议仅支持 socks5、socks5h、http、https');
        $host = (string)$parts['host']; $headerHost = $host; if (strpos($headerHost, ':') !== false && $headerHost[0] !== '[') $headerHost = '[' . $headerHost . ']';
        return ['scheme' => $scheme, 'host_header' => $headerHost, 'port' => (int)$parts['port'], 'user' => rawurldecode((string)($parts['user'] ?? '')), 'pass' => rawurldecode((string)($parts['pass'] ?? '')), 'type' => in_array($scheme, ['http','https'], true) ? CURLPROXY_HTTP : CURLPROXY_SOCKS5_HOSTNAME];
    }

    static private function normalizeQr($value)
    {
        $value = trim((string)$value); if ($value === '') return '';
        if (strpos($value, 'data:image/') === 0 || preg_match('#^https?://#i', $value)) return $value;
        $raw = base64_decode($value, true);
        if ($raw !== false && substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") return 'data:image/png;base64,' . $value;
        if ($raw !== false && substr($raw, 0, 3) === "\xff\xd8\xff") return 'data:image/jpeg;base64,' . $value;
        return $value;
    }

    static private function isImageValue($value)
    {
        $value = (string)$value; return strpos($value, 'data:image/') === 0 || (base64_decode($value, true) !== false && preg_match('/^[A-Za-z0-9+\/=]+$/', $value));
    }

    static private function orderTail($orderNo)
    {
        $value = preg_replace('/\D/', '', (string)$orderNo); return strlen($value) > 16 ? substr($value, -16) : $value;
    }

    static private function timestamp($value)
    {
        if (is_numeric($value)) { $v = (int)$value; return $v > 20000000000 ? (int)floor($v / 1000) : $v; }
        return $value !== '' ? (int)strtotime((string)$value) : 0;
    }
}
