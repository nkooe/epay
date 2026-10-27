<?php

/**
 * 收钱吧商户服务平台适配器。
 * 门店码 ZIP 和图片只在内存中处理，最终只保存二维码文本和终端元数据。
 */
class shouqianba_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('shouqianba', $channels, $context, static function($channel){
            $config=\EpayMonitor\shouqianba\getSqbConfig($channel);
            \lib\Task\PaymentMonitor::requireFields($config,['token','store_sn','terminal_sn','merchant_info']);
            $merchant=json_decode($config['merchant_info'],true);
            if(!is_array($merchant)||(string)($merchant['selected_store_sn']??'')!==$config['store_sn']||(string)($merchant['selected_terminal_sn']??'')!==$config['terminal_sn'])throw new \lib\Task\ServiceFault('请先完成门店终端绑定');
        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\shouqianba\pollTarget($target);
                \lib\Task\PaymentMonitor::progress();
            }
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    const HOST = 'https://web-platforms-msp.shouqianba.com';
    const IMAGE_HOST = 'https://images.wosaimg.com/';
    const QR_DECODE_URL = 'https://api.2dcode.biz/v1/read-qr-code';
    const CLIENT_VERSION = '7.0.0';
    const EXPIRE_SECONDS = 300;
    const MATCH_SECONDS = 480;
    const FLOAT_STEP = 0.01;
    const MAX_ZIP_BYTES = 26214400;
    const MAX_ZIP_ENTRY_BYTES = 8388608;
    const MAX_QR_IMAGE_BYTES = 5242880;
    const MAX_QR_IMAGES = 200;
    const ADMIN_NOTE = <<<'HTML'
<style>
#form-info{position:relative;overflow:visible;padding-bottom:18px}
#form-info .sqb-modal-spacer{height:8px}
#form-info .sqb-password-line{display:flex;align-items:center;gap:10px;width:100%}
#form-info .sqb-password-line input{flex:1 1 auto;min-width:0;width:auto!important}
#form-info #sqb-sync{flex:0 0 78px;width:78px;min-height:40px;margin:0;white-space:nowrap}
#form-info .sqb-action-row{display:flex;align-items:center;gap:12px;width:100%;margin-top:14px}
#form-info .sqb-action-row #sqb-store-sync,#form-info .sqb-action-row #save{display:block!important;float:none!important;box-sizing:border-box;flex:1 1 0;width:auto!important;min-height:44px;margin:0!important}
#form-info input[name="config[token]"],#form-info input[name="config[store_id]"],#form-info input[name="config[terminal_id]"],#form-info input[name="config[terminal_device_fingerprint]"],#form-info input[name="config[login_checked_at]"],#form-info textarea[name="config[store_list]"],#form-info textarea[name="config[terminal_list]"],#form-info textarea[name="config[merchant_info]"]{background:#f5f7fa}
#form-info textarea[name="config[store_list]"],#form-info textarea[name="config[terminal_list]"],#form-info textarea[name="config[merchant_info]"]{display:none}
#sqb-config-note{display:block;margin-top:12px;line-height:1.8;color:#008000}
#sqb-status{color:#008000}
@media (max-width:480px){#form-info .sqb-password-line{gap:8px}#form-info #sqb-sync{flex-basis:72px;width:72px;padding-left:8px;padding-right:8px}#form-info .sqb-action-row{gap:8px}}
</style>
<span id="sqb-config-note"><strong>登录状态：</strong><span id="sqb-status">登录态失效</span></span>
<script>
(function(){
    var form=document.getElementById('form-info');
    if(!form)return;
    var layerContent=form.closest?form.closest('.layui-layer-content'):null;
    var layerBox=form.closest?form.closest('.layui-layer'):null;
    if(layerContent){layerContent.style.maxHeight='calc(100vh - 92px)';layerContent.style.height='auto';layerContent.style.overflowY='auto'}
    if(layerBox){layerBox.style.height='auto';layerBox.style.maxHeight='calc(100vh - 42px)'}
    var query=function(name){return form.querySelector('[name="config['+name+']"]')};
    var user=query('username'),pass=query('password'),token=query('token'),status=document.getElementById('sqb-status');
    var endpoint='./plugin_page.php?channel=[channel]&func=adminLogin';
    if(pass)pass.type='password';
    ['token','store_id','terminal_sn','terminal_id','terminal_device_fingerprint','store_list','terminal_list','merchant_info','login_checked_at'].forEach(function(name){var el=query(name),group=el&&el.closest?el.closest('.form-group'):null;if(group)group.style.display='none'});
    function post(action,data){data=data||{};data.action=action;return fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(data)}).then(function(r){return r.text()}).then(function(t){var box=document.createElement('div');box.innerHTML=t;var node=box.querySelector('#sqb-response');if(!node)throw Error('后台响应格式错误');return JSON.parse(node.value||node.textContent||'{}')})}
    function say(ok){if(status){status.textContent=ok?'登录态正常':'登录态失效';status.style.color=ok?'#16803c':'#c62828'}}
    var sync=document.createElement('button');sync.type='button';sync.id='sqb-sync';sync.className='btn btn-success';sync.textContent='同步';
    sync.onclick=function(){
        sync.disabled=true;
        post('sync',{username:user?user.value:'',password:pass?pass.value:''}).then(function(data){if(data.code!==0)throw Error(data.msg||'同步失败');if(token)token.value=data.token||token.value;say(true)}).catch(function(){say(false)}).then(function(){sync.disabled=false})
    };
    var passGroup=pass&&pass.closest?pass.closest('.form-group'):null;
    if(passGroup){var line=document.createElement('div');line.className='sqb-password-line';passGroup.insertBefore(line,pass);line.appendChild(pass);line.appendChild(sync)}
    else if(pass&&pass.parentNode)pass.parentNode.appendChild(sync);
    var save=form.querySelector('#save');
    if(save){
        var actions=document.createElement('div');actions.className='sqb-action-row';
        var storeSync=document.createElement('button');storeSync.type='button';storeSync.id='sqb-store-sync';storeSync.className='btn btn-info';storeSync.textContent='同步门店';
        storeSync.onclick=function(){window.open(endpoint+'&action=select','shouqianba_select','width=760,height=720,resizable=yes,scrollbars=yes')};
        save.parentNode.insertBefore(actions,save);actions.appendChild(storeSync);actions.appendChild(save)
    }
    post('state').then(function(data){if(data.code===0){if(token)token.value=data.token||'';var store=query('store_sn');if(store)store.value=data.store_sn||'';if(typeof data.live==='boolean')say(data.live)}else{say(false)}}).catch(function(){});
})();
</script>
HTML;

    static public $info = [
        'name' => 'shouqianba',
        'showname' => '收钱吧门店终端码',
        'author' => 'Coco',
        'link' => 'https://s.shouqianba.com/s-cua/terminal/selectStores',
        'types' => ['alipay', 'wxpay'],
        'inputs' => [
            'username' => ['name' => '收钱吧账号', 'type' => 'input', 'note' => '收钱吧商户服务平台账号'],
            'password' => ['name' => '收钱吧密码', 'type' => 'input', 'note' => '点击同步时使用，系统不向收钱吧以外的接口发送'],
            'token' => ['name' => '登录态 Token', 'type' => 'input', 'note' => '点击同步后自动保存，请勿手动修改'],
            'store_sn' => ['name' => '门店号', 'type' => 'input', 'note' => '只用于区分门店；实际收款二维码由门店终端决定'],
            'store_id' => ['name' => '门店 ID', 'type' => 'input', 'note' => '选择门店后自动保存'],
            'terminal_sn' => ['name' => '终端号', 'type' => 'input', 'note' => '选择门店终端后自动保存'],
            'terminal_id' => ['name' => '终端 ID', 'type' => 'input', 'note' => '选择门店终端后自动保存'],
            'terminal_device_fingerprint' => ['name' => '终端指纹', 'type' => 'input', 'note' => '用于流水严格匹配终端'],
            'store_list' => ['name' => '已同步门店列表', 'type' => 'textarea', 'note' => '自动生成'],
            'terminal_list' => ['name' => '已同步终端列表', 'type' => 'textarea', 'note' => '自动生成'],
            'merchant_info' => ['name' => '商户信息（JSON）', 'type' => 'textarea', 'note' => '自动生成，不要手动修改'],
            'login_checked_at' => ['name' => '登录态同步时间', 'type' => 'input', 'note' => '自动维护'],
        ],
        'note' => self::ADMIN_NOTE,
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    static public function submit()
    {
        global $channel;
        if (!self::readyForOrder($channel, $message)) return ['type' => 'error', 'msg' => $message];
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    static public function mapi()
    {
        global $channel;
        if (!self::readyForOrder($channel, $message)) return ['type' => 'error', 'msg' => $message];
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    static public function notify()
    {
        return ['type' => 'error', 'msg' => '收钱吧由 受管收款监控 轮询交易流水，不提供 HTTP 回调'];
    }

    static public function qrcode()
    {
        global $order, $channel, $cdnpublic, $conf;
        $title = trim((string)($order['typeshowname'] ?? $order['typename'] ?? '收钱吧支付')) . '-收银台';
        $browserTitle = !empty($conf['sitename']) ? '收银台-' . $conf['sitename'] : '收银台';
        $code_url = '';
        $error_msg = '';
        $config = self::config($channel);
        $token = '';
        if ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif (!empty($order['addtime']) && strtotime($order['addtime']) + self::EXPIRE_SECONDS <= time()) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } elseif (!self::ensureLiveToken($channel, $token, $liveError)) {
            $error_msg = $liveError ?: '登录态失效，请点击后台同步';
        } elseif (($bound = self::selectedQr($config)) === null) {
            $error_msg = '未绑定当前门店终端收款码，请先选择终端并同步收款码';
        } elseif (!self::reserveFloatingAmount($channel, $order)) {
            $error_msg = '订单金额分配失败，请稍后重试';
        } else {
            $code_url = $bound['qrcode'];
        }
        $paytime = max(0, !empty($order['addtime']) ? strtotime($order['addtime']) + self::EXPIRE_SECONDS - time() : 0);
        $paymentIcon = ((string)($order['typename'] ?? '') === 'alipay') ? '/assets/icon/alipay.ico' : '/assets/icon/wxpay.ico';
        include PAY_ROOT . 'inc/qrcode.page.php';
        exit;
    }

    static public function adminLogin($channel)
    {
        $action = (string)($_REQUEST['action'] ?? '');
        if (!in_array($action, ['state','sync','stores','terminals','sync_qrcodes'], true)) { self::renderSelector($channel); return; }
        self::adminResponse(self::appLogin($channel, $action, $_POST + $_GET));
    }

    static public function appLogin($channel, $action, array $input = [])
    {
        if ($action === 'state') {
            $config = self::config($channel);
            $live = false;
            if ($config['token'] !== '') {
                // 状态栏必须反映接口实际登录态，不能只凭本地缓存 Token 判定正常。
                $stateResponse = self::apiRequest('GET', '/api/login/ucUser/getCurrentUser', $config['token']);
                if ((int)($stateResponse['http'] ?? 0) === 0 || !empty($stateResponse['error'])) {
                    // 网络/DNS/超时不是 Token 明确失效，记录日志但不触发刷新。
                    error_log('shouqianba: login state check transport error: ' . ((string)($stateResponse['error'] ?? '') ?: 'HTTP 0'));
                    return ['code' => 0, 'msg' => '登录态失效', 'live' => null, 'transport_error' => true, 'token' => $config['token'], 'store_sn' => $config['store_sn'], 'terminal_sn' => $config['terminal_sn']];
                }
                $live = self::apiSuccess($stateResponse);
            }
            return ['code' => 0, 'msg' => $live ? '登录态正常' : '登录态失效', 'live' => $live, 'token' => $config['token'], 'store_sn' => $config['store_sn'], 'terminal_sn' => $config['terminal_sn']];
        }
        if ($action === 'sync') {
            $config = self::config($channel);
            $username = trim((string)($input['username'] ?? $config['username']));
            $password = (string)($input['password'] ?? $config['password']);
            if ($username === '' || $password === '') return ['code' => -1, 'msg' => '账号和密码不能为空'];
            $login = self::login($username, $password);
            if ($login['code'] !== 0) return $login;
            $checkedAt = date('Y-m-d H:i:s');
            $updates = ['username' => $username, 'password' => $password, 'token' => $login['token'], 'login_checked_at' => $checkedAt];
            // 登录接口可能使旧 Token 失效，先落库并广播登录态；门店由“同步门店”单独获取。
            if (!self::saveRuntimeConfig($channel, $updates)) return ['code' => -1, 'msg' => '登录成功，但保存登录态失败'];
            $synced = self::syncTokenForAccount($username, $password, $login['token'], $checkedAt);
            return ['code' => 0, 'msg' => '登录成功，登录态已同步到 ' . max(1, $synced) . ' 个同账号通道；请点击“同步门店”获取门店列表', 'token' => $login['token']];
        }
        if ($action === 'stores') {
            $token = '';
            if (!self::ensureLiveToken($channel, $token, $error)) return ['code' => -1, 'msg' => $error];
            // 门店管理页面实际通过二维码终端接口返回门店及终端信息。
            $terminals = self::findTerminals($token);
            if ($terminals['code'] !== 0 && !empty($terminals['token_invalid']) && self::recoverRejectedToken($channel, $token, $newToken, $refreshError)) {
                $token = $newToken;
                $terminals = self::findTerminals($token);
            }
            if ($terminals['code'] !== 0) return ['code' => -1, 'msg' => $terminals['msg']];
            return ['code' => 0, 'msg' => '获取门店成功', 'stores' => self::storesFromTerminals($terminals['items']), 'terminals' => $terminals['items']];
        }
        if ($action === 'terminals') {
            $token = '';
            if (!self::ensureLiveToken($channel, $token, $error)) return ['code' => -1, 'msg' => $error];
            $storeId = trim((string)($input['store_id'] ?? ''));
            $storeSn = trim((string)($input['store_sn'] ?? ''));
            $result = self::findTerminals($token, $storeId, $storeSn);
            if ($result['code'] !== 0 && !empty($result['token_invalid']) && self::recoverRejectedToken($channel, $token, $newToken, $refreshError)) {
                $token = $newToken;
                $result = self::findTerminals($token, $storeId, $storeSn);
            }
            return $result['code'] === 0 ? ['code' => 0, 'msg' => '获取终端成功', 'terminals' => $result['items']] : ['code' => -1, 'msg' => $result['msg']];
        }
        if ($action === 'sync_qrcodes') {
            $updates = [];
            foreach (['store_sn', 'store_id', 'terminal_sn', 'terminal_id', 'terminal_device_fingerprint'] as $key) {
                if (isset($input[$key])) $updates[$key] = trim((string)$input[$key]);
            }
            $runtimeChannel = !empty($channel['subid']) ? \lib\Channel::getSub((int)$channel['subid']) : self::channelById((int)$channel['id']);
            $result = self::syncQrcodes($runtimeChannel ?: $channel, $updates);
            return $result['code'] === 0 ? ['code' => 0, 'msg' => $result['msg'], 'merchant_info' => $result['merchant_info']] : ['code' => -1, 'msg' => $result['msg']];
        }
        return ['code' => -1, 'msg' => '不支持的登录操作'];
    }

    static public function ensureLiveToken($channel, &$token = null, &$message = null)
    {
        $config = self::config($channel);
        $token = $config['token'];
        $message = '';
        if ($token === '') {
            $shared = self::findSharedLiveToken($config['username'], $config['password'], '');
            if ($shared !== '') {
                $token = $shared;
                self::syncTokenForAccount($config['username'], $config['password'], $shared, date('Y-m-d H:i:s'));
                return true;
            }
            $message = '登录态为空，请点击同步';
            return false;
        }
        // Token 一直使用缓存值；只有业务接口明确返回失效时，调用方才进入 recoverRejectedToken。
        return true;
    }

    static private function readyForOrder($channel, &$message)
    {
        $token = '';
        if (!self::ensureLiveToken($channel, $token, $message)) return false;
        if (self::selectedQr(self::config($channel)) === null) {
            $message = '未绑定当前门店终端收款码，请先选择终端并同步收款码';
            return false;
        }
        return true;
    }

    /** 为同一基础金额的并发订单分配唯一的 0.01 尾数。 */
    static private function reserveFloatingAmount($channel, &$order)
    {
        global $DB;
        $base = round((float)($order['money'] ?? $order['realmoney'] ?? 0), 2);
        if ($base <= 0 || empty($order['trade_no'])) return false;
        $ext = self::readExt($order);
        if (isset($ext['shouqianba_float_amount'])) {
            $assigned = round((float)$ext['shouqianba_float_amount'], 2);
            if ($assigned > 0) {
                $order['realmoney'] = number_format($assigned, 2, '.', '');
                return true;
            }
        }
        $channelId = (int)($channel['id'] ?? 0);
        $subchannelId = (int)($order['subchannel'] ?? $channel['subid'] ?? 0);
        $tradeNo = daddslashes((string)$order['trade_no']);
        $lockName = 'shouqianba_float_' . $channelId . '_' . $subchannelId . '_' . number_format($base, 2, '.', '_');
        if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,5)', [':lock_name' => $lockName]) !== 1) return false;
        try {
            $rows = $DB->getAll("SELECT trade_no,realmoney,ext FROM pre_order WHERE channel='" . $channelId . "' AND subchannel='" . $subchannelId . "' AND status=0 AND trade_no<>'" . $tradeNo . "' AND addtime>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%shouqianba_expired%')");
            $used = [];
            foreach ($rows as $row) {
                $rowExt = !empty($row['ext']) ? @unserialize($row['ext']) : [];
                $rowBase = is_array($rowExt) && isset($rowExt['shouqianba_float_base']) ? round((float)$rowExt['shouqianba_float_base'], 2) : round((float)$row['realmoney'], 2);
                if (abs($rowBase - $base) > 0.001) continue;
                $amount = is_array($rowExt) && isset($rowExt['shouqianba_float_amount']) ? round((float)$rowExt['shouqianba_float_amount'], 2) : round((float)$row['realmoney'], 2);
                $used[number_format($amount, 2, '.', '')] = true;
            }
            $assigned = $base;
            while (isset($used[number_format($assigned, 2, '.', '')])) $assigned = round($assigned + self::FLOAT_STEP, 2);
            $ext['shouqianba_float_base'] = $base;
            $ext['shouqianba_float_amount'] = $assigned;
            $old = (float)($order['realmoney'] ?? $base);
            $order['realmoney'] = number_format($assigned, 2, '.', '');
            $data = ['realmoney' => $order['realmoney'], 'ext' => serialize($ext)];
            if (isset($order['getmoney'])) $data['getmoney'] = number_format(max(0, (float)$order['getmoney'] + $assigned - $old), 2, '.', '');
            if ($DB->update('order', $data, ['trade_no' => $order['trade_no']]) === false) return false;
            $order['ext'] = $data['ext'];
            if (isset($data['getmoney'])) $order['getmoney'] = $data['getmoney'];
            return true;
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static private function readExt($order)
    {
        if (empty($order['ext'])) return [];
        $ext = @unserialize($order['ext']);
        return is_array($ext) ? $ext : [];
    }

    static public function markExpired($order)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0) return false;
        $ext = self::readExt($order);
        if (!empty($ext['shouqianba_expired'])) return false;
        $ext['shouqianba_expired'] = 1;
        $ext['shouqianba_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static public function isExpired($order)
    {
        $ext = self::readExt($order);
        return !empty($ext['shouqianba_expired']) || (!empty($order['addtime']) && strtotime($order['addtime']) + self::EXPIRE_SECONDS <= time());
    }

    static public function queryTransactions($token)
    {
        $result = self::apiRequest('POST', '/api/transaction/findTransactions', $token, [
            'date_start' => (time() - self::MATCH_SECONDS) * 1000,
            'date_end' => time() * 1000 + 999,
        ]);
        if (!self::apiSuccess($result)) {
            return [
                'code' => -1,
                'msg' => self::responseMessage($result, '查询交易失败'),
                'items' => [],
                'token_invalid' => self::isTokenRejectedResponse($result),
            ];
        }
        return ['code' => 0, 'msg' => '', 'items' => self::records($result), 'token_invalid' => false];
    }

    /** 仅在业务接口明确拒绝缓存 Token 后调用，刷新失败再用账号密码登录。 */
    static public function recoverRejectedToken($channel, $rejectedToken, &$newToken = null, &$message = null)
    {
        global $DB;
        $config = self::config($channel);
        $message = '';
        $newToken = '';
        $lockName = 'shouqianba_token_' . sha1($config['username'] . '|' . $config['password']);
        $locked = false;
        if ($config['username'] !== '' && $config['password'] !== '' && isset($DB)) {
            $locked = (int)$DB->getColumn('SELECT GET_LOCK(:lock_name,5)', [':lock_name' => $lockName]) === 1;
            if (!$locked) {
                $message = '同账号登录态正在刷新，请稍后重试';
                return false;
            }
            // 其它通道可能已经完成刷新并广播，直接复用最新缓存，避免重复登录覆盖 Token。
            $latest = self::config($channel);
            if ($latest['token'] !== '' && $latest['token'] !== (string)$rejectedToken) {
                $newToken = $latest['token'];
                $locked = false;
                $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
                return true;
            }
        }
        try {
            $refresh = self::apiRequest('POST', '/api/login/ucUser/refreshToken', $rejectedToken, []);
            $newToken = self::tokenFromResponse($refresh);
            if ($newToken === '') {
                $refresh = self::apiRequest('GET', '/api/login/ucUser/refreshToken', $rejectedToken);
                $newToken = self::tokenFromResponse($refresh);
            }
            // 刷新接口返回 Token 也要立即验活，避免把无效结果广播给同账号通道。
            if ($newToken !== '' && !self::apiSuccess(self::apiRequest('GET', '/api/login/ucUser/getCurrentUser', $newToken))) {
                $newToken = '';
            }
            if ($newToken === '') {
                if ($config['username'] === '' || $config['password'] === '') {
                    $message = self::responseMessage($refresh, '刷新登录态失败，账号密码未配置');
                    return false;
                }
                $login = self::login($config['username'], $config['password']);
                if ($login['code'] !== 0) {
                    $message = $login['msg'];
                    return false;
                }
                $newToken = $login['token'];
            }
            self::syncTokenForAccount($config['username'], $config['password'], $newToken, date('Y-m-d H:i:s'));
            return true;
        } finally {
            if ($locked) $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static public function isTokenRejectedResponse($response)
    {
        $http = (int)($response['http'] ?? 0);
        if ($http === 401 || $http === 403) return true;
        $json = $response['json'] ?? [];
        $code = is_array($json) ? (string)($json['code'] ?? $json['status'] ?? '') : '';
        if (in_array((int)$code, [401, 403, 50201], true)) return true;
        if (preg_match('/(?:token|auth|login).*(?:expired|invalid|unauthor|失效|过期|无效)|(?:expired|invalid|unauthor).*(?:token|auth|login)/i', $code)) return true;
        $message = self::responseMessage($response, '') . ' ' . (string)($response['raw'] ?? '');
        return (bool)preg_match('/(?:token|令牌).{0,24}(?:expired|invalid|失效|过期|无效)|(?:expired|invalid|失效|过期|无效).{0,24}(?:token|令牌)|登录态.{0,12}(?:失效|过期|无效)|登录.{0,12}(?:失效|过期)|请重新登录|重新登录|未登录|unauthorized|authentication required/i', $message);
    }

    static private function login($username, $password)
    {
        $body = ['username' => $username, 'password' => md5($password), 'uc_device' => ['device_type' => 2, 'default_device' => 0, 'platform' => '商户服务平台', 'device_fingerprint' => '12340d18-e414-49cf-815a-66ab8ec1a480', 'device_name' => '收钱吧商户平台', 'device_model' => 'Windows', 'device_brand' => 'Chrome']];
        $result = self::apiRequest('POST', '/api/login/ucUser/login', '', $body);
        $token = self::tokenFromResponse($result);
        if ($token === '') return ['code' => -1, 'msg' => self::responseMessage($result, '收钱吧登录失败')];
        return ['code' => 0, 'msg' => '登录成功', 'token' => $token];
    }

    static private function findTerminals($token, $storeId = '', $storeSn = '')
    {
        // 终端管理页面的“批量下载门店码”只展示二维码终端。
        // 真实请求使用空的门店筛选字段，返回记录本身带 store_sn/store_name/store_id。
        $pageSize = 10;
        $items = [];
        $seen = [];
        $total = null;
        for ($page = 1; $page <= 50; $page++) {
            $body = ['store_name' => '', 'store_sn' => '', 'page' => $page, 'page_size' => $pageSize, 'status' => 1, 'isQrcode' => true];
            $result = self::apiRequest('POST', '/api/findTerminals', $token, $body);
            $parsed = self::recordsResult($result, '获取门店终端失败');
            if ($parsed['code'] !== 0) return $parsed;
            $pageItems = $parsed['items'];
            $beforeCount = count($items);
            foreach ($pageItems as $terminal) {
                $key = self::terminalSn($terminal);
                if ($key === '') $key = (string)self::value($terminal, ['id', 'terminal_id']);
                if ($key === '') $key = 'page' . $page . '_' . count($items);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $items[] = $terminal;
            }
            $reportedTotal = self::firstValue($result, ['json', 'data', 'total']);
            if (is_numeric($reportedTotal)) $total = (int)$reportedTotal;
            if (!$pageItems || count($items) === $beforeCount || ($total !== null && count($items) >= $total) || count($pageItems) < $pageSize) break;
        }
        $parsed = ['code' => 0, 'msg' => '', 'items' => $items];
        if ($storeId !== '' || $storeSn !== '') {
            $parsed['items'] = array_values(array_filter($parsed['items'], function ($terminal) use ($storeId, $storeSn) {
                $sameId = $storeId === '' || (string)self::value($terminal, ['store_id', 'storeId']) === $storeId;
                $sameSn = $storeSn === '' || (string)self::value($terminal, ['store_sn', 'storeSn']) === $storeSn;
                return $sameId && $sameSn;
            }));
        }
        return $parsed;
    }

    static private function syncQrcodes($channel, $selection = [])
    {
        $token = '';
        if (!self::ensureLiveToken($channel, $token, $error)) return ['code' => -1, 'msg' => $error];
        $config = array_merge(self::config($channel), is_array($selection) ? $selection : []);
        if ($config['store_sn'] === '' || $config['terminal_sn'] === '') return ['code' => -1, 'msg' => '请先选择门店和门店终端'];
        $terminals = self::findTerminals($token, $config['store_id'], $config['store_sn']);
        if ($terminals['code'] !== 0 && !empty($terminals['token_invalid']) && self::recoverRejectedToken($channel, $token, $newToken, $refreshError)) {
            $token = $newToken;
            $terminals = self::findTerminals($token, $config['store_id'], $config['store_sn']);
        }
        if ($terminals['code'] !== 0) return ['code' => -1, 'msg' => $terminals['msg']];
        $selected = self::findTerminal($terminals['items'], $config['terminal_sn']);
        if (!$selected) return ['code' => -1, 'msg' => '所选终端不在该门店中，请重新选择'];
        $storeId = (string)self::value($selected, ['store_id', 'storeId']);
        if ($storeId === '') $storeId = $config['store_id'];
        $store = ['id' => $storeId, 'sn' => (string)self::value($selected, ['store_sn', 'storeSn']), 'name' => (string)self::value($selected, ['store_name', 'name']), 'merchant_id' => (string)self::value($selected, ['merchant_id']), 'merchant_name' => (string)self::value($selected, ['merchant_name'])];
        if ($store['sn'] === '') $store['sn'] = $config['store_sn'];
        // 收钱吧真实接口接收二维码终端 sn，传门店 sn 会创建失败任务或下载错误内容。
        $task = self::apiRequest('POST', '/api/createQrcodeImageDownTask', $token, ['records' => [self::terminalSn($selected)]]);
        if (!self::apiSuccess($task) && self::isTokenRejectedResponse($task) && self::recoverRejectedToken($channel, $token, $newToken, $refreshError)) {
            $token = $newToken;
            $task = self::apiRequest('POST', '/api/createQrcodeImageDownTask', $token, ['records' => [self::terminalSn($selected)]]);
        }
        if (!self::apiSuccess($task)) return ['code' => -1, 'msg' => self::responseMessage($task, '创建门店码下载任务失败')];
        // createQrcodeImageDownTask 返回 {data:{id:...}}；firstValue 的路径必须逐级传入。
        $taskId = self::firstValue($task, ['json', 'data', 'apply_id']);
        if ($taskId === null || (string)$taskId === '') $taskId = self::firstValue($task, ['json', 'data', 'id']);
        if ($taskId === null || (string)$taskId === '') $taskId = self::firstValue($task, ['json', 'apply_id']);
        if ($taskId === null || (string)$taskId === '') $taskId = self::firstValue($task, ['json', 'id']);
        if ($taskId === null || (string)$taskId === '') return ['code' => -1, 'msg' => '收钱吧未返回门店码下载任务编号'];
        $zipResult = self::waitZip($token, (string)$taskId);
        if ($zipResult['code'] !== 0 && !empty($zipResult['token_invalid']) && self::recoverRejectedToken($channel, $token, $newToken, $refreshError)) {
            $token = $newToken;
            $zipResult = self::waitZip($token, (string)$taskId);
        }
        if ($zipResult['code'] !== 0) {
            // 明确失败或已完成但没有下载地址时，任务已经是终态，可以立即清理。
            if (!empty($zipResult['cleanup'])) self::deleteTaskApplyLog($token, (string)$taskId);
            return ['code' => -1, 'msg' => $zipResult['msg']];
        }

        // 从下载到落库都放在 finally 前，任何可预见的失败都会清掉已完成任务记录。
        try {
            $archive = self::binaryRequest('GET', $zipResult['url'], null, [], self::MAX_ZIP_BYTES);
            if ($archive['code'] !== 0) return ['code' => -1, 'msg' => $archive['msg']];
            $files = self::extractZip($archive['body']);
            if ($files['code'] !== 0) return $files;
            $mapped = [];
            foreach ($files['files'] as $file) {
                $decoded = self::decodeQrImage($file['name'], $file['body']);
                if ($decoded['code'] !== 0) continue;
                $matched = self::matchTerminal($file['name'], $terminals['items']);
                if ($matched['code'] !== 0) continue;
                $key = self::terminalSn($matched['terminal']);
                if (!isset($mapped[$key])) $mapped[$key] = ['values' => [], 'file' => $file['name']];
                foreach ($decoded['contents'] as $value) if (trim((string)$value) !== '') $mapped[$key]['values'][] = (string)$value;
            }
            $rows = [];
            $selectedRow = null;
            foreach ($terminals['items'] as $terminal) {
                $sn = self::terminalSn($terminal);
                if (!isset($mapped[$sn]) || !$mapped[$sn]['values']) continue;
                $row = ['terminal_id' => (string)self::value($terminal, ['id', 'terminal_id']), 'terminal_sn' => $sn, 'client_sn' => (string)self::value($terminal, ['client_sn']), 'device_fingerprint' => self::fingerprint($terminal), 'terminal_name' => (string)self::value($terminal, ['name', 'terminal_name']), 'qrcode' => $mapped[$sn]['values'][0], 'qrcodes' => array_values(array_unique($mapped[$sn]['values'])), 'qrcode_file' => $mapped[$sn]['file']];
                $rows[] = $row;
                if ($sn === self::terminalSn($selected)) $selectedRow = $row;
            }
            if (!$selectedRow || trim($selectedRow['qrcode']) === '') return ['code' => -1, 'msg' => '未找到所选终端对应的收款码，拒绝继续'];
            $merchant = ['updated_at' => time(), 'selected_store_sn' => $config['store_sn'], 'selected_terminal_sn' => self::terminalSn($selected), 'selected_terminal_client_sn' => (string)self::value($selected, ['client_sn', 'clientSn', 'terminal_client_sn']), 'selected_terminal_id' => (string)self::value($selected, ['id', 'terminal_id']), 'selected_device_fingerprint' => self::fingerprint($selected), 'stores' => [$store], 'terminals' => $rows];
            $saved = self::saveRuntimeConfig($channel, ['store_sn' => $config['store_sn'], 'store_id' => $storeId, 'terminal_sn' => $merchant['selected_terminal_sn'], 'terminal_id' => $merchant['selected_terminal_id'], 'terminal_device_fingerprint' => $merchant['selected_device_fingerprint'], 'merchant_info' => json_encode($merchant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'store_list' => self::storeText([$store]), 'terminal_list' => self::terminalText($terminals['items'])]);
            if (!$saved) return ['code' => -1, 'msg' => '二维码已解析，但保存商户信息失败'];
            return ['code' => 0, 'msg' => '已解析 ' . count($rows) . ' 个终端收款码', 'merchant_info' => $merchant];
        } finally {
            self::deleteTaskApplyLog($token, (string)$taskId);
        }
    }

    static private function waitZip($token, $taskId)
    {
        for ($i = 0; $i < 30; $i++) {
            // 下载中心按页返回记录，任务较多时不能只看第一页。
            $pageSize = 10;
            $total = null;
            for ($page = 1; $page <= 20; $page++) {
                $result = self::apiRequest('POST', '/api/orderStatement/findTaskApplyLogs', $token, ['page' => $page, 'page_size' => $pageSize, 'apply_system' => 2]);
                if (!self::apiSuccess($result)) return ['code' => -1, 'msg' => self::responseMessage($result, '查询门店码下载任务失败'), 'token_invalid' => self::isTokenRejectedResponse($result)];
                $tasks = self::records($result);
                $reportedTotal = self::firstValue($result, ['json', 'data', 'total']);
                if (is_numeric($reportedTotal)) $total = (int)$reportedTotal;
                foreach ($tasks as $task) {
                    if ((int)self::value($task, ['type']) !== 9) continue;
                    $candidate = (string)self::value($task, ['apply_id', 'id']);
                    if ($taskId !== '' && ($candidate === '' || $candidate !== (string)$taskId)) continue;
                    $status = (int)self::value($task, ['apply_status', 'status']);
                    if ($status === 2) {
                        $apply = self::value($task, ['apply_result', 'result']);
                        if (is_string($apply)) $apply = json_decode($apply, true);
                        $path = is_array($apply) ? (string)($apply['statement_oss_url'] ?? '') : '';
                        if ($path === '') $path = (string)self::value($task, ['statement_oss_url']);
                        if ($path === '') return ['code' => -1, 'msg' => '下载任务完成但没有返回压缩包地址', 'cleanup' => true];
                        return ['code' => 0, 'url' => preg_match('#^https?://#i', $path) ? $path : self::IMAGE_HOST . ltrim($path, '/')];
                    }
                    if ($status >= 3) return ['code' => -1, 'msg' => (string)self::value($task, ['apply_status_desc', 'apply_status_description', 'message']) ?: '门店码下载任务失败', 'cleanup' => true];
                }
                if (!$tasks || count($tasks) < $pageSize || ($total !== null && $page * $pageSize >= $total)) break;
            }
            sleep(1);
        }
        return ['code' => -1, 'msg' => '门店码下载任务超时，请稍后重试'];
    }

    /** 删除收钱吧下载中心中的门店码生成记录；失败不覆盖主流程结果。 */
    static private function deleteTaskApplyLog($token, $taskId)
    {
        $taskId = trim((string)$taskId);
        if ($taskId === '') return false;
        $result = self::apiRequest('POST', '/api/orderStatement/deleteTaskApplyLog', $token, ['taskApplyLogId' => $taskId]);
        if (self::apiSuccess($result)) return true;
        error_log('shouqianba: deleteTaskApplyLog failed, task_id=' . $taskId . ', message=' . self::responseMessage($result, 'unknown error'));
        return false;
    }

    static private function decodeQrImage($name, $body)
    {
        if (strlen((string)$body) > self::MAX_QR_IMAGE_BYTES) return ['code' => -1, 'msg' => '二维码图片超过允许大小'];
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : (in_array($ext, ['webp'], true) ? 'image/webp' : 'image/png');
        $boundary = '----sqb-' . bin2hex(random_bytes(12));
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$name));
        $payload = '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\nContent-Type: " . $mime . "\r\n\r\n" . $body . "\r\n--" . $boundary . "--\r\n";
        $result = self::binaryRequest('POST', self::QR_DECODE_URL, $payload, ['Content-Type: multipart/form-data; boundary=' . $boundary, 'Accept: application/json']);
        if ($result['code'] !== 0) return ['code' => -1, 'msg' => '草料二维码接口请求失败'];
        $json = json_decode($result['body'], true);
        $contents = is_array($json) ? ($json['data']['contents'] ?? []) : [];
        return (is_array($contents) && (int)($json['code'] ?? -1) === 0) ? ['code' => 0, 'contents' => $contents] : ['code' => -1, 'msg' => '草料二维码没有返回内容'];
    }

    static private function extractZip($data)
    {
        $size = strlen((string)$data);
        if ($size < 4 || substr($data, 0, 2) !== 'PK') return ['code' => -1, 'msg' => '下载内容不是 ZIP 文件'];
        if ($size > self::MAX_ZIP_BYTES) return ['code' => -1, 'msg' => '压缩包超过允许大小'];
        $files = [];
        $imageCount = 0;
        $central = strrpos($data, "PK\x05\x06");
        if ($central !== false && $size >= $central + 22) {
            $count = self::u16($data, $central + 10); $offset = self::u32($data, $central + 16);
            for ($i = 0; $i < $count && $i < 10000; $i++) {
                if ($offset < 0 || $offset + 46 > $size || substr($data, $offset, 4) !== "PK\x01\x02") break;
                $method = self::u16($data, $offset + 10); $compressedSize = self::u32($data, $offset + 20); $nameLen = self::u16($data, $offset + 28); $extraLen = self::u16($data, $offset + 30); $commentLen = self::u16($data, $offset + 32); $localOffset = self::u32($data, $offset + 42);
                $name = substr($data, $offset + 46, $nameLen);
                $localHeader = $localOffset + 30;
                if ($localHeader > $size || $localHeader + self::u16($data, $localOffset + 26) + self::u16($data, $localOffset + 28) > $size) { $offset += 46 + $nameLen + $extraLen + $commentLen; continue; }
                $localNameLen = self::u16($data, $localOffset + 26); $localExtraLen = self::u16($data, $localOffset + 28); $rawOffset = $localHeader + $localNameLen + $localExtraLen;
                $isImage = !preg_match('#^(?:__MACOSX/|[^/]+/)$#i', $name) && preg_match('/\.(png|jpe?g|webp|gif)$/i', $name);
                if ($isImage) {
                    $imageCount++;
                    if ($imageCount > self::MAX_QR_IMAGES) return ['code' => -1, 'msg' => '压缩包内图片数量超过限制'];
                    if ($compressedSize > self::MAX_ZIP_ENTRY_BYTES || $rawOffset + $compressedSize > $size) return ['code' => -1, 'msg' => '二维码图片压缩条目超过限制'];
                    $raw = substr($data, $rawOffset, $compressedSize);
                    $body = self::inflateZipEntry($method, $raw);
                    if ($body === null) return ['code' => -1, 'msg' => '二维码图片解压失败或超过限制'];
                    if ($body !== '' && strlen($body) <= self::MAX_QR_IMAGE_BYTES) $files[] = ['name' => $name, 'body' => $body];
                }
                $offset += 46 + $nameLen + $extraLen + $commentLen;
            }
        }
        if (!$files) {
            $offset = 0;
            while (($offset = strpos($data, "PK\x03\x04", $offset)) !== false) {
                if ($offset + 30 > $size) break;
                $method = self::u16($data, $offset + 8); $compressedSize = self::u32($data, $offset + 18); $nameLen = self::u16($data, $offset + 26); $extraLen = self::u16($data, $offset + 28); $name = substr($data, $offset + 30, $nameLen); $rawOffset = $offset + 30 + $nameLen + $extraLen;
                $isImage = preg_match('/\.(png|jpe?g|webp|gif)$/i', $name);
                if ($isImage) {
                    $imageCount++;
                    if ($imageCount > self::MAX_QR_IMAGES) return ['code' => -1, 'msg' => '压缩包内图片数量超过限制'];
                    if ($compressedSize > self::MAX_ZIP_ENTRY_BYTES || $rawOffset + $compressedSize > $size) return ['code' => -1, 'msg' => '二维码图片压缩条目超过限制'];
                    $raw = substr($data, $rawOffset, $compressedSize); $body = self::inflateZipEntry($method, $raw);
                    if ($body === null) return ['code' => -1, 'msg' => '二维码图片解压失败或超过限制'];
                    if ($body !== '') $files[] = ['name' => $name, 'body' => $body];
                }
                $offset = $rawOffset + $compressedSize;
            }
        }
        return $files ? ['code' => 0, 'files' => $files] : ['code' => -1, 'msg' => '压缩包内没有可解析的二维码图片'];
    }

    static private function inflateZipEntry($method, $raw)
    {
        if ($method === 0) return strlen($raw) <= self::MAX_QR_IMAGE_BYTES ? $raw : null;
        if ($method !== 8) return null;
        $body = @gzinflate($raw, self::MAX_QR_IMAGE_BYTES + 1);
        return $body !== false && strlen($body) <= self::MAX_QR_IMAGE_BYTES ? $body : null;
    }

    static private function matchTerminal($name, $terminals)
    {
        $key = self::normalize((string)$name); $matches = [];
        foreach ($terminals as $terminal) { foreach ([self::terminalSn($terminal), self::value($terminal, ['client_sn', 'device_fingerprint', 'id'])] as $value) { $part = self::normalize((string)$value); if (strlen($part) >= 4 && strpos($key, $part) !== false) { $matches[] = $terminal; break; } } }
        if (count($matches) === 1) return ['code' => 0, 'terminal' => $matches[0]];
        if (count($matches) > 1) return ['code' => -1, 'msg' => '图片文件名同时匹配多个终端'];
        if (count($terminals) === 1) return ['code' => 0, 'terminal' => $terminals[0]];
        return ['code' => -1, 'msg' => '图片文件名没有匹配到终端号'];
    }

    static private function selectedQr($config)
    {
        if ($config['store_sn'] === '' || $config['terminal_sn'] === '' || $config['merchant_info'] === '') return null;
        $info = json_decode($config['merchant_info'], true); if (!is_array($info) || (string)($info['selected_store_sn'] ?? '') !== $config['store_sn'] || (string)($info['selected_terminal_sn'] ?? '') !== $config['terminal_sn']) return null;
        foreach (($info['terminals'] ?? []) as $row) if ((string)($row['terminal_sn'] ?? '') === $config['terminal_sn'] && trim((string)($row['qrcode'] ?? '')) !== '') return $row;
        return null;
    }

    static private function renderSelector($channel)
    {
        $endpoint = './plugin_page.php?channel=' . (int)$channel['id'] . '&func=adminLogin';
        echo '<style>.sqb-wrap{max-width:560px!important}.sqb-terminal{text-align:center}.sqb-terminal button{float:none!important;display:block;margin:10px auto 0}.sqb-progress{display:block;width:220px;height:8px;margin:10px auto 0;background:#e5e7eb;border-radius:5px;overflow:hidden}.sqb-progress span{display:block;width:38%;height:100%;background:#1677ff;border-radius:5px;animation:sqb-progress-move 1.1s ease-in-out infinite}@keyframes sqb-progress-move{0%{transform:translateX(-130%)}100%{transform:translateX(290%)}}</style><script>(function(){function bind(){var root=document.getElementById("sqb-terminals");if(!root)return;root.querySelectorAll(".sqb-terminal button").forEach(function(btn){if(btn.getAttribute("data-sqb-progress"))return;btn.setAttribute("data-sqb-progress","1");btn.addEventListener("click",function(){var row=btn.parentNode,p=document.createElement("div");p.className="sqb-progress";p.innerHTML="<span></span>";row.appendChild(p);var label=document.getElementById("sqb-msg"),stages=["正在创建下载任务…","任务已创建，等待收钱吧生成 ZIP…","正在下载 ZIP…","正在解析二维码图片…"],idx=0,timer=setInterval(function(){if(btn.getAttribute("data-sqb-done")){clearInterval(timer);observer.disconnect();if(p.parentNode)p.parentNode.removeChild(p);return}idx=Math.min(idx+1,stages.length-1);if(label)label.textContent=stages[idx]},1500),observer=new MutationObserver(function(){if(btn.getAttribute("data-sqb-done")||!btn.disabled){clearInterval(timer);observer.disconnect();if(p.parentNode)p.parentNode.removeChild(p)}});observer.observe(btn,{attributes:true,attributeFilter:["disabled","data-sqb-done"]})})})}document.addEventListener("DOMContentLoaded",function(){var root=document.getElementById("sqb-terminals");if(!root)return;new MutationObserver(bind).observe(root,{childList:true,subtree:true});bind()})})();</script>';
        ?><style>body{font-family:Arial,"Microsoft YaHei",sans-serif;background:#f5f7fa}.sqb-wrap{max-width:700px;margin:20px auto;background:#fff;padding:20px;border:1px solid #e5e7eb}.sqb-wrap h3{margin:0 0 12px}.sqb-msg{min-height:24px;color:#666}.sqb-store,.sqb-terminal{border:1px solid #e5e7eb;padding:10px;margin:6px 0;cursor:pointer}.sqb-store:hover,.sqb-terminal:hover{background:#f0f7ff}.sqb-terminal button{float:right}.sqb-muted{color:#888;font-size:12px}.sqb-close{margin-left:10px}</style><div class="sqb-wrap"><h3>选择收钱吧门店终端</h3><div class="sqb-msg" id="sqb-msg">正在读取门店…</div><div id="sqb-stores"></div><div id="sqb-terminals"></div></div><script>(function(){var ep=<?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES);?>,msg=document.getElementById("sqb-msg"),stores=document.getElementById("sqb-stores"),terms=document.getElementById("sqb-terminals");function call(a,d){d=d||{};d.action=a;return fetch(ep,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(d)}).then(function(r){return r.text()}).then(function(t){var x=document.createElement("div");x.innerHTML=t;var n=x.querySelector("#sqb-response");if(!n)throw Error("后台响应格式错误");return JSON.parse(n.value||n.textContent||"{}");})}function text(v){return String(v==null?"":v)}function load(){call("stores").then(function(d){if(d.code!==0)throw Error(d.msg||"获取门店失败");stores.innerHTML="<b>门店</b>";d.stores.forEach(function(s){var el=document.createElement("div");el.className="sqb-store";el.textContent=text(s.sn||s.store_sn)+" | "+text(s.name||s.store_name);el.onclick=function(){terms.innerHTML="";msg.textContent="正在读取终端…";call("terminals",{store_id:text(s.id||s.store_id),store_sn:text(s.sn||s.store_sn)}).then(function(t){if(t.code!==0)throw Error(t.msg||"获取终端失败");msg.textContent="选择该门店的可下载终端，点击同步收款码";terms.innerHTML="<b>门店终端</b>";t.terminals.forEach(function(x){var row=document.createElement("div");row.className="sqb-terminal";var summary=document.createElement("span");summary.textContent=text(x.client_sn||x.sn||x.terminal_sn)+" | "+text(x.name||x.terminal_name)+" ";var fingerprint=document.createElement("span");fingerprint.className="sqb-muted";fingerprint.textContent=text(x.device_fingerprint||"");summary.appendChild(fingerprint);row.appendChild(summary);var b=document.createElement("button");b.className="btn btn-primary";b.textContent="下载并解析收款码";b.onclick=function(e){e.stopPropagation();b.disabled=true;msg.textContent="正在创建任务、下载 ZIP、解析图片，请等待…";call("sync_qrcodes",{store_sn:text(s.sn||s.store_sn),store_id:text(s.id||s.store_id),terminal_sn:text(x.sn||x.terminal_sn||x.client_sn),terminal_id:text(x.id||x.terminal_id),terminal_device_fingerprint:text(x.device_fingerprint||"")}).then(function(r){if(r.code!==0)throw Error(r.msg||"同步失败");b.textContent="已完成";b.setAttribute("data-sqb-done","1");var progress=row.querySelector(".sqb-progress");if(progress)progress.parentNode.removeChild(progress);msg.textContent=(r.msg||"同步成功")+"，请手动关闭此窗口";var close=document.createElement("button");close.type="button";close.className="btn btn-success sqb-close";close.textContent="关闭窗口";close.onclick=function(){window.close()};msg.appendChild(close);try{if(window.opener&&!window.opener.closed)window.opener.location.reload()}catch(z){}}).catch(function(e){msg.textContent=e.message||"同步失败";b.disabled=false})};row.appendChild(b);terms.appendChild(row)})}).catch(function(e){msg.textContent=e.message||"获取终端失败"})};stores.appendChild(el)})}).catch(function(e){msg.textContent=e.message||"获取门店失败"})}load()})();</script><?php
    }

    static private function adminResponse($data)
    {
        echo '<textarea id="sqb-response" style="display:none">' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</textarea>'; exit;
    }

    static private function saveConfig($id, $updates)
    {
        global $DB; $row = $DB->getRow("SELECT config FROM pre_channel WHERE id='" . (int)$id . "'"); if (!$row) return false; $config = json_decode((string)($row['config'] ?? ''), true); if (!is_array($config)) $config = []; foreach ($updates as $key => $value) $config[$key] = $value; return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => (int)$id]) !== false;
    }

    /**
     * Token 等运行时字段必须写回实际生效的配置位置。子通道使用
     * [字段名] 映射时写 pre_subchannel.info，否则写父通道 config。
     */
    static private function saveRuntimeConfig($channel, $updates)
    {
        if(\lib\Task\PaymentMonitor::activeFor('shouqianba'))return \lib\Task\PaymentMonitor::saveCredentials($channel,$updates,['token', 'login_checked_at']);
        global $DB;
        $channelId = (int)($channel['id'] ?? 0);
        $subchannelId = (int)($channel['subid'] ?? 0);
        if ($channelId <= 0) return false;
        if ($subchannelId <= 0) {
            return self::saveConfig($channelId, $updates);
        }
        $row = $DB->getRow("SELECT A.plugin,A.config,B.info FROM pre_subchannel B INNER JOIN pre_channel A ON B.channel=A.id WHERE B.id='" . $subchannelId . "' LIMIT 1");
        if (!$row || (string)$row['plugin'] !== 'shouqianba') return false;
        $parent = json_decode((string)($row['config'] ?? ''), true);
        $info = json_decode((string)($row['info'] ?? ''), true);
        if (!is_array($parent)) $parent = [];
        if (!is_array($info)) $info = [];
        $parentDirty = false;
        $infoDirty = false;
        foreach ($updates as $key => $value) {
            $raw = (string)($parent[$key] ?? '');
            if (strlen($raw) >= 3 && $raw[0] === '[' && substr($raw, -1) === ']') {
                $mapped = substr($raw, 1, -1);
                if ($mapped !== '') { $info[$mapped] = (string)$value; $infoDirty = true; continue; }
            }
            $parent[$key] = (string)$value;
            $parentDirty = true;
        }
        $ok = true;
        if ($parentDirty) $ok = $DB->update('channel', ['config' => json_encode($parent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
        if ($infoDirty) $ok = $DB->update('subchannel', ['info' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $subchannelId]) !== false && $ok;
        return $ok;
    }

    /** 返回当前系统内所有账号密码完全相同的收钱吧主通道和子通道。 */
    static private function accountTargets($username, $password)
    {
        global $DB;
        $username = trim((string)$username);
        $password = trim((string)$password);
        if ($username === '' || $password === '') return [];
        $targets = [];
        foreach ($DB->getAll("SELECT id FROM pre_channel WHERE plugin='shouqianba' ORDER BY id ASC") as $row) {
            $main = \lib\Channel::get((int)$row['id']);
            if ($main) {
                $cfg = self::config($main);
                if (hash_equals($username, $cfg['username']) && hash_equals($password, $cfg['password'])) $targets[] = $main;
            }
            foreach ($DB->getAll("SELECT id FROM pre_subchannel WHERE channel='" . (int)$row['id'] . "' ORDER BY id ASC") as $subrow) {
                $sub = \lib\Channel::getSub((int)$subrow['id']);
                if (!$sub) continue;
                $cfg = self::config($sub);
                if (hash_equals($username, $cfg['username']) && hash_equals($password, $cfg['password'])) $targets[] = $sub;
            }
        }
        return $targets;
    }

    /** 把新 Token 广播到同插件、同账号密码的全部配置，门店终端配置互不覆盖。 */
    static private function syncTokenForAccount($username, $password, $token, $checkedAt)
    {
        $token = trim((string)$token);
        if ($token === '') return 0;
        $count = 0;
        foreach (self::accountTargets($username, $password) as $target) {
            if(\lib\Task\PaymentMonitor::activeFor('shouqianba')&&!\lib\Task\PaymentMonitor::owns($target))continue;
            if (self::saveRuntimeConfig($target, ['token' => $token, 'login_checked_at' => (string)$checkedAt])) $count++;
        }
        return $count;
    }

    /** 查找并实际验证同账号其它通道已经持有的新 Token。 */
    static private function findSharedLiveToken($username, $password, $excludeToken)
    {
        $seen = [];
        foreach (self::accountTargets($username, $password) as $target) {
            $candidate = self::config($target)['token'];
            if ($candidate === '' || $candidate === (string)$excludeToken || isset($seen[$candidate])) continue;
            $seen[$candidate] = true;
            if (self::apiSuccess(self::apiRequest('GET', '/api/login/ucUser/getCurrentUser', $candidate))) return $candidate;
        }
        return '';
    }

    static private function channelById($id)
    {
        return \lib\Channel::get((int)$id);
    }

    static private function config($channel)
    {
        $keys = ['username', 'password', 'token', 'store_sn', 'store_id', 'terminal_sn', 'terminal_id', 'terminal_device_fingerprint', 'merchant_info', 'store_list', 'terminal_list', 'login_checked_at']; $out = []; foreach ($keys as $key) $out[$key] = trim((string)($channel[$key] ?? '')); return $out;
    }

    static private function apiRequest($method, $path, $token = '', $body = null)
    {
        $url = preg_match('#^https?://#i', $path) ? $path : self::HOST . $path;
        $apiPath = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $query = [];
        if ($token !== '') $query['token'] = $token;
        if (in_array($apiPath, ['/api/transaction/findTransactions', '/api/findTerminals', '/api/createQrcodeImageDownTask', '/api/orderStatement/findTaskApplyLogs', '/api/orderStatement/deleteTaskApplyLog'], true)) $query['client_version'] = self::CLIENT_VERSION;
        if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        $headers = ['Accept: application/json, */*', 'Origin: https://s.shouqianba.com', 'Referer: https://s.shouqianba.com/', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122 Safari/537.36'];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
        if (strtoupper($method) === 'POST') { $headers[] = 'Content-Type: application/json;charset=UTF-8'; curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        $raw = curl_exec($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); $json = is_string($raw) ? json_decode($raw, true) : null; return ['http' => $http, 'json' => is_array($json) ? $json : [], 'raw' => (string)$raw, 'error' => $error];
    }

    static private function binaryRequest($method, $url, $body = null, $headers = [], $maxBytes = 0)
    {
        $ch = curl_init($url);
        $buffer = '';
        $tooLarge = false;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers, CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$buffer, &$tooLarge, $maxBytes) {
            if ($maxBytes > 0 && strlen($buffer) + strlen($chunk) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $buffer .= $chunk;
            return strlen($chunk);
        }]);
        if (strtoupper($method) === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($tooLarge) return ['code' => -1, 'msg' => '响应内容超过允许大小'];
        if ($raw === false || $error !== '' || $http < 200 || $http >= 300) return ['code' => -1, 'msg' => 'HTTP ' . $http . ($error ? '：' . $error : '')];
        return ['code' => 0, 'body' => $buffer];
    }

    static private function apiSuccess($response)
    {
        $json = $response['json'] ?? []; if (!is_array($json) || !$json) return false; $code = $json['code'] ?? ($json['status'] ?? null); return $response['http'] >= 200 && $response['http'] < 300 && ($code === null || in_array((int)$code, [0, 200, 50000], true));
    }

    static private function responseMessage($response, $fallback)
    {
        $json = $response['json'] ?? []; $data = is_array($json['data'] ?? null) ? $json['data'] : []; foreach (['msg', 'message', 'errmsg', 'error'] as $key) if (!empty($json[$key])) return (string)$json[$key]; foreach (['msg', 'message', 'errmsg', 'error'] as $key) if (!empty($data[$key])) return (string)$data[$key]; return $fallback;
    }

    static private function tokenFromResponse($response)
    {
        $json = $response['json'] ?? []; foreach (['data.mchUserTokenInfo.token', 'data.token', 'mchUserTokenInfo.token', 'token', 'access_token', 'data.access_token'] as $path) { $value = self::firstValue($json, explode('.', $path)); if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value); } return '';
    }

    static private function recordsResult($response, $fallback)
    {
        if (!self::apiSuccess($response)) return ['code' => -1, 'msg' => self::responseMessage($response, $fallback), 'items' => [], 'token_invalid' => self::isTokenRejectedResponse($response)]; return ['code' => 0, 'msg' => '', 'items' => self::records($response), 'token_invalid' => false];
    }

    static private function records($response)
    {
        $json = $response['json'] ?? $response; $data = is_array($json['data'] ?? null) ? $json['data'] : $json; foreach (['records', 'list', 'rows', 'items'] as $key) if (is_array($data[$key] ?? null)) return $data[$key]; return is_array($data) && array_keys($data) === range(0, count($data) - 1) ? $data : [];
    }

    static private function firstValue($data, $path)
    {
        foreach ($path as $part) { if (!is_array($data) || !array_key_exists($part, $data)) return null; $data = $data[$part]; } return $data;
    }

    static private function value($row, $keys)
    {
        foreach ($keys as $key) if (is_array($row) && isset($row[$key]) && $row[$key] !== '') return $row[$key]; return '';
    }

    static private function storeSn($store) { return (string)self::value($store, ['sn', 'store_sn', 'storeSn']); }
    static private function terminalSn($terminal) { return (string)self::value($terminal, ['sn', 'terminal_sn', 'terminalSn', 'client_sn']); }
    static private function fingerprint($terminal) { return (string)self::value($terminal, ['device_fingerprint', 'terminal_device_fingerprint', 'deviceFingerprint']); }
    static private function findTerminal($items, $sn) { foreach ($items as $row) if (self::terminalSn($row) === (string)$sn) return $row; return null; }
    static private function normalize($value) { $value = strtolower((string)$value); $value = preg_replace_callback('/%([0-9a-f]{2})/i', function ($m) { return chr(hexdec($m[1])); }, $value); return preg_replace('/[^a-z0-9]/', '', $value); }
    static private function storeText($items) { $out = []; foreach ($items as $row) $out[] = self::storeSn($row) . ' | ' . self::value($row, ['name', 'store_name']); return implode("\n", $out); }
    static private function storesFromTerminals($items)
    {
        $stores = [];
        foreach ($items as $terminal) {
            $id = (string)self::value($terminal, ['store_id', 'storeId']);
            $sn = (string)self::value($terminal, ['store_sn', 'storeSn']);
            $key = $id !== '' ? $id : $sn;
            if ($key === '' || isset($stores[$key])) continue;
            $stores[$key] = ['id' => $id, 'sn' => $sn, 'name' => (string)self::value($terminal, ['store_name', 'name']), 'merchant_id' => (string)self::value($terminal, ['merchant_id']), 'merchant_name' => (string)self::value($terminal, ['merchant_name'])];
        }
        return array_values($stores);
    }
    static private function terminalText($items) { $out = []; foreach ($items as $row) $out[] = self::terminalSn($row) . ' | ' . self::value($row, ['name', 'terminal_name']) . ' | ' . self::fingerprint($row); return implode("\n", $out); }
    static private function u16($data, $offset) { $v = unpack('v', substr($data, $offset, 2)); return $v ? (int)$v[1] : 0; }
    static private function u32($data, $offset) { $v = unpack('V', substr($data, $offset, 4)); return $v ? (int)$v[1] : 0; }
}
