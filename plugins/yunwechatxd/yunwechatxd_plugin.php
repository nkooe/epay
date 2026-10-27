<?php

/**
 * 微信新云端–XD：兼容应用宝/电脑管家登录桥，收款单/小账本两种收款方式。
 *
 * 云端地址用于登录桥配置；收款单和小账本流水分别使用对应微信接口。
 */
class yunwechatxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('yunwechatxd', $channels, $context, static function($channel){
            $config=self::getConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['gateway', 'sid', 'login_mode', 'pay_mode']);

        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\yunwechatxd\pollTarget($target,$state);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const EXPIRE_SECONDS = 300;
    const FLOAT_STEP = 0.01;
    const RECEIPT_MD = 'https://payapp.wechatpay.cn/receiptmdmgr';
    const RECEIPT_WX = 'https://payapp.wechatpay.cn/receiptwxmgr';
    const RECEIPT_SJT = 'https://payapp.wechatpay.cn/receiptsjtmgr';
    const SMALLBOOK = 'https://smallbook.wxpapp.weixin.qq.com';

    static public $info = [
        'name' => 'yunwechatxd',
        'showname' => '微信新云端–XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['wxpay'],
        'inputs' => [
            'gateway' => [
                'name' => '网关地址（完整 URL）',
                'type' => 'input',
                'note' => '例如：https://example.com',
            ],
            'login_mode' => [
                'name' => '登录方式',
                'type' => 'select',
                'options' => [
                    'yyb' => '应用宝',
                    'guanjia' => '电脑管家',
                ],
            ],
            'pay_mode' => [
                'name' => '收款方式',
                'type' => 'select',
                'options' => [
                    'receipt' => '收款单',
                    'smallbook' => '小账本',
                ],
            ],
            'sid' => [
                'name' => 'SID（登录状态）',
                'type' => 'input',
                'note' => '扫码登录后自动获取，不支持手动填写',
            ],
            'proxy_mode' => [
                'name' => '代理模式',
                'type' => 'select',
                'options' => [
                    'no_proxy' => '不使用代理',
                    'custom_proxy' => '自定义 Socks5/HTTP 代理',
                ],
            ],
            'proxy' => [
                'name' => '代理地址',
                'type' => 'input',
                'note' => '例如：socks5://127.0.0.1:1080 或 http://127.0.0.1:8080',
            ],
        ],
        'note' => '<style>form#form-info + br,form#form-info ~ br{display:none!important}#form-info{text-align:left!important;overflow:visible}#form-info #save,#form-info .yun-xd-login-action{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;vertical-align:top;margin:0 0 12px!important}#form-info #save{margin-left:4%!important}.yun-xd-login-action{text-align:center;line-height:20px;padding:10px 16px;background:#65b832;color:#fff!important;border-radius:4px;text-decoration:none}.yun-xd-login-action:hover{background:#579f2b;text-decoration:none}.yun-xd-config-note{display:block;margin-top:12px;line-height:1.8;clear:both}#form-info input[name="config[sid]"]{background:#f5f7fa;cursor:not-allowed}</style><a class="yun-xd-login-action" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,\'yun_wechat_xd_login_[channel]\',\'width=480,height=720,resizable=yes,scrollbars=yes\');return false;">扫码登录</a><span class="yun-xd-config-note">点击“扫码登录”后自动获取 SID；注意登录方式和收款方式不通用。需重新扫码登录。已有登录态只有在新扫码成功、账号信息同步并保存成功后才会替换；扫码失败、取消、关闭窗口或同步失败都会保留旧登录态。</span><script>(function(){var f=document.getElementById(\'form-info\'),a=document.querySelector(\'.yun-xd-login-action\'),s=document.getElementById(\'save\'),sid=document.querySelector(\'input[name="config[sid]"]\');if(f&&a&&s&&a.parentNode!==f){f.insertBefore(a,s);}if(sid){sid.readOnly=true;sid.setAttribute(\'aria-readonly\',\'true\');}}());(function(){var sid=document.querySelector("input[name=\'config[sid]\']"),url="./plugin_page.php?channel=[channel]&func=adminLogin&action=state";if(!sid)return;function load(){fetch(url,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.text();}).then(function(text){var doc=new DOMParser().parseFromString(text,"text/html"),box=doc.querySelector("#yun-wechat-xd-response");if(!box)throw new Error("响应格式错误");var data=JSON.parse(box.value||box.textContent||"{}");if(data.code!==0)throw new Error(data.msg||"读取失败");sid.value=data.sid||"";}).catch(function(){});}load();window.setInterval(load,5000);}());(function(){var mode=document.querySelector("select[name=\'config[proxy_mode]\']"),proxy=document.querySelector("input[name=\'config[proxy]\']");if(!mode||!proxy)return;var box=proxy.closest(".form-group");function sync(){if(box)box.style.display=mode.value==="custom_proxy"?"":"none";}mode.addEventListener("change",sync);sync();}());</script>',
        'bindwxmp' => false,
        'bindwxa' => false,
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
        global $order, $channel, $cdnpublic, $conf;

        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? ''));
        if ($paymentName === '') {
            $paymentName = '支付';
        }
        $paymentType = trim((string)($order['typename'] ?? ''));
        $paymentIcon = preg_match('/^[a-z0-9_-]+$/i', $paymentType) ? '/assets/icon/' . $paymentType . '.ico' : '';
        $title = $paymentName . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';

        $expired = self::isExpired($order);
        $code_url = '';
        $error_msg = '';
        $config = self::getConfig($channel);
        if ($order['status'] != 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif ($expired) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } elseif (self::sidModeMismatch($config)) {
            $error_msg = '登录方式或收款方式已变更，请重新扫码登录';
        } elseif (($cloud = self::checkCloudStatus($config))['code'] !== 1) {
            $error_msg = $cloud['msg'];
        } else {
            if (!self::reserveFloatingAmount($channel, $order)) {
                $error_msg = '金额分配失败，请稍后重试';
            } else {
                $result = self::createCode($channel, $order);
                if ($result['code'] == 0) {
                    $code_url = $result['qrcode'];
                } else {
                    $error_msg = $result['msg'];
                }
            }
        }

        $paytime = max(0, strtotime($order['addtime']) + self::EXPIRE_SECONDS - time());
        include PAY_ROOT . 'inc/qrcode.page.php';
        exit;
    }

    static public function notify()
    {
        return ['type' => 'error', 'msg' => '该插件使用 受管收款监控 轮询流水，不提供 HTTP 回调'];
    }

    /**
     * 后台扫码页统一通过 admin/plugin_page.php 加载，避免暴露插件目录页面。
     * action 请求仍会经过后台登录校验，响应放在隐藏 textarea 中供扫码页解析。
     */
    /** Native administrator adapter; no HTML, exit, cookies or client-selected method. */
    static public function appLogin($channel, $action, array $input = [])
    {
        if ($action === 'start') return self::beginLogin($channel);
        if ($action === 'poll') return self::pollLogin($channel, (string)($input['session_id'] ?? ''), (string)($input['appid'] ?? ''));
        return ['code' => -1, 'msg' => '不支持的登录操作'];
    }

    static public function adminLogin($channel)
    {
        $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
        if ($action === 'start') {
            self::adminLoginResponse(self::beginLogin($channel));
        }
        if ($action === 'poll') {
            $result = self::pollLogin(
                $channel,
                (string)($_GET['session_id'] ?? ''),
                (string)($_GET['appid'] ?? '')
            );
            self::adminLoginResponse($result);
        }
        if ($action === 'state') {
            $config = self::getConfig($channel);
            self::adminLoginResponse([
                'code' => 0,
                'sid' => $config['sid'],
                'aid' => $config['aid'],
                'account_type' => $config['account_type'],
                'shop_id' => $config['shop_id'],
                'sid_login_mode' => $config['sid_login_mode'],
                'sid_pay_mode' => $config['sid_pay_mode'],
            ]);
        }

        global $cdnpublic;
        $config = self::getConfig($channel);
        $channelId = (int)$channel['id'];
        $loginMode = $config['login_mode'] === 'guanjia' ? '电脑管家' : '应用宝';
        $payMode = $config['pay_mode'] === 'smallbook' ? '小账本' : '收款单';
        $sidState = $config['sid'] !== '' ? '已获取' : '未获取';
        $statusText = self::sidModeMismatch($config)
            ? '登录方式或收款方式已变更，请重新扫码登录。'
            : ($config['sid'] !== '' ? '当前 SID 已保存，可重新扫码覆盖。' : '尚未登录，请扫码确认。');
        $endpoint = './plugin_page.php?channel=' . $channelId . '&func=adminLogin';
        ?>
        <style>
        .yun-xd-login{max-width:430px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}
        .yun-xd-login h3{margin:0 0 10px;font-size:20px}.yun-xd-meta{color:#666;line-height:1.8}.yun-xd-status{min-height:24px;margin:12px 0 4px;color:#1677ff;font-weight:600}.yun-xd-status.ok{color:#16803c}.yun-xd-status.bad{color:#c62828}.yun-xd-qr{width:260px;height:260px;margin:12px auto 16px;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;color:#888;background:#fafafa}.yun-xd-qr img{max-width:240px;max-height:240px}.yun-xd-msg{min-height:24px;margin:10px 0;color:#555}.yun-xd-msg.ok{color:#16803c}.yun-xd-msg.bad{color:#c62828}.yun-xd-login button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}.yun-xd-login button:disabled{opacity:.6;cursor:wait}
        </style>
        <div class="yun-xd-login">
            <h3>微信新云端–XD扫码登录</h3>
            <div class="yun-xd-meta">通道：<?php echo htmlspecialchars((string)$channel['name'], ENT_QUOTES, 'UTF-8'); ?><br>登录方式：<?php echo $loginMode; ?><br>收款方式：<?php echo $payMode; ?><br>SID：<?php echo $sidState; ?></div>
            <div class="yun-xd-qr" id="yun-xd-qr">正在准备二维码…</div>
            <div class="yun-xd-status" id="yun-xd-status">状态：准备二维码</div>
            <div class="yun-xd-msg" id="yun-xd-msg"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></div>
            <button type="button" id="yun-xd-start">重新获取二维码</button>
        </div>
        <script src="<?php echo htmlspecialchars($cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
        <script>
        (function(){
            var endpoint=<?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES); ?>, qr=document.getElementById('yun-xd-qr'), status=document.getElementById('yun-xd-status'), msg=document.getElementById('yun-xd-msg'), start=document.getElementById('yun-xd-start'), timer=0;
            function say(text, cls){msg.className='yun-xd-msg '+(cls||'');msg.textContent=text||'';}
            function setStatus(text, cls){status.className='yun-xd-status '+(cls||'');status.textContent='状态：'+(text||'');}
            function parseResponse(text){var doc=new DOMParser().parseFromString(text,'text/html'), node=doc.querySelector('#yun-wechat-xd-response');if(!node)throw new Error('后台响应格式错误');return JSON.parse(node.value||node.textContent||'{}');}
            function draw(value, kind){qr.innerHTML='';if(kind==='image'){var img=document.createElement('img');img.alt='登录二维码';img.src=value;img.onerror=function(){qr.textContent='二维码图片加载失败，请检查网关地址';};qr.appendChild(img);return;}if(window.jQuery&&jQuery.fn.qrcode){jQuery(qr).qrcode({text:value,width:240,height:240,typeNumber:-1});}else{qr.textContent='二维码数据已返回，请更新后台静态资源';}}
            function request(url){return fetch(url,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text();}).then(parseResponse);}
            function poll(session, appid){setStatus('正在检查登录状态');request(endpoint+'&action=poll&session_id='+encodeURIComponent(session)+'&appid='+encodeURIComponent(appid)).then(function(data){if(data.code===0){setStatus('登录成功','ok');say(data.msg||'登录成功，SID 和账号信息已自动同步','ok');start.disabled=false;try{if(window.opener&&!window.opener.closed){window.opener.location.reload();}}catch(e){}return;}if(data.code===201){var scanned=(data.msg||'').indexOf('已扫码')>=0;setStatus(scanned?'已扫码，等待确认':'等待扫码');say(data.msg||'等待扫码确认');timer=setTimeout(function(){poll(session,appid);},2000);return;}setStatus('登录失败','bad');say(data.msg||'登录失败','bad');start.disabled=false;}).catch(function(){setStatus('连接重试中','bad');say('后台请求失败，正在重试');timer=setTimeout(function(){poll(session,appid);},3000);});}
            function begin(){clearTimeout(timer);start.disabled=true;setStatus('准备二维码');say('正在获取登录二维码');request(endpoint+'&action=start').then(function(data){if(data.code!==0){setStatus('获取二维码失败','bad');say(data.msg||'获取二维码失败','bad');start.disabled=false;return;}draw(data.qrcode,data.qrcode_type||'text');setStatus('等待扫码');say('请扫码并在微信中确认');poll(data.session_id,data.appid);}).catch(function(e){setStatus('获取二维码失败','bad');say(e.message||'后台请求失败','bad');start.disabled=false;});}
            start.onclick=begin;begin();
        }());
        </script>
        <?php
    }

    static private function adminLoginResponse($result)
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<textarea id="yun-wechat-xd-response" style="display:none">' . htmlspecialchars($json ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>';
        exit;
    }

    /** 开始后台扫码登录，SID 和收款账号由后续轮询自动写回通道。 */
    static public function beginLogin($channel)
    {
        $config = self::getConfig($channel);
        if ($config['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址'];
        if ($config['login_mode'] === '' || $config['pay_mode'] === '') {
            return ['code' => -1, 'msg' => '登录方式或收款方式配置无效'];
        }
        $appid = $config['pay_mode'] === 'smallbook' ? 'wx28be8489b7a36aaa' : 'wx264e9b6d4d484f51';
        if ($config['login_mode'] === 'guanjia') {
            $result = self::requestJson('POST', rtrim($config['gateway'], '/') . '/guanjia/api/qrcode', ['appid' => $appid], $config);
            $payload = self::responsePayload($result);
            if ($result['code'] != 0) return $result;
            if (empty($payload['uuid'])) return ['code' => -1, 'msg' => '电脑管家未返回登录会话'];
            $qr = self::loginQrData($config['gateway'], $payload, $config);
            if ($qr['value'] === '') return ['code' => -1, 'msg' => '电脑管家未返回登录二维码'];
            return ['code' => 0, 'session_id' => (string)$payload['uuid'], 'qrcode' => $qr['value'], 'qrcode_type' => $qr['type'], 'appid' => $appid];
        }
        $result = self::requestJson('POST', rtrim($config['gateway'], '/') . '/yyb/qr', [], $config);
        $payload = self::responsePayload($result);
        if ($result['code'] != 0) return $result;
        if (empty($payload['session_id'])) return ['code' => -1, 'msg' => '应用宝未返回登录会话'];
        $qr = self::loginQrData($config['gateway'], $payload, $config);
        if ($qr['value'] === '') return ['code' => -1, 'msg' => '应用宝未返回登录二维码'];
        return ['code' => 0, 'session_id' => (string)$payload['session_id'], 'qrcode' => $qr['value'], 'qrcode_type' => $qr['type'], 'appid' => $appid];
    }

    /** 轮询登录状态并自动换 SID、账号 ID、账号类型和店铺 ID。 */
    static public function pollLogin($channel, $sessionId, $appid)
    {
        $config = self::getConfig($channel);
        $base = rtrim($config['gateway'], '/');
        if ($sessionId === '') return ['code' => -1, 'msg' => '登录会话为空'];
        if ($config['login_mode'] === 'guanjia') {
            $result = self::requestJson('GET', $base . '/guanjia/api/status?uuid=' . rawurlencode($sessionId), null, $config);
            $payload = self::responsePayload($result);
            if ($result['code'] != 0) return $result;
            if (($payload['status'] ?? '') !== 'authorized') {
                return ['code' => 201, 'msg' => ($payload['status'] ?? '') === 'ready' ? '已扫码，请在微信确认' : '请扫描二维码登录'];
            }
            $account = $payload['result'] ?? [];
            $ref = (string)($account['ref'] ?? $account['bind_account'] ?? '');
            $code = (string)($account['code'] ?? $account['Code'] ?? '');
        } else {
            $result = self::requestJson('GET', $base . '/yyb/qr/' . rawurlencode($sessionId) . '/poll', null, $config);
            $payload = self::responsePayload($result);
            if ($result['code'] != 0) return $result;
            if (($payload['status'] ?? '') !== 'authorized') {
                return ['code' => 201, 'msg' => ($payload['status'] ?? '') === 'scanned' ? '已扫码，请在微信确认' : '请扫描二维码登录'];
            }
            $confirmed = self::requestJson('POST', $base . '/yyb/qr/' . rawurlencode($sessionId) . '/confirm', [], $config);
            $account = self::responsePayload($confirmed);
            if ($confirmed['code'] != 0) return $confirmed;
            $ref = (string)($account['openid'] ?? $account['uin'] ?? $account['id'] ?? '');
            $codeResult = self::requestJson('POST', $base . '/yyb/wxapp/getCode', ['ref' => $ref, 'app_id' => $appid], $config);
            $codePayload = self::responsePayload($codeResult);
            if ($codeResult['code'] != 0) return $codeResult;
            $code = (string)($codePayload['result']['code'] ?? $codePayload['code'] ?? $codePayload['Code'] ?? '');
        }
        if ($ref === '' || $code === '') return ['code' => -1, 'msg' => '登录桥未返回账号标识或 code'];
        $loginUrl = $appid === 'wx28be8489b7a36aaa'
            ? 'https://smallbook.wxpapp.weixin.qq.com/qrapp/user/login?js_code='
            : 'https://payapp.wechatpay.cn/receiptwxmgr/account/list?miniprogram_version=3.15.9&js_code=';
        $login = self::requestJson('GET', $loginUrl . rawurlencode($code), null, $config);
        if ($login['code'] != 0) return ['code' => -1, 'msg' => '小程序登录接口失败：' . $login['msg']];
        $sid = self::extractSid($login['data']);
        if ($sid === '') return ['code' => -1, 'msg' => '小程序登录接口未返回 SID'];
        $updates = [
            'sid' => $sid,
            'sid_login_mode' => $config['login_mode'],
            'sid_pay_mode' => $config['pay_mode'],
            'cloud_ref' => $ref,
        ];
        if ($config['pay_mode'] === 'receipt') {
            $accountResult = self::loadReceiptAccount($updates, $config);
            if ($accountResult['code'] != 0) return $accountResult;
            $updates = array_merge($updates, $accountResult['updates']);
        }
        // 旧登录态在这里之前绝不能清理或覆盖：只有新扫码、换取 SID、
        // 收款账号同步和配置写入全部成功，才一次性提交新登录态。
        // 任一步失败、取消或关闭扫码窗口，前面的 return 会保留数据库中的旧 SID。
        $saved = self::saveChannelConfig((int)$channel['id'], $updates);
        return $saved ? ['code' => 0, 'msg' => '登录成功，SID 和收款账号已自动同步', 'updates' => $updates] : ['code' => -1, 'msg' => '登录成功，但写入通道配置失败'];
    }

    static private function responsePayload($result)
    {
        if (!is_array($result) || !isset($result['data']) || !is_array($result['data'])) return [];
        $data = $result['data'];
        return isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    }

    /**
     * 云端账号状态是下单前置条件。
     *
     * 应用宝和电脑管家都由云端负责检测微信账号是否在线；本插件只请求
     * 云端账号列表，按扫码保存的 cloud_ref 找到同一账号，再读取云端 status。
     * 不用微信账单接口、SID 探针或本地通道状态推断在线，避免把其他账号的
     * 在线状态误当成当前账号在线。
     */
    static public function checkChannelStatus($channel)
    {
        return self::checkCloudStatus(self::getConfig($channel));
    }

    static private function checkCloudStatus($config)
    {
        if ($config['gateway'] === '') {
            return ['code' => -1, 'online' => false, 'msg' => '未配置网关地址，已禁止发起订单'];
        }
        if ($config['sid'] === '' || $config['cloud_ref'] === '' || $config['login_mode'] === '' || $config['pay_mode'] === '') {
            return ['code' => -1, 'online' => false, 'msg' => '尚未扫码登录云端，已禁止发起订单'];
        }
        $path = $config['login_mode'] === 'guanjia' ? '/guanjia/api/accounts' : '/yyb/accounts';
        $url = rtrim($config['gateway'], '/') . $path;
        $ref = trim((string)($config['cloud_ref'] ?? ''));
        if ($ref !== '') $url .= '?ref=' . rawurlencode($ref);
        $result = self::requestJson('GET', $url, null, $config);
        if ($result['code'] !== 0) {
            return ['code' => -1, 'online' => false, 'msg' => '云端状态检查失败，已禁止发起订单：' . $result['msg']];
        }
        $data = $result['data'];
        $accounts = self::responsePayload($result);
        if (!is_array($accounts)) $accounts = [];
        if (!$accounts) {
            return ['code' => 0, 'online' => false, 'msg' => '云端明确返回离线，请重新扫码登录', 'data' => $data];
        }
        $account = null;
        foreach ($accounts as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateRefs = $config['login_mode'] === 'guanjia'
                ? [$candidate['ref'] ?? '', $candidate['bind_account'] ?? '']
                : [$candidate['openid'] ?? '', $candidate['uin'] ?? '', $candidate['id'] ?? ''];
            $matched = false;
            foreach ($candidateRefs as $candidateRef) {
                $candidateRef = (string)$candidateRef;
                if ($candidateRef !== '' && hash_equals($ref, $candidateRef)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null) {
            return ['code' => 0, 'online' => false, 'msg' => '云端未找到当前登录账号，请重新扫码登录', 'data' => $data];
        }
        $status = strtolower(trim((string)($account['status'] ?? $account['state'] ?? '')));
        if (in_array($status, ['alive', 'online'], true)) {
            return ['code' => 1, 'online' => true, 'msg' => '云端在线', 'data' => $account];
        }
        if (in_array($status, ['offline', 'dead', 'logout', 'logged_out', 'expired'], true)) {
            return ['code' => 0, 'online' => false, 'msg' => '云端明确返回离线，请重新扫码登录', 'data' => $account];
        }
        return ['code' => -1, 'online' => false, 'msg' => '云端未返回可识别的在线状态，已禁止发起订单', 'data' => $account];
    }

    /** 微信本地接口的鉴权错误不等于云端账号离线，需要重新换取对应小程序 SID。 */
    static private function sidInvalidText($value)
    {
        $text = strtolower(trim((string)$value));
        foreach (['用户鉴权错误', '用户登录态鉴权失败', '鉴权失败', '校验登录态失败', '效验登录态失败', '校验失败', '效验失败', '登录态失效', '登录态无效', '登录失败', '会话失效', 'session invalid', 'invalid sid'] as $needle) {
            if (strpos($text, strtolower($needle)) !== false) return true;
        }
        return false;
    }

    static private function extractValue($data, $keys)
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? self::extractValue($decoded, $keys) : '';
        }
        if (!is_array($data)) return '';
        foreach ($keys as $key) {
            if (isset($data[$key]) && !is_array($data[$key]) && (string)$data[$key] !== '') return (string)$data[$key];
        }
        foreach (['data', 'result', 'account', 'user'] as $child) {
            if (isset($data[$child])) {
                $value = self::extractValue($data[$child], $keys);
                if ($value !== '') return $value;
            }
        }
        return '';
    }

    static private function cloudCode($config, $ref, $appid)
    {
        $base = rtrim($config['gateway'], '/');
        if ($config['login_mode'] === 'guanjia') {
            $result = self::requestJson('POST', $base . '/guanjia/api/get-code', ['ref' => $ref, 'appid' => $appid], $config);
        } else {
            $result = self::requestJson('POST', $base . '/yyb/wxapp/getCode', ['ref' => $ref, 'app_id' => $appid], $config);
        }
        if ($result['code'] != 0) return ['code' => -1, 'msg' => '云端获取小程序 code 失败：' . $result['msg']];
        $code = self::extractValue(self::responsePayload($result), ['code', 'js_code', 'jscode', 'Code']);
        return $code !== '' ? ['code' => 0, 'value' => $code] : ['code' => -1, 'msg' => '云端未返回小程序登录 code'];
    }

    /**
     * 刷新当前登录方式和收款方式对应的 SID。
     * 旧 SID 只有在新 SID、收款账号同步和配置写入全部成功后才会被替换。
     */
    static private function refreshSid($channel, $config)
    {
        $ref = trim((string)$config['cloud_ref']);
        if ($ref === '') return ['code' => -1, 'msg' => '缺少云端账号标识，无法刷新 SID'];
        $appid = $config['pay_mode'] === 'smallbook' ? 'wx28be8489b7a36aaa' : 'wx264e9b6d4d484f51';
        $code = self::cloudCode($config, $ref, $appid);
        if ($code['code'] != 0) return $code;
        $loginUrl = $appid === 'wx28be8489b7a36aaa'
            ? self::SMALLBOOK . '/qrapp/user/login?js_code='
            : self::RECEIPT_WX . '/account/list?miniprogram_version=3.15.9&js_code=';
        $login = self::requestJson('GET', $loginUrl . rawurlencode($code['value']), null, $config);
        if ($login['code'] != 0) return ['code' => -1, 'msg' => '刷新 SID 失败：' . $login['msg']];
        $sid = self::extractSid($login['data']);
        if ($sid === '') return ['code' => -1, 'msg' => '刷新 SID 失败：接口未返回 SID'];
        $updates = [
            'sid' => $sid,
            'sid_login_mode' => $config['login_mode'],
            'sid_pay_mode' => $config['pay_mode'],
            'cloud_ref' => $ref,
        ];
        if ($config['pay_mode'] === 'receipt') {
            $account = self::loadReceiptAccount($updates, $config);
            if ($account['code'] != 0) return ['code' => -1, 'msg' => '刷新 SID 后同步收款账号失败：' . $account['msg']];
            $updates = array_merge($updates, $account['updates']);
        }
        if (!self::saveChannelConfig((int)$channel['id'], $updates)) return ['code' => -1, 'msg' => '刷新 SID 成功，但写入通道配置失败'];
        return ['code' => 0, 'updates' => $updates, 'msg' => ''];
    }

    static private function extractSid($data)
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? self::extractSid($decoded) : '';
        }
        if (!is_array($data)) return '';
        foreach (['sid', 'Sid', 'receipt_sid', 'receiptSid'] as $key) {
            if (!empty($data[$key])) return (string)$data[$key];
        }
        foreach (['data', 'result'] as $key) {
            if (isset($data[$key])) {
                $sid = self::extractSid($data[$key]);
                if ($sid !== '') return $sid;
            }
        }
        return '';
    }

    static private function loadReceiptAccount(&$updates, $config)
    {
        $result = self::requestJson('GET', self::RECEIPT_WX . '/account/list?' . http_build_query(['miniprogram_version' => '3.15.10', 'sid' => $updates['sid']]), null, $config);
        if ($result['code'] != 0) return $result;
        $accounts = self::responsePayload($result)['account_list'] ?? [];
        foreach ($accounts as $account) {
            if (($account['account_status'] ?? '') !== 'ACTIVATED') continue;
            $updates['aid'] = (string)($account['account_id'] ?? '');
            $updates['account_type'] = (string)($account['account_type'] ?? '');
            if ($updates['aid'] === '' || $updates['account_type'] === '') continue;
            $shop = self::requestJson('GET', self::receiptGateway($updates['account_type']) . '/account/get?' . http_build_query([
                'miniprogram_version' => '3.15.10', 'account_id' => $updates['aid'], 'account_type' => $updates['account_type'], 'sid' => $updates['sid'],
            ]), null, $config);
            if ($shop['code'] == 0) {
                $shops = self::responsePayload($shop)['auth_shop_list'] ?? [];
                if (!empty($shops[0]['shop_id'])) $updates['shop_id'] = (string)$shops[0]['shop_id'];
            }
            return ['code' => 0, 'updates' => $updates];
        }
        return ['code' => -1, 'msg' => '登录成功，但未找到已激活的收款账号'];
    }

    static private function saveChannelConfig($channelId, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yunwechatxd'))return \lib\AdminApp\PluginConfig::capture('yunwechatxd',$channelId,$updates);
        if(\lib\Task\PaymentMonitor::activeFor('yunwechatxd'))return \lib\Task\PaymentMonitor::saveCredentials($channelId,$updates,['sid', 'sid_login_mode', 'sid_pay_mode', 'cloud_ref', 'aid', 'account_type', 'shop_id']);
        global $DB;
        $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$channelId . "' LIMIT 1");
        if (!$row || $row['plugin'] !== 'yunwechatxd') return false;
        $config = json_decode($row['config'], true);
        if (!is_array($config)) $config = [];
        foreach ($updates as $key => $value) $config[$key] = (string)$value;
        return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }

    static public function createCode($channel, $order)
    {
        $config = self::getConfig($channel);
        if ($config['login_mode'] === '' || $config['pay_mode'] === '') {
            return ['code' => -1, 'msg' => '登录方式或收款方式配置无效'];
        }
        if ($config['sid'] === '') {
            return ['code' => -1, 'msg' => '未配置 SID'];
        }
        if (self::sidModeMismatch($config)) {
            return ['code' => -1, 'msg' => '登录方式或收款方式已变更，请重新扫码登录'];
        }
        if ($config['gateway'] === '') {
            return ['code' => -1, 'msg' => '未配置网关地址'];
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = $config['pay_mode'] === 'smallbook'
                ? self::createSmallbookCode($config)
                : self::createReceiptCode($config, $order);
            if ($result['code'] == 0) return $result;
            if ($attempt !== 1 || !self::sidInvalidText($result['msg'])) return $result;
            $refresh = self::refreshSid($channel, $config);
            if ($refresh['code'] != 0) {
                return ['code' => -1, 'msg' => $result['msg'] . '；' . $refresh['msg']];
            }
            $config = array_merge($config, $refresh['updates']);
        }
        return ['code' => -1, 'msg' => '创建支付二维码失败'];
    }

    /** 为相同基础金额的并行订单分配唯一金额，过期订单不参与占用。 */
    static private function reserveFloatingAmount($channel, &$order)
    {
        global $DB;
        $base = round((float)($order['money'] ?? $order['realmoney'] ?? 0), 2);
        if ($base <= 0 || empty($order['trade_no'])) {
            return false;
        }
        $ext = [];
        if (!empty($order['ext'])) {
            $decoded = @unserialize($order['ext']);
            if (is_array($decoded)) $ext = $decoded;
        }
        if (isset($ext['yunwechatxd_float_amount'])) {
            $assigned = round((float)$ext['yunwechatxd_float_amount'], 2);
            if ($assigned > 0) {
                $order['realmoney'] = number_format($assigned, 2, '.', '');
                return true;
            }
        }
        $channelId = (int)($channel['id'] ?? 0);
        $subchannelId = (int)($order['subchannel'] ?? $channel['subid'] ?? 0);
        $tradeNo = daddslashes((string)$order['trade_no']);
        $lockName = 'yunwechatxd_float_' . $channelId . '_' . $subchannelId . '_' . number_format($base, 2, '.', '_');
        $locked = (int)$DB->getColumn("SELECT GET_LOCK(:lock_name, 5)", [':lock_name' => $lockName]);
        if ($locked !== 1) {
            return false;
        }
        try {
            $rows = $DB->getAll("SELECT trade_no,realmoney,ext FROM pre_order WHERE channel='" . $channelId . "' AND subchannel='" . $subchannelId . "' AND status=0 AND trade_no<>'" . $tradeNo . "' AND addtime >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%yun_wechat_xd_expired%')");
            $used = [];
            foreach ($rows as $row) {
                $rowExt = !empty($row['ext']) ? @unserialize($row['ext']) : [];
                $rowBase = is_array($rowExt) && isset($rowExt['yunwechatxd_float_base'])
                    ? round((float)$rowExt['yunwechatxd_float_base'], 2)
                    : round((float)$row['realmoney'], 2);
                if (abs($rowBase - $base) > 0.001) continue;
                $amount = is_array($rowExt) && isset($rowExt['yunwechatxd_float_amount'])
                    ? round((float)$rowExt['yunwechatxd_float_amount'], 2)
                    : round((float)$row['realmoney'], 2);
                $used[number_format($amount, 2, '.', '')] = true;
            }
            $assigned = $base;
            while (isset($used[number_format($assigned, 2, '.', '')])) {
                $assigned = round($assigned + self::FLOAT_STEP, 2);
            }
            $ext['yunwechatxd_float_base'] = $base;
            $ext['yunwechatxd_float_amount'] = $assigned;
            $oldRealMoney = (float)($order['realmoney'] ?? $base);
            $order['realmoney'] = number_format($assigned, 2, '.', '');
            $delta = round($assigned - $oldRealMoney, 2);
            $data = ['realmoney' => $order['realmoney'], 'ext' => serialize($ext)];
            if (isset($order['getmoney'])) {
                $data['getmoney'] = number_format(max(0, (float)$order['getmoney'] + $delta), 2, '.', '');
                $order['getmoney'] = $data['getmoney'];
            }
            if ($DB->update('order', $data, ['trade_no' => $order['trade_no']]) === false) {
                return false;
            }
            return true;
        } finally {
            $DB->getColumn("SELECT RELEASE_LOCK(:lock_name)", [':lock_name' => $lockName]);
        }
    }

    static private function createReceiptCode($config, $order)
    {
        if ($config['aid'] === '' || $config['account_type'] === '') {
            return ['code' => -1, 'msg' => '收款单需要配置收款账号 ID 和账号类型'];
        }
        $gateway = self::receiptGateway($config['account_type']);
        if (!$gateway) {
            return ['code' => -1, 'msg' => '账号类型只能是 1、2 或 3'];
        }
        $fee = (int)round((float)$order['realmoney'] * 100);
        if ($fee <= 0) {
            return ['code' => -1, 'msg' => '收款金额无效'];
        }
        $common = [
            'miniprogram_version' => '3.15.9',
            'account_id' => $config['aid'],
            'account_type' => $config['account_type'],
            'sid' => $config['sid'],
        ];
        if ($config['account_type'] === '3') {
            $body = [
                'miniprogram_version' => '3.15.9',
                'fee' => $fee,
                'remark' => $order['trade_no'],
                'remark_pic_urls' => '',
                'option_list' => [],
                'receipt_item_list' => [],
                'sid' => $config['sid'],
            ];
            if ($config['shop_id'] !== '') {
                $body['shop_id'] = (int) $config['shop_id'];
            }
            $create = self::requestJson('POST', $gateway . '/receipt/create?' . http_build_query($common), $body, $config);
        } else {
            $query = $common + [
                'remark_pic_urls' => '',
                'option_list' => '[]',
                'remark' => $order['trade_no'],
                'fee' => $fee,
            ];
            if ($config['shop_id'] !== '') {
                $query['shop_id'] = $config['shop_id'];
            }
            $create = self::requestJson('GET', $gateway . '/receipt/create?' . http_build_query($query), null, $config);
        }
        if ($create['code'] != 0) {
            return $create;
        }
        $receipt = $create['data']['data']['receipt'] ?? $create['data']['data']['receipt_info'] ?? null;
        $receipt_id = is_array($receipt) ? ($receipt['receipt_id'] ?? '') : '';
        if ($receipt_id === '') {
            return ['code' => -1, 'msg' => '收款单接口未返回 receipt_id'];
        }
        $query = [
            'miniprogram_version' => '3.15.10',
            'wxacode_path_type' => 1,
            'receipt_id' => $receipt_id,
            'account_id' => $config['aid'],
            'account_type' => $config['account_type'],
            'sid' => $config['sid'],
        ];
        $qr = self::requestJson('GET', $gateway . '/receipt/getwxacode?' . http_build_query($query), null, $config);
        if ($qr['code'] != 0) {
            return $qr;
        }
        $qrcode = $qr['data']['data']['qrcode'] ?? '';
        if ($qrcode === '') {
            return ['code' => -1, 'msg' => '收款单接口未返回二维码'];
        }
        return ['code' => 0, 'qrcode' => self::normalizeQr($qrcode), 'receipt_id' => $receipt_id];
    }

    static private function createSmallbookCode($config)
    {
        $url = self::SMALLBOOK . '/qrappsl/profile/getpayqrcode?' . http_build_query([
            'sid' => $config['sid'],
            'v' => '6.23.1',
        ]);
        $result = self::requestJson('GET', $url, null, $config);
        if ($result['code'] != 0) {
            return $result;
        }
        $qrcode = $result['data']['data']['qrcode_content'] ?? $result['data']['data']['qrcode'] ?? '';
        if ($qrcode === '') {
            return ['code' => -1, 'msg' => '小账本接口未返回二维码'];
        }
        return ['code' => 0, 'qrcode' => self::normalizeQr($qrcode)];
    }

    static public function getFlows($channel)
    {
        $config = self::getConfig($channel);
        if ($config['login_mode'] === '' || $config['pay_mode'] === '') {
            return ['code' => -1, 'msg' => '登录方式或收款方式配置无效', 'items' => []];
        }
        if ($config['sid'] === '') {
            return ['code' => -1, 'msg' => '未配置 SID', 'items' => []];
        }
        if (self::sidModeMismatch($config)) {
            return ['code' => -1, 'msg' => '登录方式或收款方式已变更，请重新扫码登录', 'items' => []];
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($config['pay_mode'] === 'smallbook') {
                $now = time();
                $body = [
                    'v' => '5.132.6', 'sid' => $config['sid'],
                    'start_time' => $now - ($now % 3600), 'end_time' => $now,
                    'page_size' => 10, 'sort' => 'desc', 'is_first' => true,
                    'last_create_time' => 0, 'last_id' => '',
                ];
                $url = self::SMALLBOOK . '/qrappzd/user/incomelistflexible?sid=' . rawurlencode($config['sid']) . '&v=5.132.6';
                $result = self::requestJson('POST', $url, $body, $config);
                if ($result['code'] == 0) return ['code' => 0, 'msg' => '', 'items' => $result['data']['data']['data_list'] ?? []];
            } else {
                $gateway = self::receiptGateway($config['account_type']);
                if (!$gateway || $config['aid'] === '') return ['code' => -1, 'msg' => '收款单账号配置不完整', 'items' => []];
                $url = $gateway . '/receipt/list?' . http_build_query([
                    'account_type' => $config['account_type'],
                    'account_id' => $config['aid'],
                    'sid' => $config['sid'],
                ]);
                $result = self::requestJson('POST', $url, [
                    'miniprogram_version' => '3.15.10', 'start_time' => 0, 'end_time' => 0,
                    'page_size' => 10, 'state' => [], 'shop_id_list' => [], 'sid' => $config['sid'],
                ], $config);
                if ($result['code'] == 0) return ['code' => 0, 'msg' => '', 'items' => $result['data']['data']['receipt'] ?? []];
            }
            if ($attempt !== 1 || !self::sidInvalidText($result['msg'])) {
                return ['code' => $result['code'], 'msg' => $result['msg'], 'items' => []];
            }
            $refresh = self::refreshSid($channel, $config);
            if ($refresh['code'] != 0) return ['code' => -1, 'msg' => $result['msg'] . '；' . $refresh['msg'], 'items' => []];
            $config = array_merge($config, $refresh['updates']);
        }
        return ['code' => -1, 'msg' => '查询流水失败', 'items' => []];
    }

    static public function flowValues($item, $smallbook = false)
    {
        $rawTime = $item['timestamp'] ?? $item['create_time'] ?? $item['created_at'] ?? $item['time'] ?? 0;
        if (is_numeric($rawTime)) {
            $flowTime = (int)$rawTime;
            if ($flowTime > 20000000000) {
                $flowTime = (int)floor($flowTime / 1000);
            }
        } else {
            $flowTime = $rawTime !== '' ? (int)strtotime((string)$rawTime) : 0;
        }
        if ($smallbook) {
            return [
                'id' => (string)($item['trans_id'] ?? $item['id_v3'] ?? $item['id'] ?? ''),
                'amount' => (float)($item['fee'] ?? $item['money'] ?? 0),
                'remark' => (string)($item['payer_remark'] ?? $item['remark'] ?? ''),
                'buyer' => (string)($item['payer_user_name'] ?? ''),
                'time' => $flowTime,
            ];
        }
        return [
            'id' => (string)($item['receipt_id'] ?? $item['trans_id'] ?? $item['id_v3'] ?? $item['id'] ?? ''),
            'amount' => (float)($item['fee'] ?? $item['money'] ?? 0),
            'remark' => (string)($item['remark'] ?? $item['payer_remark'] ?? ''),
            'buyer' => (string)($item['payer_nick_name'] ?? $item['payer_user_name'] ?? ''),
            'time' => $flowTime,
        ];
    }

    static public function markExpired($order)
    {
        global $DB;
        if (($order['status'] ?? 0) != 0) {
            return false;
        }
        $ext = [];
        if (!empty($order['ext'])) {
            $decoded = @unserialize($order['ext']);
            if (is_array($decoded)) {
                $ext = $decoded;
            }
        }
        if (!empty($ext['yun_wechat_xd_expired'])) {
            return false;
        }
        $ext['yun_wechat_xd_expired'] = 1;
        $ext['yun_wechat_xd_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static public function isExpired($order)
    {
        if (!empty($order['ext'])) {
            $ext = @unserialize($order['ext']);
            if (is_array($ext) && !empty($ext['yun_wechat_xd_expired'])) {
                return true;
            }
        }
        return !empty($order['addtime']) && strtotime($order['addtime']) + self::EXPIRE_SECONDS <= time();
    }

    static public function requestJson($method, $url, $body, $config)
    {
        $referer = (($config['pay_mode'] ?? 'receipt') === 'smallbook')
            ? 'https://servicewechat.com/wx28be8489b7a36aaa/1181/page-frame.html'
            : 'https://servicewechat.com/wx264e9b6d4d484f51/187/page-frame.html';
        $headers = [
            'Accept: application/json, */*',
            'Content-Type: application/json',
            'xweb_xhr: 1',
            'Referer: ' . $referer,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122 Safari/537.36 MicroMessenger/7.0.20.1781',
        ];
        // 代理头只交给云端网关；本机直请求微信时不携带任何代理配置。
        if (self::isGatewayUrl($url, $config)) {
            try {
                foreach (self::proxyClusterHeaders($config) as $name => $value) {
                    $headers[] = $name . ': ' . $value;
                }
            } catch (\Throwable $e) {
                return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []];
            }
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $retryable = (bool)preg_match('#/(?:guanjia/api/qrcode|guanjia/api/status|yyb/qr(?:/.*)?|yyb/wxapp/getCode)$#', $path);
        $raw = false;
        $error = '';
        $status = 0;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                // 代理由云端按 X-Proxy-Cluster-* 头建立；本机不允许环境变量
                // 或 php.ini 的代理设置偷偷改变到云端网关的连接路径。
                CURLOPT_NOPROXY => '*',
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if (strtoupper($method) === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$retryable || !in_array($status, [502, 503, 504], true) || $attempt >= 2) {
                break;
            }
            usleep(250000);
        }
        if ($raw === false || $error !== '') {
            return ['code' => -1, 'msg' => '请求接口失败：' . $error, 'data' => []];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $bodyPreview = trim((string)preg_replace('/\s+/', ' ', (string)$raw));
            if (function_exists('mb_substr')) {
                $bodyPreview = mb_substr($bodyPreview, 0, 160, 'UTF-8');
            } else {
                $bodyPreview = substr($bodyPreview, 0, 160);
            }
            $displayPath = $path !== '' ? $path : $url;
            return ['code' => -1, 'msg' => '接口返回不是 JSON（HTTP ' . $status . '，' . $displayPath . '）' . ($bodyPreview !== '' ? '：' . $bodyPreview : ''), 'data' => []];
        }
        $statusKey = null;
        foreach (['errcode', 'retcode', 'code', 'ok'] as $key) {
            if (array_key_exists($key, $data)) {
                $statusKey = $key;
                break;
            }
        }
        if ($statusKey === null) {
            return ['code' => -1, 'msg' => '接口响应缺少状态码（HTTP ' . $status . '）', 'data' => $data];
        }
        $statusValue = $data[$statusKey];
        if ($statusKey === 'ok') {
            $success = $statusValue === true || $statusValue === 1 || $statusValue === '1' || strtolower((string)$statusValue) === 'true';
        } elseif (is_numeric($statusValue)) {
            $success = (int)$statusValue === 0 || (int)$statusValue === 200;
        } else {
            $success = in_array(strtolower(trim((string)$statusValue)), ['ok', 'success', 'succeed'], true);
        }
        if ($status < 200 || $status >= 300 || !$success) {
            return ['code' => -1, 'msg' => (string)($data['msg'] ?? $data['errmsg'] ?? $data['message'] ?? $data['error'] ?? ('接口错误（HTTP ' . $status . '）')), 'data' => $data];
        }
        return ['code' => 0, 'msg' => '', 'data' => $data];
    }

    /**
     * 云端代理转发头。代理由云端网关使用，PHP 本地不建立 SOCKS 连接。
     */
    static private function isGatewayUrl($url, $config)
    {
        $urlParts = parse_url((string)$url);
        $gatewayParts = parse_url((string)($config['gateway'] ?? ''));
        if (!is_array($urlParts) || !is_array($gatewayParts) || empty($urlParts['host']) || empty($gatewayParts['host'])) return false;
        if (strcasecmp((string)$urlParts['host'], (string)$gatewayParts['host']) !== 0) return false;
        $urlPort = isset($urlParts['port']) ? (int)$urlParts['port'] : (($urlParts['scheme'] ?? '') === 'http' ? 80 : 443);
        $gatewayPort = isset($gatewayParts['port']) ? (int)$gatewayParts['port'] : (($gatewayParts['scheme'] ?? '') === 'http' ? 80 : 443);
        return $urlPort === $gatewayPort;
    }

    static private function proxyClusterHeaders($config)
    {
        $mode = strtolower(trim((string)($config['proxy_mode'] ?? 'no_proxy')));
        $proxy = trim((string)($config['proxy'] ?? ''));
        if (in_array($mode, ['', 'no_proxy', 'none', '0'], true)) {
            return [];
        }
        if ($mode !== 'custom_proxy') {
            throw new \InvalidArgumentException('代理模式无效，已拒绝直连云端');
        }
        if ($proxy === '') {
            throw new \InvalidArgumentException('代理模式已开启，但未填写代理地址，已拒绝直连云端');
        }
        if (strpos($proxy, '://') === false) {
            $proxy = 'socks5://' . $proxy;
        }
        $parts = parse_url($proxy);
        if (!$parts || empty($parts['host'])) {
            throw new \InvalidArgumentException('代理地址格式无效，已拒绝直连云端');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'socks5'));
        if (!in_array($scheme, ['socks5', 'socks5h', 'http', 'https'], true)) {
            throw new \InvalidArgumentException('代理协议仅支持 socks5、socks5h、http 或 https，已拒绝直连云端');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 0;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('代理地址必须包含 1-65535 的端口，已拒绝直连云端');
        }
        if (!empty($parts['path']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new \InvalidArgumentException('代理地址不能包含路径、查询参数或片段，已拒绝直连云端');
        }
        $address = $parts['host'];
        if (strpos($address, ':') !== false && $address[0] !== '[') $address = '[' . $address . ']';
        $address .= ':' . $port;
        return [
            'X-Proxy-Cluster-URL' => $address,
            'X-Proxy-Cluster-Type' => $scheme,
            'X-Proxy-Cluster-User' => rawurldecode((string)($parts['user'] ?? '')),
            'X-Proxy-Cluster-Pass' => rawurldecode((string)($parts['pass'] ?? '')),
        ];
    }

    static private function normalizeQr($qrcode)
    {
        if (strpos($qrcode, 'data:image/') === 0 || filter_var($qrcode, FILTER_VALIDATE_URL)) {
            return $qrcode;
        }
        $raw = base64_decode($qrcode, true);
        if ($raw !== false && strlen($raw) > 8) {
            if (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n") {
                return 'data:image/png;base64,' . $qrcode;
            }
            if (substr($raw, 0, 3) === "\xff\xd8\xff") {
                return 'data:image/jpeg;base64,' . $qrcode;
            }
        }
        return $qrcode;
    }

    /** 云端登录桥可能返回图片地址，也可能只返回微信确认地址。 */
    static private function loginQrData($gateway, $payload, $config)
    {
        foreach (['image', 'image_base64', 'image_url', 'qrcode_image'] as $key) {
            if (!isset($payload[$key]) || !is_string($payload[$key]) || trim($payload[$key]) === '') {
                continue;
            }
            $value = trim($payload[$key]);
            if ($key === 'image_url' && (strpos($value, '/') === 0 || strpos($value, '//') === 0)) {
                $value = self::resolveGatewayUrl($gateway, $value);
            }
            if ($key === 'image_url' || $key === 'qrcode_image') {
                $image = self::requestBinary($value, $config);
                if ($image['code'] === 0) {
                    return ['value' => $image['data'], 'type' => 'image'];
                }
                continue;
            }
            $value = self::normalizeQr($value);
            return ['value' => $value, 'type' => 'image'];
        }
        $text = isset($payload['qrcode']) ? trim((string)$payload['qrcode']) : '';
        return ['value' => $text, 'type' => 'text'];
    }

    static private function resolveGatewayUrl($gateway, $path)
    {
        if (strpos($path, '//') === 0) {
            $scheme = parse_url($gateway, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $path;
        }
        $parts = parse_url($gateway);
        if (strpos($path, '/') === 0 && is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $origin .= ':' . (int)$parts['port'];
            }
            return $origin . $path;
        }
        return rtrim($gateway, '/') . '/' . ltrim($path, '/');
    }

    /** 通过同一代理拉取登录二维码图片，避免浏览器绕过服务器代理直连云端。 */
    static private function requestBinary($url, $config)
    {
        $ch = curl_init($url);
        $headers = ['Accept: image/*,*/*;q=0.8', 'User-Agent: Mozilla/5.0'];
        try {
            foreach (self::proxyClusterHeaders($config) as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
        } catch (\Throwable $e) {
            curl_close($ch);
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOPROXY => '*',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($raw === false || $error !== '') {
            return ['code' => -1, 'msg' => '二维码图片请求失败：' . $error];
        }
        if ($status < 200 || $status >= 300 || $raw === '') {
            return ['code' => -1, 'msg' => '二维码图片请求失败（HTTP ' . $status . '）'];
        }
        if (strpos($contentType, 'image/') !== 0) {
            return ['code' => -1, 'msg' => '二维码接口未返回图片'];
        }
        return ['code' => 0, 'data' => 'data:' . $contentType . ';base64,' . base64_encode($raw)];
    }

    static private function receiptGateway($accountType)
    {
        switch ((string)$accountType) {
            case '1': return self::RECEIPT_MD;
            case '2': return self::RECEIPT_WX;
            case '3': return self::RECEIPT_SJT;
        }
        return null;
    }

    static private function getConfig($channel)
    {
        $keys = ['gateway','login_mode','pay_mode','sid','sid_login_mode','sid_pay_mode','cloud_ref','aid','account_type','shop_id','proxy_mode','proxy'];
        $config = [];
        foreach ($keys as $key) {
            $config[$key] = trim((string)($channel[$key] ?? ''));
        }
        if (!in_array($config['login_mode'], ['yyb', 'guanjia'], true)) $config['login_mode'] = '';
        if (!in_array($config['pay_mode'], ['receipt', 'smallbook'], true)) $config['pay_mode'] = '';
        if ($config['proxy_mode'] === '') $config['proxy_mode'] = 'no_proxy';
        return $config;
    }

    static private function sidModeMismatch($config)
    {
        if (empty($config['sid'])) {
            return false;
        }
        return ($config['sid_login_mode'] !== '' && $config['sid_login_mode'] !== $config['login_mode'])
            || ($config['sid_pay_mode'] !== '' && $config['sid_pay_mode'] !== $config['pay_mode']);
    }
}
