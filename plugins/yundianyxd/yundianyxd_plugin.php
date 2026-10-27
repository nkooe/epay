<?php

/**
 * 微信双店员云端-XD。
 *
 * 云端只负责登录桥和获取小程序 code；个人店员、经营店员的微信接口、
 * 账单查询和本地订单回调均由本插件处理。两种收款方式使用完全独立的
 * SID、账号标识和业务配置，切换方式不会覆盖另一种方式的登录态。
 */
class yundianyxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('yundianyxd', $channels, $context, static function($channel){
            $config=self::getConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['gateway']);
            $mode=$config['pay_mode'];
            \lib\Task\PaymentMonitor::requireFields($config,[$mode.'_sid']);
        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\yundianyxd\monitorTarget($target,$state);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const PERSONAL_APP_ID = 'wxe8f3ea96c5c34a37';
    const CLERK_APP_ID = 'wx95ce10b0494846b7';
    const WX_HOST = 'https://payapp.weixin.qq.com';
    const PERSONAL_REFERER = 'https://servicewechat.com/wxe8f3ea96c5c34a37/1/page-frame.html';
    const CLERK_REFERER = 'https://servicewechat.com/wx95ce10b0494846b7/96/page-frame.html';
    const CLERK_VERSION = '0.11.34';
    const ORDER_EXPIRE_SECONDS = 300;
    // 订单本身 5 分钟失效；流水允许额外保留 3 分钟用于网络延迟，匹配窗口固定 8 分钟。
    const PERSONAL_FLOW_WINDOW = 480;
    const BUSINESS_FLOW_WINDOW = 480;
    const PAGE_SIZE = 10;

    static public $info = [
        'name' => 'yundianyxd',
        'showname' => '微信双店员云端-XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['wxpay'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'gateway' => ['name' => '网关地址', 'type' => 'input', 'note' => '填写云端完整 URL'],
            'pay_mode' => ['name' => '收款方式', 'type' => 'select', 'options' => ['personal' => '个人店员', 'business' => '经营店员']],
            'personal_login_mode' => ['name' => '个人店员登录方式', 'type' => 'select', 'options' => ['yyb' => '应用宝', 'guanjia' => '电脑管家']],
            'business_login_mode' => ['name' => '经营店员登录方式', 'type' => 'select', 'options' => ['yyb' => '应用宝', 'guanjia' => '电脑管家']],
            'type' => ['name' => '收款码类型', 'type' => 'select', 'options' => ['qrcode' => '二维码', 'image' => '图片']],
            'personal_qrcode' => ['name' => '个人店员收款码地址', 'type' => 'input', 'note' => '二维码模式填写个人店员固定收款码地址'],
            'personal_qrcode_file' => ['name' => '个人店员收款码图片地址', 'type' => 'input', 'note' => '图片模式填写个人店员图片 URL 或 data:image 地址'],
            'business_qrcode' => ['name' => '经营店员收款码地址', 'type' => 'input', 'note' => '二维码模式填写经营店员固定收款码地址'],
            'business_qrcode_file' => ['name' => '经营店员收款码图片地址', 'type' => 'input', 'note' => '图片模式填写经营店员图片 URL 或 data:image 地址'],
            'personal_sid' => ['name' => '个人店员 SID', 'type' => 'input', 'note' => '个人店员扫码成功后自动获取'],
            'personal_payee_openid' => ['name' => '个人店员收款人', 'type' => 'hidden'],
            'personal_payee_name' => ['name' => '个人店员收款人名称', 'type' => 'hidden'],
            'personal_payee_shop' => ['name' => '个人店员店铺', 'type' => 'hidden'],
            'personal_payee_list' => ['name' => '个人店员收款人列表', 'type' => 'hidden'],
            'business_sid' => ['name' => '经营店员 SID', 'type' => 'input', 'note' => '经营店员扫码成功后自动获取'],
            'business_aid' => ['name' => '经营店员 mch_id', 'type' => 'input', 'note' => '扫码后自动同步'],
            'business_shop_id' => ['name' => '经营店员 shop_id', 'type' => 'input', 'note' => '扫码后自动同步'],
            'business_account_list' => ['name' => '经营店员店铺列表', 'type' => 'textarea', 'note' => '扫码后自动同步'],
            'personal_bind_token' => ['name' => '个人店员绑定信息', 'type' => 'hidden'],
            'business_bind_token' => ['name' => '经营店员绑定信息', 'type' => 'hidden'],
            'personal_yyb_ref' => ['name' => '个人店员应用宝账号', 'type' => 'hidden'],
            'personal_guanjia_ref' => ['name' => '个人店员电脑管家账号', 'type' => 'hidden'],
            'business_yyb_ref' => ['name' => '经营店员应用宝账号', 'type' => 'hidden'],
            'business_guanjia_ref' => ['name' => '经营店员电脑管家账号', 'type' => 'hidden'],
            'proxy_mode' => ['name' => '代理模式', 'type' => 'select', 'options' => ['no_proxy' => '不使用代理', 'custom_proxy' => '自定义 Socks5/HTTP 代理']],
            'proxy' => ['name' => '代理地址', 'type' => 'input', 'note' => '仅用于请求云端网关，不用于微信店员接口'],
        ],
        'bindwxmp' => false,
        'bindwxa' => false,
        'note' => '<style>
        form#form-info + br,form#form-info ~ br{display:none!important}
        #form-info{text-align:left!important;overflow:visible}
        #form-info #save,form#form-info .yundianyxd-login{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;margin:0 0 12px!important;vertical-align:top}
        #form-info #save{margin-left:4%!important}
        .yundianyxd-login{text-align:center;line-height:20px;padding:10px 16px;background:#07c160;color:#fff!important;border-radius:4px;text-decoration:none}
        .yundianyxd-login:hover{background:#06ad56;text-decoration:none}
        .yundianyxd-note{display:block;clear:both;line-height:1.8}
        #form-info input[name="config[personal_sid]"],#form-info input[name="config[business_sid]"],#form-info input[name="config[business_aid]"],#form-info input[name="config[business_shop_id]"]{background:#f5f7fa;cursor:not-allowed}
        </style>
        <a class="yundianyxd-login" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,\'yundianyxd_login_[channel]\',\'width=480,height=760,resizable=yes,scrollbars=yes\');return false;">扫码登录</a>
        <span class="yundianyxd-note">先选择收款方式，再点击扫码登录；个人店员和经营店员的登录态、SID、账号信息分别保存，互不通用。</span>
        <script>(function(){var f=document.getElementById("form-info"),a=document.querySelector(".yundianyxd-login"),s=document.getElementById("save"),mode=document.querySelector("select[name=\'config[pay_mode]\']"),type=document.querySelector("select[name=\'config[type]\']"),pqr=document.querySelector("input[name=\'config[personal_qrcode]\']"),pimg=document.querySelector("input[name=\'config[personal_qrcode_file]\']"),bqr=document.querySelector("input[name=\'config[business_qrcode]\']"),bimg=document.querySelector("input[name=\'config[business_qrcode_file]\']"),pl=document.querySelector("select[name=\'config[personal_login_mode]\']"),bl=document.querySelector("select[name=\'config[business_login_mode]\']"),ps=document.querySelector("input[name=\'config[personal_sid]\']"),bs=document.querySelector("input[name=\'config[business_sid]\']"),ba=document.querySelector("input[name=\'config[business_aid]\']"),bi=document.querySelector("input[name=\'config[business_shop_id]\']"),pm=document.querySelector("select[name=\'config[proxy_mode]\']"),pa=document.querySelector("input[name=\'config[proxy]\']"),row=function(e){return e&&e.closest?e.closest(".form-group"):null};if(f&&a&&s&&a.parentNode!==f)f.insertBefore(a,s);["personal_payee_openid","personal_payee_name","personal_payee_shop","personal_payee_list","personal_bind_token","business_bind_token","personal_yyb_ref","personal_guanjia_ref","business_yyb_ref","business_guanjia_ref","business_account_list"].forEach(function(k){var e=document.querySelector("input[name=\'config["+k+"]\'],textarea[name=\'config["+k+"]\']"),r=row(e);if(r)r.style.display="none"});[ps,bs,ba,bi].forEach(function(e){if(e){e.readOnly=true;e.setAttribute("aria-readonly","true")}});function sync(){var m=mode?mode.value:"personal",k=type?type.value:"qrcode",r=row(pqr),ri=row(pimg),q=row(bqr),qi=row(bimg),p=row(pa),l=row(pl),n=row(bl),x=row(ps),y=row(bs),z=row(ba),w=row(bi);if(r)r.style.display=m==="personal"&&k==="qrcode"?"":"none";if(ri)ri.style.display=m==="personal"&&k==="image"?"":"none";if(q)q.style.display=m==="business"&&k==="qrcode"?"":"none";if(qi)qi.style.display=m==="business"&&k==="image"?"":"none";if(p)p.style.display=pm&&pm.value==="custom_proxy"?"":"none";if(l)l.style.display=m==="personal"?"":"none";if(n)n.style.display=m==="business"?"":"none";if(x)x.style.display=m==="personal"?"":"none";if(y)y.style.display=m==="business"?"":"none";if(z)z.style.display=m==="business"?"":"none";if(w)w.style.display=m==="business"?"":"none"}if(mode)mode.addEventListener("change",sync);if(type)type.addEventListener("change",sync);if(pm)pm.addEventListener("change",sync);sync()}());</script>',
    ];

    static public function submit() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }
    static public function mapi() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }

    static public function qrcode()
    {
        global $order, $channel, $conf, $siteurl;
        $config = self::getConfig($channel);
        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? '微信支付'));
        $title = ($paymentName !== '' ? $paymentName : '微信支付') . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $paymentIcon = '/assets/icon/wxpay.ico';
        $code_url = '';
        $error_msg = '';
        if ((int)($order['status'] ?? 0) !== 0) $error_msg = '订单状态已改变，请返回订单页面查看结果';
        elseif (self::isExpired($order)) { self::markExpired($order); $error_msg = '订单已超时，请重新发起支付'; }
        else {
            $cloud = self::checkCloudStatus($channel);
            if ((int)$cloud['code'] !== 1) $error_msg = $cloud['msg'];
            else {
                $created = self::createCode($config, $siteurl);
                if ((int)$created['code'] === 0) $code_url = $created['qrcode'];
                else $error_msg = $created['msg'];
            }
        }
        $paytime = max(0, strtotime((string)$order['addtime']) + self::ORDER_EXPIRE_SECONDS - time());
        $code_image = $config['type'] === 'image';
        define('IN_PLUGIN', true);
        include __DIR__ . '/inc/qrcode.page.php';
        exit;
    }

    static public function notify() { return ['type' => 'error', 'msg' => '本插件通过店员账单轮询，不接收 HTTP 回调']; }

    /** Native administrator adapter; no HTML, exit, cookies or client-selected method. */
    static public function appLogin($channel, $action, array $input = [])
    {
        if ($action === 'start') return self::beginLogin($channel);
        if ($action === 'poll') return self::pollLogin($channel, (string)($input['session_id'] ?? ''));
        return ['code' => -1, 'msg' => '不支持的登录操作'];
    }

    static public function adminLogin($channel)
    {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'start') self::adminResponse(self::beginLogin($channel));
        if ($action === 'poll') self::adminResponse(self::pollLogin($channel, (string)($_GET['session_id'] ?? '')));
        if ($action === 'state') {
            $cfg = self::getConfig($channel); $mode = $cfg['pay_mode'];
            self::adminResponse(['code' => 0, 'mode' => $mode, 'login_mode' => self::loginMode($cfg, $mode), 'sid' => self::modeSid($cfg, $mode), 'ref' => self::boundRef($cfg, $mode), 'aid' => $cfg['business_aid'], 'shop_id' => $cfg['business_shop_id'], 'payee' => $cfg['personal_payee_name']]);
        }
        $cfg = self::getConfig($channel); $mode = $cfg['pay_mode']; $modeName = $mode === 'business' ? '经营店员' : '个人店员';
        $loginName = self::loginMode($cfg, $mode) === 'guanjia' ? '电脑管家' : '应用宝';
        $sid = self::modeSid($cfg, $mode);
        $state = $sid !== '' && self::boundRef($cfg, $mode) !== '' ? '已登录' : '尚未登录';
        $endpoint = './plugin_page.php?channel=' . (int)$channel['id'] . '&func=adminLogin';
        ?>
        <style>.yundianyxd-box{max-width:440px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}.yundianyxd-box h3{margin:0 0 10px}.yundianyxd-meta{color:#666;line-height:1.8}.yundianyxd-qr{width:260px;height:260px;margin:16px auto;border:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;justify-content:center;color:#888}.yundianyxd-qr img{max-width:240px;max-height:240px}.yundianyxd-state{min-height:24px;margin:8px 0;color:#1677ff;font-weight:600}.yundianyxd-state.ok{color:#16803c}.yundianyxd-state.bad{color:#c62828}.yundianyxd-msg{min-height:24px;margin:10px 0;color:#555}.yundianyxd-msg.ok{color:#16803c}.yundianyxd-msg.bad{color:#c62828}.yundianyxd-box button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}</style>
        <div class="yundianyxd-box"><h3>微信双店员云端-XD扫码登录</h3><div class="yundianyxd-meta">收款方式：<?php echo htmlspecialchars($modeName, ENT_QUOTES, 'UTF-8'); ?><br>登录方式：<?php echo htmlspecialchars($loginName, ENT_QUOTES, 'UTF-8'); ?><br>登录状态：<?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?><br><?php if ($sid !== ''): ?>SID：<?php echo htmlspecialchars($sid, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div><div class="yundianyxd-qr" id="yundianyxd-qr">正在准备二维码…</div><div class="yundianyxd-state" id="yundianyxd-state">状态：准备二维码</div><div class="yundianyxd-msg" id="yundianyxd-msg"></div><button type="button" id="yundianyxd-start">重新获取二维码</button></div>
        <script src="/assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
        <script>(function(){var ep=<?php echo json_encode($endpoint,JSON_UNESCAPED_SLASHES); ?>,box=document.getElementById('yundianyxd-qr'),state=document.getElementById('yundianyxd-state'),msg=document.getElementById('yundianyxd-msg'),btn=document.getElementById('yundianyxd-start'),timer=0;function parse(t){var d=new DOMParser().parseFromString(t,'text/html'),n=d.querySelector('#yundianyxd-response');if(!n)throw Error('后台响应格式错误');return JSON.parse(n.value||n.textContent||'{}')}function req(u){return fetch(u,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text()}).then(parse)}function setState(t,c){state.className='yundianyxd-state '+(c||'');state.textContent='状态：'+t}function say(t,c){msg.className='yundianyxd-msg '+(c||'');msg.textContent=t||''}function draw(v,k){box.innerHTML='';v=v||'';if(k==='image'||v.indexOf('data:image/')===0||(/^https?:\/\//.test(v)&&/qrcode|qr\./i.test(v))){var i=document.createElement('img');i.src=v;i.alt='登录二维码';box.appendChild(i)}else if(window.jQuery&&jQuery.fn.qrcode)jQuery(box).qrcode({text:v,width:240,height:240,typeNumber:-1});else{box.textContent='二维码组件加载失败，请刷新后重试';say('二维码组件加载失败','bad')}}function poll(id){req(ep+'&action=poll&session_id='+encodeURIComponent(id)).then(function(d){if(d.code===0){setState('登录成功','ok');say(d.msg||'账号信息已同步','ok');btn.disabled=false;try{if(window.opener&&!window.opener.closed)window.opener.location.reload()}catch(e){}return}if(d.code===201){setState(d.state==='scanned'?'已扫码，请确认':'等待扫码');say(d.msg||'');timer=setTimeout(function(){poll(id)},2000);return}setState('登录失败','bad');say(d.msg||'登录失败','bad');btn.disabled=false}).catch(function(){setState('连接重试中','bad');say('后台请求失败，正在重试');timer=setTimeout(function(){poll(id)},3000)})}function start(){clearTimeout(timer);btn.disabled=true;setState('等待扫码');say('');req(ep+'&action=start').then(function(d){if(d.code!==0){setState('获取二维码失败','bad');say(d.msg||'获取二维码失败','bad');btn.disabled=false;return}draw(d.qrcode,d.qrcode_type||'text');setState('等待扫码');poll(d.session_id)}).catch(function(e){setState('获取二维码失败','bad');say(e.message||'后台请求失败','bad');btn.disabled=false})}btn.onclick=start;start()}());</script>
        <?php
    }

    static private function adminResponse($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<textarea id="yundianyxd-response" style="display:none">' . htmlspecialchars($json ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>'; exit;
    }

    static private function beginLogin($channel)
    {
        $cfg = self::getConfig($channel); $loginMode = self::loginMode($cfg, $cfg['pay_mode']); if ($cfg['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址并保存'];
        $path = $loginMode === 'guanjia' ? '/guanjia/api/qrcode' : '/yyb/qr';
        $body = $loginMode === 'guanjia' ? ['appid' => self::appId($cfg['pay_mode'])] : [];
        $result = self::gatewayRequest('POST', $cfg['gateway'] . $path, $body, $cfg); if ($result['code'] !== 0) return $result;
        $data = self::payload($result); $session = self::extract($data, ['session_id', 'uuid', 'sessionId']); $qrcode = self::extract($data, ['qrcode', 'qr_code', 'url', 'login_url', 'image', 'image_base64']);
        if ($session === '' || $qrcode === '') return ['code' => -1, 'msg' => '云端未返回完整登录二维码'];
        $key = self::modeKey($cfg['pay_mode'], 'bind_token');
        if (!self::saveConfig((int)$channel['id'], [$key => json_encode(['session_id' => $session, 'protocol' => $loginMode, 'mode' => $cfg['pay_mode']], JSON_UNESCAPED_UNICODE)])) return ['code' => -1, 'msg' => '登录会话保存失败'];
        return ['code' => 0, 'session_id' => $session, 'qrcode' => self::normalizeQr($qrcode), 'qrcode_type' => self::isImageValue($qrcode) ? 'image' : 'text'];
    }

    static private function pollLogin($channel, $session)
    {
        $cfg = self::getConfig($channel); $loginMode = self::loginMode($cfg, $cfg['pay_mode']); if ($session === '') return ['code' => -1, 'msg' => '登录会话为空'];
        $bind = json_decode((string)$cfg[self::modeKey($cfg['pay_mode'], 'bind_token')], true); if (!is_array($bind) || (string)($bind['session_id'] ?? '') !== $session || ($bind['protocol'] ?? '') !== $loginMode) return ['code' => -1, 'msg' => '登录会话无效，请重新扫码'];
        $data = null;
        if ($loginMode === 'guanjia') {
            $r = self::gatewayRequest('GET', $cfg['gateway'] . '/guanjia/api/status?uuid=' . rawurlencode($session), null, $cfg); if ($r['code'] !== 0) return self::retryTransientLoginError($r, 'waiting'); $data = self::payload($r); $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? '')));
            if (in_array($status, ['pending', 'ready', 'scanned'], true)) return ['code' => 201, 'state' => $status === 'scanned' ? 'scanned' : 'waiting', 'msg' => $status === 'scanned' ? '已扫码，请在微信确认' : '等待扫码'];
            if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return ['code' => -1, 'msg' => (string)($data['error'] ?? $data['msg'] ?? '电脑管家登录状态异常')];
            $account = is_array($data['result'] ?? null) ? $data['result'] : $data; $ref = self::extract($account, ['ref', 'bind_account', 'openid', 'uin', 'id']);
        } else {
            $r = self::gatewayRequest('GET', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/poll', null, $cfg); if ($r['code'] !== 0) return self::retryTransientLoginError($r, 'waiting'); $data = self::payload($r); $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? '')));
            if (in_array($status, ['pending', 'ready', 'scanned'], true)) return ['code' => 201, 'state' => $status === 'scanned' ? 'scanned' : 'waiting', 'msg' => $status === 'scanned' ? '已扫码，请在微信确认' : '等待扫码'];
            if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return ['code' => -1, 'msg' => '应用宝登录状态异常'];
            $r = self::gatewayRequest('POST', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/confirm', [], $cfg); if ($r['code'] !== 0) return self::retryTransientLoginError($r, 'scanned'); $ref = self::extract(self::payload($r), ['ref', 'bind_account', 'openid', 'uin', 'id']);
        }
        if ($ref === '') return ['code' => -1, 'msg' => '登录成功但云端未返回账号标识'];
        $sync = self::syncMode($cfg, $ref); if ($sync['code'] !== 0) return self::retryTransientLoginError($sync, 'scanned');
        $prefix = $cfg['pay_mode'] === 'business' ? 'business_' : 'personal_'; $refKey = $prefix . ($loginMode === 'guanjia' ? 'guanjia_ref' : 'yyb_ref');
        $oldRefKey = $prefix . ($loginMode === 'guanjia' ? 'yyb_ref' : 'guanjia_ref');
        $updates = [$refKey => $ref, $oldRefKey => '', $prefix . 'bound_login_mode' => $loginMode, $prefix . 'bind_token' => json_encode(['ref' => $ref, 'session_id' => $session, 'protocol' => $loginMode, 'mode' => $cfg['pay_mode']], JSON_UNESCAPED_UNICODE)];
        foreach ($sync['updates'] as $key => $value) $updates[$prefix . $key] = $value;
        if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '登录成功但保存账号信息失败'];
        return ['code' => 0, 'msg' => '登录成功，' . ($cfg['pay_mode'] === 'business' ? '经营店员' : '个人店员') . '账号信息已同步'];
    }

    static private function syncMode($cfg, $ref)
    {
        $code = self::getMiniCode($cfg, $ref); if ($code['code'] !== 0) return $code;
        return $cfg['pay_mode'] === 'business' ? self::syncBusiness($cfg, $code['value']) : self::syncPersonal($cfg, $code['value']);
    }

    static private function syncPersonal($cfg, $code)
    {
        $r = self::wechatRequest('GET', '/qrapp/notifier/login?js_code=' . rawurlencode($code), null, $cfg, 'personal'); if ($r['code'] !== 0) return $r;
        // 登录接口的 SID 与 data 同级；不能先调用 payload()，否则空 data 会遮掉顶层 SID。
        $sid = self::extract($r['data'] ?? [], ['sid', 'Sid', 'session_id', 'sessionId']); if ($sid === '') return ['code' => -1, 'msg' => '个人店员登录未返回 SID'];
        $payees = self::fetchPersonalPayees($cfg, $sid);
        if ($payees['code'] !== 0) return $payees;
        $rows = $payees['items'];
        $row = $rows[0];
        return ['code' => 0, 'updates' => [
            'sid' => $sid,
            // payee_openid 只是 IncomeList2 必需的收款账本范围，不参与订单匹配。
            'payee_openid' => $row['payee_openid'],
            'payee_name' => $row['payee_name'],
            'payee_shop' => $row['payee_shop'],
            'payee_list' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]];
    }

    static private function syncBusiness($cfg, $code)
    {
        $r = self::wechatRequest('GET', '/sjtassistant/assistant/login?jscode=' . rawurlencode($code), null, $cfg, 'business'); if ($r['code'] !== 0) return $r;
        // 经营店员登录响应为 {data:[], ..., sid:"..."}，SID 位于顶层。
        $sid = self::extract($r['data'] ?? [], ['sid', 'Sid', 'clerk_sid', 'clerkSid']); if ($sid === '') return ['code' => -1, 'msg' => '经营店员登录未返回 SID'];
        $r = self::wechatRequest('GET', '/sjtassistant/assistant/getshoplist?v=' . rawurlencode(self::CLERK_VERSION) . '&sid=' . rawurlencode($sid), null, $cfg, 'business'); if ($r['code'] !== 0) return $r;
        $data = self::payload($r); $rows = self::arrayRows($data, ['shop_list', 'shops', 'list']); if (!$rows) return ['code' => -1, 'msg' => '经营店员未返回可用店铺'];
        $selected = $rows[0]; foreach ($rows as $row) if ((string)self::first($row, ['mch_id', 'mchId']) === (string)$cfg['business_aid'] && (string)self::first($row, ['shop_id', 'shopId']) === (string)$cfg['business_shop_id']) { $selected = $row; break; }
        $lines = []; foreach ($rows as $row) $lines[] = '店铺:' . (self::first($row, ['shop_name', 'shopName', 'name']) ?: '未命名店铺') . '|mch_id:' . self::first($row, ['mch_id', 'mchId']) . '|shop_id:' . self::first($row, ['shop_id', 'shopId']);
        $aid = self::first($selected, ['mch_id', 'mchId']); $shop = self::first($selected, ['shop_id', 'shopId']); if ($aid === '' || $shop === '') return ['code' => -1, 'msg' => '经营店员店铺缺少 mch_id 或 shop_id'];
        return ['code' => 0, 'updates' => ['sid' => $sid, 'aid' => $aid, 'shop_id' => $shop, 'account_list' => implode("\n", $lines)]];
    }

    static private function getMiniCode($cfg, $ref)
    {
        $loginMode = self::loginMode($cfg, $cfg['pay_mode']); $path = $loginMode === 'guanjia' ? '/guanjia/api/get-code' : '/yyb/wxapp/getCode'; $body = $loginMode === 'guanjia' ? ['ref' => $ref, 'appid' => self::appId($cfg['pay_mode'])] : ['ref' => $ref, 'app_id' => self::appId($cfg['pay_mode'])];
        $r = self::gatewayRequest('POST', $cfg['gateway'] . $path, $body, $cfg); if ($r['code'] !== 0) return $r; $code = self::extract(self::payload($r), ['jscode', 'js_code', 'auth_code', 'code_value', 'Code', 'code']);
        return $code === '' || in_array($code, ['0', '1', '200'], true) ? ['code' => -1, 'msg' => '云端未返回店员小程序 code'] : ['code' => 0, 'value' => $code];
    }

    static public function checkCloudStatus($channel)
    {
        $cfg = self::getConfig($channel); $ref = self::boundRef($cfg, $cfg['pay_mode']); if ($cfg['gateway'] === '' || $ref === '') return ['code' => 0, 'msg' => '尚未扫码登录当前店员，已禁止发起订单'];
        $loginMode = self::loginMode($cfg, $cfg['pay_mode']); $path = $loginMode === 'guanjia' ? '/guanjia/api/accounts' : '/yyb/accounts'; $r = self::gatewayRequest('GET', $cfg['gateway'] . $path, null, $cfg); if ($r['code'] !== 0) return ['code' => -1, 'msg' => '云端状态检查失败：' . $r['msg']];
        $rows = self::payload($r); if (isset($rows['accounts']) && is_array($rows['accounts'])) $rows = $rows['accounts']; if (isset($rows['list']) && is_array($rows['list'])) $rows = $rows['list']; if (!is_array($rows)) return ['code' => -1, 'msg' => '云端状态响应异常'];
        foreach ($rows as $row) { if (!is_array($row)) continue; $refs = [(string)($row['ref'] ?? ''), (string)($row['bind_account'] ?? ''), (string)($row['openid'] ?? ''), (string)($row['uin'] ?? ''), (string)($row['id'] ?? '')]; if (!in_array($ref, $refs, true)) continue; $status = strtolower(trim((string)($row['status'] ?? $row['state'] ?? ''))); if (in_array($status, ['offline', 'dead', 'logout', 'logged_out', 'expired', '0'], true)) return ['code' => 0, 'msg' => '云端明确返回离线，请重新扫码登录']; return ['code' => 1, 'msg' => '云端在线']; }
        return ['code' => 0, 'msg' => '云端未找到当前店员，请重新扫码登录'];
    }

    static public function getFlows($channel)
    {
        $cfg = self::getConfig($channel); $mode = $cfg['pay_mode']; $sid = self::modeSid($cfg, $mode); if ($sid === '') return ['code' => -1, 'msg' => '当前店员 SID 为空', 'items' => []];
        if ($mode === 'personal' && trim((string)$cfg['personal_payee_list']) === '') {
            // 兼容已有登录态：仅在没有缓存账本列表时同步一次，不要求重新扫码。
            $payees = self::fetchPersonalPayees($cfg, $sid);
            if ($payees['code'] !== 0) return ['code' => -1, 'msg' => $payees['msg'], 'items' => []];
            $rows = $payees['items'];
            $first = $rows[0];
            $updates = [
                'personal_payee_openid' => $first['payee_openid'],
                'personal_payee_name' => $first['payee_name'],
                'personal_payee_shop' => $first['payee_shop'],
                'personal_payee_list' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '收款账本列表保存失败', 'items' => []];
            foreach ($updates as $key => $value) $cfg[$key] = (string)$value;
        }
        return $mode === 'business' ? self::getBusinessFlows($cfg) : self::getPersonalFlows($cfg);
    }

    static public function isCredentialError($message) { return self::sidInvalid($message); }

    static public function reloadChannel($channel)
    {
        if (!empty($channel['subid'])) return \lib\Channel::getSub((int)$channel['subid']);
        return \lib\Channel::get((int)$channel['id']);
    }

    static public function refreshCredentials($channel)
    {
        $cfg = self::getConfig($channel); $mode = $cfg['pay_mode']; $ref = self::boundRef($cfg, $mode);
        if ($ref === '') return ['code' => -1, 'msg' => '当前店员没有云端账号标识'];
        $sync = self::syncMode($cfg, $ref); if ((int)$sync['code'] !== 0) return $sync;
        $prefix = $mode === 'business' ? 'business_' : 'personal_'; $updates = [];
        foreach ($sync['updates'] as $key => $value) $updates[$prefix . $key] = $value;
        if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '刷新店员登录态成功但保存失败'];
        return ['code' => 0, 'msg' => ''];
    }

    static private function getPersonalFlows($cfg)
    {
        $sid = $cfg['personal_sid'];
        $payees = self::personalPayees($cfg);
        if (!$payees) return ['code' => -1, 'msg' => '个人店员未保存收款账本列表', 'items' => []];
        $now = time();
        $items = [];
        $seen = [];
        foreach ($payees as $payee) {
            $body = ['payee_openid' => $payee['payee_openid'], 'start_time' => $now - self::PERSONAL_FLOW_WINDOW, 'end_time' => $now + 60, 'timestamp' => $now, 'page_size' => self::PAGE_SIZE, 'last_record_id' => '', 'sort' => 'desc', 'sid' => $sid];
            // 个人店员流水属于 qrappzd；payee_openid 只用于定位收款账本。
            $r = self::wechatRequest('POST', '/qrappzd/notifier/IncomeList2?sid=' . rawurlencode($sid), $body, $cfg, 'personal');
            if ($r['code'] !== 0) return ['code' => -1, 'msg' => $r['msg'], 'items' => []];
            $data = self::payload($r);
            foreach (self::arrayRows($data, ['list']) as $row) {
                if (!is_array($row)) continue;
                $id = self::first($row, ['trans_id', 'transaction_id', 'id', 'id_v3']);
                $amount = self::money($row['fee'] ?? $row['real_fee'] ?? $row['total_fee'] ?? $row['amount'] ?? $row['money'] ?? 0);
                $at = self::timestamp($row['timestamp'] ?? $row['pay_time'] ?? $row['create_time'] ?? $row['trade_time'] ?? 0);
                if ($id === '' || $amount <= 0 || $at <= 0 || isset($seen[$id])) continue;
                $seen[$id] = true;
                // 双店员不依赖付款人或备注，匹配只使用流水号、金额和时间。
                $items[] = ['id' => $id, 'amount' => $amount, 'time' => $at, 'buyer' => ''];
            }
        }
        usort($items, function ($a, $b) { return (int)$b['time'] <=> (int)$a['time']; });
        return ['code' => 0, 'items' => $items];
    }

    static private function fetchPersonalPayees($cfg, $sid)
    {
        $r = self::wechatRequest('POST', '/qrapp/notifier/GetPayeeList?sid=' . rawurlencode($sid), ['sid' => $sid], $cfg, 'personal');
        if ($r['code'] !== 0) return $r;
        $rows = self::arrayRows(self::payload($r), ['payee_list', 'payeeList', 'list']);
        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $openid = self::extract($row, ['payee_openid', 'openid', 'payeeOpenid']);
            if ($openid === '' || isset($seen[$openid])) continue;
            $seen[$openid] = true;
            $items[] = [
                'payee_openid' => $openid,
                'payee_name' => self::first($row, ['payee_nickname', 'nickname', 'name']),
                'payee_shop' => self::first($row, ['shop_name', 'shopname']),
            ];
        }
        return $items ? ['code' => 0, 'msg' => '', 'items' => $items] : ['code' => -1, 'msg' => '个人店员未返回收款账本列表', 'items' => []];
    }

    static private function personalPayees($cfg)
    {
        $rows = json_decode((string)($cfg['personal_payee_list'] ?? ''), true);
        $items = [];
        $seen = [];
        if (is_array($rows)) foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $openid = trim((string)($row['payee_openid'] ?? $row['openid'] ?? $row['payeeOpenid'] ?? ''));
            if ($openid === '' || isset($seen[$openid])) continue;
            $seen[$openid] = true;
            $items[] = [
                'payee_openid' => $openid,
                'payee_name' => trim((string)($row['payee_name'] ?? $row['payee_nickname'] ?? $row['nickname'] ?? '')),
                'payee_shop' => trim((string)($row['payee_shop'] ?? $row['shop_name'] ?? '')),
            ];
        }
        $legacy = trim((string)($cfg['personal_payee_openid'] ?? ''));
        if (!$items && $legacy !== '') $items[] = ['payee_openid' => $legacy, 'payee_name' => (string)($cfg['personal_payee_name'] ?? ''), 'payee_shop' => (string)($cfg['personal_payee_shop'] ?? '')];
        return $items;
    }

    static private function getBusinessFlows($cfg)
    {
        $sid = $cfg['business_sid']; $params = ['v' => self::CLERK_VERSION, 'sid' => $sid, 'mch_id' => $cfg['business_aid'], 'shop_id' => $cfg['business_shop_id'], 'end_time' => '0', 'page_size' => self::PAGE_SIZE, 'last_id' => '', 'from_page' => 'index', 'is_only_assistant' => 'false']; $r = self::wechatRequest('GET', '/sjtassistant/assistantbill/getassistantbilllist?' . http_build_query($params), null, $cfg, 'business'); if ($r['code'] !== 0) return ['code' => -1, 'msg' => $r['msg'], 'items' => []];
        $data = self::payload($r); $rows = self::arrayRows($data, ['bill_list', 'items']); $items = []; $now = time(); foreach ($rows as $row) { if (!is_array($row)) continue; $rowTime = self::timestamp($row['pay_succ_time'] ?? 0); if ($rowTime > 0 && ($now - $rowTime > self::BUSINESS_FLOW_WINDOW || $rowTime > $now + 120)) continue; $id = self::first($row, ['id', 'bill_id', 'billId']); $paySucc = self::first($row, ['pay_succ_time', 'paySuccTime']); if ($id === '' || $paySucc === '') continue; $detailParams = ['v' => self::CLERK_VERSION, 'sid' => $sid, 'mch_id' => $cfg['business_aid'], 'shop_id' => $cfg['business_shop_id'], 'id' => $id, 'pay_succ_time' => $paySucc]; $d = self::wechatRequest('GET', '/sjtassistant/assistantbill/getassistantbilldetail?' . http_build_query($detailParams), null, $cfg, 'business'); if ($d['code'] !== 0) { if (self::sidInvalid($d['msg'])) return ['code' => -1, 'msg' => $d['msg'], 'items' => []]; continue; } $detail = self::payload($d); $channelType = strtoupper((string)($detail['channel_type'] ?? '')); $state = strtoupper((string)($detail['current_state'] ?? '')); if (!in_array($channelType, ['QRCODE_ONLINE', 'LINK_QRCODE'], true) || !in_array($state, ['PAID', 'SUCCESS', '已收款'], true)) continue; if (!empty($detail['refund_list']) && is_array($detail['refund_list'])) continue; $amount = 0; foreach (['received_fee_amount', 'recv_fee', 'settlement_total', 'total_fee', 'order_amount'] as $key) { if (isset($detail[$key])) { $amount = self::money($detail[$key]); if ($amount > 0) break; } } if ($amount <= 0) foreach (['settlement_amount', 'total_amount'] as $key) $amount = max($amount, self::money($row[$key] ?? 0)); $at = self::timestamp($row['pay_succ_time'] ?? $detail['transfer_recv_time'] ?? 0); $trade = self::first($detail, ['out_trade_no', 'trans_id']) ?: $id; if ($trade !== '' && $amount > 0 && $at > 0) $items[] = ['id' => $trade, 'amount' => $amount, 'time' => $at, 'buyer' => self::first($detail, ['payer_nick_name', 'payer_name'])]; }
        return ['code' => 0, 'items' => $items];
    }

    static public function flowWindowSeconds($channel) { return self::getConfig($channel)['pay_mode'] === 'business' ? self::BUSINESS_FLOW_WINDOW : self::PERSONAL_FLOW_WINDOW; }
    static public function orderExpireSeconds() { return self::ORDER_EXPIRE_SECONDS; }
    static public function isExpired($order) { return !empty($order['addtime']) && strtotime($order['addtime']) + self::ORDER_EXPIRE_SECONDS <= time(); }
    static public function markExpired($order) { global $DB; if ((int)($order['status'] ?? 0) !== 0) return false; $ext = self::readExt($order); if (!empty($ext['yundianyxd_expired'])) return true; $ext['yundianyxd_expired'] = 1; $ext['yundianyxd_expired_at'] = date('Y-m-d H:i:s'); return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false; }

    static private function createCode($cfg, $siteurl)
    {
        $prefix = $cfg['pay_mode'] === 'business' ? 'business_' : 'personal_'; $value = $cfg['type'] === 'image' ? $cfg[$prefix . 'qrcode_file'] : $cfg[$prefix . 'qrcode']; if ($value === '') return ['code' => -1, 'msg' => $cfg['type'] === 'image' ? '尚未配置当前收款方式的收款码图片地址' : '尚未配置当前收款方式的收款码地址']; if ($cfg['type'] === 'image') { $value = self::normalizeQr($value); if (strpos($value, '/') === 0 && $siteurl !== '') $value = rtrim($siteurl, '/') . '/' . ltrim($value, '/'); } return ['code' => 0, 'qrcode' => $value];
    }

    static private function saveConfig($channelId, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yundianyxd'))return \lib\AdminApp\PluginConfig::capture('yundianyxd',$channelId,$updates);
        if(\lib\Task\PaymentMonitor::activeFor('yundianyxd'))return \lib\Task\PaymentMonitor::saveCredentials($channelId,$updates,['personal_sid', 'personal_payee_openid', 'personal_payee_name', 'personal_payee_shop', 'personal_payee_list', 'personal_bind_token', 'personal_yyb_ref', 'personal_guanjia_ref', 'personal_bound_login_mode', 'business_sid', 'business_aid', 'business_shop_id', 'business_account_list', 'business_bind_token', 'business_yyb_ref', 'business_guanjia_ref', 'business_bound_login_mode']);
        global $DB; $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$channelId . "' LIMIT 1"); if (!$row || $row['plugin'] !== 'yundianyxd') return false; $config = json_decode((string)$row['config'], true); if (!is_array($config)) $config = []; foreach ($updates as $key => $value) $config[$key] = (string)$value; return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }

    static private function getConfig($channel)
    {
        $keys = ['gateway','pay_mode','personal_login_mode','business_login_mode','type','personal_qrcode','personal_qrcode_file','business_qrcode','business_qrcode_file','proxy_mode','proxy','personal_sid','personal_payee_openid','personal_payee_name','personal_payee_shop','personal_payee_list','personal_bind_token','personal_yyb_ref','personal_guanjia_ref','personal_bound_login_mode','business_sid','business_aid','business_shop_id','business_account_list','business_bind_token','business_yyb_ref','business_guanjia_ref','business_bound_login_mode']; $cfg = []; foreach ($keys as $key) $cfg[$key] = trim((string)($channel[$key] ?? '')); $cfg['gateway'] = rtrim($cfg['gateway'], '/'); if (!in_array($cfg['pay_mode'], ['personal','business'], true)) $cfg['pay_mode'] = 'personal'; if (!in_array($cfg['personal_login_mode'], ['yyb','guanjia'], true)) $cfg['personal_login_mode'] = 'yyb'; if (!in_array($cfg['business_login_mode'], ['yyb','guanjia'], true)) $cfg['business_login_mode'] = 'yyb'; if (!in_array($cfg['type'], ['qrcode','image'], true)) $cfg['type'] = 'qrcode'; if (!in_array($cfg['proxy_mode'], ['no_proxy','custom_proxy'], true)) $cfg['proxy_mode'] = 'no_proxy'; return $cfg;
    }

    static private function modeKey($mode, $key) { return ($mode === 'business' ? 'business_' : 'personal_') . $key; }
    static private function modeSid($cfg, $mode) { return (string)$cfg[self::modeKey($mode, 'sid')]; }
    static private function loginMode($cfg, $mode) { $key = $mode === 'business' ? 'business_login_mode' : 'personal_login_mode'; return $cfg[$key] === 'guanjia' ? 'guanjia' : 'yyb'; }
    static private function boundRef($cfg, $mode) { return (string)$cfg[self::modeKey($mode, self::loginMode($cfg, $mode) === 'guanjia' ? 'guanjia_ref' : 'yyb_ref')]; }
    static private function appId($mode) { return $mode === 'business' ? self::CLERK_APP_ID : self::PERSONAL_APP_ID; }
    static private function readExt($order) { if (empty($order['ext'])) return []; $ext = @unserialize($order['ext']); return is_array($ext) ? $ext : []; }

    static private function gatewayRequest($method, $url, $body, $cfg)
    {
        $headers = ['Accept: application/json, */*', 'Content-Type: application/json', 'User-Agent: Mozilla/5.0']; try { foreach (self::proxyHeaders($cfg) as $key => $value) $headers[] = $key . ': ' . $value; } catch (Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []]; } return self::httpJson($method, $url, $body, $headers, '云端');
    }

    static private function wechatRequest($method, $path, $body, $cfg, $mode)
    {
        $url = self::WX_HOST . $path; $headers = ['Accept: application/json', 'Content-Type: application/json', 'xweb_xhr: 1', 'Referer: ' . ($mode === 'business' ? self::CLERK_REFERER : self::PERSONAL_REFERER), 'User-Agent: Mozilla/5.0 MicroMessenger/8.0.69 MiniProgramEnv/android']; return self::httpJson($method, $url, $body, $headers, '微信店员');
    }

    static private function httpJson($method, $url, $body, $headers, $label)
    {
        if (strtoupper($method) !== 'POST' && is_array($body) && $body) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($body); $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_NOPROXY => '*']); if (strtoupper($method) === 'POST') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST'); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($raw === false || $error !== '') return ['code' => -1, 'msg' => '请求接口失败：' . $error, 'data' => []]; $data = json_decode((string)$raw, true); if (!is_array($data)) return ['code' => -1, 'msg' => '接口返回不是 JSON（HTTP ' . $status . '）', 'data' => []]; if ($status < 200 || $status >= 300) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? ($label . ' HTTP ' . $status)), 'data' => $data]; foreach (['errcode','retcode','ret_code','error_code','errno'] as $key) if (isset($data[$key]) && (string)$data[$key] !== '' && !in_array((string)$data[$key], ['0','1','200'], true)) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? $data['errmsg'] ?? ($label . '接口返回失败')), 'data' => $data]; if (isset($data['ok']) && $data['ok'] === false) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? ($label . '接口返回失败')), 'data' => $data]; if (isset($data['code']) && is_numeric($data['code']) && !in_array((int)$data['code'], [0,1,200], true)) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? ($label . '接口返回失败')), 'data' => $data]; return ['code' => 0, 'msg' => '', 'data' => $data];
    }

    static private function proxyHeaders($cfg)
    {
        if ($cfg['proxy_mode'] !== 'custom_proxy') return []; $raw = trim((string)$cfg['proxy']); if ($raw === '') throw new InvalidArgumentException('已开启代理但未填写代理地址'); if (strpos($raw, '://') === false) $raw = 'socks5://' . $raw; $parts = parse_url($raw); if (!$parts || empty($parts['host']) || empty($parts['port'])) throw new InvalidArgumentException('代理地址格式无效'); $scheme = strtolower((string)($parts['scheme'] ?? 'socks5')); if (!in_array($scheme, ['socks5','socks5h','http','https'], true)) throw new InvalidArgumentException('代理协议仅支持 socks5、socks5h、http、https'); return ['X-Proxy-Cluster-URL' => $parts['host'] . ':' . (int)$parts['port'], 'X-Proxy-Cluster-Type' => $scheme, 'X-Proxy-Cluster-User' => rawurldecode((string)($parts['user'] ?? '')), 'X-Proxy-Cluster-Pass' => rawurldecode((string)($parts['pass'] ?? ''))];
    }

    static private function payload($result) { $data = is_array($result['data'] ?? null) ? $result['data'] : []; if (isset($data['data']) && is_array($data['data'])) return $data['data']; if (isset($data['result']) && is_array($data['result'])) return $data['result']; return $data; }
    static private function extract($value, $keys) { if (is_string($value)) { $decoded = json_decode($value, true); return is_array($decoded) ? self::extract($decoded, $keys) : ''; } if (!is_array($value)) return ''; foreach ($keys as $key) if (isset($value[$key]) && !is_array($value[$key]) && (string)$value[$key] !== '') return (string)$value[$key]; foreach ($value as $child) if (is_array($child)) { $found = self::extract($child, $keys); if ($found !== '') return $found; } return ''; }
    static private function first($value, $keys) { foreach ($keys as $key) if (is_array($value) && isset($value[$key]) && (string)$value[$key] !== '') return (string)$value[$key]; return ''; }
    static private function arrayRows($data, $keys) { if (!is_array($data)) return []; foreach ($keys as $key) if (isset($data[$key]) && is_array($data[$key])) return array_values($data[$key]); foreach (['data','result','payload'] as $key) if (isset($data[$key]) && is_array($data[$key])) { $rows = self::arrayRows($data[$key], $keys); if ($rows) return $rows; } return []; }
    static private function timestamp($value) { if (is_numeric($value)) { $time = (int)$value; return $time > 20000000000 ? (int)floor($time / 1000) : $time; } $time = strtotime((string)$value); return $time === false ? 0 : $time; }
    static private function money($value) { if ($value === null || $value === '') return 0; if (is_string($value) && strpos($value, '.') !== false) return (float)$value; $number = (float)$value; return $number > 0 ? $number / 100 : 0; }
    static private function normalizeQr($value) { $value = trim((string)$value); if ($value === '' || strpos($value, 'data:image/') === 0 || preg_match('#^https?://#i', $value)) return $value; $raw = base64_decode($value, true); if ($raw !== false && substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") return 'data:image/png;base64,' . $value; if ($raw !== false && substr($raw, 0, 3) === "\xff\xd8\xff") return 'data:image/jpeg;base64,' . $value; return $value; }
    static private function isImageValue($value) { $value = (string)$value; return strpos($value, 'data:image/') === 0 || (base64_decode($value, true) !== false && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)); }
    static private function retryTransientLoginError($result, $state)
    {
        $text = strtolower((string)($result['msg'] ?? ''));
        foreach (['httpdns', 'deadline exceeded', 'client.timeout', 'timed out', 'timeout', 'temporarily unavailable', 'temporary failure', 'connection reset', 'bad gateway', '502', '503', '504'] as $needle) {
            if (strpos($text, $needle) !== false) return ['code' => 201, 'state' => $state, 'msg' => '云端微信请求暂时超时，保留登录会话并自动重试'];
        }
        return $result;
    }

    static private function sidInvalid($value) { $text = strtolower((string)$value); foreach (['用户鉴权错误','鉴权失败','登录失败','登录态失效','登录态无效','会话失效','session invalid','invalid sid'] as $needle) if (strpos($text, strtolower($needle)) !== false) return true; return false; }
}
