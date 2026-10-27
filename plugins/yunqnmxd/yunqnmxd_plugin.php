<?php

/**
 * 微信云端全能码-XD。
 * 云端只负责扫码登录桥和获取小程序 code；全能码账本会话、流水查询和订单回调由本插件直连微信完成。
 */
class yunqnmxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('yunqnmxd', $channels, $context, static function($channel){
            $config=self::getConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['gateway', 'sid', 'login_mode', 'tally_openid']);

        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\yunqnmxd\monitorTarget($target,$state);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const APP_ID = 'wx7c86e0c731b9b8ef';
    const WX_HOST = 'https://payapp.wechatpay.cn';
    const WX_REFERER = 'https://servicewechat.com/wx7c86e0c731b9b8ef/177/page-frame.html';
    const ORDER_EXPIRE_SECONDS = 300;
    // 固定收款码不会写入订单备注，流水只能按金额和时间匹配；匹配窗口固定 5 分钟。
    const FLOW_WINDOW_SECONDS = 300;
    const PAGE_SIZE = 20;
    const MAX_PAGES = 5;

    static public $info = [
        'name' => 'yunqnmxd',
        'showname' => '微信云端全能码–XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['wxpay'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'gateway' => ['name' => '网关地址', 'type' => 'input', 'note' => '填写云端完整 URL'],
            'login_mode' => ['name' => '登录方式', 'type' => 'select', 'options' => ['yyb' => '应用宝', 'guanjia' => '电脑管家']],
            'type' => ['name' => '收款码类型', 'type' => 'select', 'options' => ['qrcode' => '二维码', 'image' => '图片']],
            'qrcode' => ['name' => '收款码地址', 'type' => 'input', 'note' => '二维码模式填写收款码内容或地址'],
            'qrcode_file' => ['name' => '收款码图片地址', 'type' => 'input', 'note' => '图片模式填写图片 URL 或 data:image 地址'],
            'sid' => ['name' => '登录状态', 'type' => 'input', 'note' => '扫码成功后自动保存，不支持手动填写'],
            'yyb_ref' => ['name' => '应用宝账号', 'type' => 'hidden'],
            'guanjia_ref' => ['name' => '电脑管家账号', 'type' => 'hidden'],
            'custom_session_key' => ['name' => '全能码会话', 'type' => 'hidden'],
            'tally_openid' => ['name' => '账本用户', 'type' => 'hidden'],
            'bound_login_mode' => ['name' => '当前登录方式', 'type' => 'hidden'],
            'proxy_mode' => ['name' => '代理模式', 'type' => 'select', 'options' => ['no_proxy' => '不使用代理', 'custom_proxy' => '自定义 Socks5/HTTP 代理']],
            'proxy' => ['name' => '代理地址', 'type' => 'input', 'note' => '仅用于请求云端网关，不用于微信账本接口'],
        ],
        'bindwxmp' => false,
        'bindwxa' => false,
        'note' => '<style>
        form#form-info + br,form#form-info ~ br{display:none!important}
        #form-info{text-align:left!important;overflow:visible}
        #form-info #save,form#form-info .yunqnmxd-login-action{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;margin:0 0 12px!important;vertical-align:top}
        #form-info #save{margin-left:4%!important}
        .yunqnmxd-login-action{text-align:center;line-height:20px;padding:10px 16px;background:#07c160;color:#fff!important;border-radius:4px;text-decoration:none}
        .yunqnmxd-login-action:hover{background:#06ad56;text-decoration:none}
        .yunqnmxd-note{display:block;clear:both;line-height:1.8}
        #form-info input[name="config[sid]"]{background:#f5f7fa;cursor:not-allowed}
        </style>
        <a class="yunqnmxd-login-action" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,\'yunqnmxd_login_[channel]\',\'width=480,height=720,resizable=yes,scrollbars=yes\');return false;">扫码登录</a>
        <span class="yunqnmxd-note">扫码登录后自动建立全能码账本会话；登录方式切换后需重新扫码。固定收款码流水不写入订单备注，按金额和流水时间匹配 5 分钟内订单。</span>
        <script>(function(){var f=document.getElementById("form-info"),a=document.querySelector(".yunqnmxd-login-action"),s=document.getElementById("save"),type=document.querySelector("select[name=\'config[type]\']"),qr=document.querySelector("input[name=\'config[qrcode]\']"),img=document.querySelector("input[name=\'config[qrcode_file]\']"),pm=document.querySelector("select[name=\'config[proxy_mode]\']"),pa=document.querySelector("input[name=\'config[proxy]\']"),row=function(e){return e&&e.closest?e.closest(".form-group"):null};if(f&&a&&s&&a.parentNode!==f){f.insertBefore(a,s)}["yyb_ref","guanjia_ref","custom_session_key","tally_openid","bound_login_mode"].forEach(function(k){var e=document.querySelector("input[name=\'config["+k+"]\'],textarea[name=\'config["+k+"]\']"),r=row(e);if(r)r.style.display="none"});["sid"].forEach(function(k){var e=document.querySelector("input[name=\'config["+k+"]\']");if(e){e.readOnly=true;e.setAttribute("aria-readonly","true")}});function sync(){var q=row(qr),i=row(img),p=row(pa),kind=type?type.value:"qrcode";if(q)q.style.display=kind==="qrcode"?"":"none";if(i)i.style.display=kind==="image"?"":"none";if(p)p.style.display=pm&&pm.value==="custom_proxy"?"":"none"}if(type)type.addEventListener("change",sync);if(pm)pm.addEventListener("change",sync);sync()}());</script>',
    ];

    static public function submit() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }
    static public function mapi() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }

    static public function qrcode()
    {
        global $order, $channel, $conf, $siteurl;
        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? '微信支付'));
        $title = ($paymentName !== '' ? $paymentName : '微信支付') . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $paymentIcon = '/assets/icon/wxpay.ico';
        $code_url = '';
        $error_msg = '';
        $config = self::getConfig($channel);
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

    static public function notify() { return ['type' => 'error', 'msg' => '本插件通过 受管收款监控 轮询全能码账本，不接收 HTTP 回调']; }

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
        if ($action === 'state') self::adminResponse(['code' => 0, 'data' => self::getConfig($channel)]);
        $cfg = self::getConfig($channel);
        $endpoint = './plugin_page.php?channel=' . (int)$channel['id'] . '&func=adminLogin';
        $mode = $cfg['login_mode'] === 'guanjia' ? '电脑管家' : '应用宝';
        $state = self::boundRef($cfg) !== '' && $cfg['custom_session_key'] !== '' ? '已登录' : '尚未登录';
        ?>
        <style>.yunqnmxd-box{max-width:430px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}.yunqnmxd-box h3{margin:0 0 10px}.yunqnmxd-meta{color:#666;line-height:1.8}.yunqnmxd-qr{width:260px;height:260px;margin:16px auto;border:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;justify-content:center;color:#888}.yunqnmxd-qr img{max-width:240px;max-height:240px}.yunqnmxd-state{min-height:24px;margin:8px 0;color:#1677ff;font-weight:600}.yunqnmxd-state.ok{color:#16803c}.yunqnmxd-state.bad{color:#c62828}.yunqnmxd-msg{min-height:24px;margin:10px 0;color:#555}.yunqnmxd-msg.ok{color:#16803c}.yunqnmxd-msg.bad{color:#c62828}.yunqnmxd-box button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}</style>
        <div class="yunqnmxd-box"><h3>微信云端全能码–XD扫码登录</h3><div class="yunqnmxd-meta">通道：<?php echo htmlspecialchars((string)$channel['name'], ENT_QUOTES, 'UTF-8'); ?><br>登录方式：<?php echo $mode; ?><br>登录状态：<?php echo $state; ?></div><div class="yunqnmxd-qr" id="yunqnmxd-qr">正在准备二维码…</div><div class="yunqnmxd-state" id="yunqnmxd-state">状态：准备二维码</div><div class="yunqnmxd-msg" id="yunqnmxd-msg"></div><button type="button" id="yunqnmxd-start">重新获取二维码</button></div>
        <script src="/assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
        <script>(function(){var ep=<?php echo json_encode($endpoint,JSON_UNESCAPED_SLASHES); ?>,box=document.getElementById('yunqnmxd-qr'),state=document.getElementById('yunqnmxd-state'),msg=document.getElementById('yunqnmxd-msg'),btn=document.getElementById('yunqnmxd-start'),timer=0;function parse(t){var d=new DOMParser().parseFromString(t,'text/html'),n=d.querySelector('#yunqnmxd-response');if(!n)throw Error('后台响应格式错误');return JSON.parse(n.value||n.textContent||'{}')}function req(u){return fetch(u,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text()}).then(parse)}function setState(t,c){state.className='yunqnmxd-state '+(c||'');state.textContent='状态：'+t}function say(t,c){msg.className='yunqnmxd-msg '+(c||'');msg.textContent=t||''}function draw(v,k){box.innerHTML='';v=v||'';if(k==='image'||v.indexOf('data:image/')===0||/^https?:\/\//.test(v)&&/qrcode|qr\./i.test(v)){var i=document.createElement('img');i.src=v;i.alt='登录二维码';box.appendChild(i)}else if(window.jQuery&&jQuery.fn.qrcode)jQuery(box).qrcode({text:v,width:240,height:240,typeNumber:-1});else{box.textContent='二维码组件加载失败，请刷新后重试';say('二维码组件加载失败','bad')}}function poll(id){req(ep+'&action=poll&session_id='+encodeURIComponent(id)).then(function(d){if(d.code===0){setState('登录成功','ok');say(d.msg||'全能码会话已建立','ok');btn.disabled=false;try{if(window.opener&&!window.opener.closed)window.opener.location.reload()}catch(e){}return}if(d.code===201){setState(d.state==='scanned'?'已扫码，请确认':'等待扫码');say(d.msg||'');timer=setTimeout(function(){poll(id)},2000);return}setState('登录失败','bad');say(d.msg||'登录失败','bad');btn.disabled=false}).catch(function(){setState('连接重试中','bad');say('后台请求失败，正在重试');timer=setTimeout(function(){poll(id)},3000)})}function start(){clearTimeout(timer);btn.disabled=true;setState('等待扫码');say('');req(ep+'&action=start').then(function(d){if(d.code!==0){setState('获取二维码失败','bad');say(d.msg||'获取二维码失败','bad');btn.disabled=false;return}draw(d.qrcode,d.qrcode_type||'text');setState('等待扫码');poll(d.session_id)}).catch(function(e){setState('获取二维码失败','bad');say(e.message||'后台请求失败','bad');btn.disabled=false})}btn.onclick=start;start()}());</script>
        <?php
    }

    static private function adminResponse($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<textarea id="yunqnmxd-response" style="display:none">' . htmlspecialchars($json ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>';
        exit;
    }

    static public function beginLogin($channel)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址并保存'];
        if ($cfg['login_mode'] === '') return ['code' => -1, 'msg' => '请选择登录方式并保存'];
        $path = $cfg['login_mode'] === 'guanjia' ? '/guanjia/api/qrcode' : '/yyb/qr';
        $body = $cfg['login_mode'] === 'guanjia' ? ['appid' => self::APP_ID] : [];
        $result = self::requestJson('POST', $cfg['gateway'] . $path, $body, $cfg, true);
        if ($result['code'] !== 0) return $result;
        $data = self::payload($result);
        $session = self::extract($data, ['session_id', 'uuid', 'sessionId']);
        $qrcode = self::extract($data, ['qrcode', 'qr_code', 'url', 'login_url', 'image', 'image_base64']);
        if ($session === '' || $qrcode === '') return ['code' => -1, 'msg' => '云端未返回完整登录二维码'];
        return ['code' => 0, 'session_id' => $session, 'qrcode' => self::normalizeQr($qrcode), 'qrcode_type' => self::isImageValue($qrcode) ? 'image' : 'text'];
    }

    static public function pollLogin($channel, $session)
    {
        $cfg = self::getConfig($channel);
        if ($session === '') return ['code' => -1, 'msg' => '登录会话为空'];
        if ($cfg['login_mode'] === 'guanjia') {
            $result = self::requestJson('GET', $cfg['gateway'] . '/guanjia/api/status?uuid=' . rawurlencode($session), null, $cfg, true);
            $data = self::payload($result);
            if ($result['code'] !== 0) return self::retryTransientLoginError($result, 'waiting');
            $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? '')));
            if (in_array($status, ['pending', 'ready', 'scanned'], true)) return self::loginPending($status);
            if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return ['code' => -1, 'msg' => '电脑管家登录状态异常'];
            $account = is_array($data['result'] ?? null) ? $data['result'] : $data;
            $ref = self::extract($account, ['ref', 'openid', 'uin', 'bind_account', 'id']);
        } else {
            $result = self::requestJson('GET', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/poll', null, $cfg, true);
            $data = self::payload($result);
            if ($result['code'] !== 0) return self::retryTransientLoginError($result, 'waiting');
            $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? '')));
            if (in_array($status, ['pending', 'scanned'], true)) return self::loginPending($status);
            if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return ['code' => -1, 'msg' => '应用宝登录状态异常'];
            $confirmed = self::requestJson('POST', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/confirm', [], $cfg, true);
            if ($confirmed['code'] !== 0) return self::retryTransientLoginError($confirmed, 'scanned');
            $ref = self::extract(self::payload($confirmed), ['ref', 'openid', 'uin', 'bind_account', 'id']);
        }
        if ($ref === '') return ['code' => -1, 'msg' => '登录成功但云端未返回账号标识'];
        $sessionData = self::bootstrapSession($cfg, $ref);
        if ($sessionData['code'] !== 0) return self::retryTransientLoginError($sessionData, 'scanned');
        $refKey = $cfg['login_mode'] === 'guanjia' ? 'guanjia_ref' : 'yyb_ref';
        $updates = [$refKey => $ref, 'custom_session_key' => $sessionData['session'], 'tally_openid' => $sessionData['openid'], 'bound_login_mode' => $cfg['login_mode'], 'sid' => $sessionData['session']];
        $updates[$cfg['login_mode'] === 'guanjia' ? 'yyb_ref' : 'guanjia_ref'] = '';
        if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '登录成功但保存通道配置失败'];
        return ['code' => 0, 'msg' => '登录成功，全能码账本会话已建立'];
    }

    static private function loginPending($status)
    {
        return ['code' => 201, 'state' => $status === 'scanned' || $status === 'ready' ? 'scanned' : 'waiting', 'msg' => $status === 'scanned' || $status === 'ready' ? '已扫码，请在微信中确认' : '等待扫码'];
    }

    static private function bootstrapSession($cfg, $ref)
    {
        $code = self::getMiniCode($cfg, $ref);
        if ($code['code'] !== 0) return $code;
        $data = self::wxRequest('/tallybook/index', ['op_from' => 0, 'classification' => 0, 'year' => (int)date('Y'), 'month' => (int)date('n'), 'limit' => self::PAGE_SIZE, 'sort_type' => 1, 'is_get_more' => 0, 'skip_grant' => 0, 'use_new_sys' => 1, 'wxcode' => $code['value']]);
        if ($data['code'] !== 0) return $data;
        $session = self::extract(self::payload($data), ['custom_session_key']);
        if ($session === '') return ['code' => -1, 'msg' => '全能码接口未返回 custom_session_key'];
        $entrance = self::wxRequest('/tallybook/entrance', ['op_from' => 0, 'custom_session_key' => $session]);
        if ($entrance['code'] !== 0) return $entrance;
        $entry = self::payload($entrance);
        $newSession = self::extract($entry, ['custom_session_key']);
        if ($newSession !== '') $session = $newSession;
        return ['code' => 0, 'session' => $session, 'openid' => self::extract($entry, ['openid', 'tally_openid'])];
    }

    static private function getMiniCode($cfg, $ref)
    {
        $path = $cfg['login_mode'] === 'guanjia' ? '/guanjia/api/get-code' : '/yyb/wxapp/getCode';
        $body = $cfg['login_mode'] === 'guanjia' ? ['ref' => $ref, 'appid' => self::APP_ID] : ['ref' => $ref, 'app_id' => self::APP_ID];
        $result = self::requestJson('POST', $cfg['gateway'] . $path, $body, $cfg, true);
        if ($result['code'] !== 0) return $result;
        $code = self::extract(self::payload($result), ['jscode', 'js_code', 'auth_code', 'code_value', 'Code', 'code']);
        return $code === '' || in_array($code, ['0', '1', '200'], true) ? ['code' => -1, 'msg' => '云端未返回全能码小程序 code'] : ['code' => 0, 'value' => $code];
    }

    static public function checkCloudStatus($channel)
    {
        $cfg = self::getConfig($channel);
        $ref = self::boundRef($cfg);
        if ($cfg['gateway'] === '' || $ref === '') return ['code' => 0, 'msg' => '尚未扫码登录云端，已禁止发起订单'];
        $path = $cfg['login_mode'] === 'guanjia' ? '/guanjia/api/accounts' : '/yyb/accounts';
        $result = self::requestJson('GET', $cfg['gateway'] . $path, null, $cfg, true);
        if ($result['code'] !== 0) return ['code' => -1, 'msg' => '云端状态检查失败：' . $result['msg']];
        $rows = self::payload($result);
        if (is_array($rows['accounts'] ?? null)) $rows = $rows['accounts'];
        if (is_array($rows['list'] ?? null)) $rows = $rows['list'];
        if (!is_array($rows)) return ['code' => -1, 'msg' => '云端状态响应异常'];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $refs = [(string)($row['ref'] ?? ''), (string)($row['openid'] ?? ''), (string)($row['uin'] ?? ''), (string)($row['bind_account'] ?? ''), (string)($row['id'] ?? '')];
            if (!in_array($ref, $refs, true)) continue;
            $status = strtolower(trim((string)($row['status'] ?? $row['state'] ?? '')));
            if (in_array($status, ['online', 'alive', 'active', '1', 'ok'], true)) return ['code' => 1, 'msg' => '云端在线'];
            if (in_array($status, ['offline', 'dead', 'logout', 'logged_out', 'expired', '0'], true)) return ['code' => 0, 'msg' => '云端明确返回离线，请重新扫码登录'];
            return ['code' => -1, 'msg' => '云端未返回可识别的在线状态'];
        }
        return ['code' => 0, 'msg' => '云端未找到当前登录账号，请重新扫码登录'];
    }

    static private function createCode($cfg, $siteurl = '')
    {
        $value = $cfg['type'] === 'image' ? $cfg['qrcode_file'] : $cfg['qrcode'];
        if ($value === '') return ['code' => -1, 'msg' => $cfg['type'] === 'image' ? '尚未配置收款码图片地址' : '尚未配置收款码地址'];
        if ($cfg['type'] === 'image') {
            $value = self::normalizeQr($value);
            if (strpos($value, '/') === 0 && $siteurl !== '') $value = rtrim($siteurl, '/') . '/' . ltrim($value, '/');
        }
        return ['code' => 0, 'qrcode' => $value];
    }

    static public function getLedger($channel)
    {
        $cfg = self::getConfig($channel);
        $ref = self::boundRef($cfg);
        if ($ref === '') return ['code' => -1, 'msg' => '尚未扫码登录全能码', 'items' => []];
        $session = $cfg['custom_session_key'];
        if ($session !== '') {
            $entry = self::wxRequest('/tallybook/entrance', ['op_from' => 0, 'custom_session_key' => $session]);
            if ($entry['code'] === 0) {
                $data = self::payload($entry);
                $newSession = self::extract($data, ['custom_session_key']);
                if ($newSession !== '') { $session = $newSession; self::saveConfig((int)$channel['id'], ['custom_session_key' => $session, 'tally_openid' => self::extract($data, ['openid', 'tally_openid'])]); }
            } else $session = '';
        }
        if ($session === '') {
            $boot = self::bootstrapSession($cfg, $ref);
            if ($boot['code'] !== 0) return ['code' => -1, 'msg' => $boot['msg'], 'items' => []];
            $session = $boot['session'];
            self::saveConfig((int)$channel['id'], ['custom_session_key' => $session, 'tally_openid' => $boot['openid']]);
        }
        $window = self::FLOW_WINDOW_SECONDS;
        $body = ['op_from' => 0, 'classification' => 0, 'year' => (int)date('Y'), 'month' => (int)date('n'), 'limit' => self::PAGE_SIZE, 'sort_type' => 1, 'is_get_more' => 0, 'skip_grant' => 0, 'use_new_sys' => 1, 'custom_session_key' => $session];
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = self::wxRequest('/tallybook/index', $body);
            if ($result['code'] !== 0) return ['code' => -1, 'msg' => $result['msg'], 'items' => []];
            $data = self::payload($result);
            $newSession = self::extract($data, ['custom_session_key']);
            if ($newSession !== '') $session = $newSession;
            foreach ((array)($data['records_info'] ?? []) as $month) foreach ((array)($month['days'] ?? []) as $day) foreach ((array)($day['records'] ?? []) as $row) if (is_array($row)) $rows[] = $row;
            $oldest = 0;
            foreach ($rows as $row) { $t = self::timestamp($row['bill_time'] ?? 0); if ($t > 0 && ($oldest === 0 || $t < $oldest)) $oldest = $t; }
            $searchAfter = (string)($data['search_after'] ?? '');
            if (($data['need_get_more'] ?? false) !== true || $searchAfter === '' || ($oldest > 0 && $oldest < time() - $window)) break;
            $body['is_get_more'] = 1;
            $body['search_after'] = $searchAfter;
        }
        self::saveConfig((int)$channel['id'], ['custom_session_key' => $session]);
        return ['code' => 0, 'items' => self::filterFlows($rows, $window), 'msg' => ''];
    }

    static private function filterFlows($rows, $window)
    {
        $items = [];
        $now = time();
        foreach ($rows as $row) {
            if ((int)($row['bill_type'] ?? 0) !== 2 || (int)($row['trans_source'] ?? 0) !== 1) continue;
            if (in_array((int)($row['classification'] ?? 0), [27, 33, 34, 36], true)) continue;
            $remark = trim((string)($row['remark'] ?? ''));
            $object = trim((string)($row['trade_object'] ?? ''));
            $haystack = strtolower($remark . ' ' . $object);
            $blocked = false;
            foreach (['红包', '消费', '退款', '退还', '付款', '转账', '充值'] as $word) if (strpos($haystack, $word) !== false) { $blocked = true; break; }
            if ($blocked) continue;
            $amount = (float)($row['balance'] ?? 0);
            $time = self::timestamp($row['bill_time'] ?? 0);
            if ($amount <= 0 || $time <= 0 || $time < $now - $window || $time > $now + 60) continue;
            // 固定收款码流水不带订单备注，不能依赖备注关键词；订单号、金额和时间由下方匹配逻辑校验。
            $id = self::first($row, ['id', 'trans_id']);
            if ($id === '') continue;
            $items[] = ['id' => $id, 'amount' => $amount, 'time' => $time, 'remark' => $remark !== '' ? $remark : $object, 'buyer' => $object];
        }
        return $items;
    }

    static public function flowValues($row) { return $row; }
    static public function orderExpireSeconds() { return self::ORDER_EXPIRE_SECONDS; }
    static public function flowWindowSeconds($channel) { return self::FLOW_WINDOW_SECONDS; }

    static public function isExpired($order) { return !empty($order['addtime']) && strtotime($order['addtime']) + self::ORDER_EXPIRE_SECONDS <= time(); }
    static public function markExpired($order)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0) return false;
        $ext = self::readExt($order);
        if (!empty($ext['yunqnmxd_expired'])) return true;
        $ext['yunqnmxd_expired'] = 1;
        $ext['yunqnmxd_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static private function saveConfig($channelId, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yunqnmxd'))return \lib\AdminApp\PluginConfig::capture('yunqnmxd',$channelId,$updates);
        if(\lib\Task\PaymentMonitor::activeFor('yunqnmxd'))return \lib\Task\PaymentMonitor::saveCredentials($channelId,$updates,['sid', 'yyb_ref', 'guanjia_ref', 'custom_session_key', 'tally_openid', 'bound_login_mode']);
        global $DB;
        $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$channelId . "' LIMIT 1");
        if (!$row || $row['plugin'] !== 'yunqnmxd') return false;
        $config = json_decode((string)$row['config'], true); if (!is_array($config)) $config = [];
        foreach ($updates as $key => $value) $config[$key] = (string)$value;
        return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }

    static private function getConfig($channel)
    {
        $keys = ['gateway', 'login_mode', 'type', 'qrcode', 'qrcode_file', 'proxy_mode', 'proxy', 'sid', 'yyb_ref', 'guanjia_ref', 'custom_session_key', 'tally_openid', 'bound_login_mode'];
        $cfg = []; foreach ($keys as $key) $cfg[$key] = trim((string)($channel[$key] ?? ''));
        $cfg['gateway'] = rtrim($cfg['gateway'], '/');
        if (!in_array($cfg['login_mode'], ['yyb', 'guanjia'], true)) $cfg['login_mode'] = '';
        if (!in_array($cfg['type'], ['qrcode', 'image'], true)) $cfg['type'] = 'qrcode';
        if (!in_array($cfg['proxy_mode'], ['no_proxy', 'custom_proxy'], true)) $cfg['proxy_mode'] = 'no_proxy';
        return $cfg;
    }

    static private function boundRef($cfg) { return $cfg['login_mode'] === 'guanjia' ? $cfg['guanjia_ref'] : $cfg['yyb_ref']; }
    static private function readExt($order) { if (empty($order['ext'])) return []; $ext = @unserialize($order['ext']); return is_array($ext) ? $ext : []; }

    static private function requestJson($method, $url, $body, $cfg, $gateway)
    {
        $headers = ['Accept: application/json, */*', 'Content-Type: application/json', 'xweb_xhr: 1', 'User-Agent: Mozilla/5.0 MicroMessenger/8.0.69 MiniProgramEnv/android'];
        $headers[] = 'Referer: ' . self::WX_REFERER;
        try { if ($gateway) foreach (self::proxyHeaders($cfg) as $name => $value) $headers[] = $name . ': ' . $value; }
        catch (\Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []]; }
        if (strtoupper($method) !== 'POST' && is_array($body) && $body) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_NOPROXY => '*']);
        if (strtoupper($method) === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $error !== '') return ['code' => -1, 'msg' => '请求接口失败：' . $error, 'data' => []];
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) return ['code' => -1, 'msg' => '接口返回不是 JSON（HTTP ' . $status . '）', 'data' => []];
        if ($status < 200 || $status >= 300) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? ('HTTP ' . $status)), 'data' => $data];
        foreach (['errcode', 'retcode', 'ret_code'] as $key) if (isset($data[$key]) && (int)$data[$key] !== 0) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? $data['errmsg'] ?? $data['ret_msg'] ?? '接口返回失败'), 'data' => $data];
        if (isset($data['code']) && is_numeric($data['code']) && !in_array((int)$data['code'], [0, 200], true)) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data];
        return ['code' => 0, 'msg' => '', 'data' => $data];
    }

    static private function wxRequest($path, $body)
    {
        return self::requestJson('POST', self::WX_HOST . $path, $body, [], false);
    }

    static private function proxyHeaders($cfg)
    {
        if ($cfg['proxy_mode'] !== 'custom_proxy') return [];
        $raw = trim((string)$cfg['proxy']);
        if ($raw === '') throw new \InvalidArgumentException('已开启代理但未填写代理地址');
        if (strpos($raw, '://') === false) $raw = 'socks5://' . $raw;
        $parts = parse_url($raw);
        if (!$parts || empty($parts['host']) || empty($parts['port'])) throw new \InvalidArgumentException('代理地址格式无效');
        $scheme = strtolower((string)($parts['scheme'] ?? 'socks5'));
        if (!in_array($scheme, ['socks5', 'socks5h', 'http', 'https'], true)) throw new \InvalidArgumentException('代理协议仅支持 socks5、socks5h、http、https');
        return ['X-Proxy-Cluster-URL' => $parts['host'] . ':' . (int)$parts['port'], 'X-Proxy-Cluster-Type' => $scheme, 'X-Proxy-Cluster-User' => rawurldecode((string)($parts['user'] ?? '')), 'X-Proxy-Cluster-Pass' => rawurldecode((string)($parts['pass'] ?? ''))];
    }

    static private function payload($result)
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (isset($data['data']) && is_array($data['data'])) return $data['data'];
        if (isset($data['result']) && is_array($data['result'])) return $data['result'];
        return $data;
    }
    static private function extract($value, $keys)
    {
        if (is_string($value)) { $decoded = json_decode($value, true); return is_array($decoded) ? self::extract($decoded, $keys) : ''; }
        if (!is_array($value)) return '';
        foreach ($keys as $key) if (isset($value[$key]) && !is_array($value[$key]) && (string)$value[$key] !== '') return (string)$value[$key];
        foreach ($value as $child) { if (!is_array($child)) continue; $found = self::extract($child, $keys); if ($found !== '') return $found; }
        return '';
    }
    static private function first($value, $keys) { return self::extract($value, $keys); }
    static private function timestamp($value)
    {
        if (is_numeric($value)) { $time = (int)$value; return $time > 20000000000 ? (int)floor($time / 1000) : $time; }
        $time = strtotime((string)$value); return $time === false ? 0 : $time;
    }
    static private function retryTransientLoginError($result, $state)
    {
        $text = strtolower((string)($result['msg'] ?? ''));
        foreach (['httpdns', 'deadline exceeded', 'client.timeout', 'timed out', 'timeout', 'temporarily unavailable', 'temporary failure', 'connection reset', 'bad gateway', '502', '503', '504'] as $needle) {
            if (strpos($text, $needle) !== false) return ['code' => 201, 'state' => $state, 'msg' => '云端微信请求暂时超时，保留登录会话并自动重试'];
        }
        return $result;
    }

    static private function normalizeQr($value)
    {
        $value = trim((string)$value); if ($value === '' || strpos($value, 'data:image/') === 0 || preg_match('#^https?://#i', $value)) return $value;
        $raw = base64_decode($value, true);
        if ($raw !== false && substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") return 'data:image/png;base64,' . $value;
        if ($raw !== false && substr($raw, 0, 3) === "\xff\xd8\xff") return 'data:image/jpeg;base64,' . $value;
        return $value;
    }
    static private function isImageValue($value) { $value = (string)$value; return strpos($value, 'data:image/') === 0 || (base64_decode($value, true) !== false && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)); }
}
