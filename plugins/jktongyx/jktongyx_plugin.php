<?php
require_once dirname(__DIR__, 2) . '/includes/lib/ReportHeartbeat.php';

/**
 * 通用监控码-X转发。
 *
 * 收款码保存在支付通道；监控 App 配置二维码仅在后台弹窗中临时生成，
 * 平台地址、商户 ID 和商户密钥不会写入通道配置或其它存储。
 */
class jktongyx_plugin
{
    const EXPIRE_SECONDS = 300;
    const FLOAT_STEP = 0.01;
    const REPORT_TIMESTAMP_TOLERANCE = 600;
    const HEARTBEAT_TIMEOUT = 15;
    const OFFLINE_MESSAGE = '通道已离线，请稍后重试';
    const NOTIFICATION_TITLES = [
        'wxpay' => ['微信收款助手', '微信支付收款', '二维码赞赏到账', '店员消息', '收款单到账', '个人收款码到账', '收款通知', '微信收款商业版', '对外收款'],
        'alipay' => ['支付宝', '收钱码', '店员通', '已转入余额', '你已成功收款', '通过扫码向你付款', '成功收款'],
    ];

    static public $info = [
        'name' => 'jktongyx',
        'showname' => '通用监控码-X转发',
        'author' => 'Coco',
        'link' => '',
        'types' => ['alipay', 'wxpay'],
        'inputs' => [
            'qrcode_type' => [
                'name' => '收款码类型',
                'type' => 'select',
                'options' => [
                    'qrcode' => '二维码',
                    'image' => '图片',
                ],
            ],
            'heartbeat_enabled' => [
                'name' => '心跳检测',
                'type' => 'select',
                'options' => ['0' => '关闭', '1' => '开启'],
                'note' => '默认关闭；开启后首次收到在线心跳前不可支付，连续 15 秒未收到有效心跳则离线',
            ],
            'client_name' => [
                'name' => '客户端名称',
                'type' => 'input',
                'default' => '易监控',
                'note' => '默认易监控，须与安卓上报名称完全一致；请勿使用 PC 正在上报的名称',
            ],
            'qrcode' => [
                'name' => '收款码地址',
                'type' => 'textarea',
                'note' => '二维码填写解码后的内容文本或 URL；图片填写图片 URL 或 data:image 数据',
            ],
        ],
        'note' => <<<'HTML'
<style>
form#form-info + br,form#form-info ~ br{display:none!important}
#form-info{text-align:left!important;overflow:visible}
#form-info .jktongyx-actions{display:flex!important;align-items:center;gap:4%;width:100%;margin:12px 0!important}
#form-info .jktongyx-actions #save,#form-info .jktongyx-actions .jktongyx-config{display:inline-flex!important;float:none!important;flex:1 1 0;width:auto!important;min-height:42px;box-sizing:border-box;margin:0!important;align-items:center;justify-content:center}
.jktongyx-config{padding:10px 16px;background:#1677ff;color:#fff!important;border-radius:4px;text-decoration:none!important}
.jktongyx-config:hover{background:#0e63dc;color:#fff!important}
.jktongyx-note{display:block;clear:both;margin-top:10px;line-height:1.8;color:#008000}
</style>
<a class="jktongyx-config" href="./plugin_page.php?channel=[channel]&func=adminConfig" target="_blank" onclick="window.open(this.href,'jktongyx_config_[channel]','width=500,height=700,resizable=yes,scrollbars=yes');return false;">配置信息</a>
<span class="jktongyx-note">订单有效期和流水匹配窗口均为 5 分钟；同商户、同支付方式、同基础金额的订单按 0.01 递增分配。监控 App 使用订单所属商户自己的 V1 MD5 密钥上报。</span>
<script>(function(){var form=document.getElementById('form-info'),button=document.querySelector('.jktongyx-config'),save=form&&form.querySelector('#save');if(!form||!button||!save)return;var actions=document.createElement('div');actions.className='jktongyx-actions';save.parentNode.insertBefore(actions,save);actions.appendChild(button);actions.appendChild(save);}());</script>
HTML,
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    static public function submit()
    {
        global $order, $channel;
        $state = self::onlineState((int)($order['uid'] ?? 0), $channel);
        if (!$state['online']) return ['type' => 'error', 'msg' => $state['message']];
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
    }

    static public function mapi()
    {
        return self::submit();
    }

    static public function qrcode()
    {
        global $DB, $order, $channel, $conf, $cdnpublic;

        $siteName = trim((string)($conf['sitename'] ?? ''));
        $payType = strtolower(trim((string)($order['typename'] ?? '')));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? '支付'));
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $title = ($paymentName !== '' ? $paymentName : '支付') . '-收银台';
        $paymentIcon = in_array($payType, ['alipay', 'wxpay'], true) ? '/assets/icon/' . $payType . '.ico' : '';
        $error_msg = '';
        $code_url = '';
        $config = self::config($channel);
        $merchantId = (int)($order['uid'] ?? 0);
        $merchant = $merchantId > 0 ? $DB->getRow('SELECT uid,`key`,keytype,status,pay FROM pre_user WHERE uid=:uid LIMIT 1', [':uid' => $merchantId]) : null;
        $state = self::onlineState($merchantId, $channel);

        if ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif (self::isExpired($order)) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } elseif (!$merchant) {
            $error_msg = $merchantId === 1000 ? '请先创建默认的1000商户' : '订单所属商户不存在';
        } elseif ((int)($merchant['keytype'] ?? 0) !== 0) {
            $error_msg = '当前商户未使用V1 MD5密钥';
        } elseif ((int)($merchant['status'] ?? 0) !== 1 || (int)($merchant['pay'] ?? 0) !== 1) {
            $error_msg = '订单所属商户当前不可用';
        } elseif (!in_array($payType, ['alipay', 'wxpay'], true)) {
            $error_msg = '当前支付方式不受支持';
        } elseif (($configError = self::configError($config)) !== '') {
            $error_msg = $configError;
        } elseif (!$state['online'] && (!$state['heartbeat_enabled'] || !$state['channel_enabled'] || empty(self::readExt($order)['jktongyx_float_amount']))) {
            $error_msg = $state['message'];
        } elseif (!self::reserveFloatingAmount($order)) {
            $error_msg = '订单金额分配失败，请稍后重试';
        } else {
            $code_url = $config['qrcode'];
        }

        $paytime = max(0, !empty($order['addtime']) ? strtotime($order['addtime']) + self::EXPIRE_SECONDS - time() : 0);
        $qrcodeType = $config['qrcode_type'];
        $checkoutState = self::paymentState($order, $channel);
        $statusUrl = '/pay/xstatus/' . $order['trade_no'] . '/?token=' . self::statusToken($order['trade_no']);
        header('Cache-Control: no-store');
        include PAY_ROOT . 'inc/qrcode.page.php';
        exit;
    }

    static public function notify()
    {
        return ['type' => 'error', 'msg' => '该插件使用 /api/report/{商户ID} 接收监控 App 上报'];
    }

    /** 后台临时工具：只在浏览器内生成配置二维码，不接收提交、不写入数据库。 */
    static public function adminConfig($channel)
    {
        global $siteurl, $cdnpublic;
        $defaultSite = rtrim((string)$siteurl, '/');
        ?>
<style>
    *{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#20242a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.wrap{max-width:460px;margin:20px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px}.wrap h3{margin:0 0 18px;text-align:center}.field{margin-bottom:14px}.field label{display:block;margin-bottom:6px;font-weight:600}.field input{display:block;width:100%;height:40px;padding:8px 10px;border:1px solid #d8dde3;border-radius:4px}.hint{margin:8px 0 14px;color:#777;font-size:13px;line-height:1.7}.generate{display:block;width:100%;height:42px;border:0;border-radius:4px;background:#1677ff;color:#fff;font-size:15px;cursor:pointer}.generate:hover{background:#0e63dc}.result{display:none;margin-top:18px;padding-top:18px;border-top:1px solid #e8ebef;text-align:center}.qr{width:250px;height:250px;margin:0 auto 12px;padding:10px;border:1px solid #e5e7eb;background:#fff}.qr canvas,.qr img{max-width:230px;max-height:230px}.encoded{width:100%;min-height:80px;padding:8px;border:1px solid #d8dde3;border-radius:4px;resize:vertical;word-break:break-all}.error{min-height:22px;margin-top:10px;color:#c62828;text-align:center}
</style>
<main class="wrap">
    <h3>监控 App 配置信息</h3>
    <div class="field"><label for="site">平台地址</label><input id="site" type="url" value="<?php echo htmlspecialchars($defaultSite, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off"></div>
    <div class="field"><label for="pid">商户 ID</label><input id="pid" type="number" min="1" value="1000" autocomplete="off"></div>
    <div class="field"><label for="key">商户密钥</label><input id="key" type="text" value="" placeholder="填写该商户的 V1 MD5 密钥" autocomplete="off"></div>
    <div class="hint">此页面只是临时生成工具。平台地址、商户 ID、密钥和生成结果均不会保存或记录；关闭窗口后即丢弃。</div>
    <button type="button" class="generate" id="generate">生成监控二维码</button>
    <div class="error" id="error"></div>
    <section class="result" id="result"><div class="qr" id="qr"></div><textarea class="encoded" id="encoded" readonly></textarea></section>
</main>
<script src="<?php echo htmlspecialchars((string)$cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery/1.12.4/jquery.min.js"></script>
<script src="<?php echo htmlspecialchars((string)$cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<script>
(function(){
    var site=document.getElementById('site'),pid=document.getElementById('pid'),key=document.getElementById('key'),button=document.getElementById('generate'),error=document.getElementById('error'),result=document.getElementById('result'),qr=document.getElementById('qr'),encoded=document.getElementById('encoded');
    function base64Utf8(text){
        if(window.TextEncoder){var bytes=new TextEncoder().encode(text),binary='',step=8192;for(var i=0;i<bytes.length;i+=step)binary+=String.fromCharCode.apply(null,bytes.subarray(i,i+step));return btoa(binary)}
        return btoa(unescape(encodeURIComponent(text)));
    }
    button.onclick=function(){
        error.textContent='';result.style.display='none';qr.innerHTML='';encoded.value='';
        var cleanSite=String(site.value||'').trim().replace(/\/+$/,''),merchantId=parseInt(pid.value,10),merchantKey=String(key.value||'').trim();
        if(!/^https?:\/\/[^\s]+$/i.test(cleanSite)){error.textContent='请填写正确的平台地址';return}
        if(!merchantId||merchantId<1){error.textContent='请填写正确的商户 ID';return}
        if(!merchantKey){error.textContent='请填写该商户的 V1 MD5 密钥';return}
        site.value=cleanSite;
        var value=base64Utf8(JSON.stringify({site:cleanSite,pid:merchantId,key:merchantKey}));
        encoded.value=value;
        if(window.jQuery&&jQuery.fn.qrcode){jQuery(qr).qrcode({text:value,width:230,height:230,typeNumber:-1});result.style.display='block'}else{error.textContent='二维码组件加载失败'}
    };
}());
</script>
        <?php
        exit;
    }

    /** 处理 API 入口分发的收款上报和商户心跳。 */
    static public function apiEndpoint($path = null)
    {
        $path = trim((string)($path === null ? ($_GET['s'] ?? '') : $path), '/');
        if (preg_match('#^report/([1-9][0-9]*)/heart$#D', $path, $match)) {
            self::heartbeatEndpoint((int)$match[1]);
            return true;
        }
        if (!preg_match('#^report/([1-9][0-9]*)$#', $path, $match)) return false;
        self::reportEndpoint((int)$match[1]);
        return true;
    }

    static private function heartbeatEndpoint($merchantId)
    {
        self::jsonHeaders();
        try {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new \RuntimeException('只接受 POST 请求', 405);
            $raw = (string)file_get_contents('php://input');
            if (strlen($raw) > 4096) throw new \RuntimeException('心跳正文过大', 413);
            $payload = json_decode($raw, true);
            if (!is_array($payload) || !preg_match('/^\s*\{/', $raw)) throw new \RuntimeException('心跳正文必须是 JSON 对象', 400);
            self::handleHeartbeat($merchantId, $payload);
        } catch (\RuntimeException $e) {
            self::jsonResponse($e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500, $e->getMessage(), null, true);
        } catch (\Throwable $e) {
            self::jsonResponse(500, '心跳处理失败，请稍后重试', null, true);
        }
        self::jsonResponse(200, '成功', null, true);
    }

    static private function handleHeartbeat($merchantId, $payload)
    {
        global $DB;
        $fields = ['client_name', 'ext_data', 'timestamp', 'sign'];
        if (count($payload) !== 4 || array_diff($fields, array_keys($payload))) throw new \RuntimeException('心跳需要 client_name、ext_data、timestamp、sign 四个字段', 400);
        if (!is_string($payload['client_name']) || trim($payload['client_name']) === '' || strlen($payload['client_name']) > 200) throw new \RuntimeException('client_name 请填写 App 名称', 400);
        if (!is_string($payload['ext_data']) || strlen($payload['ext_data']) > 2048) throw new \RuntimeException('ext_data 必须是 JSON 字符串', 400);
        $ext = json_decode($payload['ext_data'], true);
        if (!preg_match('/^\s*\{/', $payload['ext_data']) || !is_array($ext) || !isset($ext['online']) || !in_array($ext['online'], [0, 1], true)) throw new \RuntimeException('ext_data.online 必须为整数 0 或 1', 400);
        if ((!is_int($payload['timestamp']) && !is_string($payload['timestamp'])) || !preg_match('/^(?:[0-9]{10}|[0-9]{13})$/D', (string)$payload['timestamp'])) throw new \RuntimeException('timestamp 必须为 Unix 秒数或毫秒数', 400);
        if (!is_string($payload['sign']) || !preg_match('/^[a-fA-F0-9]{32}$/D', $payload['sign'])) throw new \RuntimeException('缺少有效 MD5 签名', 403);
        $merchant = $DB->getRow('SELECT uid,`key`,keytype,status,pay FROM pre_user WHERE uid=:uid LIMIT 1', [':uid' => (int)$merchantId]);
        if (!$merchant || (int)$merchant['keytype'] !== 0 || empty($merchant['key'])) throw new \RuntimeException('商户不存在或未启用 V1 MD5 密钥', 403);
        if ((int)$merchant['status'] !== 1 || (int)$merchant['pay'] !== 1) throw new \RuntimeException('商户当前不可用', 403);
        $expected = \lib\Payment::makeSign($payload, (string)$merchant['key']);
        if (!hash_equals($expected, strtolower($payload['sign']))) throw new \RuntimeException('V1 MD5签名校验失败', 403);
        if (!\lib\ReportHeartbeat::matchesX($DB, $payload['client_name'])) throw new \RuntimeException('客户端名称与 X 通道配置不一致', 422);

        // 签名使用原始 timestamp；验签后统一为秒，沿用既有超时和防重放规则。
        $timestamp = strlen((string)$payload['timestamp']) === 13 ? intdiv((int)$payload['timestamp'], 1000) : (int)$payload['timestamp'];
        $now = time();
        if ($timestamp > $now + 5 || $now - $timestamp >= self::HEARTBEAT_TIMEOUT) throw new \RuntimeException('心跳时间戳已过期或无效，请校准 App 时间', 408);

        $key = self::heartbeatKey($merchantId, $payload['client_name']);
        $lockName = 'jkx_heart_' . $key;
        if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lockName]) !== 1) throw new \RuntimeException('心跳正在处理，请稍后重试', 409);
        try {
            $previous = self::heartbeat($merchantId, $payload['client_name']);
            $reportedAt = (int)($previous['reported_at'] ?? 0);
            // 重复和旧请求不续期；同一秒收到离线时，离线优先。
            if ($timestamp < $reportedAt || ($timestamp === $reportedAt && ($ext['online'] === 1 || (int)($previous['online'] ?? 0) === 0))) return;
            $record = ['online' => $ext['online'], 'last_seen' => $timestamp === $reportedAt ? (int)$previous['last_seen'] : time(), 'reported_at' => $timestamp, 'client_name' => $payload['client_name']];
            if ($DB->exec('REPLACE INTO pre_cache (k,v,expire) VALUES (:key,:value,0)', [':key' => $key, ':value' => json_encode($record, JSON_UNESCAPED_UNICODE)]) === false) throw new \RuntimeException('心跳状态保存失败', 500);
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static private function heartbeatKey($merchantId, $clientName = \lib\ReportHeartbeat::DEFAULT_X_CLIENT)
    {
        return md5('jktongyx:heart:' . (int)$merchantId . ':' . $clientName);
    }

    static private function heartbeat($merchantId, $clientName = \lib\ReportHeartbeat::DEFAULT_X_CLIENT)
    {
        global $DB;
        $raw = $DB->getColumn('SELECT v FROM pre_cache WHERE k=:key LIMIT 1', [':key' => self::heartbeatKey($merchantId, $clientName)]);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($record) ? $record : [];
    }

    /** X 心跳归属于对应商户和配置的客户端名称。 */
    static public function monitorStatus($channel, $merchantId = 1000)
    {
        $state = self::onlineState($merchantId, $channel);
        $reason = !$state['channel_enabled'] ? '通道已停用' : (!$state['heartbeat_enabled'] ? '未启用心跳校验' : (!$state['last_seen'] ? '尚未收到该商户的客户端心跳' : ($state['online'] ? '心跳正常' : ($state['last_seen'] > time() ? '心跳时间异常' : (time() >= $state['last_seen'] + self::HEARTBEAT_TIMEOUT ? '心跳已超时' : '客户端上报离线')))));
        return $state + ['merchant_id'=>$merchantId,'timeout'=>self::HEARTBEAT_TIMEOUT,'server_time'=>time(),'client_name'=>\lib\ReportHeartbeat::clientName($channel),'reason'=>$reason];
    }

    static private function onlineState($merchantId, $channel)
    {
        global $DB;
        $heartbeatEnabled = self::config($channel)['heartbeat_enabled'];
        $enabled = $merchantId > 0 && ($channel['plugin'] ?? '') === 'jktongyx' && (int)$DB->getColumn('SELECT status FROM pre_channel WHERE id=:id LIMIT 1', [':id' => (int)($channel['id'] ?? 0)]) === 1;
        if (!empty($channel['subid'])) $enabled = $enabled && (int)$DB->getColumn('SELECT status FROM pre_subchannel WHERE id=:id AND uid=:uid LIMIT 1', [':id' => (int)$channel['subid'], ':uid' => $merchantId]) === 1;
        $heart = $heartbeatEnabled ? self::heartbeat($merchantId, \lib\ReportHeartbeat::clientName($channel)) : [];
        $lastSeen = (int)($heart['last_seen'] ?? 0);
        $until = $lastSeen > 0 ? $lastSeen + self::HEARTBEAT_TIMEOUT : 0;
        $online = $enabled && (!$heartbeatEnabled || (($heart['online'] ?? null) === 1 && $lastSeen > 0 && $lastSeen <= time() && time() < $until));
        return ['online' => $online, 'heartbeat_enabled' => $heartbeatEnabled, 'channel_enabled' => $enabled, 'last_seen' => $lastSeen, 'online_until' => $online && $heartbeatEnabled ? $until : 0, 'message' => $online ? '' : ($enabled ? self::OFFLINE_MESSAGE : '通道已关闭，请重新发起支付')];
    }

    static private function statusToken($tradeNo)
    {
        return hash_hmac('sha256', 'jktongyx:status:' . $tradeNo, SYS_KEY);
    }

    static public function xstatus()
    {
        global $order, $channel;
        self::jsonHeaders();
        $token = $_GET['token'] ?? '';
        if (empty($order['trade_no']) || !is_string($token) || !hash_equals(self::statusToken($order['trade_no']), $token)) self::jsonResponse(403, '收银台状态令牌无效');
        self::jsonResponse(200, '成功', self::paymentState($order, $channel));
    }

    static private function paymentState($order, $channel)
    {
        $state = self::onlineState((int)($order['uid'] ?? 0), $channel);
        $paid = (int)($order['status'] ?? 0) === 1;
        $remaining = max(0, (!empty($order['addtime']) ? strtotime($order['addtime']) : 0) + self::EXPIRE_SECONDS - time());
        $backurl = null;
        if ($paid) $backurl = !empty($order['endtime']) && time() - strtotime($order['endtime']) > 300 ? '/payok.html' : creat_callback($order)['return'];
        return $state + ['paid' => $paid, 'closed' => !$paid && ((int)($order['status'] ?? 0) !== 0 || self::isExpired($order)), 'expires_in' => $remaining, 'server_time' => time(), 'backurl' => $backurl];
    }

    static private function reportEndpoint($merchantId)
    {
        global $DB, $channel;
        self::jsonHeaders();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') self::jsonResponse(405, '只接受 POST 请求');
        $merchant = $DB->getRow('SELECT uid,`key`,keytype,status,pay,publickey FROM pre_user WHERE uid=:uid LIMIT 1', [':uid' => (int)$merchantId]);
        if (!$merchant) self::jsonResponse(400, (int)$merchantId === 1000 ? '请先创建默认的1000商户' : '商户不存在');
        if ((int)($merchant['keytype'] ?? 0) !== 0) self::jsonResponse(403, '当前商户未使用V1 MD5密钥');
        if ((int)($merchant['status'] ?? 0) !== 1 || (int)($merchant['pay'] ?? 0) !== 1) self::jsonResponse(403, '商户当前不可用');

        $payload = self::readPayload();
        $report = self::normalizeReportPayload($payload);
        if ($report['code'] !== 0) self::jsonResponse(400, $report['msg']);
        $timestamp = self::parseTimestamp($report['timestamp']);
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::REPORT_TIMESTAMP_TOLERANCE) self::jsonResponse(408, '上报时间戳已过期');
        $token = trim((string)($_GET['token'] ?? ''));
        $sign = trim((string)($payload['sign'] ?? ''));
        if ($token !== '') {
            if (!hash_equals((string)$merchant['key'], $token)) self::jsonResponse(403, '监控上报 Token 校验失败');
        } elseif ($sign !== '') {
            $signData = $payload;
            unset($signData['sign']);
            $signData['sign_type'] = 'MD5';
            $expected = lib\Payment::makeSign($signData, (string)$merchant['key']);
            if (!is_string($expected) || !hash_equals(strtolower($expected), strtolower($sign))) self::jsonResponse(403, 'V1 MD5签名校验失败');
        } else {
            self::jsonResponse(403, '缺少监控上报 Token');
        }

        $record = self::parseNotification($report['content'], $report['from'], $timestamp, (int)$merchantId);
        if ($record['code'] !== 0) self::jsonResponse(422, $record['msg']);

        $lockName = 'jktongyx_report_' . sha1((string)$merchantId . '|' . $record['flow_id']);
        if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,5)', [':lock_name' => $lockName]) !== 1) self::jsonResponse(409, '通知正在处理，请稍后重试');
        try {
            $done = $DB->getRow("SELECT trade_no FROM pre_order WHERE uid='" . (int)$merchantId . "' AND api_trade_no=:flow_id AND status=1 LIMIT 1", [':flow_id' => $record['flow_id']]);
            if ($done) self::jsonResponse(200, '订单已处理', ['trade_no' => $done['trade_no'], 'duplicate' => 1]);

            $amount = number_format((float)$record['amount'], 2, '.', '');
            $rows = $DB->getAll(
                "SELECT A.*,B.name typename,B.showname typeshowname,C.plugin FROM pre_order A INNER JOIN pre_type B ON A.type=B.id INNER JOIN pre_channel C ON A.channel=C.id WHERE A.uid='" . (int)$merchantId . "' AND C.plugin='jktongyx' AND A.status=0 AND A.realmoney=:amount AND B.name=:pay_type AND A.addtime>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND (A.ext IS NULL OR A.ext='' OR A.ext NOT LIKE '%jktongyx_expired%') ORDER BY A.addtime ASC",
                [':amount' => $amount, ':pay_type' => $record['pay_type']]
            );
            $matches = [];
            foreach ($rows as $row) {
                $created = !empty($row['addtime']) ? strtotime((string)$row['addtime']) : 0;
                if ($created <= 0 || $record['paid_at'] <= $created || $record['paid_at'] > $created + self::EXPIRE_SECONDS || time() > $created + self::EXPIRE_SECONDS) continue;
                $matches[] = $row;
            }
            if (count($matches) !== 1) self::jsonResponse(409, count($matches) > 1 ? '存在多个候选订单，拒绝自动匹配' : '未找到符合条件的待支付订单');

            $order = $matches[0];
            $fresh = $DB->getRow('SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A INNER JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1', [':trade_no' => $order['trade_no']]);
            if (!$fresh || (int)$fresh['status'] !== 0) self::jsonResponse(409, '订单状态已改变');
            $channel = (int)($fresh['subchannel'] ?? 0) > 0 ? \lib\Channel::getSub((int)$fresh['subchannel']) : \lib\Channel::get((int)$fresh['channel']);
            if (!$channel || (string)($channel['plugin'] ?? '') !== 'jktongyx') self::jsonResponse(409, '订单支付通道不匹配');
            $fresh['plugin'] = 'jktongyx';
            processNotify($fresh, $record['flow_id'], null, null, null, date('Y-m-d H:i:s', $record['paid_at']));
            $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $fresh['trade_no']]);
            if ($status !== 1) self::jsonResponse(500, '订单状态更新失败，请重试');
            self::jsonResponse(200, '订单支付成功', ['trade_no' => $fresh['trade_no'], 'pay_type' => $record['pay_type'], 'amount' => $amount]);
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static private function parseNotification($content, $outerFrom, $reportTimestamp, $merchantId)
    {
        $package = strtolower(trim((string)($content['package_name'] ?? $content['packageName'] ?? $content['package'] ?? '')));
        $app = trim((string)($content['app_name'] ?? $content['appName'] ?? $content['application_name'] ?? ''));
        $title = (string)($content['title'] ?? '');
        $message = (string)($content['msg'] ?? $content['message'] ?? $content['text'] ?? $content['content'] ?? '');
        $payType = ['com.eg.android.alipaygphone' => 'alipay', 'com.tencent.mm' => 'wxpay'][$package] ?? '';
        if ($payType === '' || !in_array($title, self::NOTIFICATION_TITLES[$payType], true)) {
            return ['code' => -1, 'msg' => '通知来源或收款标题不匹配'];
        }

        // 标题与正文分别校验，正文只允许完整收款模板，不从聊天或引用内容提取金额。
        $amount = self::extractAmount($message, $payType);
        if ($amount <= 0) return ['code' => -1, 'msg' => '收款正文格式或金额无效'];
        $receiveTime = $content['receive_time'] ?? $content['receiveTime'] ?? $content['time'] ?? $content['timestamp'] ?? $reportTimestamp;
        $paidAt = self::parseTimestamp($receiveTime);
        if ($paidAt <= 0 || $paidAt > time() + 60) return ['code' => -1, 'msg' => '通知支付时间无效'];
        $identity = implode('|', [
            (string)$merchantId,
            $outerFrom,
            $package,
            $app,
            $title,
            $message,
            (string)$receiveTime,
            (string)($content['device_name'] ?? $content['deviceName'] ?? ''),
        ]);
        return ['code' => 0, 'msg' => '', 'pay_type' => $payType, 'amount' => round($amount, 2), 'paid_at' => $paidAt, 'flow_id' => 'jkx_' . hash('sha256', $identity)];
    }

    static private function extractAmount($text, $payType)
    {
        $patterns = $payType === 'alipay' ? [
            '/\A(?:通过扫码向你付款|你已成功收款)\h*[¥￥]?\h*([0-9]+(?:\.[0-9]{1,2})?)\h*元\z/u',
        ] : [
            '/\A微信支付收款([0-9]+(?:\.[0-9]{1,2})?)元(?:\(朋友到店\))?\z/u',
            '/\A(?:微信支付收款到账|二维码赞赏到账|(?:店员消息[:：]?\h*)?收款到账|收款单到账|个人收款码到账)\h*[¥￥]?\h*([0-9]+(?:\.[0-9]{1,2})?)\h*元\z/u',
            '/\A(?:(?:微信支付|微信收款商业版|微信收款助手)[:：]?\h*)?收款\h*[¥￥]\h*([0-9]+(?:\.[0-9]{1,2})?)(?:\h*元)?\z/u',
            '/\A(?:(?:微信支付|微信收款商业版|微信收款助手)[:：]?\h*)?收款\h*([0-9]+(?:\.[0-9]{1,2})?)\h*元\z/u',
            '/\A(?:(?:收款通知|对外收款)[:：]?\h*)?已收款\h*[¥￥]?\h*([0-9]+(?:\.[0-9]{1,2})?)\h*元\z/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) return (float)$match[1];
        }
        return 0.0;
    }

    /** 同商户、同支付方式、同基础金额在五分钟内使用不同的 0.01 尾数。 */
    static private function reserveFloatingAmount(&$order)
    {
        global $DB;
        $base = round((float)($order['money'] ?? $order['realmoney'] ?? 0), 2);
        $tradeNo = trim((string)($order['trade_no'] ?? ''));
        $merchantId = (int)($order['uid'] ?? 0);
        $typeId = (int)($order['type'] ?? 0);
        if ($base <= 0 || $tradeNo === '' || $merchantId <= 0 || $typeId <= 0) return false;
        $ext = self::readExt($order);
        if (isset($ext['jktongyx_float_amount']) && (float)$ext['jktongyx_float_amount'] > 0) {
            $order['realmoney'] = number_format((float)$ext['jktongyx_float_amount'], 2, '.', '');
            return true;
        }
        $lockName = 'jktongyx_float_' . $merchantId . '_' . $typeId . '_' . str_replace('.', '_', number_format($base, 2, '.', ''));
        if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,5)', [':lock_name' => $lockName]) !== 1) return false;
        try {
            $rows = $DB->getAll(
                "SELECT A.realmoney,A.ext FROM pre_order A INNER JOIN pre_channel C ON A.channel=C.id WHERE A.uid='" . $merchantId . "' AND A.type='" . $typeId . "' AND C.plugin='jktongyx' AND A.status=0 AND A.trade_no<>:trade_no AND A.addtime>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND (A.ext IS NULL OR A.ext='' OR A.ext NOT LIKE '%jktongyx_expired%')",
                [':trade_no' => $tradeNo]
            );
            $used = [];
            foreach ($rows as $row) {
                $rowExt = !empty($row['ext']) ? @unserialize($row['ext']) : [];
                $rowBase = is_array($rowExt) && isset($rowExt['jktongyx_float_base']) ? round((float)$rowExt['jktongyx_float_base'], 2) : round((float)$row['realmoney'], 2);
                if (abs($rowBase - $base) > 0.001) continue;
                $amount = is_array($rowExt) && isset($rowExt['jktongyx_float_amount']) ? round((float)$rowExt['jktongyx_float_amount'], 2) : round((float)$row['realmoney'], 2);
                $used[number_format($amount, 2, '.', '')] = true;
            }
            $assigned = $base;
            while (isset($used[number_format($assigned, 2, '.', '')])) $assigned = round($assigned + self::FLOAT_STEP, 2);
            $old = (float)($order['realmoney'] ?? $base);
            $ext['jktongyx_float_base'] = $base;
            $ext['jktongyx_float_amount'] = $assigned;
            $order['realmoney'] = number_format($assigned, 2, '.', '');
            $data = ['realmoney' => $order['realmoney'], 'ext' => serialize($ext)];
            if (isset($order['getmoney'])) $data['getmoney'] = number_format(max(0, (float)$order['getmoney'] + $assigned - $old), 2, '.', '');
            if ($DB->update('order', $data, ['trade_no' => $tradeNo]) === false) return false;
            $order['ext'] = $data['ext'];
            if (isset($data['getmoney'])) $order['getmoney'] = $data['getmoney'];
            return true;
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static private function config($channel)
    {
        $type = strtolower(trim((string)($channel['qrcode_type'] ?? 'qrcode')));
        return ['qrcode_type' => in_array($type, ['qrcode', 'image'], true) ? $type : 'qrcode', 'qrcode' => trim((string)($channel['qrcode'] ?? '')), 'heartbeat_enabled' => (string)($channel['heartbeat_enabled'] ?? '0') === '1', 'client_name' => \lib\ReportHeartbeat::clientName($channel)];
    }

    static private function configError($config)
    {
        if ($config['qrcode'] === '') return '请先填写收款码地址';
        if (strlen($config['qrcode']) > 1048576) return '收款码内容过长';
        if ($config['qrcode_type'] === 'image' && !preg_match('#^(?:https?://|data:image/)#i', $config['qrcode'])) return '图片类型必须填写图片 URL 或 data:image 数据';
        return '';
    }

    static private function readExt($order)
    {
        if (empty($order['ext'])) return [];
        $ext = @unserialize($order['ext']);
        return is_array($ext) ? $ext : [];
    }

    static private function isExpired($order)
    {
        $ext = self::readExt($order);
        if (!empty($ext['jktongyx_expired'])) return true;
        return empty($order['addtime']) || strtotime((string)$order['addtime']) + self::EXPIRE_SECONDS <= time();
    }

    static private function markExpired($order)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0 || empty($order['trade_no'])) return false;
        $ext = self::readExt($order);
        if (!empty($ext['jktongyx_expired'])) return true;
        $ext['jktongyx_expired'] = 1;
        $ext['jktongyx_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static private function readPayload()
    {
        $raw = trim((string)file_get_contents('php://input'));
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) return $json;
            $form = [];
            parse_str($raw, $form);
            if ($form) return $form;
        }
        return is_array($_POST) ? $_POST : [];
    }

    /**
     * 兼容两种移动端协议：
     * 1. {from, content:"{...}", timestamp, sign}
     * 2. {from, package_name, app_name, title, msg, receive_time, device_name}
     */
    static private function normalizeReportPayload($payload)
    {
        if (!is_array($payload) || !$payload) return ['code' => -1, 'msg' => '请求正文不是有效 JSON'];

        $content = null;
        if (isset($payload['content']) && is_array($payload['content'])) {
            $content = $payload['content'];
        } elseif (isset($payload['content']) && is_string($payload['content'])) {
            $decoded = json_decode(trim($payload['content']), true);
            if (is_array($decoded)) $content = $decoded;
        }

        $flatFields = ['package_name', 'packageName', 'package', 'app_name', 'appName', 'title', 'msg', 'message', 'text', 'receive_time', 'receiveTime'];
        $looksFlat = false;
        foreach ($flatFields as $field) {
            if (array_key_exists($field, $payload)) {
                $looksFlat = true;
                break;
            }
        }
        if ($content === null && isset($payload['content']) && !$looksFlat) return ['code' => -1, 'msg' => 'content 不是有效 JSON'];
        if ($content === null) $content = $payload;
        $from = trim((string)($payload['from'] ?? $content['from'] ?? ''));
        if ($from === '') return ['code' => -1, 'msg' => '缺少参数：from'];

        $timestamp = $payload['timestamp'] ?? $content['timestamp'] ?? $content['receive_time'] ?? $content['receiveTime'] ?? $content['time'] ?? '';
        if (trim((string)$timestamp) === '') return ['code' => -1, 'msg' => '缺少参数：timestamp/receive_time'];

        return ['code' => 0, 'msg' => '', 'from' => $from, 'timestamp' => $timestamp, 'content' => $content];
    }

    static private function parseTimestamp($value)
    {
        $text = trim((string)$value);
        if ($text === '') return 0;
        if (preg_match('/^[0-9]{13}$/', $text)) return (int)floor((int)$text / 1000);
        if (preg_match('/^[0-9]{10}$/', $text)) return (int)$text;
        $time = strtotime($text);
        return $time === false ? 0 : (int)$time;
    }

    static private function jsonHeaders()
    {
        @header('Content-Type: application/json; charset=UTF-8');
        @header('Cache-Control: no-store');
    }

    static private function jsonResponse($code, $message, $data = null, $withRedirect = false)
    {
        http_response_code((int)$code);
        $response = ['code' => (int)$code, 'message' => (string)$message, 'data' => $withRedirect && $data === null ? (object)[] : $data];
        if ($withRedirect) $response['redirect'] = '';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
