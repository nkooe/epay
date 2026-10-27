<?php

/**
 * 微信收款链接云端–XD。
 *
 * 云端只负责应用宝/电脑管家扫码和获取小程序 code，并通过请求头接收代理配置。
 * 收款链接创建、流水查询、暂停和删除均由本插件服务器直连微信接口，不使用本机 cURL 代理。
 */
class yunskljxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('yunskljxd', $channels, $context, static function($channel){
            $config=self::getConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['gateway', 'sid', 'shop_id', 'login_mode']);

        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\yunskljxd\monitorTarget($target,$state);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    static private $deleteRetryAt = [];
    // 无经营权限只能占用一个链接 3 分钟；有经营权限不受单链接限制，支付 5 分钟。
    const F2F_EXPIRE_SECONDS = 180;
    const BUSINESS_EXPIRE_SECONDS = 300;
    const F2F_FLOW_GRACE_SECONDS = 180;
    const BUSINESS_FLOW_GRACE_SECONDS = 300;
    const CANCEL_RETRY_SECONDS = 30;
    // 已支付链接先暂停，保留 15 分钟给流水延迟和回调重试，之后再删除。
    const PAID_LINK_RETENTION_SECONDS = 900;
    const FLOAT_STEP = 0.01;

    const LINK_APP_ID = 'wx9291fe7dadf5a574';
    // 收款链接列表、创建和管理接口使用 /sjt/linkqrcode/linkqrcode/... 路径。
    const LINK_HOST = 'https://sjtmgr.wxpapp.weixin.qq.com/sjt/linkqrcode';
    const API_VERSION = '1.21.13';
    const SHOP_VERSION = '0.69.7';
    const OPERATOR_MANAGER = 'E_LINK_QRCODE_OPERATOR_ROLE_MANAGER';

    static public $info = [
        'name' => 'yunskljxd',
        'showname' => '微信收款链接云端–XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['wxpay'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'gateway' => ['name' => '网关地址', 'type' => 'input', 'note' => '填写云端网关完整 URL'],
            'login_mode' => ['name' => '登录方式', 'type' => 'select', 'options' => ['yyb' => '应用宝', 'guanjia' => '电脑管家']],
            'link_permission_mode' => ['name' => '收款链接权限', 'type' => 'select', 'options' => ['f2f' => '无经营权限', 'business' => '有经营权限']],
            'sid' => ['name' => 'SID（登录状态）', 'type' => 'input', 'note' => '扫码登录后自动获取，不支持手动填写'],
            'shop_id' => ['name' => '店铺 ID', 'type' => 'input', 'note' => '有经营权限时扫码登录后自动同步'],
            'proxy_mode' => ['name' => '是否使用代理', 'type' => 'select', 'options' => ['no_proxy' => '否', 'custom_proxy' => '是']],
            'proxy' => ['name' => '代理地址', 'type' => 'input', 'note' => 'socks5://用户:密码@地址:端口 或 http://地址:端口'],
        ],
        'bindwxmp' => false,
        'bindwxa' => false,
        'note' => '<style>
        form#form-info{overflow:visible;text-align:left!important}
        form#form-info #save,form#form-info .yunskljxd-login{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;margin:0 0 12px!important;vertical-align:top}
        form#form-info #save{margin-left:4%!important}
        .yunskljxd-login{text-align:center;line-height:20px;padding:10px 16px;background:#07c160;color:#fff!important;border-radius:4px;text-decoration:none}
        .yunskljxd-login:hover{background:#06ad56;text-decoration:none}
        #form-info input[name="config[sid]"],#form-info input[name="config[shop_id]"]{background:#f5f7fa;cursor:not-allowed}
        </style>
        <a class="yunskljxd-login" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,\'yunskljxd_login_[channel]\',\'width=470,height=720,resizable=yes,scrollbars=yes\');return false;">扫码登录</a>
        <span style="display:block;clear:both;line-height:1.8">点击“扫码登录”后自动获取 SID；注意登录方式和收款链接权限不通用，需重新扫码登录。只有新扫码成功并完成账号信息同步后才替换旧登录态。</span>
        <script>(function(){
        var f=document.getElementById("form-info"),a=document.querySelector(".yunskljxd-login"),s=document.getElementById("save");
        if(f&&a&&s){f.insertBefore(a,s)}
        ["sid","shop_id"].forEach(function(k){var e=document.querySelector("input[name=\'config["+k+"]\']");if(e){e.readOnly=true;e.setAttribute("aria-readonly","true")}});
        var m=document.querySelector("select[name=\'config[proxy_mode]\']"),p=document.querySelector("input[name=\'config[proxy]\']"),pm=document.querySelector("select[name=\'config[link_permission_mode]\']"),shop=document.querySelector("input[name=\'config[shop_id]\']");
        if(m&&p){var b=p.closest(".form-group");var sync=function(){if(b)b.style.display=m.value==="custom_proxy"?"":"none"};m.addEventListener("change",sync);sync()}
        if(pm&&shop){var sb=shop.closest(".form-group"),ss=function(){if(sb)sb.style.display=pm.value==="business"?"":"none"};pm.addEventListener("change",ss);ss()}
        }());</script>',
    ];

    static public function submit() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }
    static public function mapi() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }

    static public function qrcode()
    {
        global $order, $channel, $conf;
        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? '微信支付'));
        $title = $paymentName . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $paymentIcon = '/assets/icon/wxpay.ico'; $error_msg = ''; $code_url = ''; $wait_seconds = 0;
        $expireSeconds = self::expireSeconds($channel);
        if ((int)($order['status'] ?? 0) !== 0) $error_msg = '订单状态已改变，请返回订单页面查看结果';
        elseif (self::isExpired($order, $channel)) { self::markExpired($order); $error_msg = '订单已超时，请重新发起支付'; }
        else {
        $status = self::checkChannelStatus($channel);
            if ((int)$status['code'] !== 1) $error_msg = $status['msg'];
            elseif (!self::reserveAmount($channel, $order)) $error_msg = '金额分配失败，请稍后重试';
            else { $created = self::createCode($channel, $order); if ((int)$created['code'] === 0) $code_url = $created['qrcode']; else { $error_msg = $created['msg']; $wait_seconds = max(0, (int)($created['wait_seconds'] ?? 0)); } }
        }
        $paytime = max(0, strtotime((string)$order['addtime']) + $expireSeconds - time());
        define('IN_PLUGIN_PAGE', true); include __DIR__ . '/inc/qrcode.page.php'; exit;
    }

    static public function notify() { return ['type' => 'error', 'msg' => '本插件由 受管收款监控 轮询收款链接流水，不接收 HTTP 回调']; }

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
        $cfg = self::getConfig($channel); $endpoint = './plugin_page.php?channel=' . (int)$channel['id'] . '&func=adminLogin';
        $state = $cfg['sid'] !== '' ? '已保存登录态' : '尚未登录'; $mode = $cfg['login_mode'] === 'guanjia' ? '电脑管家' : '应用宝'; $permission = $cfg['link_permission_mode'] === 'business' ? '有经营权限' : '无经营权限';
        ?>
        <style>
        .yunskljxd-login-box{max-width:430px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}.yunskljxd-login-box h3{margin:0 0 10px}.yunskljxd-login-meta{color:#666;line-height:1.8}.yunskljxd-qr{width:260px;height:260px;margin:16px auto;border:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;justify-content:center;color:#888}.yunskljxd-qr img{max-width:240px;max-height:240px}.yunskljxd-login-state{min-height:24px;margin:8px 0;color:#1677ff;font-weight:600}.yunskljxd-login-state.ok{color:#16803c}.yunskljxd-login-state.bad{color:#c62828}.yunskljxd-msg{min-height:24px;margin:10px 0;color:#555}.yunskljxd-msg.ok{color:#16803c}.yunskljxd-msg.bad{color:#c62828}.yunskljxd-login-box button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}
        </style>
        <div class="yunskljxd-login-box"><h3>微信收款链接云端–XD扫码登录</h3><div class="yunskljxd-login-meta">通道：<?php echo htmlspecialchars((string)$channel['name'], ENT_QUOTES, 'UTF-8'); ?><br>登录方式：<?php echo $mode; ?><br>收款链接权限：<?php echo $permission; ?><br>状态：<?php echo $state; ?></div><div class="yunskljxd-qr" id="yunskljxd-qr">正在准备二维码…</div><div class="yunskljxd-login-state" id="yunskljxd-login-state">状态：准备二维码</div><div class="yunskljxd-msg" id="yunskljxd-msg"></div><button type="button" id="yunskljxd-start">重新获取二维码</button></div>
        <script>(function(){var ep=<?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES); ?>,box=document.getElementById('yunskljxd-qr'),state=document.getElementById('yunskljxd-login-state'),msg=document.getElementById('yunskljxd-msg'),btn=document.getElementById('yunskljxd-start'),timer=0;function parse(t){var d=new DOMParser().parseFromString(t,'text/html'),n=d.querySelector('#yunskljxd-response');if(!n)throw Error('后台响应格式错误');return JSON.parse(n.value||n.textContent||'{}')}function req(u){return fetch(u,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text()}).then(parse)}function say(t,c){msg.className='yunskljxd-msg '+(c||'');msg.textContent=t||''}function setState(t,c){state.className='yunskljxd-login-state '+(c||'');state.textContent='状态：'+(t||'')}function draw(v,k){box.innerHTML='';v=v||'';if(k==='image'||v.indexOf('data:image/')===0||/^https?:\/\//.test(v)&&/qrcode|qr\./i.test(v)){var i=document.createElement('img');i.src=v;i.alt='登录二维码';box.appendChild(i)}else if(window.jQuery&&jQuery.fn.qrcode){jQuery(box).qrcode({text:v,width:240,height:240,typeNumber:-1})}else{box.textContent=v}}function poll(id){req(ep+'&action=poll&session_id='+encodeURIComponent(id)).then(function(d){if(d.code===0){setState('登录成功','ok');say(d.msg||'SID 和账号信息已同步','ok');btn.disabled=false;try{if(window.opener&&!window.opener.closed)window.opener.location.reload()}catch(e){}return}if(d.code===201){setState(d.state==='scanned'?'已扫码，请在微信中确认':'等待扫码');say(d.msg||'');timer=setTimeout(function(){poll(id)},2000);return}setState('登录失败','bad');say(d.msg||'登录失败','bad');btn.disabled=false}).catch(function(){setState('连接重试中','bad');say('后台请求失败，正在重试');timer=setTimeout(function(){poll(id)},3000)})}function start(){clearTimeout(timer);btn.disabled=true;setState('等待扫码');say('');req(ep+'&action=start').then(function(d){if(d.code!==0){setState('获取二维码失败','bad');say(d.msg||'获取二维码失败','bad');btn.disabled=false;return}draw(d.qrcode,d.qrcode_type||'text');setState('等待扫码');poll(d.session_id)}).catch(function(e){setState('获取二维码失败','bad');say(e.message||'后台请求失败','bad');btn.disabled=false})}btn.onclick=start;start()}());</script>
        <?php
    }

    static private function adminResponse($data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); echo '<textarea id="yunskljxd-response" style="display:none">' . htmlspecialchars($json ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>'; exit;
    }

    static public function beginLogin($channel)
    {
        $cfg = self::getConfig($channel); if ($cfg['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址并保存']; if (!in_array($cfg['login_mode'], ['yyb', 'guanjia'], true)) return ['code' => -1, 'msg' => '请选择登录方式并保存'];
        if ($cfg['login_mode'] === 'yyb') { $r = self::requestJson('POST', $cfg['gateway'] . '/yyb/qr', [], $cfg, true, self::LINK_APP_ID); $data = self::payload($r); if ($r['code'] !== 0) return $r; return self::loginQrResult($data, (string)($data['session_id'] ?? ''), $cfg); }
        $r = self::requestJson('POST', $cfg['gateway'] . '/guanjia/api/qrcode', ['appid' => self::LINK_APP_ID], $cfg, true, self::LINK_APP_ID); $data = self::payload($r); if ($r['code'] !== 0) return $r; return self::loginQrResult($data, (string)($data['uuid'] ?? ''), $cfg);
    }

    static private function loginQrResult($data, $session, $cfg)
    {
        if ($session === '') return ['code' => -1, 'msg' => '云端未返回登录会话'];
        foreach (['image', 'image_base64', 'image_url', 'qrcode_image'] as $key) if (!empty($data[$key])) { $value = (string)$data[$key]; if (strpos($value, '/') === 0) $value = rtrim($cfg['gateway'], '/') . '/' . ltrim($value, '/'); return ['code' => 0, 'session_id' => $session, 'qrcode' => self::normalizeQr($value), 'qrcode_type' => 'image']; }
        $qrcode = self::first($data, ['qrcode', 'qr', 'qr_code', 'url', 'login_url']); if ($qrcode === '') return ['code' => -1, 'msg' => '云端未返回登录二维码']; $isImage = self::isImageValue($qrcode) || (bool)preg_match('/\.(?:png|jpe?g|gif|webp)(?:\?|$)/i', $qrcode); return ['code' => 0, 'session_id' => $session, 'qrcode' => self::normalizeQr($qrcode), 'qrcode_type' => $isImage ? 'image' : 'text'];
    }

    static public function pollLogin($channel, $session)
    {
        $cfg = self::getConfig($channel); if ($session === '') return ['code' => -1, 'msg' => '登录会话为空']; $ref = ''; $code = '';
        if ($cfg['login_mode'] === 'yyb') { $r = self::requestJson('GET', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/poll', null, $cfg, true, self::LINK_APP_ID); $data = self::payload($r); if ($r['code'] !== 0) return $r; $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? ''))); if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return self::loginPending($status, '应用宝'); $confirmed = self::requestJson('POST', $cfg['gateway'] . '/yyb/qr/' . rawurlencode($session) . '/confirm', [], $cfg, true, self::LINK_APP_ID); $account = self::payload($confirmed); if ($confirmed['code'] !== 0) return $confirmed; $ref = self::first($account, ['ref', 'openid', 'uin', 'id']); }
        else { $r = self::requestJson('GET', $cfg['gateway'] . '/guanjia/api/status?uuid=' . rawurlencode($session), null, $cfg, true, self::LINK_APP_ID); $data = self::payload($r); if ($r['code'] !== 0) return $r; $status = strtolower(trim((string)($data['status'] ?? $data['state'] ?? ''))); if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return self::loginPending($status, '电脑管家'); $account = is_array($data['result'] ?? null) ? $data['result'] : $data; $ref = self::first($account, ['ref', 'bind_account', 'openid', 'uin', 'id']); $code = self::first($account, ['code', 'js_code', 'jscode']); }
        if ($ref === '') return ['code' => -1, 'msg' => '登录成功但云端未返回账号标识']; if ($code === '') { $cloudCode = self::getCloudCode($cfg, $ref); if ($cloudCode['code'] !== 0) return $cloudCode; $code = $cloudCode['value']; }
        $link = self::linkLogin($code, $cfg); if ($link['code'] !== 0) return ['code' => -1, 'msg' => '收款链接登录失败：' . $link['msg']]; $sid = self::extract($link['data'], ['sid', 'Sid', 'receipt_sid', 'receiptSid']); if ($sid === '') return ['code' => -1, 'msg' => '登录成功但未返回收款链接 SID'];
        $updates = ['sid' => $sid, 'cloud_ref' => $ref, 'sid_login_mode' => $cfg['login_mode'], 'sid_permission_mode' => $cfg['link_permission_mode']];
        if ($cfg['link_permission_mode'] === 'business') { $shop = self::getShopInfo($sid, $cfg); if ($shop['code'] !== 0) return $shop; $updates['shop_id'] = (string)$shop['shop_id']; } else $updates['shop_id'] = '';
        if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '登录成功，但写入通道配置失败']; return ['code' => 0, 'msg' => '登录成功，SID 和收款账号信息已同步'];
    }

    static private function loginPending($status, $mode)
    {
        $status = strtolower(trim((string)$status)); if (in_array($status, ['scanned', 'scaned', 'confirmed', 'confirming', 'ready_confirm'], true)) return ['code' => 201, 'state' => 'scanned', 'msg' => '已扫码，请在微信中确认']; if (in_array($status, ['expired', 'timeout', 'cancelled', 'canceled', 'failed', 'rejected'], true)) return ['code' => -1, 'state' => 'failed', 'msg' => $mode . '二维码已失效，请重新获取']; return ['code' => 201, 'state' => 'waiting', 'msg' => '等待扫码'];
    }

    static private function getCloudCode($cfg, $ref)
    {
        $path = $cfg['login_mode'] === 'yyb' ? '/yyb/wxapp/getCode' : '/guanjia/api/get-code'; $body = $cfg['login_mode'] === 'yyb' ? ['ref' => $ref, 'app_id' => self::LINK_APP_ID] : ['ref' => $ref, 'appid' => self::LINK_APP_ID]; $r = self::requestJson('POST', $cfg['gateway'] . $path, $body, $cfg, true, self::LINK_APP_ID); if ($r['code'] !== 0) return $r; $value = self::extract(self::payload($r), ['code', 'js_code', 'jscode', 'Code']); return $value === '' ? ['code' => -1, 'msg' => '云端未返回小程序登录 code'] : ['code' => 0, 'value' => $value];
    }

    static private function linkLogin($code, $cfg)
    {
        $url = self::LINK_HOST . '/linkqrcodesource/getshopinfo?jscode=' . rawurlencode($code) . '&v=' . self::API_VERSION; $body = ['v' => self::API_VERSION, 'account_type' => 'E_ACCOUNT_TYPE_F2F', 'operator_role' => self::OPERATOR_MANAGER, 'merchant_identifier' => '', 'jscode' => $code]; return self::requestJson('POST', $url, $body, $cfg, false, self::LINK_APP_ID);
    }

    static private function getShopInfo($sid, $cfg)
    {
        $url = 'https://payapp.weixin.qq.com/sjtaccount/getopenshoplist?sid=' . rawurlencode($sid) . '&v=' . self::SHOP_VERSION; $r = self::requestJson('GET', $url, null, $cfg, false, self::LINK_APP_ID); if ($r['code'] !== 0) return ['code' => -1, 'msg' => '获取经营店铺失败：' . $r['msg']]; $data = self::payload($r); $shopId = (int)self::first($data, ['default_shop_id', 'shop_id', 'shopId']); foreach (self::arrayList($data, ['shop_info_list', 'shop_list', 'list']) as $row) { if (!is_array($row)) continue; if ($shopId <= 0) $shopId = (int)self::first($row, ['shop_id', 'shopId', 'id']); if ($shopId > 0) break; } return $shopId > 0 ? ['code' => 0, 'shop_id' => $shopId] : ['code' => -1, 'msg' => '登录成功但未获取到经营店铺 ID'];
    }

    static public function checkChannelStatus($channel)
    {
        $cfg = self::getConfig($channel); if ($cfg['gateway'] === '' || $cfg['cloud_ref'] === '' || $cfg['sid'] === '') return ['code' => 0, 'msg' => '尚未扫码登录云端，已禁止发起订单']; if (self::sidModeMismatch($cfg)) return ['code' => 0, 'msg' => '登录方式或收款链接权限已变更，请重新扫码登录']; $path = $cfg['login_mode'] === 'guanjia' ? '/guanjia/api/accounts' : '/yyb/accounts'; $r = self::requestJson('GET', $cfg['gateway'] . $path . '?ref=' . rawurlencode($cfg['cloud_ref']), null, $cfg, true, self::LINK_APP_ID); if ($r['code'] !== 0) return ['code' => -1, 'msg' => '云端状态检查失败，已禁止发起订单：' . $r['msg']]; $rows = self::payload($r); if (isset($rows['accounts']) && is_array($rows['accounts'])) $rows = $rows['accounts']; if (isset($rows['list']) && is_array($rows['list'])) $rows = $rows['list']; if (!is_array($rows)) return ['code' => -1, 'msg' => '云端状态响应异常，已禁止发起订单'];
        foreach ($rows as $row) { if (!is_array($row)) continue; $refs = array_map('strval', [$row['ref'] ?? '', $row['bind_account'] ?? '', $row['openid'] ?? '', $row['uin'] ?? '', $row['id'] ?? '']); if (!in_array($cfg['cloud_ref'], $refs, true)) continue; $status = strtolower(trim((string)($row['status'] ?? $row['state'] ?? ''))); if (in_array($status, ['online', 'alive', 'active', '1', 'ok'], true)) return ['code' => 1, 'msg' => '云端在线']; if (in_array($status, ['offline', 'dead', 'logout', 'logged_out', 'expired', '0'], true)) return ['code' => 0, 'msg' => '云端明确返回离线，请重新扫码登录']; return ['code' => -1, 'msg' => '云端未返回可识别的在线状态，已禁止发起订单']; }
        return ['code' => 0, 'msg' => '云端未找到当前登录账号，请重新扫码登录'];
    }

    static public function expireSeconds($channel)
    {
        $cfg = self::getConfig($channel);
        return $cfg['link_permission_mode'] === 'business' ? self::BUSINESS_EXPIRE_SECONDS : self::F2F_EXPIRE_SECONDS;
    }

    static public function flowGraceSeconds($channel)
    {
        $cfg = self::getConfig($channel);
        return $cfg['link_permission_mode'] === 'business' ? self::BUSINESS_FLOW_GRACE_SECONDS : self::F2F_FLOW_GRACE_SECONDS;
    }

    static private function sidInvalidText($value)
    {
        $text = strtolower(trim((string)$value));
        foreach (['用户鉴权错误', '鉴权失败', '登录失败', '尚未登录', '未登录', '登录态失效', '登录态无效', '会话失效', '会话不存在', 'session invalid', 'login failed', 'not logged in', 'invalid sid'] as $needle) {
            if (strpos($text, strtolower($needle)) !== false) return true;
        }
        return false;
    }

    static private function refreshSid($channel, $cfg)
    {
        if ($cfg['cloud_ref'] === '') return ['code' => -1, 'msg' => '缺少云端账号标识，无法刷新 SID']; $code = self::getCloudCode($cfg, $cfg['cloud_ref']); if ($code['code'] !== 0) return ['code' => -1, 'msg' => '获取登录 code 失败：' . $code['msg']]; $login = self::linkLogin($code['value'], $cfg); if ($login['code'] !== 0) return ['code' => -1, 'msg' => '刷新 SID 失败：' . $login['msg']]; $sid = self::extract($login['data'], ['sid', 'Sid', 'receipt_sid', 'receiptSid']); if ($sid === '') return ['code' => -1, 'msg' => '刷新 SID 失败：接口未返回 SID']; if (!self::saveConfig((int)$channel['id'], ['sid' => $sid])) return ['code' => -1, 'msg' => '刷新 SID 成功但写入配置失败']; return ['code' => 0, 'sid' => $sid];
    }

    static public function createCode($channel, &$order)
    {
        $cfg = self::getConfig($channel); if ($cfg['sid'] === '') return ['code' => -1, 'msg' => '尚未扫码登录收款链接云端']; if ($cfg['link_permission_mode'] === 'business' && $cfg['shop_id'] === '') return ['code' => -1, 'msg' => '经营权限缺少店铺 ID，请重新扫码登录']; $tail = self::validOrderTail((string)$order['trade_no']); if ($tail === '') return ['code' => -1, 'msg' => '系统订单号不符合收款链接备注规则'];
        if ($cfg['link_permission_mode'] !== 'business') {
            $reuse = self::prepareF2FLink($channel, $order, $tail);
            if ((int)($reuse['code'] ?? -1) === 0) return $reuse;
            if (!empty($reuse['blocked'])) {
                self::markWaiting($order, (int)($reuse['wait_seconds'] ?? 0));
                return $reuse;
            }
            if (empty($reuse['checked'])) return ['code' => -1, 'msg' => $reuse['msg'] ?? '检查现有收款链接失败，未创建重复链接'];
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) { $result = self::createLink($cfg, $order, $tail); if ($result['code'] === 0) return $result; if ($attempt === 1 && self::sidInvalidText($result['msg'])) { $fresh = self::refreshSid($channel, $cfg); if ($fresh['code'] === 0) { $cfg['sid'] = $fresh['sid']; continue; } } return $result; }
        return ['code' => -1, 'msg' => '创建收款链接失败'];
    }

    /** 无经营权限只能保留当前订单的一个有效链接，其他链接先暂停再删除。 */
    static private function prepareF2FLink($channel, $order, $tail)
    {
        $runtimeChannel = self::reloadChannel($channel);
        $cfg = self::getConfig($runtimeChannel);
        $flows = self::getFlows($runtimeChannel);
        if ((int)($flows['code'] ?? -1) !== 0) return ['code' => -1, 'checked' => false, 'msg' => $flows['msg'] ?? '读取现有收款链接失败'];
        $ext = self::readExt($order);
        $savedReceiptId = trim((string)($ext['yunskljxd_receipt_id'] ?? ''));
        $expireSeconds = self::expireSeconds($runtimeChannel);
        $pendingRows = self::activePendingOrders((int)($runtimeChannel['id'] ?? 0), (int)($runtimeChannel['subid'] ?? 0), (string)$order['trade_no'], $expireSeconds);
        $reusable = null;
        $cleanupErrors = [];
        // 无经营权限同一时间只允许一个本地有效未支付订单；即使云端列表暂时漏项，也不能让新单抢占。
        if ($pendingRows) {
            $pendingRow = $pendingRows[0];
            $createdAtLocal = !empty($pendingRow['addtime']) ? strtotime((string)$pendingRow['addtime']) : 0;
            $wait = $createdAtLocal > 0 ? max(1, $createdAtLocal + $expireSeconds - time()) : $expireSeconds;
            return ['code' => -1, 'checked' => true, 'blocked' => true, 'wait_seconds' => $wait, 'msg' => '当前已有未支付订单，请等待当前订单结束后再发起'];
        }
        foreach ((array)($flows['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $receiptId = trim((string)($item['receipt_id'] ?? $item['id'] ?? ''));
            if ($receiptId === '') continue;
            // 已支付链接先暂停保留 15 分钟；到期后在新单拉起前再次暂停并删除。
            if (!empty($item['paid'])) {
                $paidAt = (int)($item['time'] ?? 0);
                $deleteAfter = $paidAt > 0 ? $paidAt + self::PAID_LINK_RETENTION_SECONDS : 0;
                if ($deleteAfter > 0 && time() >= $deleteAfter && self::canRetryDelete($receiptId)) {
                    $deleted = self::deleteReceiptAfterPause($cfg, $receiptId);
                    if ((int)($deleted['code'] ?? -1) !== 0) {
                        self::$deleteRetryAt[$receiptId] = time() + self::CANCEL_RETRY_SECONDS;
                        $cleanupErrors[] = $receiptId . ': ' . ($deleted['msg'] ?? '删除收款链接失败');
                    }
                } else {
                    $paused = self::pauseLink($cfg, $receiptId);
                    if ((int)($paused['code'] ?? -1) !== 0) $cleanupErrors[] = $receiptId . ': ' . ($paused['msg'] ?? '暂停收款链接失败');
                }
                continue;
            }
            $itemTail = trim((string)($item['order_no'] ?? ''));
            $sameOrder = ($savedReceiptId !== '' && $savedReceiptId === $receiptId) || ($itemTail !== '' && $itemTail === $tail);
            $createdAt = (int)($item['created_at'] ?? 0);
            $valid = !$item['paid'] && ($createdAt <= 0 || time() < $createdAt + $expireSeconds);
            if ($sameOrder && $valid && trim((string)($item['qrcode'] ?? '')) !== '') {
                $reusable = ['code' => 0, 'qrcode' => self::normalizeQr($item['qrcode']), 'receipt_id' => $receiptId];
                if ($savedReceiptId === '') self::saveLinkMeta($order, $receiptId, $tail);
                continue;
            }
            if ($sameOrder && $valid) {
                return ['code' => -1, 'checked' => true, 'msg' => '云端已有当前订单收款链接，但未返回二维码地址'];
            }
            $released = self::releaseLink($cfg, $receiptId);
            if ((int)($released['code'] ?? -1) !== 0) $cleanupErrors[] = $receiptId . ': ' . ($released['msg'] ?? '停止或删除失败');
        }
        if ($cleanupErrors) return ['code' => -1, 'checked' => true, 'msg' => '清理旧收款链接失败：' . implode('; ', $cleanupErrors)];
        return $reusable ?: ['code' => -1, 'checked' => true, 'msg' => '没有可复用的有效收款链接'];
    }

    static private function activePendingOrders($channelId, $subchannelId, $excludeTradeNo, $expireSeconds)
    {
        global $DB;
        if ($channelId <= 0) return [];
        $rows = $DB->getAll("SELECT trade_no,addtime,ext FROM pre_order WHERE channel='" . (int)$channelId . "' AND subchannel='" . (int)$subchannelId . "' AND status=0 AND (ext IS NULL OR ext NOT LIKE '%yunskljxd_waiting%') AND trade_no<>:trade_no AND addtime>=DATE_SUB(NOW(),INTERVAL " . (int)$expireSeconds . " SECOND)", [':trade_no' => (string)$excludeTradeNo]);
        $active = [];
        foreach ($rows as $row) if (!self::isExpired($row, $expireSeconds)) $active[] = $row;
        return $active;
    }

    static private function createLink($cfg, &$order, $tail)
    {
        $amount = (int)round((float)$order['realmoney'] * 100); if ($amount <= 0) return ['code' => -1, 'msg' => '收款金额无效']; $body = self::createPayload($cfg, $amount, $tail); $url = self::LINK_HOST . '/linkqrcode/create?sid=' . rawurlencode($cfg['sid']) . '&v=' . self::API_VERSION; $r = self::requestJson('POST', $url, $body, $cfg, false, self::LINK_APP_ID); if ($r['code'] !== 0) return ['code' => -1, 'msg' => '创建收款链接失败：' . $r['msg']]; $data = self::payload($r); $receiptId = self::extract($data, ['receipt_id', 'receiptId', 'id']); $qr = self::extract($data, ['qr_code_url', 'qrcode_url', 'pay_url', 'link_url', 'url']); if ($receiptId === '' || $qr === '') return ['code' => -1, 'msg' => '创建接口未返回完整收款链接']; if (!self::saveLinkMeta($order, $receiptId, $tail)) { self::releaseLink($cfg, $receiptId); return ['code' => -1, 'msg' => '收款链接已创建，但本地订单保存失败']; } return ['code' => 0, 'qrcode' => self::normalizeQr($qr), 'receipt_id' => $receiptId];
    }

    static private function createPayload($cfg, $amount, $tail)
    {
        if ($cfg['link_permission_mode'] === 'business') return ['v' => self::API_VERSION, 'shop_id' => (int)$cfg['shop_id'], 'link_qr_code' => ['remark' => $tail, 'payfee_type' => 'E_LINK_QRCODE_PAY_FEE_TYPE_FIX', 'image_description' => ['image_url_list' => []]], 'receipt_item' => [['desc' => $tail, 'price' => $amount, 'quota_type' => 'E_LINK_QRCODE_QUOTATYPE_NO_LIMIT']], 'option_type' => [], 'sid' => $cfg['sid']];
        return ['v' => self::API_VERSION, 'account_type' => 'E_ACCOUNT_TYPE_F2F', 'operator_role' => self::OPERATOR_MANAGER, 'merchant_identifier' => '', 'link_qr_code' => ['create_source' => 'E_LINK_QRCODE_CREATE_SOURCE_MANAGEMENT', 'payfee_type' => 'E_LINK_QRCODE_PAY_FEE_TYPE_FIX', 'receipt_type' => 'E_LINK_QRCODE_TYPE_CAN_SET_CHANGEABLE_PAYFEE_RECEIPT_ITEM', 'image_description' => ['image_url_list' => []]], 'receipt_item' => [['desc' => $tail, 'price' => $amount, 'payfee_type' => 'E_RECEIPT_ITEM_PAY_FEE_TYPE_FIX', 'quota_type' => 'E_LINK_QRCODE_QUOTATYPE_NO_LIMIT', 'friend_price' => 0, 'friendprice_purchase_limit' => 0]], 'option_type' => [], 'sid' => $cfg['sid']];
    }

    static private function listQuery($cfg, $state = 'E_LINK_QRCODE_STATE_CREATED')
    {
        // 微信接口的 page_size 上限为 10，超过上限会返回 errcode=22。
        $query = ['v' => self::API_VERSION, 'state' => $state, 'start_time' => 0, 'page_size' => 10, 'need_count' => 'true', 'check_created' => 'true', 'enable_update_time_sort' => 'true', 'sid' => $cfg['sid']]; if ($cfg['link_permission_mode'] === 'business') { $query['account_type'] = 'E_ACCOUNT_TYPE_SJT'; $query['operator_role'] = self::OPERATOR_MANAGER; $query['shop_id'] = (int)$cfg['shop_id']; } else { $query['account_type'] = 'E_ACCOUNT_TYPE_F2F'; $query['operator_role'] = self::OPERATOR_MANAGER; } return $query;
    }

    static public function getFlows($channel)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['sid'] === '') return ['code' => -1, 'msg' => '登录态配置不完整', 'items' => []];

        // 已付款后微信会把链接状态从 CREATED 改为 CLOSED；只查 CREATED 会稳定漏单。
        $states = ['E_LINK_QRCODE_STATE_CREATED', 'E_LINK_QRCODE_STATE_CLOSED'];
        $responses = [];
        foreach ($states as $state) {
            $url = self::LINK_HOST . '/linkqrcode/list?' . http_build_query(self::listQuery($cfg, $state));
            $r = self::requestJson('GET', $url, null, $cfg, false, self::LINK_APP_ID);
            if ($r['code'] !== 0 && self::sidInvalidText($r['msg'])) {
                $fresh = self::refreshSid($channel, $cfg);
                if ($fresh['code'] === 0) {
                    $cfg['sid'] = $fresh['sid'];
                    $url = self::LINK_HOST . '/linkqrcode/list?' . http_build_query(self::listQuery($cfg, $state));
                    $r = self::requestJson('GET', $url, null, $cfg, false, self::LINK_APP_ID);
                }
            }
            if ($r['code'] !== 0) return ['code' => -1, 'msg' => $state . ' 流水查询失败：' . $r['msg'], 'items' => []];
            $responses[] = self::payload($r);
        }

        $items = [];
        $seen = [];
        foreach ($responses as $data) {
            $rows = is_array($data['code_biz_info_list'] ?? null) ? $data['code_biz_info_list'] : (is_array($data['list'] ?? null) ? $data['list'] : []);
            // 单条查询返回 code_biz_info，而列表查询返回 code_biz_info_list，两种结构统一处理。
            if (!$rows && is_array($data['code_biz_info'] ?? null)) {
                $single = $data['code_biz_info'];
                $rows = isset($single['link_qrcode_info']) ? [$single] : $single;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                // 部分接口会在列表元素外再包一层 code_biz_info。
                if (is_array($row['code_biz_info'] ?? null) && !isset($row['link_qrcode_info'])) {
                    $row = $row['code_biz_info'];
                }
                $info = is_array($row['link_qrcode_info'] ?? null) ? $row['link_qrcode_info'] : [];
                $trade = is_array($row['qrcode_trade_info'] ?? null) ? $row['qrcode_trade_info'] : [];
                $orderBiz = is_array($row['order_biz_info'] ?? null) ? $row['order_biz_info'] : [];
                $orderInfo = is_array($orderBiz['link_qrcode_order_info'] ?? null) ? $orderBiz['link_qrcode_order_info'] : [];
                $receiptId = self::extract($info, ['receipt_id', 'receiptId', 'id']);
                if ($receiptId === '') $receiptId = self::extract($row, ['receipt_id', 'receiptId', 'id']);
                if ($receiptId === '') continue;
                $count = (int)($trade['total_trade_num'] ?? $trade['trade_num'] ?? 0);
                $payState = strtolower(trim((string)($orderInfo['pay_state'] ?? '')));
                $paid = $count > 0 || in_array($payState, ['e_order_pay_state_pay_success', 'pay_success', 'success', 'paid', '1'], true);
                $amount = self::amountFen($trade['total_trade_fee'] ?? $trade['trade_fee'] ?? $orderInfo['order_amount'] ?? $orderInfo['total_fee'] ?? $info['total_fee'] ?? $info['fee'] ?? 0);
                // CLOSED 已支付链接常把实际金额放在 receipt_item_info[].price。
                if ($amount <= 0) $amount = self::amountFen(self::extract($orderInfo, ['order_amount', 'total_fee', 'amount']));
                if ($amount <= 0) $amount = self::amountFen(self::extract($row, ['price', 'amount', 'total_fee', 'fee']));
                $remark = self::extract($orderInfo, ['receipt_remark', 'payee_remark', 'payer_remark']);
                if ($remark === '') $remark = self::extract($info, ['desc', 'description', 'remark', 'receipt_item_desc', 'name', 'title']);
                if ($remark === '') $remark = self::extract($row, ['desc', 'description', 'remark', 'receipt_item_desc', 'name', 'title']);
                $orderNo = self::extractOrderReference($orderInfo);
                if ($orderNo === '') $orderNo = self::extractOrderReference($info);
                if ($orderNo === '') $orderNo = self::extractOrderTail($row);
                $qr = self::extract($info, ['qr_code_url', 'qrcode_url', 'pay_url', 'link_url', 'url']);
                if ($qr === '') $qr = self::extract($row, ['qr_code_url', 'qrcode_url', 'pay_url', 'link_url', 'url']);
                $paidTime = self::timestamp($trade['pay_time'] ?? $trade['pay_succ_time'] ?? $orderInfo['pay_succ_time'] ?? $trade['update_time'] ?? $info['update_time'] ?? $row['update_time'] ?? 0);
                $createdAt = self::timestamp($info['create_time'] ?? $orderInfo['create_time'] ?? $row['create_time'] ?? 0);
                $buyer = self::extract($trade, ['payer_nick_name', 'payer_user_name', 'buyer_name', 'buyer']);
                if ($buyer === '') $buyer = self::extract($orderBiz['payer_info'] ?? [], ['payer_nickname', 'nickname', 'name']);
                // receipt_id 绑定本地收款链接；transaction_id 才是本次真实支付流水。
                // 两者分开保存，避免把收款链接 ID 当成支付流水号或订单备注。
                $transactionId = self::extract($orderInfo, ['transaction_id', 'transactionId', 'trans_id']);
                if ($transactionId === '') $transactionId = self::extract($trade, ['transaction_id', 'transactionId', 'trans_id']);
                $tradeId = self::extract($orderInfo, ['trade_no', 'tradeNo']);
                if ($tradeId === '') $tradeId = self::extract($trade, ['trade_no', 'tradeNo']);
                $flowId = $transactionId !== '' ? $transactionId : ($tradeId !== '' ? $tradeId : $receiptId);
                $parsed = ['id' => $flowId, 'receipt_id' => $receiptId, 'trade_no' => $tradeId, 'transaction_id' => $transactionId, 'paid' => $paid, 'amount' => $amount, 'remark' => $remark, 'order_no' => $orderNo, 'qrcode' => $qr, 'buyer' => $buyer, 'time' => $paidTime, 'created_at' => $createdAt];
                // 同一链接可能同时出现在 CREATED/CLOSED，优先保留已支付且字段更完整的记录。
                if (isset($seen[$receiptId])) {
                    $oldIndex = $seen[$receiptId];
                    if ((!$items[$oldIndex]['paid'] && $parsed['paid']) || ($items[$oldIndex]['amount'] <= 0 && $parsed['amount'] > 0)) $items[$oldIndex] = $parsed;
                    continue;
                }
                $seen[$receiptId] = count($items);
                $items[] = $parsed;
            }
        }
        return ['code' => 0, 'msg' => '', 'items' => $items];
    }

    static public function flowValues($row)
    {
        return is_array($row) ? [
            'id' => (string)($row['id'] ?? ''),
            'receipt_id' => (string)($row['receipt_id'] ?? ''),
            'trade_no' => (string)($row['trade_no'] ?? ''),
            'transaction_id' => (string)($row['transaction_id'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'remark' => (string)($row['remark'] ?? ''),
            'order_no' => (string)($row['order_no'] ?? ''),
            'buyer' => (string)($row['buyer'] ?? ''),
            'time' => (int)($row['time'] ?? 0),
            'created_at' => (int)($row['created_at'] ?? 0),
            'paid' => !empty($row['paid'])
        ] : ['id' => '', 'receipt_id' => '', 'trade_no' => '', 'transaction_id' => '', 'amount' => 0, 'remark' => '', 'order_no' => '', 'buyer' => '', 'time' => 0, 'created_at' => 0, 'paid' => false];
    }

    static private function manageLink($cfg, $receiptId, $action)
    {
        $body = ['v' => self::API_VERSION, 'receipt_id' => ctype_digit((string)$receiptId) ? (int)$receiptId : (string)$receiptId, 'sid' => $cfg['sid']]; if ($cfg['link_permission_mode'] === 'business') { $body['account_type'] = 'E_ACCOUNT_TYPE_SJT'; $body['operator_role'] = self::OPERATOR_MANAGER; $body['shop_id'] = (int)$cfg['shop_id']; } else { $body['account_type'] = 'E_ACCOUNT_TYPE_F2F'; $body['operator_role'] = self::OPERATOR_MANAGER; $body['merchant_identifier'] = ''; } $url = self::LINK_HOST . '/linkqrcode/' . $action . '?sid=' . rawurlencode($cfg['sid']) . '&v=' . self::API_VERSION; return self::requestJson('POST', $url, $body, $cfg, false, self::LINK_APP_ID);
    }

    static private function releaseLink($cfg, $receiptId)
    {
        $paused = self::manageLink($cfg, $receiptId, 'pause'); if ($paused['code'] !== 0) return ['code' => -1, 'msg' => '暂停收款链接失败：' . $paused['msg']]; $deleted = self::manageLink($cfg, $receiptId, 'delete'); return $deleted['code'] === 0 ? ['code' => 0, 'msg' => '收款链接已删除'] : ['code' => -1, 'msg' => '删除收款链接失败：' . $deleted['msg']];
    }

    /** 已支付链接只暂停，保留云端记录给后续轮询重试，不能删除。 */
    static private function pauseLink($cfg, $receiptId)
    {
        $paused = self::manageLink($cfg, $receiptId, 'pause');
        $message = (string)($paused['msg'] ?? '');
        if ($paused['code'] !== 0 && (strpos($message, '已停止收款') !== false || strpos($message, '请勿重复操作') !== false)) {
            return ['code' => 0, 'msg' => '收款链接已暂停保留'];
        }
        return $paused['code'] === 0
            ? ['code' => 0, 'msg' => '收款链接已暂停并保留']
            : ['code' => -1, 'msg' => '暂停收款链接失败：' . $paused['msg']];
    }

    static public function releaseReceipt($channel, $receiptId)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['sid'] === '' || trim((string)$receiptId) === '') return ['code' => -1, 'msg' => '释放收款链接缺少登录态或链接编号'];
        return self::releaseLink($cfg, (string)$receiptId);
    }

    /** 支付成功后暂停订单对应链接，并记录 15 分钟后的删除时间。 */
    static public function releaseOrderReceipt($channel, &$order, $paidAt = 0)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 1) return ['code' => 0, 'skipped' => true, 'msg' => '订单未支付完成'];
        $ext = self::readExt($order);
        $receiptId = trim((string)($ext['yunskljxd_receipt_id'] ?? ''));
        if ($receiptId === '' || !empty($ext['yunskljxd_receipt_deleted']) || !empty($ext['yunskljxd_receipt_released'])) return ['code' => 0, 'skipped' => true, 'msg' => '收款链接已删除'];
        $paidAt = (int)$paidAt > 0 ? (int)$paidAt : time();
        $changed = false;
        if (empty($ext['yunskljxd_receipt_paid_at'])) {
            $ext['yunskljxd_receipt_paid_at'] = date('Y-m-d H:i:s', $paidAt);
            $changed = true;
        }
        if (empty($ext['yunskljxd_receipt_delete_after'])) {
            $ext['yunskljxd_receipt_delete_after'] = $paidAt + self::PAID_LINK_RETENTION_SECONDS;
            $changed = true;
        }
        if ((int)($ext['yunskljxd_receipt_paid_at_ts'] ?? 0) !== $paidAt) {
            $ext['yunskljxd_receipt_paid_at_ts'] = $paidAt;
            $changed = true;
        }
        $normalizedDeleteAfter = (int)$ext['yunskljxd_receipt_delete_after'];
        if ($ext['yunskljxd_receipt_delete_after'] !== $normalizedDeleteAfter) $changed = true;
        $ext['yunskljxd_receipt_delete_after'] = $normalizedDeleteAfter;
        if ($changed) {
            $serialized = serialize($ext);
            $DB->update('order', ['ext' => $serialized], ['trade_no' => $order['trade_no']]);
            $order['ext'] = $serialized;
        }
        if (!empty($ext['yunskljxd_receipt_paused'])) {
            return ['code' => 0, 'skipped' => true, 'msg' => '收款链接已暂停，等待到期删除'];
        }
        $cfg = self::getConfig($channel);
        if ($cfg['sid'] === '') return ['code' => -1, 'msg' => '暂停收款链接缺少登录态'];
        $result = self::pauseLink($cfg, $receiptId);
        if ((int)($result['code'] ?? -1) === 0) {
            $ext['yunskljxd_receipt_paused'] = 1;
            $ext['yunskljxd_receipt_paused_at'] = date('Y-m-d H:i:s');
            unset($ext['yunskljxd_receipt_pause_error'], $ext['yunskljxd_receipt_release_error']);
            $serialized = serialize($ext);
            $DB->update('order', ['ext' => $serialized], ['trade_no' => $order['trade_no']]);
            $order['ext'] = $serialized;
        } else {
            $ext['yunskljxd_receipt_pause_error'] = (string)($result['msg'] ?? '暂停收款链接失败');
            $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]);
        }
        return $result;
    }

    /** 到期删除前再次暂停，已暂停/已删除均按幂等成功处理。 */
    static private function deleteReceiptAfterPause($cfg, $receiptId)
    {
        $paused = self::pauseLink($cfg, $receiptId);
        if ((int)($paused['code'] ?? -1) !== 0) return ['code' => -1, 'msg' => $paused['msg'] ?? '暂停收款链接失败'];
        $deleted = self::manageLink($cfg, $receiptId, 'delete');
        if ((int)($deleted['code'] ?? -1) === 0) return ['code' => 0, 'msg' => '收款链接已暂停并删除'];
        $message = strtolower((string)($deleted['msg'] ?? ''));
        foreach (['不存在', '已删除', 'not found', 'already deleted'] as $needle) {
            if (strpos($message, strtolower($needle)) !== false) return ['code' => 0, 'msg' => '收款链接已删除'];
        }
        return ['code' => -1, 'msg' => '删除收款链接失败：' . ($deleted['msg'] ?? '未知错误')];
    }

    /**
     * 清理云端不再允许存在的收款链接。
     *
     * 只保留本系统当前权限有效期内仍为未支付的订单链接；已支付链接先暂停，
     * 支付满 15 分钟后再次暂停确认并删除，删除失败按 30 秒间隔重试。
     * 无经营权限只能存在一个链接，因此这套规则也会清掉所有孤立旧链接。
     */
    static public function cleanupRemoteLinks($channel, $channelId = 0, $subchannelId = 0, $flowResult = null)
    {
        $runtimeChannel = self::reloadChannel($channel);
        $cfg = self::getConfig($runtimeChannel);
        if ($cfg['sid'] === '') return ['code' => -1, 'msg' => '登录态配置不完整', 'paused' => 0, 'released' => 0, 'failed' => 0];

        $flows = $flowResult;
        if (!is_array($flows)) $flows = self::getFlows($runtimeChannel);
        if ((int)($flows['code'] ?? -1) !== 0) return ['code' => -1, 'msg' => (string)($flows['msg'] ?? '读取云端收款链接失败'), 'paused' => 0, 'released' => 0, 'failed' => 0];

        $expireSeconds = self::expireSeconds($runtimeChannel);
        $active = self::activePendingIndex((int)$channelId, (int)$subchannelId, $expireSeconds);
        $paused = 0;
        $released = 0;
        $failed = 0;
        $errors = [];
        foreach ((array)($flows['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $receiptId = trim((string)($item['receipt_id'] ?? $item['id'] ?? ''));
            if ($receiptId === '') continue;
            if (isset($active['paid'][$receiptId])) {
                $meta = $active['paid_meta'][$receiptId] ?? [];
                $tradeNo = (string)($meta['trade_no'] ?? ($active['paid_orders'][$receiptId] ?? ''));
                $deleteAfter = (int)($meta['delete_after'] ?? 0);
                if (!empty($active['released'][$receiptId]) || !empty($active['deleted'][$receiptId])) continue;
                $retryAt = (int)($meta['retry_at'] ?? 0);
                if ($deleteAfter > 0 && time() >= $deleteAfter && self::canRetryDelete($receiptId, $retryAt)) {
                    $deleted = self::deleteReceiptAfterPause($cfg, $receiptId);
                    if ((int)($deleted['code'] ?? -1) === 0) {
                        $released++;
                        unset(self::$deleteRetryAt[$receiptId]);
                        if ($tradeNo !== '') self::markReceiptDeleted($tradeNo);
                    } else {
                        $failed++;
                        self::$deleteRetryAt[$receiptId] = time() + self::CANCEL_RETRY_SECONDS;
                        if ($tradeNo !== '') self::markReceiptDeleteError($tradeNo, (string)($deleted['msg'] ?? '删除收款链接失败'));
                        $errors[] = $receiptId . ': ' . (string)($deleted['msg'] ?? '删除收款链接失败');
                    }
                    continue;
                }
                if (!empty($active['paused'][$receiptId])) continue;
                $pausedResult = self::pauseLink($cfg, $receiptId);
                if ((int)($pausedResult['code'] ?? -1) === 0) {
                    $paused++;
                    if ($tradeNo !== '') self::markReceiptPaused($tradeNo, (int)($meta['paid_at'] ?? 0), $deleteAfter);
                } else {
                    $failed++;
                    $errors[] = $receiptId . ': ' . (string)($pausedResult['msg'] ?? '暂停收款链接失败');
                }
                continue;
            }
            // 云端已显示有交易但本地暂时没有匹配订单：有支付时间时同样保留 15 分钟，
            // 没有可信支付时间只能暂停，避免网络延迟时误删流水。
            if (!empty($item['paid'])) {
                $paidAt = (int)($item['time'] ?? 0);
                $deleteAfter = $paidAt > 0 ? $paidAt + self::PAID_LINK_RETENTION_SECONDS : 0;
                if ($deleteAfter > 0 && time() >= $deleteAfter && self::canRetryDelete($receiptId)) {
                    $deleted = self::deleteReceiptAfterPause($cfg, $receiptId);
                    if ((int)($deleted['code'] ?? -1) === 0) {
                        $released++;
                        unset(self::$deleteRetryAt[$receiptId]);
                    } else {
                        $failed++;
                        self::$deleteRetryAt[$receiptId] = time() + self::CANCEL_RETRY_SECONDS;
                        $errors[] = $receiptId . ': ' . (string)($deleted['msg'] ?? '删除收款链接失败');
                    }
                } else {
                    $pausedResult = self::pauseLink($cfg, $receiptId);
                    if ((int)($pausedResult['code'] ?? -1) === 0) $paused++;
                    else { $failed++; $errors[] = $receiptId . ': ' . (string)($pausedResult['msg'] ?? '暂停收款链接失败'); }
                }
                continue;
            }
            if (!empty($active['released'][$receiptId])) continue;
            $tail = trim((string)($item['order_no'] ?? ''));
            $isSystemPending = isset($active['receipts'][$receiptId]) || ($tail !== '' && isset($active['tails'][$tail]));
            $createdAt = (int)($item['created_at'] ?? 0);
            $expired = $createdAt > 0 && time() >= $createdAt + $expireSeconds;

            // 当前仍在有效期内且对应本地未支付订单的链接必须保留，便于完成匹配。
            if ($isSystemPending && !$expired) continue;

            $releasedResult = self::releaseLink($cfg, $receiptId);
            if ((int)($releasedResult['code'] ?? -1) === 0) {
                $released++;
            } else {
                $failed++;
                $errors[] = $receiptId . ': ' . (string)($releasedResult['msg'] ?? '暂停或删除失败');
            }
        }
        return ['code' => $failed > 0 ? -1 : 0, 'msg' => $errors ? implode('; ', $errors) : '收款链接清理完成', 'paused' => $paused, 'released' => $released, 'failed' => $failed];
    }

    static private function activePendingIndex($channelId, $subchannelId, $expireSeconds)
    {
        global $DB;
        $index = ['receipts' => [], 'tails' => [], 'paid' => [], 'paid_orders' => [], 'paid_meta' => [], 'paused' => [], 'deleted' => [], 'released' => []];
        if ($channelId <= 0) return $index;
        $rows = $DB->getAll("SELECT trade_no,status,addtime,endtime,ext FROM pre_order WHERE channel='" . (int)$channelId . "' AND subchannel='" . (int)$subchannelId . "' AND ((status=0 AND (ext IS NULL OR ext NOT LIKE '%yunskljxd_waiting%') AND addtime>=DATE_SUB(NOW(),INTERVAL " . (int)$expireSeconds . " SECOND)) OR (status=1 AND ext LIKE '%yunskljxd_receipt_id%' AND (ext IS NULL OR ext NOT LIKE '%yunskljxd_receipt_deleted%')))");
        foreach ($rows as $row) {
            $ext = self::readExt($row);
            $receiptId = trim((string)($ext['yunskljxd_receipt_id'] ?? ''));
            if ((int)($row['status'] ?? 0) === 1) {
                if ($receiptId !== '') {
                    $index['paid'][$receiptId] = true;
                    $index['paid_orders'][$receiptId] = (string)$row['trade_no'];
                    $paidAt = (int)($ext['yunskljxd_receipt_paid_at_ts'] ?? 0);
                    if ($paidAt <= 0 && !empty($ext['yunskljxd_receipt_paid_at'])) $paidAt = self::parseTime($ext['yunskljxd_receipt_paid_at']);
                    if ($paidAt <= 0 && !empty($row['endtime'])) $paidAt = self::parseTime($row['endtime']);
                    if ($paidAt <= 0 && !empty($row['addtime'])) $paidAt = self::parseTime($row['addtime']);
                    $deleteAfter = (int)($ext['yunskljxd_receipt_delete_after'] ?? 0);
                    if ($deleteAfter <= 0 && $paidAt > 0) $deleteAfter = $paidAt + self::PAID_LINK_RETENTION_SECONDS;
                    $index['paid_meta'][$receiptId] = ['trade_no' => (string)$row['trade_no'], 'paid_at' => $paidAt, 'delete_after' => $deleteAfter, 'retry_at' => (int)($ext['yunskljxd_receipt_delete_retry_at'] ?? 0)];
                    if (!empty($ext['yunskljxd_receipt_paused'])) $index['paused'][$receiptId] = true;
                    if (!empty($ext['yunskljxd_receipt_deleted'])) $index['deleted'][$receiptId] = true;
                    if (!empty($ext['yunskljxd_receipt_released'])) $index['released'][$receiptId] = true;
                }
                continue;
            }
            if (self::isExpired($row, $expireSeconds)) continue;
            if ($receiptId !== '') $index['receipts'][$receiptId] = true;
            $tail = self::validOrderTail((string)($row['trade_no'] ?? ''));
            if ($tail !== '') $index['tails'][$tail] = true;
        }
        return $index;
    }

    static private function markReceiptPaused($tradeNo, $paidAt = 0, $deleteAfter = 0)
    {
        global $DB;
        $row = $DB->getRow('SELECT ext FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$tradeNo]);
        if (!$row) return false;
        $ext = self::readExt($row);
        $paidAt = (int)$paidAt;
        if ($paidAt <= 0 && !empty($ext['yunskljxd_receipt_paid_at'])) $paidAt = self::parseTime($ext['yunskljxd_receipt_paid_at']);
        if ($paidAt <= 0) $paidAt = time();
        $deleteAfter = (int)$deleteAfter;
        if ($deleteAfter <= 0) $deleteAfter = (int)($ext['yunskljxd_receipt_delete_after'] ?? 0);
        if ($deleteAfter <= 0) $deleteAfter = $paidAt + self::PAID_LINK_RETENTION_SECONDS;
        $ext['yunskljxd_receipt_paused'] = 1;
        $ext['yunskljxd_receipt_paused_at'] = date('Y-m-d H:i:s');
        $ext['yunskljxd_receipt_paid_at'] = date('Y-m-d H:i:s', $paidAt);
        $ext['yunskljxd_receipt_paid_at_ts'] = $paidAt;
        $ext['yunskljxd_receipt_delete_after'] = $deleteAfter;
        unset($ext['yunskljxd_receipt_pause_error'], $ext['yunskljxd_receipt_release_error']);
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => (string)$tradeNo]) !== false;
    }

    static private function markReceiptDeleted($tradeNo)
    {
        global $DB;
        $row = $DB->getRow('SELECT ext FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$tradeNo]);
        if (!$row) return false;
        $ext = self::readExt($row);
        $ext['yunskljxd_receipt_deleted'] = 1;
        $ext['yunskljxd_receipt_deleted_at'] = date('Y-m-d H:i:s');
        $ext['yunskljxd_receipt_released'] = 1;
        unset($ext['yunskljxd_receipt_delete_error'], $ext['yunskljxd_receipt_delete_retry_at']);
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => (string)$tradeNo]) !== false;
    }

    static private function markReceiptDeleteError($tradeNo, $message)
    {
        global $DB;
        $row = $DB->getRow('SELECT ext FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$tradeNo]);
        if (!$row) return false;
        $ext = self::readExt($row);
        $ext['yunskljxd_receipt_delete_error'] = (string)$message;
        $ext['yunskljxd_receipt_delete_retry_at'] = time() + self::CANCEL_RETRY_SECONDS;
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => (string)$tradeNo]) !== false;
    }

    static private function canRetryDelete($receiptId, $persistedRetryAt = 0)
    {
        $retryAt = max((int)$persistedRetryAt, (int)(self::$deleteRetryAt[(string)$receiptId] ?? 0));
        return $retryAt <= time();
    }

    static private function parseTime($value)
    {
        if (is_numeric($value)) {
            $time = (int)$value;
            return $time > 20000000000 ? (int)floor($time / 1000) : $time;
        }
        $time = strtotime((string)$value);
        return $time === false ? 0 : $time;
    }

    static private function reloadChannel($channel)
    {
        if (!is_array($channel)) return $channel;
        if (!empty($channel['subid'])) {
            $fresh = \lib\Channel::getSub((int)$channel['subid']);
        } else {
            $fresh = \lib\Channel::get((int)($channel['id'] ?? 0));
        }
        return is_array($fresh) ? $fresh : $channel;
    }

    static public function markExpired($order)
    {
        global $DB; if ((int)($order['status'] ?? 0) !== 0 || self::isExpiredFlag($order)) return false; $ext = self::readExt($order); $ext['yunskljxd_expired'] = 1; $ext['yunskljxd_expired_at'] = date('Y-m-d H:i:s'); return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }
    static private function markWaiting(&$order, $waitSeconds)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0) return false;
        $ext = self::readExt($order);
        $ext['yunskljxd_waiting'] = 1;
        $ext['yunskljxd_wait_until'] = date('Y-m-d H:i:s', time() + max(0, (int)$waitSeconds));
        $serialized = serialize($ext);
        $order['ext'] = $serialized;
        return $DB->update('order', ['ext' => $serialized], ['trade_no' => $order['trade_no']]) !== false;
    }
    static public function isExpired($order, $channelOrExpireSeconds = null)
    {
        // 等待中的收银台尚未拿到收款链接，不能按它自己的创建时间提前失效；
        // 等待倒计时结束后页面会重新尝试，成功创建链接时再清除该标记。
        if (!empty(self::readExt($order)['yunskljxd_waiting'])) return false;
        if (is_numeric($channelOrExpireSeconds)) {
            $expireSeconds = max(1, (int)$channelOrExpireSeconds);
        } elseif (is_array($channelOrExpireSeconds)) {
            $expireSeconds = self::expireSeconds($channelOrExpireSeconds);
        } else {
            $expireSeconds = self::F2F_EXPIRE_SECONDS;
        }
        return self::isExpiredFlag($order) || (!empty($order['addtime']) && strtotime($order['addtime']) + $expireSeconds <= time());
    }
    static private function isExpiredFlag($order) { $ext = self::readExt($order); return !empty($ext['yunskljxd_expired']); }

    static private function reserveAmount($channel, &$order)
    {
        global $DB; $base = round((float)($order['money'] ?? $order['realmoney'] ?? 0), 2); if ($base <= 0 || empty($order['trade_no'])) return false; $ext = self::readExt($order); if (!empty($ext['yunskljxd_amount'])) { $order['realmoney'] = number_format((float)$ext['yunskljxd_amount'], 2, '.', ''); return true; } $lock = 'yunskljxd_float_' . (int)$channel['id'] . '_' . number_format($base, 2, '.', '_'); if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,5)', [':lock_name' => $lock]) !== 1) return false;
        $expireSeconds = self::expireSeconds($channel);
        try { $rows = $DB->getAll("SELECT realmoney,ext FROM pre_order WHERE channel='" . (int)$channel['id'] . "' AND status=0 AND (ext IS NULL OR ext NOT LIKE '%yunskljxd_waiting%') AND trade_no<>:trade_no AND addtime>=DATE_SUB(NOW(),INTERVAL " . (int)$expireSeconds . " SECOND)", [':trade_no' => (string)$order['trade_no']]); $used = []; foreach ($rows as $row) { $e = !empty($row['ext']) ? @unserialize($row['ext']) : []; $rowBase = is_array($e) && isset($e['yunskljxd_base']) ? (float)$e['yunskljxd_base'] : (float)$row['realmoney']; if (abs($rowBase - $base) > 0.001 || (is_array($e) && !empty($e['yunskljxd_expired']))) continue; $value = is_array($e) && isset($e['yunskljxd_amount']) ? (float)$e['yunskljxd_amount'] : (float)$row['realmoney']; $used[number_format($value, 2, '.', '')] = true; } $assigned = $base; while (isset($used[number_format($assigned, 2, '.', '')])) $assigned = round($assigned + self::FLOAT_STEP, 2); $ext['yunskljxd_base'] = $base; $ext['yunskljxd_amount'] = $assigned; $old = (float)($order['realmoney'] ?? $base); $order['realmoney'] = number_format($assigned, 2, '.', ''); $data = ['realmoney' => $order['realmoney'], 'ext' => serialize($ext)]; if (isset($order['getmoney'])) $data['getmoney'] = number_format(max(0, (float)$order['getmoney'] + $assigned - $old), 2, '.', ''); $ok = $DB->update('order', $data, ['trade_no' => $order['trade_no']]) !== false; if ($ok) $order['ext'] = $data['ext']; return $ok; } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lock]); }
    }

    static private function requestJson($method, $url, $body, $cfg, $gateway, $appId = null)
    {
        $headers = ['Accept: application/json, */*', 'Content-Type: application/json', 'xweb_xhr: 1', 'User-Agent: Mozilla/5.0 MicroMessenger/7.0']; $headers[] = 'Referer: https://servicewechat.com/' . ($appId ?: self::LINK_APP_ID) . '/1/page-frame.html'; $proxy = null; try { $proxy = self::proxyParts($cfg); } catch (Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []]; } if ($gateway && $proxy !== null) { $headers[] = 'X-Proxy-Cluster-URL: ' . $proxy['host_header'] . ':' . $proxy['port']; $headers[] = 'X-Proxy-Cluster-Type: ' . $proxy['scheme']; $headers[] = 'X-Proxy-Cluster-User: ' . $proxy['user']; $headers[] = 'X-Proxy-Cluster-Pass: ' . $proxy['pass']; }
        $ch = curl_init($url); $path = (string)(parse_url($url, PHP_URL_PATH) ?: ''); $longPoll = $gateway && (bool)preg_match('#/yyb/qr/[^/]+/poll$#', $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => $longPoll ? 10 : 6, CURLOPT_TIMEOUT => $longPoll ? 70 : ($gateway ? 20 : 35), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_NOPROXY => '*']); if (strtoupper($method) === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($raw === false || $error !== '') return ['code' => -1, 'msg' => '请求接口失败：' . $error, 'data' => []]; $data = json_decode((string)$raw, true); if (!is_array($data)) return ['code' => -1, 'msg' => '接口返回不是 JSON（HTTP ' . $status . '）', 'data' => []]; if ($status < 200 || $status >= 300) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? ('HTTP ' . $status)), 'data' => $data]; foreach (['errcode', 'retcode'] as $key) if (isset($data[$key]) && (int)$data[$key] !== 0) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data]; if (array_key_exists('ok', $data) && !$data['ok']) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data]; if (isset($data['code']) && is_numeric($data['code']) && !in_array((int)$data['code'], [0, 200], true)) return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['message'] ?? '接口返回失败'), 'data' => $data]; return ['code' => 0, 'msg' => '', 'data' => $data];
    }

    static private function payload($result) { $data = is_array($result['data'] ?? null) ? $result['data'] : []; if (isset($data['data']) && is_array($data['data'])) return $data['data']; if (isset($data['result']) && is_array($data['result'])) return $data['result']; return $data; }
    static private function extract($value, $keys) { if (is_string($value)) { $decoded = json_decode($value, true); return is_array($decoded) ? self::extract($decoded, $keys) : ''; } if (!is_array($value)) return ''; foreach ($keys as $key) if (isset($value[$key]) && !is_array($value[$key]) && (string)$value[$key] !== '') return (string)$value[$key]; foreach ($value as $child) { if (!is_array($child)) continue; $found = self::extract($child, $keys); if ($found !== '') return $found; } return ''; }
    static private function first($array, $keys) { return self::extract($array, $keys); }
    static private function arrayList($value, $keys) { if (!is_array($value)) return []; foreach ($keys as $key) if (isset($value[$key]) && is_array($value[$key])) return isset($value[$key][0]) ? $value[$key] : [$value[$key]]; foreach (['data', 'result', 'account', 'user'] as $child) if (isset($value[$child])) { $list = self::arrayList($value[$child], $keys); if ($list) return $list; } return []; }

    static private function getConfig($channel)
    {
        $keys = ['gateway', 'login_mode', 'link_permission_mode', 'sid', 'shop_id', 'cloud_ref', 'sid_login_mode', 'sid_permission_mode', 'proxy_mode', 'proxy']; $cfg = []; foreach ($keys as $key) $cfg[$key] = trim((string)($channel[$key] ?? '')); $cfg['gateway'] = rtrim($cfg['gateway'], '/'); if (!in_array($cfg['login_mode'], ['yyb', 'guanjia'], true)) $cfg['login_mode'] = ''; if (!in_array($cfg['link_permission_mode'], ['f2f', 'business'], true)) $cfg['link_permission_mode'] = 'f2f'; if ($cfg['proxy_mode'] === '') $cfg['proxy_mode'] = 'no_proxy'; return $cfg;
    }
    static private function sidModeMismatch($cfg)
    {
        if ($cfg['sid'] === '') return false;
        return ($cfg['sid_login_mode'] !== '' && $cfg['sid_login_mode'] !== $cfg['login_mode']) || ($cfg['sid_permission_mode'] !== '' && $cfg['sid_permission_mode'] !== $cfg['link_permission_mode']);
    }
    static private function saveConfig($channelId, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yunskljxd'))return \lib\AdminApp\PluginConfig::capture('yunskljxd',$channelId,$updates);
        if(\lib\Task\PaymentMonitor::activeFor('yunskljxd'))return \lib\Task\PaymentMonitor::saveCredentials($channelId,$updates,['sid']);
        global $DB; $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$channelId . "' LIMIT 1"); if (!$row || $row['plugin'] !== 'yunskljxd') return false; $config = json_decode((string)$row['config'], true); if (!is_array($config)) $config = []; foreach ($updates as $key => $value) $config[$key] = (string)$value; return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }
    static private function saveLinkMeta(&$order, $receiptId, $tail)
    {
        global $DB; $ext = self::readExt($order); $ext['yunskljxd_receipt_id'] = (string)$receiptId; $ext['yunskljxd_order_tail'] = (string)$tail; $ext['yunskljxd_receipt_created_at'] = date('Y-m-d H:i:s'); unset($ext['yunskljxd_receipt_cancelled'], $ext['yunskljxd_cancelled_at'], $ext['yunskljxd_cancel_error'], $ext['yunskljxd_waiting'], $ext['yunskljxd_wait_until']); $serialized = serialize($ext); $order['ext'] = $serialized; return $DB->update('order', ['ext' => $serialized], ['trade_no' => $order['trade_no']]) !== false;
    }
    static private function readExt($order) { if (empty($order['ext'])) return []; $ext = @unserialize($order['ext']); return is_array($ext) ? $ext : []; }
    static private function proxyParts($cfg)
    {
        if (in_array(strtolower(trim((string)$cfg['proxy_mode'])), ['', 'no_proxy', 'none', '0'], true)) return null; $raw = trim((string)$cfg['proxy']); if ($raw === '') throw new InvalidArgumentException('已开启代理但未填写代理地址'); if (strpos($raw, '://') === false) $raw = 'socks5://' . $raw; $parts = parse_url($raw); if (!$parts || empty($parts['host']) || empty($parts['port'])) throw new InvalidArgumentException('代理地址格式无效'); $scheme = strtolower((string)($parts['scheme'] ?? '')); if (!in_array($scheme, ['socks5', 'socks5h', 'http', 'https'], true)) throw new InvalidArgumentException('代理协议仅支持 socks5、socks5h、http、https'); $host = (string)$parts['host']; $headerHost = strpos($host, ':') !== false && $host[0] !== '[' ? '[' . $host . ']' : $host; return ['scheme' => $scheme, 'host_header' => $headerHost, 'port' => (int)$parts['port'], 'user' => rawurldecode((string)($parts['user'] ?? '')), 'pass' => rawurldecode((string)($parts['pass'] ?? ''))];
    }
    static private function normalizeQr($value) { $value = trim((string)$value); if ($value === '' || strpos($value, 'data:image/') === 0 || preg_match('#^https?://#i', $value)) return $value; $raw = base64_decode($value, true); if ($raw !== false && substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") return 'data:image/png;base64,' . $value; if ($raw !== false && substr($raw, 0, 3) === "\xff\xd8\xff") return 'data:image/jpeg;base64,' . $value; return $value; }
    static private function isImageValue($value) { $value = (string)$value; return strpos($value, 'data:image/') === 0 || (base64_decode($value, true) !== false && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)); }
    static private function validOrderTail($orderNo) { $value = preg_replace('/\D/', '', (string)$orderNo); return strlen($value) >= 16 ? substr($value, -16) : ''; }
    static private function extractOrderTail($value, $depth = 0)
    {
        if ($depth > 8 || $value === null) return ''; if (is_string($value) || is_numeric($value)) { $text = trim((string)$value); return preg_match('/\d{16}/', $text, $m) ? substr($m[0], -16) : ''; } if (!is_array($value)) return ''; foreach (['order_id', 'out_trade_no', 'trade_no', 'remark', 'desc', 'description', 'receipt_item_desc', 'name', 'title', 'content', 'link_qrcode_info', 'qrcode_trade_info'] as $key) if (isset($value[$key])) { $found = self::extractOrderTail($value[$key], $depth + 1); if ($found !== '') return $found; } foreach ($value as $child) { $found = self::extractOrderTail($child, $depth + 1); if ($found !== '') return $found; } return '';
    }
    static private function extractOrderReference($value, $depth = 0)
    {
        if ($depth > 8 || $value === null) return '';
        if (is_string($value) || is_numeric($value)) {
            $text = trim((string)$value);
            return preg_match('/\d{10,24}/', $text, $m) ? $m[0] : '';
        }
        if (!is_array($value)) return '';
        foreach (['receipt_remark', 'remark', 'out_trade_no', 'order_id', 'desc', 'description', 'receipt_item_desc', 'name', 'title'] as $key) {
            if (!isset($value[$key])) continue;
            $found = self::extractOrderReference($value[$key], $depth + 1);
            if ($found !== '') return $found;
        }
        return '';
    }
    static private function amountFen($value) { if ($value === '' || $value === null) return 0; $text = trim((string)$value); return strpos($text, '.') !== false ? (float)$text * 100 : (float)$text; }
    static private function timestamp($value) { if (is_numeric($value)) { $v = (int)$value; return $v > 20000000000 ? (int)floor($v / 1000) : $v; } return $value !== '' ? (int)strtotime((string)$value) : 0; }
}
