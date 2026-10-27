<?php

/**
 * 星译付云端-XD。
 *
 * 登录二维码由配置的网关提供；登录成功后只保存该通道自己的 appsession。
 * 星译付收款单接口由插件直连，网关地址不参与支付接口请求。
 */
class yunxingyifuxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('yunxingyifuxd', $channels, $context, static function($channel){
            $config=self::getConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['gateway', 'xingyi_username', 'xingyi_password', 'appsession']);

        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\yunxingyifuxd\monitor($target);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const HOST = 'https://yhk.postar.cn';
    // 网关扫码完成后只返回绑定账号标识；业务会话必须用这个小程序 AppID 重新取 code 换取。
    const APP_ID = 'wxaceada211a82dcdd';
    const EXPIRE_SECONDS = 300;
    const MATCH_SECONDS = 480;
    const REQUEST_TIMEOUT = 15;
    const LOGIN_QR_TIMEOUT = 60;
    const LOGIN_POLL_TIMEOUT = 30;
    const GET_CODE_TIMEOUT = 120;
    // API 代理不能在登录轮询的每次 HTTP 请求中重复提取，否则供应商会限频。
    const API_PROXY_CACHE_SECONDS = 60;
    // 星译付网页端和小程序登录使用同一组 2048 位 RSA 公钥，账号密码按
    // PKCS#1 v1.5 加密后提交，明文不会发送到星译付接口。
    const LOGIN_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuQfc4cC9gg9iTyUlJQyYoAzY6Ng6wjEYtS7igsjUVpB30jH09fxsX9tViDqIesacJBNY7LeBsmr/pZ4qUc8JsMm/FNgfgFT8c96hT+3b62E/SJKNYh2fgl28RQSFmBDuVJXswXA/NhI9adc9AOXAVWPbl8v2Iu28yCK8Zucniyiu0nzIafXb2z0v5ixsCE8XJdMUfwtxXhXKiC6qz+Jw8Z7NNn4aNQem7ELXg29ORxbzMQUxZcA0C7txkk3xukNr7JyT9dkfl1mCAsDTb/SkyrOhEX+Dtv10d+7yLK8TB+uQ1jG46IikX7yS5gKV1oI5WwFipiYPiU0LMNKTTngPQwIDAQAB';

    static public $info = [
        'name' => 'yunxingyifuxd',
        'showname' => '星译付云端-XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['wxpay', 'alipay'],
        'transtypes' => [],
        'select' => null,
        'inputs' => [
            'gateway' => ['name' => '网关地址', 'type' => 'input', 'note' => '填写扫码登录网关完整 URL'],
            'login_mode' => ['name' => '登录方式', 'type' => 'select', 'options' => ['yyb' => '应用宝', 'guanjia' => '电脑管家']],
            'xingyi_username' => ['name' => '星译付账号', 'type' => 'input', 'note' => '填写星译付商户登录账号'],
            'xingyi_password' => ['name' => '星译付密码', 'type' => 'input', 'note' => '仅用于向星译付登录接口自动换取 AppSession'],
            'appsession' => ['name' => 'AppSession', 'type' => 'input', 'note' => '扫码自动获取，也可手动填写星译付 appsession'],
            'xingyi_dept_id' => ['name' => '星译付商户 ID', 'type' => 'input', 'note' => '扫码登录或同步 AppSession 时自动获取，也可手动填写'],
            'proxy_mode' => ['name' => '代理模式', 'type' => 'select', 'options' => ['no_proxy' => '不使用代理', 'custom_proxy' => '自定义 Socks5/HTTP 代理', 'api' => 'API 模式']],
            'proxy_custom' => ['name' => '自定义代理地址', 'type' => 'input', 'note' => '填写 http:// 或 socks5:// 代理地址'],
            'proxy_api' => ['name' => '熊猫代理 API 地址', 'type' => 'input', 'note' => '填写熊猫代理 API 完整地址'],
        ],
        'note' => '<style>form#form-info + br,form#form-info ~ br{display:none!important}#form-info{text-align:left!important;overflow:visible}#form-info .yunxingyifu-actions{display:flex!important;gap:4%;width:100%;margin:0 0 12px!important}#form-info .yunxingyifu-actions #save,#form-info .yunxingyifu-actions .yunxingyifu-login{display:inline-flex!important;float:none!important;flex:1 1 0;width:auto!important;box-sizing:border-box;margin:0!important;align-items:center;justify-content:center}.yunxingyifu-login{text-align:center;line-height:20px;padding:10px 16px;background:#1677ff;color:#fff!important;border-radius:4px;text-decoration:none}.yunxingyifu-login:hover{background:#0e63dc;text-decoration:none}.yunxingyifu-note{display:block;clear:both;line-height:1.8}.yunxingyifu-session-line{display:flex!important;align-items:center;gap:8px;width:100%}.yunxingyifu-session-line input{flex:1 1 auto!important;width:auto!important}.yunxingyifu-sync{flex:0 0 auto!important;min-width:72px;height:34px}</style><span class="yunxingyifu-note">填写星译付账号密码并保存。扫码登录只负责重新获取当前通道的 AppSession；商户 ID 会自动获取，也可手动填写。</span><a class="yunxingyifu-login" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,&#39;yunxingyifu_login_[channel]&#39;,&#39;width=480,height=720,resizable=yes,scrollbars=yes&#39;);return false;">扫码登录</a><script>(function(){var f=document.getElementById("form-info"),a=document.querySelector(".yunxingyifu-login"),s=document.getElementById("save"),mode=document.getElementsByName("config[proxy_mode]")[0],proxyCustom=document.getElementsByName("config[proxy_custom]")[0],proxyApi=document.getElementsByName("config[proxy_api]")[0],password=document.getElementsByName("config[xingyi_password]")[0],appsession=document.getElementsByName("config[appsession]")[0],dept=document.getElementsByName("config[xingyi_dept_id]")[0],syncUrl="./plugin_page.php?channel=[channel]&func=adminLogin&action=sync",row=function(e){return e&&e.closest?e.closest(".form-group"):null};if(!f||!s)return;if(password){password.type="password";password.autocomplete="new-password";}if(appsession&&appsession.parentNode){var line=document.createElement("div"),syncBtn=document.createElement("button");line.className="yunxingyifu-session-line";syncBtn.type="button";syncBtn.className="btn btn-info yunxingyifu-sync";syncBtn.textContent="同步";appsession.parentNode.insertBefore(line,appsession);line.appendChild(appsession);line.appendChild(syncBtn);syncBtn.onclick=function(){syncBtn.disabled=true;syncBtn.textContent="同步中";fetch(syncUrl,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.text()}).then(function(t){var d=new DOMParser().parseFromString(t,"text/html"),n=d.querySelector("#yunxingyifu-response"),j=n?JSON.parse(n.value||n.textContent||"{}"):{};if(j.code!==0)throw Error(j.msg||"同步失败");if(j.appsession)appsession.value=j.appsession;if(dept&&j.dept_id)dept.value=j.dept_id;syncBtn.textContent="已同步";if(window.layer&&layer.msg)layer.msg(j.msg||"同步成功",{icon:1});else alert(j.msg||"同步成功");setTimeout(function(){syncBtn.textContent="同步";syncBtn.disabled=false},1500)}).catch(function(e){syncBtn.textContent="同步";syncBtn.disabled=false;if(window.layer&&layer.msg)layer.msg(e.message||"同步失败",{icon:2});else alert(e.message||"同步失败")})};}function move(e){var r=row(e);if(r&&r.parentNode===f)f.insertBefore(r,s);}move(mode);move(proxyCustom);move(proxyApi);var actions=document.createElement("div");actions.className="yunxingyifu-actions";f.appendChild(actions);if(a)actions.appendChild(a);actions.appendChild(s);function sync(){var m=mode?mode.value:"no_proxy",rc=row(proxyCustom),ra=row(proxyApi);if(rc)rc.style.display=m==="custom_proxy"?"":"none";if(ra)ra.style.display=m==="api"?"":"none";if(proxyCustom){proxyCustom.placeholder="填写 http:// 或 socks5:// 代理地址";proxyCustom.title="自定义 Socks5/HTTP 代理地址";}if(proxyApi){proxyApi.placeholder="填写熊猫代理 API 地址";proxyApi.title="熊猫代理 API 地址";}}if(mode)mode.addEventListener("change",sync);sync();}());</script>',
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    static public function submit() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }
    static public function mapi() { return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/']; }

    static public function qrcode()
    {
        global $order, $channel, $conf, $cdnpublic;
        $config = self::getConfig($channel); $code_url = ''; $error_msg = '';
        $siteName = trim((string)($conf['sitename'] ?? '')); $payType = strtolower(trim((string)($order['typename'] ?? 'wxpay'))); $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? '支付'));
        $title = ($paymentName !== '' ? $paymentName : '支付') . '-收银台'; $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台'; $paymentIcon = strpos($payType, 'ali') !== false ? '/assets/icon/alipay.ico' : '/assets/icon/wxpay.ico'; $payHint = strpos($payType, 'ali') !== false ? '请使用支付宝扫一扫完成支付' : '请使用微信扫一扫完成支付';
        if ((int)($order['status'] ?? 0) !== 0) $error_msg = '订单状态已改变，请返回订单页面查看结果';
        elseif (self::isExpired($order)) { self::markExpired($order); $error_msg = '订单已超时，请重新发起支付'; }
        elseif ($config['appsession'] === '') $error_msg = '未配置 AppSession，已禁止发起订单';
        else { $made = self::createReceipt($config, $order); if ($made['code'] === 0) $code_url = $made['qrcode']; else $error_msg = $made['msg']; }
        $paytime = max(0, strtotime((string)$order['addtime']) + self::EXPIRE_SECONDS - time()); define('IN_PLUGIN', true); include __DIR__ . '/inc/qrcode.page.php'; exit;
    }

    static public function notify() { return ['type' => 'error', 'msg' => '本插件由 受管收款监控 轮询星译付订单']; }

    /** Native administrator adapter; no HTML, exit, cookies or client-selected method. */
    static public function appLogin($channel, $action, array $input = [])
    {
        if ($action === 'sync') {
            $r = self::syncSharedAppSession($channel);
            if (($r['code'] ?? -1) !== 0) return $r;
            if (!self::saveConfig((int)$channel['id'], ['appsession'=>$r['appsession'], 'xingyi_dept_id'=>$r['dept_id']])) return ['code'=>-1, 'msg'=>'同步登录态保存失败'];
            return ['code'=>0, 'msg'=>'有效 AppSession 已同步并保存'];
        }
        if ($action === 'start') return self::beginLogin($channel);
        if ($action === 'poll') return self::pollLogin($channel, (string)($input['session_id'] ?? ''));
        return ['code' => -1, 'msg' => '不支持的登录操作'];
    }

    static public function adminLogin($channel)
    {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'sync') self::adminResponse(self::syncSharedAppSession($channel));
        if ($action === 'start') self::adminResponse(self::beginLogin($channel));
        if ($action === 'poll') self::adminResponse(self::pollLogin($channel, (string)($_GET['session_id'] ?? '')));
        $c = self::getConfig($channel); $endpoint = './plugin_page.php?channel=' . (int)$channel['id'] . '&func=adminLogin'; $mode = $c['login_mode'] === 'guanjia' ? '电脑管家' : '应用宝';
        ?>
        <style>.yxf-login{max-width:430px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}.yxf-login h3{margin:0 0 10px;color:#1677ff}.yxf-meta{color:#666;line-height:1.8}.yxf-qr{width:260px;height:260px;margin:14px auto;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;background:#fafafa}.yxf-qr img{max-width:240px;max-height:240px}.yxf-state{min-height:24px;margin:8px 0;font-weight:600;color:#1677ff}.yxf-state.ok{color:#16803c}.yxf-state.bad{color:#c62828}.yxf-msg{min-height:24px;margin:10px 0;color:#555}.yxf-login button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}</style>
        <div class="yxf-login"><h3>星译付云端-XD扫码登录</h3><div class="yxf-meta">登录方式：<?php echo htmlspecialchars($mode, ENT_QUOTES, 'UTF-8'); ?></div><div class="yxf-qr" id="yxf-qr">正在准备二维码…</div><div class="yxf-state" id="yxf-state">状态：准备二维码</div><div class="yxf-msg" id="yxf-msg"></div><button type="button" id="yxf-start">重新获取二维码</button></div>
        <script src="/assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script><script>(function(){var e=<?php echo json_encode($endpoint,JSON_UNESCAPED_SLASHES); ?>,q=document.getElementById('yxf-qr'),s=document.getElementById('yxf-state'),m=document.getElementById('yxf-msg'),b=document.getElementById('yxf-start'),timer=0;function parse(t){var d=new DOMParser().parseFromString(t,'text/html'),n=d.querySelector('#yunxingyifu-response');if(!n)throw Error('后台响应格式错误');return JSON.parse(n.value||n.textContent||'{}')}function req(u){return fetch(u,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text()}).then(parse)}function state(t,c){s.className='yxf-state '+(c||'');s.textContent='状态：'+t}function say(t){m.textContent=t||''}function draw(v,k){q.innerHTML='';if(k==='image'){var i=document.createElement('img');i.src=v;q.appendChild(i)}else if(window.jQuery&&jQuery.fn.qrcode)jQuery(q).qrcode({text:v,width:240,height:240,typeNumber:-1});else q.textContent=v}function done(t){q.innerHTML='<span style="color:#16803c;font-size:54px">✓</span>';state('AppSession 可用','ok');say(t||'已复用同账号登录态');b.disabled=false;try{if(window.opener&&!window.opener.closed)window.opener.location.reload()}catch(x){}}function poll(id){req(e+'&action=poll&session_id='+encodeURIComponent(id)).then(function(d){if(d.code===0){done(d.msg||'新的 AppSession 已保存');return}if(d.code===201){state(d.state==='scanned'?'已扫码，请确认':'等待扫码');say(d.msg||'');timer=setTimeout(function(){poll(id)},2000);return}if(d.code===410){state('二维码已过期','bad');say(d.msg||'请重新获取二维码');b.disabled=false;return}state('AppSession 获取失败','bad');say(d.msg||'获取 AppSession 失败');b.disabled=false}).catch(function(){state('连接重试中','bad');say('后台请求失败，正在重试');timer=setTimeout(function(){poll(id)},3000)})}function start(){clearTimeout(timer);b.disabled=true;state('等待扫码');say('');req(e+'&action=start').then(function(d){if(d.code!==0){state('获取二维码失败','bad');say(d.msg);b.disabled=false;return}draw(d.qrcode,d.qrcode_type||'text');poll(d.session_id)}).catch(function(x){state('获取二维码失败','bad');say(x.message);b.disabled=false})}b.onclick=start;start()}());</script>
        <?php
    }

    static private function adminResponse($data) { echo '<textarea id="yunxingyifu-response" style="display:none">' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>'; exit; }

    static private function beginLogin($channel)
    {
        $c = self::getConfig($channel);
        if ($c['xingyi_username'] === '' || $c['xingyi_password'] === '') return ['code' => -1, 'msg' => '请先填写星译付账号和密码并保存'];
        if ($c['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址并保存']; $path = $c['login_mode'] === 'guanjia' ? '/guanjia/api/qrcode' : '/yyb/qr'; $body = $c['login_mode'] === 'guanjia' ? ['appid' => self::APP_ID] : [];
        $r = self::gatewayRequest('POST', $c['gateway'] . $path, $body, $c, self::LOGIN_QR_TIMEOUT); if ($r['code'] !== 0) return $r; $d = self::payload($r); $sid = self::extract($d, ['session_id','uuid','sessionId']); $qr = self::extract($d, ['qrcode','qr_code','url','login_url','image','image_base64']); if ($sid === '' || $qr === '') return ['code' => -1, 'msg' => '云端未返回完整登录二维码']; return ['code' => 0, 'session_id' => $sid, 'qrcode' => self::normalizeQr($qr), 'qrcode_type' => self::isImageValue($qr) ? 'image' : 'text'];
    }

    static private function pollLogin($channel, $session)
    {
        $c = self::getConfig($channel); if ($session === '') return ['code' => -1, 'msg' => '登录会话为空']; $base = $c['gateway'];
        if ($c['login_mode'] === 'guanjia') {
            $r = self::gatewayRequest('GET', $base . '/guanjia/api/status?uuid=' . rawurlencode($session), null, $c, self::LOGIN_POLL_TIMEOUT);
            if ($r['code'] !== 0) return self::loginSessionError($r);
            $d = self::payload($r);
            $status = strtolower((string)($d['status'] ?? $d['state'] ?? ''));
            if (in_array($status, ['pending', 'ready', 'scanned'], true)) {
                return ['code' => 201, 'state' => $status === 'scanned' ? 'scanned' : 'waiting', 'msg' => $status === 'scanned' ? '已扫码，请在微信确认' : '等待扫码'];
            }
            if ($status === 'expired') return ['code' => 410, 'msg' => '本次二维码已过期，请重新获取二维码'];
            if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) {
                return ['code' => -1, 'msg' => (string)($d['error'] ?? $d['msg'] ?? '电脑管家登录状态异常')];
            }
            // 电脑管家二维码创建时已经指定 APP_ID，授权结果会直接带这个
            // 小程序的 code；这里不能再拿其它小程序的值覆盖它。
            $account = is_array($d['result'] ?? null) ? $d['result'] : $d;
            $ref = self::extract($account, ['ref', 'bind_account', 'openid', 'uin', 'id']);
            $miniCode = self::extract($account, ['code', 'js_code', 'jscode', 'Code']);
            if ($miniCode === '' && $ref !== '') {
                $code = self::getMiniCode($c, $ref);
                if ($code['code'] !== 0) return $code;
                $miniCode = $code['value'];
            }
            if ($miniCode === '') return ['code' => -1, 'msg' => '电脑管家登录成功但未返回星译付小程序 code'];
        } else {
            $r = self::gatewayRequest('GET', $base . '/yyb/qr/' . rawurlencode($session) . '/poll', null, $c, self::LOGIN_POLL_TIMEOUT);
            if ($r['code'] !== 0) return self::loginSessionError($r);
            $d = self::payload($r);
            $status = strtolower((string)($d['status'] ?? $d['state'] ?? ''));
            if (in_array($status, ['pending', 'ready', 'scanned'], true)) {
                return ['code' => 201, 'state' => $status === 'scanned' ? 'scanned' : 'waiting', 'msg' => $status === 'scanned' ? '已扫码，请在微信确认' : '等待扫码'];
            }
            if ($status === 'expired') return ['code' => 410, 'msg' => '本次二维码已过期，请重新获取二维码'];
            if (!in_array($status, ['authorized', 'confirmed', 'success'], true)) return ['code' => -1, 'msg' => '应用宝二维码状态异常'];
            $r = self::gatewayRequest('POST', $base . '/yyb/qr/' . rawurlencode($session) . '/confirm', [], $c, self::LOGIN_POLL_TIMEOUT);
            if ($r['code'] !== 0) return $r;
            $account = self::payload($r);
            $ref = self::extract($account, ['openid', 'uin', 'id', 'ref', 'bind_account']);
            if ($ref === '') return ['code' => -1, 'msg' => '应用宝登录成功但云端未返回账号标识'];
            // 应用宝 confirm 只返回账号 ref。必须用目标小程序 APP_ID
            // 重新换 code，不能复用扫码阶段其它小程序的 code。
            $code = self::getMiniCode($c, $ref);
            if ($code['code'] !== 0) return $code;
            $miniCode = $code['value'];
        }
        // 两种登录方式最终都把 wxaceada211a82dcdd 的 code 交给星译付，
        // 由星译付返回真正业务 appsession；网关不会直接返回可用会话。
        $login = self::exchangeAppSession($c, $miniCode);
        if ($login['code'] !== 0) return $login;
        $appsession = $login['value'];
        if ($appsession === '') return ['code' => -1, 'msg' => '登录成功但星译付未返回 appsession'];
        $updates = ['appsession' => $appsession];
        if (!empty($login['dept_id'])) $updates['xingyi_dept_id'] = (string)$login['dept_id'];
        if (!self::saveConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '登录成功但保存 appsession 失败'];
        return ['code' => 0, 'msg' => '登录成功，新的 AppSession 已保存'];
    }

    static private function gatewayRequest($method, $url, $body, $cfg, $timeout = null)
    {
        $attempts = $cfg['proxy_mode'] === 'api' ? 2 : 1;
        $last = ['code' => -1, 'msg' => '云端请求失败', 'data' => []];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try { $proxy = self::proxyContext($cfg); } catch (Throwable $e) {
                $last = ['code' => -1, 'msg' => $e->getMessage(), 'data' => []];
                if ($attempt < $attempts) { self::clearApiProxyCache(self::proxyValue($cfg)); continue; }
                return $last;
            }
            $headers = ['Accept: application/json, */*', 'Content-Type: application/json', 'User-Agent: Mozilla/5.0'];
            if ($proxy) {
                $headers[] = 'X-Proxy-Cluster-URL: ' . $proxy['host_header'] . ':' . $proxy['port'];
                $headers[] = 'X-Proxy-Cluster-Type: ' . $proxy['scheme'];
                $headers[] = 'X-Proxy-Cluster-User: ' . $proxy['user'];
                $headers[] = 'X-Proxy-Cluster-Pass: ' . $proxy['pass'];
            }
            // 代理交给网关使用，插件到网关本身保持直连。
            $last = self::httpJson($method, $url, $body, $headers, '云端', $cfg, false, $timeout);
            if ($last['code'] === 0) return $last;
            $status = (int)($last['http_status'] ?? 0);
            if ($attempt < $attempts && in_array($status, [502, 503, 504], true)) {
                self::clearApiProxyCache(self::proxyValue($cfg));
                continue;
            }
            return $last;
        }
        return $last;
    }

    static private function xingyiRequest($method, $path, $body, $cfg)
    {
        $headers = ['Accept: application/json, text/plain, */*', 'Content-Type: application/json;charset=utf-8', 'client-type: C', 'charset: utf-8', 'appsession: ' . $cfg['appsession'], 'Referer: https://servicewechat.com/wxaceada211a82dcdd/45/page-frame.html', 'User-Agent: Mozilla/5.0 MicroMessenger/8.0.69.3040'];
        $attempts = $cfg['proxy_mode'] === 'api' ? 2 : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $r = self::httpJson($method, self::HOST . $path, $body, $headers, '星译付', $cfg, true);
            if ($r['code'] === 0) { $d = self::payload($r); return ['code' => 0, 'data' => $d]; }
            $msg = strtolower((string)($r['msg'] ?? ''));
            $proxyFailure = strpos($msg, 'proxy') !== false || strpos($msg, '407') !== false || strpos($msg, 'connect') !== false;
            if ($attempt < $attempts && $proxyFailure) { self::clearApiProxyCache(self::proxyValue($cfg)); continue; }
            return $r;
        }
        return ['code' => -1, 'msg' => '请求星译付失败', 'data' => []];
    }

    /** 从当前登录的微信账号为星译付小程序取一次短期 code。 */
    static private function getMiniCode($cfg, $ref)
    {
        $guanjia = $cfg['login_mode'] === 'guanjia';
        $path = $guanjia ? '/guanjia/api/get-code' : '/yyb/wxapp/getCode';
        $body = $guanjia ? ['ref' => $ref, 'appid' => self::APP_ID] : ['ref' => $ref, 'app_id' => self::APP_ID];
        $r = self::gatewayRequest('POST', $cfg['gateway'] . $path, $body, $cfg, self::GET_CODE_TIMEOUT);
        if ($r['code'] !== 0) return ['code' => -1, 'msg' => '获取星译付小程序 code 失败：' . $r['msg']];
        $value = self::extract(self::payload($r), ['code', 'js_code', 'jscode', 'Code']);
        return $value !== '' ? ['code' => 0, 'value' => $value] : ['code' => -1, 'msg' => '云端未返回星译付小程序 code'];
    }

    /**
     * 星译付完整登录链路：账号密码预处理 -> 取得商户签名数据 ->
     * 组合目标小程序 code 登录商户 -> 验证并返回业务 AppSession。
     */
    static private function exchangeAppSession($cfg, $code)
    {
        if ($cfg['xingyi_username'] === '' || $cfg['xingyi_password'] === '') {
            return ['code' => -1, 'msg' => '请先填写星译付账号和密码并保存'];
        }
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json;charset=utf-8',
            'client-type: C',
            'charset: utf-8',
            'Referer: https://servicewechat.com/' . self::APP_ID . '/45/page-frame.html',
            'User-Agent: Mozilla/5.0 MicroMessenger/8.0.69.3040',
        ];
        try {
            $username = self::rsaEncrypt($cfg['xingyi_username']);
            $password = self::rsaEncrypt($cfg['xingyi_password']);
        } catch (Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        $pre = self::httpJson('POST', self::HOST . '/v1/auth/mina/login/merchant/pre', [
            'username' => $username,
            'password' => $password,
            'loginWay' => 3,
            'clientType' => 2,
        ], $headers, '星译付账号登录', $cfg, true, 30);
        if ($pre['code'] !== 0) return $pre;
        $preData = self::payload($pre);
        $extended = is_array($preData['extendedData'] ?? null) ? $preData['extendedData'] : [];
        $merchant = self::selectMerchant($preData['deptInfos'] ?? [], $cfg['xingyi_dept_id']);
        $user = is_array($merchant['userInfo'] ?? null) ? $merchant['userInfo'] : [];
        $userId = (int)($user['userId'] ?? $merchant['userId'] ?? 0);
        $deptId = (int)($merchant['deptId'] ?? 0);
        $phone = trim((string)($extended['phone'] ?? $user['phone'] ?? ''));
        $sign = trim((string)($extended['sign'] ?? ''));
        $extData = trim((string)($extended['extData'] ?? ''));
        if ($userId <= 0 || $deptId <= 0 || $phone === '' || $sign === '' || $extData === '') {
            return ['code' => -1, 'msg' => '星译付账号登录成功，但未返回完整商户授权参数'];
        }
        $login = self::httpJson('POST', self::HOST . '/v1/auth/mina/login/merchant', [
            'clientType' => 2,
            'code' => $code,
            'sign' => $sign,
            'phone' => $phone,
            'userId' => $userId,
            'deptId' => $deptId,
            'appId' => 8,
            'extData' => $extData,
        ], $headers, '星译付商户登录', $cfg, true, 30);
        if ($login['code'] !== 0) return $login;
        $value = self::extract(self::payload($login), ['app_session', 'appsession', 'appSession']);
        if ($value === '') return ['code' => -1, 'msg' => '星译付商户登录接口未返回 appsession'];

        // 新登录态必须通过业务接口验证后才能覆盖旧值。
        $verifyConfig = $cfg;
        $verifyConfig['appsession'] = $value;
        $verify = self::xingyiRequest('POST', '/v1/system/mina/my/userInfo', null, $verifyConfig);
        if ($verify['code'] !== 0 || empty($verify['data']['userId']) || empty($verify['data']['deptId'])) {
            $verifyMsg = trim((string)($verify['msg'] ?? ''));
            return ['code' => -1, 'msg' => $verifyMsg !== '' ? $verifyMsg : '新的 AppSession 验证失败'];
        }
        return ['code' => 0, 'value' => $value, 'dept_id' => (string)$deptId];
    }

    static private function rsaEncrypt($value)
    {
        if (!function_exists('openssl_public_encrypt')) throw new RuntimeException('服务器 PHP 未启用 OpenSSL 扩展');
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(self::LOGIN_PUBLIC_KEY, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) throw new RuntimeException('星译付登录公钥加载失败');
        $encrypted = '';
        $ok = openssl_public_encrypt((string)$value, $encrypted, $key, OPENSSL_PKCS1_PADDING);
        if (!$ok) throw new RuntimeException('星译付账号密码加密失败');
        return base64_encode($encrypted);
    }

    static private function selectMerchant($departments, $preferredDeptId = '')
    {
        $all = [];
        self::collectMerchants(is_array($departments) ? $departments : [], $all);
        if (!$all) return [];
        if ((string)$preferredDeptId !== '') {
            foreach ($all as $merchant) {
                if ((string)($merchant['deptId'] ?? '') === (string)$preferredDeptId) return $merchant;
            }
        }
        return $all[0];
    }

    static private function collectMerchants($items, &$result)
    {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (!empty($item['deptId']) && (is_array($item['userInfo'] ?? null) || !empty($item['userId']))) $result[] = $item;
            if (is_array($item['childDeptInfos'] ?? null)) self::collectMerchants($item['childDeptInfos'], $result);
        }
    }

    /** 二维码会话错误只属于本次扫码，不代表已保存的 AppSession 失效。 */
    static private function loginSessionError($result)
    {
        $msg = trim((string)($result['msg'] ?? ''));
        if (strpos($msg, '登录状态已过期') !== false || strpos($msg, '会话已过期') !== false) {
            return ['code' => 410, 'msg' => '本次二维码已过期，请重新获取二维码'];
        }
        return $result;
    }

    static private function httpJson($method, $url, $body, $headers, $label, $cfg, $useProxy, $timeout = null)
    {
        try { $proxy = $useProxy ? self::proxyContext($cfg) : null; } catch (Throwable $e) { return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []]; } $requestTimeout = $timeout === null ? self::REQUEST_TIMEOUT : max(1, (int)$timeout); $ch = curl_init($url); $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => min(15, $requestTimeout), CURLOPT_TIMEOUT => $requestTimeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        if (strtoupper($method) === 'POST' && $body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($proxy) { $opts[CURLOPT_PROXY] = $proxy['host'] . ':' . $proxy['port']; $opts[CURLOPT_PROXYTYPE] = $proxy['type']; if ($proxy['user'] !== '' || $proxy['pass'] !== '') $opts[CURLOPT_PROXYUSERPWD] = $proxy['user'] . ':' . $proxy['pass']; }
        curl_setopt_array($ch, $opts); $raw = curl_exec($ch); $err = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($raw === false || $err !== '') return ['code' => -1, 'msg' => '请求' . $label . '失败：' . $err, 'data' => [], 'http_status' => $status]; $d = json_decode((string)$raw, true); if (!is_array($d)) return ['code' => -1, 'msg' => $label . '接口返回不是 JSON（HTTP ' . $status . '）', 'data' => [], 'http_status' => $status]; if ($status < 200 || $status >= 300) return ['code' => -1, 'msg' => (string)($d['msg'] ?? $d['message'] ?? ($label . ' HTTP ' . $status)), 'data' => $d, 'http_status' => $status]; if (isset($d['code']) && is_numeric($d['code']) && !in_array((int)$d['code'], [0,1,200], true)) return ['code' => -1, 'msg' => (string)($d['msg'] ?? $d['message'] ?? ($label . '接口失败')), 'data' => $d, 'http_status' => $status]; return ['code' => 0, 'msg' => '', 'data' => $d, 'http_status' => $status];
    }

    static private function proxyValue($cfg)
    {
        return $cfg['proxy_mode'] === 'api' ? (string)$cfg['proxy_api'] : (string)$cfg['proxy_custom'];
    }

    static private function proxyContext($cfg)
    {
        if ($cfg['proxy_mode'] === 'no_proxy') return null;
        if ($cfg['proxy_mode'] === 'api') {
            $raw = trim(self::proxyValue($cfg));
            if ($raw === '') throw new InvalidArgumentException('API 模式未填写代理地址');
            $defaultScheme = self::proxySchemeFromApiUrl($raw);
            $cached = self::loadApiProxyCache($raw);
            if ($cached !== null) return $cached;
            $lastError = '熊猫代理 API 未返回可用节点';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $ch = curl_init($raw);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
                $text = trim((string)curl_exec($ch)); $err = curl_error($ch); curl_close($ch);
                if ($err !== '' || $text === '') {
                    $lastError = '熊猫代理 API 请求失败：' . $err;
                } else {
                    try {
                        $proxy = self::parseProxy($text, $defaultScheme);
                        self::saveApiProxyCache($raw, $proxy);
                        return $proxy;
                    } catch (Throwable $e) { $lastError = $e->getMessage(); }
                }
                // 熊猫接口明确限制同一订单至少间隔 1 秒提取。
                if ($attempt < 2) usleep(1100000);
            }
            throw new RuntimeException($lastError);
        }
        $raw = trim(self::proxyValue($cfg));
        if ($raw === '') throw new InvalidArgumentException('自定义代理模式未填写代理地址');
        if (strpos($raw, '://') === false) $raw = 'socks5://' . $raw;
        $u = parse_url($raw);
        if (!$u || empty($u['host']) || empty($u['port'])) throw new InvalidArgumentException('代理地址格式无效');
        $scheme = strtolower((string)($u['scheme'] ?? 'socks5'));
        if (!in_array($scheme, ['http', 'https', 'socks5', 'socks5h'], true)) throw new InvalidArgumentException('代理协议仅支持 http、https、socks5、socks5h');
        $host = (string)$u['host']; $hostHeader = strpos($host, ':') !== false && $host[0] !== '[' ? '[' . $host . ']' : $host;
        return ['host' => $host, 'host_header' => $hostHeader, 'port' => (int)$u['port'], 'scheme' => $scheme, 'user' => rawurldecode((string)($u['user'] ?? '')), 'pass' => rawurldecode((string)($u['pass'] ?? '')), 'type' => in_array($scheme, ['socks5', 'socks5h'], true) ? (defined('CURLPROXY_SOCKS5_HOSTNAME') && $scheme === 'socks5h' ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5) : CURLPROXY_HTTP];
    }

    static private function parseProxy($text, $defaultType)
    {
        $text = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$text));
        $defaultType = self::normalizeProxyScheme($defaultType);
        $json = json_decode($text, true);
        if (is_array($json)) {
            $code = $json['code'] ?? $json['errno'] ?? 0;
            if (!in_array((string)$code, ['0', '200'], true)) {
                $message = trim((string)($json['msg'] ?? $json['message'] ?? '代理供应商返回错误'));
                throw new RuntimeException('熊猫代理 API：' . $message);
            }
            $node = self::firstProxyNode($json);
            if (!$node) {
                $message = trim((string)($json['msg'] ?? $json['message'] ?? '未返回代理节点'));
                throw new RuntimeException('熊猫代理 API：' . $message);
            }
            if (isset($node['proxy']) && is_string($node['proxy']) && trim($node['proxy']) !== '') {
                return self::parseProxy((string)$node['proxy'], $defaultType);
            }
            $host = trim((string)($node['ip'] ?? $node['host'] ?? $node['server'] ?? ''));
            $port = (int)($node['port'] ?? 0);
            $user = trim((string)($node['account'] ?? $node['username'] ?? $node['user'] ?? ''));
            $pass = (string)($node['password'] ?? $node['pwd'] ?? $node['pass'] ?? '');
            $scheme = self::normalizeProxyScheme($node['protocol'] ?? $node['proxyType'] ?? $node['type'] ?? $defaultType);
            return self::buildProxy($host, $port, $scheme, $user, $pass);
        }

        $line = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) as $candidate) {
            if (trim((string)$candidate) !== '') { $line = trim((string)$candidate); break; }
        }
        if ($line === '') throw new RuntimeException('熊猫代理 API：未返回代理节点');
        if (strpos($line, '://') !== false || strpos($line, '@') !== false) {
            if (strpos($line, '://') === false) $line = $defaultType . '://' . $line;
            $u = parse_url($line);
            if (!$u || empty($u['host']) || empty($u['port'])) throw new RuntimeException('熊猫代理 API 返回格式无法识别');
            return self::buildProxy((string)$u['host'], (int)$u['port'], $u['scheme'] ?? $defaultType, rawurldecode((string)($u['user'] ?? '')), rawurldecode((string)($u['pass'] ?? '')));
        }
        // 熊猫账号密码模式 TXT：IP:端口:账号:密码；白名单模式：IP:端口。
        $parts = explode(':', $line, 4);
        if (count($parts) === 2) return self::buildProxy($parts[0], (int)$parts[1], $defaultType, '', '');
        if (count($parts) === 4) return self::buildProxy($parts[0], (int)$parts[1], $defaultType, $parts[2], $parts[3]);
        throw new RuntimeException('熊猫代理 API 返回格式无法识别');
    }

    static private function firstProxyNode($json)
    {
        foreach (['obj', 'data', 'result', 'proxy', 'list'] as $key) {
            if (!array_key_exists($key, $json)) continue;
            $value = $json[$key];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) $value = $decoded;
                elseif (trim($value) !== '') return ['proxy' => trim($value)];
            }
            if (!is_array($value)) continue;
            if (isset($value['ip']) || isset($value['host']) || isset($value['server']) || isset($value['proxy'])) return $value;
            foreach ($value as $item) {
                if (is_array($item) && (isset($item['ip']) || isset($item['host']) || isset($item['server']) || isset($item['proxy']))) return $item;
            }
        }
        if (isset($json['ip']) || isset($json['host']) || isset($json['server'])) return $json;
        return null;
    }

    static private function proxySchemeFromApiUrl($url)
    {
        $query = (string)(parse_url((string)$url, PHP_URL_QUERY) ?? '');
        $params = [];
        parse_str($query, $params);
        return (string)($params['proxyType'] ?? '') === '2' ? 'socks5' : 'http';
    }

    static private function normalizeProxyScheme($value)
    {
        $value = strtolower(trim((string)$value));
        if (in_array($value, ['2', 'socks', 'socks5', 'socks5h', 'sk5'], true)) return 'socks5';
        return 'http';
    }

    static private function buildProxy($host, $port, $scheme, $user = '', $pass = '')
    {
        $host = trim((string)$host);
        $port = (int)$port;
        if ($host === '' || $port < 1 || $port > 65535) throw new RuntimeException('熊猫代理 API 返回格式无法识别');
        $scheme = self::normalizeProxyScheme($scheme);
        $hostHeader = strpos($host, ':') !== false && $host[0] !== '[' ? '[' . $host . ']' : $host;
        $socksType = defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
        return ['host' => $host, 'host_header' => $hostHeader, 'port' => $port, 'scheme' => $scheme, 'user' => (string)$user, 'pass' => (string)$pass, 'type' => $scheme === 'socks5' ? $socksType : CURLPROXY_HTTP];
    }

    static private function apiProxyCacheFile($apiUrl)
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yunxingyifuxd_proxy_' . hash('sha256', $apiUrl) . '.json';
    }

    static private function loadApiProxyCache($apiUrl)
    {
        $file = self::apiProxyCacheFile($apiUrl);
        if (!is_file($file)) return null;
        $cached = json_decode((string)@file_get_contents($file), true);
        if (!is_array($cached) || !isset($cached['time'], $cached['proxy']) || time() - (int)$cached['time'] >= self::API_PROXY_CACHE_SECONDS || !is_array($cached['proxy'])) return null;
        $proxy = $cached['proxy'];
        foreach (['host','host_header','port','scheme','user','pass','type'] as $key) if (!array_key_exists($key, $proxy)) return null;
        return $proxy;
    }

    static private function saveApiProxyCache($apiUrl, $proxy)
    {
        @file_put_contents(self::apiProxyCacheFile($apiUrl), json_encode(['time' => time(), 'proxy' => $proxy], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    static private function clearApiProxyCache($apiUrl)
    {
        $file = self::apiProxyCacheFile((string)$apiUrl);
        if (is_file($file)) @unlink($file);
    }

    static private function createReceipt($cfg, $order)
    {
        $money = (int)round((float)$order['realmoney'] * 100); $trade = (string)$order['trade_no']; $params = ['deptId' => (string)$cfg['xingyi_dept_id'], 'copInfos' => [['childCops' => [['composite' => 1, 'copType' => '1', 'copLabel' => $trade, 'copPrompt' => '', 'copDefaultValue' => $money, 'copValue' => $money, 'copValueType' => 1, 'copRequire' => 0, 'copQuery' => 0, 'copLikeQuery' => 1, 'copReadonly' => 0, 'copSize' => null, 'copAboutAmt' => 1, 'copResourceShared' => 0, 'copId' => '', 'copChecklist' => 1, 'copCustom' => (object)[]]], 'composite' => '2', 'copType' => '105', 'copHtmlClass' => null, 'copLabel' => $trade, 'copPrompt' => '', 'copDefaultValue' => $money, 'copValue' => $money, 'copValueType' => 1, 'copRequire' => 0, 'copQuery' => 0, 'copLikeQuery' => 1, 'copReadonly' => 0, 'copSize' => null, 'copAboutAmt' => 1, 'copResourceShared' => 0, 'copId' => '', 'copChecklist' => 1, 'copCustom' => (object)[]]], 'renId' => '', 'renTitle' => $trade, 'renEffectiveDate' => date('Y-m-d H:i:s'), 'renExpirationDate' => date('Y-m-d H:i:s', time() + self::EXPIRE_SECONDS), 'managerId' => '', 'snNoList' => [], 'useTemplate' => 0, 'confirmTwice' => 0, 'managerIdList' => []];
        $r = self::xingyiRequest('POST', '/v1/payfly/pf2/mina/merch/renovation/createReceipt', $params, $cfg); if ($r['code'] !== 0) return $r; $renId = self::scalar($r['data']); if ($renId === '') return ['code' => -1, 'msg' => '星译付未返回收款单 ID']; $r = self::xingyiRequest('POST', '/v1/payfly/pf2/mina/merch/renovation/changeStatus', ['renId' => $renId, 'status' => 2], $cfg); if ($r['code'] !== 0) return $r; $r = self::xingyiRequest('POST', '/v1/payfly/pf2/mina/merch/renovation/getReceipt?renId=' . rawurlencode($renId), null, $cfg); if ($r['code'] !== 0) return $r; $url = self::extract($r['data'], ['qrCodeEncodeNoMgrStr','codePlateUrl','codeUrl']); return $url !== '' ? ['code' => 0, 'qrcode' => $url] : ['code' => -1, 'msg' => '星译付未返回有效收款链接'];
    }

    static public function getOrderList($channel, $startTime)
    {
        $c = self::getConfig($channel);
        if ($c['appsession'] === '') return ['code' => -1, 'msg' => '未配置 AppSession'];
        $params = ['pageNum' => 1, 'pageSize' => 1000, 'payState' => '1', 'queryStartTime' => date('Y-m-d H:i:s', $startTime - 60), 'queryEndTime' => date('Y-m-d H:i:s', time() + 60)]; $r = self::xingyiRequest('POST', '/v1/payfly/pf2/mina/merch/order/page', $params, $c); if ($r['code'] !== 0) return $r; return ['code' => 0, 'items' => is_array($r['data']['records'] ?? null) ? $r['data']['records'] : []];
    }

    static private function getConfig($channel)
    { $keys = ['gateway','login_mode','xingyi_username','xingyi_password','xingyi_dept_id','proxy_mode','proxy_custom','proxy_api','appsession']; $c = []; foreach ($keys as $k) $c[$k] = trim((string)($channel[$k] ?? '')); if (!in_array($c['login_mode'], ['yyb','guanjia'], true)) $c['login_mode'] = 'yyb'; if (!in_array($c['proxy_mode'], ['no_proxy','custom_proxy','api'], true)) $c['proxy_mode'] = 'no_proxy'; $c['gateway'] = rtrim($c['gateway'], '/'); return $c; }

    /** 配置页 AppSession 右侧“同步”按钮的唯一入口。 */
    static private function syncSharedAppSession($channel)
    {
        $cfg = self::getConfig($channel);
        if ($cfg['xingyi_username'] === '' || $cfg['xingyi_password'] === '') {
            return ['code' => -1, 'msg' => '请先填写星译付账号和密码并保存'];
        }
        $result = self::reuseSharedAppSession($cfg);
        if ($result['code'] !== 0) return $result;
        return [
            'code' => 0,
            'msg' => '同步成功，已复用通道 ' . (int)$result['source_channel'] . ' 的有效 AppSession',
            'appsession' => (string)$result['appsession'],
            'dept_id' => (string)($result['dept_id'] ?? ''),
        ];
    }

    /**
     * 在同插件通道中按“星译付账号 + 密码”查找共享登录态。候选
     * AppSession 必须用当前通道网络实际通过 userInfo 验证后才能复制。
     */
    static private function reuseSharedAppSession($cfg)
    {
        global $DB;
        if ($cfg['xingyi_username'] === '' || $cfg['xingyi_password'] === '') return ['code' => -1, 'msg' => '未配置星译付账号密码'];
        $rows = $DB->getAll("SELECT id,config FROM pre_channel WHERE plugin='yunxingyifuxd' ORDER BY id");
        foreach ($rows as $row) {
            $other = json_decode((string)($row['config'] ?? ''), true);
            if (!is_array($other)) continue;
            $username = trim((string)($other['xingyi_username'] ?? ''));
            $password = trim((string)($other['xingyi_password'] ?? ''));
            if (!hash_equals($cfg['xingyi_username'], $username) || !hash_equals($cfg['xingyi_password'], $password)) continue;
            $session = trim((string)($other['appsession'] ?? ''));
            if ($session === '') continue;
            $verifyConfig = $cfg;
            $verifyConfig['appsession'] = $session;
            $verify = self::xingyiRequest('POST', '/v1/system/mina/my/userInfo', null, $verifyConfig);
            if ($verify['code'] !== 0 || empty($verify['data']['userId']) || empty($verify['data']['deptId'])) continue;
            $deptId = trim((string)($other['xingyi_dept_id'] ?? $verify['data']['deptId'] ?? ''));
            return ['code' => 0, 'appsession' => $session, 'dept_id' => $deptId, 'source_channel' => (int)$row['id']];
        }
        return ['code' => -1, 'msg' => '没有找到同账号的有效 AppSession'];
    }

    /**
     * 只更新指定通道的插件配置；不清理其它已有登录数据。
     */
    static private function saveConfig($id, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yunxingyifuxd'))return \lib\AdminApp\PluginConfig::capture('yunxingyifuxd',$id,$updates);
        if(\lib\Task\PaymentMonitor::activeFor('yunxingyifuxd'))return \lib\Task\PaymentMonitor::saveCredentials($id,$updates,['appsession', 'xingyi_dept_id']);
        global $DB;
        $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$id . "' LIMIT 1");
        if (!$row || $row['plugin'] !== 'yunxingyifuxd') return false;
        $c = json_decode((string)$row['config'], true);
        if (!is_array($c)) $c = [];
        foreach ($updates as $k => $v) {
            $value = trim((string)$v);
            $c[$k] = $value;
        }
        return $DB->update('channel', ['config' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]) !== false;
    }
    static private function payload($r) { $d = is_array($r['data'] ?? null) ? $r['data'] : []; if (isset($d['data']) && is_array($d['data'])) return $d['data']; if (isset($d['result']) && is_array($d['result'])) return $d['result']; return $d; }
    static private function extract($v, $keys) { if (is_string($v)) { $j = json_decode($v, true); return is_array($j) ? self::extract($j, $keys) : ''; } if (!is_array($v)) return ''; foreach ($keys as $k) if (isset($v[$k]) && !is_array($v[$k]) && (string)$v[$k] !== '') return (string)$v[$k]; foreach ($v as $x) if (is_array($x)) { $r = self::extract($x, $keys); if ($r !== '') return $r; } return ''; }
    static private function scalar($v) { if (is_scalar($v)) return (string)$v; return self::extract($v, ['renId','id','data','value']); }
    static private function normalizeQr($v) { $v = trim((string)$v); if ($v === '' || strpos($v, 'data:image/') === 0 || preg_match('#^https?://#i', $v)) return $v; $raw = base64_decode($v, true); return $raw !== false && substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n" ? 'data:image/png;base64,' . $v : $v; }
    static private function isImageValue($v) { return strpos((string)$v, 'data:image/') === 0 || (base64_decode((string)$v, true) !== false && preg_match('/^[A-Za-z0-9+\/=]+$/', (string)$v)); }
    static public function parseOrderTime($value)
    {
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 20000000000) $timestamp = (int)floor($timestamp / 1000);
            return ($timestamp > 0 && $timestamp < 4102444800) ? $timestamp : 0;
        }
        $value = trim((string)$value);
        if ($value === '') return 0;
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }
    static public function isExpired($o) { return !empty($o['addtime']) && self::parseOrderTime($o['addtime']) + self::EXPIRE_SECONDS <= time(); }
    static public function markExpired($o) { global $DB; if ((int)($o['status'] ?? 0) !== 0) return false; $e = []; if (!empty($o['ext'])) { $x = @unserialize($o['ext']); if (is_array($x)) $e = $x; } $e['yunxingyifuxd_expired'] = 1; return $DB->update('order', ['ext' => serialize($e)], ['trade_no' => $o['trade_no']]) !== false; }
}
