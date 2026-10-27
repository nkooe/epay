<?php

/**
 * 支付宝-云端XD：云端扫码登录获取 PID，或使用手工填写的支付宝收款码。
 */
class yunalipayxd_plugin
{
    /** Scheduled maintenance only; receipt reports own payment completion. */
    public static function checkExpiry(array $channel,array $context)
    {
        global $DB;
        $id=(int)$channel['id'];$targets=[['channel_id'=>$id,'subchannel_id'=>0]];
        foreach($DB->getAll("SELECT id FROM pre_subchannel WHERE channel='{$id}' AND status=1 ORDER BY id") as $sub)$targets[]=['channel_id'=>$id,'subchannel_id'=>(int)$sub['id']];
        $count=0;
        foreach($targets as $target){
            $sub=(int)$target['subchannel_id'];
            $rows=$DB->getAll("SELECT * FROM pre_order WHERE channel='{$id}' AND subchannel='{$sub}' AND status=0 AND addtime<DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%yunalipayxd_expired%')");
            foreach($rows as $order){self::markExpired($order);$count++;}
        }
        return ['code'=>0,'msg'=>'过期订单标记完成：'.$count.'；支付结果仍由上报接口处理'];
    }

    const EXPIRE_SECONDS = 300;
    const FLOW_GRACE_SECONDS = 480;
    const FLOAT_STEP = 0.01;

    static public $info = [
        'name' => 'yunalipayxd',
        'showname' => '支付宝-云端XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['alipay'],
        'inputs' => [
            'gateway' => [
                'name' => '网关地址（完整 URL）',
                'type' => 'input',
                'note' => '例如：https://example.com',
            ],
            'type' => [
                'name' => '收款码类型',
                'type' => 'select',
                'options' => [
                    'pid' => 'PID',
                    'qrcode' => '二维码',
                ],
            ],
            'pid' => [
                'name' => 'PID（登录状态）',
                'type' => 'input',
                'note' => '扫码登录成功后自动获取，不支持手动填写',
            ],
            'qrcode_mod' => [
                'name' => '二维码模式',
                'type' => 'select',
                'options' => [
                    '9' => '免输-转账模式①',
                    '10' => '免输-转账模式②',
                    '11' => '免输-二维码模式③',
                ],
                'note' => '仅 PID 模式生效；二维码模式的收款码地址不使用此选项',
            ],
            'qrcode' => [
                'name' => '收款码地址',
                'type' => 'input',
                'note' => '请填写支付宝收款码地址',
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
        'note' => '<style>form#form-info + br,form#form-info ~ br{display:none!important}#form-info{text-align:left!important;overflow:visible}#form-info #save,#form-info .yun-alipay-xd-login-action{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;vertical-align:top;margin:0 0 12px!important}#form-info #save{margin-left:4%!important}.yun-alipay-xd-login-action{text-align:center;line-height:20px;padding:10px 16px;background:#1677ff;color:#fff!important;border-radius:4px;text-decoration:none}.yun-alipay-xd-login-action:hover{background:#0e63dc;text-decoration:none}.yun-alipay-xd-config-note{display:block;margin-top:12px;line-height:1.8;clear:both}#form-info input[name="config[pid]"]{background:#f5f7fa;cursor:not-allowed}</style><input type="hidden" name="config[uid]" id="yun-alipay-xd-uid" value=""><input type="hidden" name="config[login_type]" id="yun-alipay-xd-login-type" value=""><a class="yun-alipay-xd-login-action" href="./plugin_page.php?channel=[channel]&func=adminLogin" target="_blank" onclick="window.open(this.href,\'yun_alipay_xd_login_[channel]\',\'width=480,height=720,resizable=yes,scrollbars=yes\');return false;">扫码登录</a><span class="yun-alipay-xd-config-note">PID 模式扫码后自动获取 PID；二维码模式扫码后仅同步云端登录态，收款码仍使用下方表单地址。云端登录成功后返回的 UID 属于当前支付通道，所有商户订单共用该 UID。已有登录态只有在新扫码成功并保存成功后才会替换，失败、取消或关闭扫码窗口不会清理旧登录态。</span><script>(function(){var f=document.getElementById(\'form-info\'),a=document.querySelector(\'.yun-alipay-xd-login-action\'),s=document.getElementById(\'save\'),type=document.querySelector("select[name=\'config[type]\']"),pid=document.querySelector("input[name=\'config[pid]\']"),mode=document.querySelector("select[name=\'config[qrcode_mod]\']"),qr=document.querySelector("input[name=\'config[qrcode]\']"),box=function(el){return el&&el.closest?el.closest(".form-group"):null};if(f&&a&&s&&a.parentNode!==f){f.insertBefore(a,s);}if(f&&document.getElementById("yun-alipay-xd-uid")&&document.getElementById("yun-alipay-xd-uid").parentNode!==f){f.appendChild(document.getElementById("yun-alipay-xd-uid"));}if(f&&document.getElementById("yun-alipay-xd-login-type")&&document.getElementById("yun-alipay-xd-login-type").parentNode!==f){f.appendChild(document.getElementById("yun-alipay-xd-login-type"));}if(pid){pid.readOnly=true;pid.setAttribute(\'aria-readonly\',\'true\');}function sync(){var q=box(qr),p=box(pid),m=box(mode);if(q)q.style.display=type&&type.value==="qrcode"?"":"none";if(p)p.style.display=type&&type.value==="pid"?"":"none";if(m)m.style.display=type&&type.value==="pid"?"":"none";}if(type){type.addEventListener(\'change\',sync);sync();}}());(function(){var pid=document.querySelector("input[name=\'config[pid]\']"),uid=document.getElementById("yun-alipay-xd-uid"),loginType=document.getElementById("yun-alipay-xd-login-type"),url="./plugin_page.php?channel=[channel]&func=adminLogin&action=state";if(!pid)return;function load(){fetch(url,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.text();}).then(function(text){var doc=new DOMParser().parseFromString(text,"text/html"),box=doc.querySelector("#yun-alipay-xd-response");if(!box)throw new Error("响应格式错误");var data=JSON.parse(box.value||box.textContent||"{}");if(data.code!==0)throw new Error(data.msg||"读取失败");pid.value=data.pid||"";if(uid)uid.value=data.uid||"";if(loginType)loginType.value=data.login_type||data.type||"";}).catch(function(error){pid.title=error.message||\'读取登录状态失败\';});}load();window.setInterval(load,5000);}());(function(){var mode=document.querySelector("select[name=\'config[proxy_mode]\']"),proxy=document.querySelector("input[name=\'config[proxy]\']");if(!mode||!proxy)return;var box=proxy.closest(".form-group");function sync(){if(box)box.style.display=mode.value==="custom_proxy"?"":"none";}mode.addEventListener("change",sync);sync();}());</script>',
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
        global $order, $channel, $conf;

        $siteName = trim((string)($conf['sitename'] ?? ''));
        $paymentName = trim((string)($order['typeshowname'] ?? $order['typename'] ?? $channel['name'] ?? '支付宝'));
        $title = ($paymentName !== '' ? $paymentName : '支付宝') . '-收银台';
        $browserTitle = $siteName !== '' ? '收银台-' . $siteName : '收银台';
        $paymentIcon = '/assets/icon/alipay.ico';
        $code_url = '';
        $error_msg = '';
        $config = self::getConfig($channel);

        if (isset($_GET['yun_alipay_xd_transfer']) && (string)$_GET['yun_alipay_xd_transfer'] === '1') {
            if ($config['config_error'] !== '' || $config['type'] !== 'pid' || $config['qrcode_mod'] !== '10') {
                exit('当前订单启动模式不可用');
            }
            if ((int)($order['status'] ?? 0) !== 0 || self::isExpired($order)) {
                self::markExpired($order);
                exit('订单已失效');
            }
            $cloud = self::checkCloudStatus($config);
            if ((int)($cloud['code'] ?? -1) !== 1) exit((string)$cloud['msg']);
            if (!self::reserveFloatingAmount($channel, $order)) exit('订单金额准备失败');
            self::renderTransferLaunchPage($config['pid'], number_format((float)$order['realmoney'], 2, '.', ''), (string)$order['trade_no']);
        } elseif (isset($_GET['yun_alipay_xd_launch']) && (string)$_GET['yun_alipay_xd_launch'] === '1') {
            if ($config['config_error'] !== '' || $config['type'] !== 'pid' || $config['qrcode_mod'] !== '11') {
                exit('当前订单启动模式不可用');
            }
            if ((int)($order['status'] ?? 0) !== 0 || self::isExpired($order)) {
                self::markExpired($order);
                exit('订单已失效');
            }
            $cloud = self::checkCloudStatus($config);
            if ((int)($cloud['code'] ?? -1) !== 1) exit((string)$cloud['msg']);
            if (!self::reserveFloatingAmount($channel, $order)) exit('订单金额准备失败');
            self::renderTransferLaunchPage($config['pid'], number_format((float)$order['realmoney'], 2, '.', ''), (string)$order['trade_no']);
        } elseif ($config['config_error'] !== '') {
            $error_msg = $config['config_error'];
        } elseif ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif (self::isExpired($order)) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } elseif (($cloud = self::checkCloudStatus($config))['code'] !== 1) {
            // PID/二维码地址可以保存在本地，但收款依赖云端在线；离线时禁止生成收款码。
            $error_msg = $cloud['msg'];
        } elseif (!self::reserveFloatingAmount($channel, $order)) {
            $error_msg = '金额分配失败，请稍后重试';
        } else {
            $result = self::createCode($config, $order);
            if ((int)$result['code'] === 0) {
                $code_url = $result['qrcode'];
            } else {
                $error_msg = $result['msg'];
            }
        }

        $paytime = max(0, strtotime($order['addtime']) + self::EXPIRE_SECONDS - time());
        include PAY_ROOT . 'inc/qrcode.page.php';
        exit;
    }

    static public function notify()
    {
        return ['type' => 'error', 'msg' => '云端上报入口为 /api/report/{插件上报标识}/pc'];
    }

    /**
     * 插件 API 总入口。
     *
     * api.php 不解析路径中的 UID、商户 ID 或自定义标识，只把完整路径交给插件。
     * 插件自行决定是否接管以及如何解释各段参数，后续增加其它身份格式或接口后缀时
     * 只需扩展这里，不需要改系统 API 入口。
     *
     * @return bool true 表示插件已接管；false 表示交回系统 API 继续处理。
     */
    static public function apiEndpoint($path = null)
    {
        $path = trim((string)($path === null ? ($_GET['s'] ?? '') : $path), '/');
        if ($path === '') return false;
        $parts = explode('/', $path, 2);
        $route = strtolower(trim((string)$parts[0]));
        if ($route === 'report') {
            self::reportEndpoint($path);
            return true;
        }
        return false;
    }

    /**
     * 云端唯一上报入口。api.php 只负责把请求交给这里，所有业务判断都在插件内完成。
     * 路径标识可能是通道号、云端 UID、支付宝 PID 或插件自定义标识，不能在系统 API 中写死。
     */
    static public function reportEndpoint($path = null)
    {
        global $DB, $channel;
        self::reportJsonHeaders();
        if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'POST'], true)) {
            self::reportResponse(405, '只接受 GET 或 POST');
        }
        $path = trim((string)($path === null ? ($_GET['s'] ?? '') : $path), '/');
        if (!preg_match('#^report/([^/]+)/pc$#', $path, $match)) {
            self::reportResponse(404, '上报路径不正确');
        }
        $reportIdentity = trim(rawurldecode($match[1]));
        if ($reportIdentity === '' || strlen($reportIdentity) > 128) {
            self::reportResponse(400, '上报标识不正确');
        }
        $payload = self::readReportPayload();
        if (!$payload) self::reportResponse(400, '上报内容不是有效 JSON 或表单');
        $record = self::normalizeReport($payload);
        if (!$record['success']) self::reportResponse((int)($record['http_code'] ?? 202), $record['message']);
        if ($record['amount'] <= 0 || $record['flow_id'] === '' || $record['flow_time'] <= 0) {
            self::reportResponse(422, '上报缺少流水号、金额或支付时间');
        }

        $targets = self::findReportTargets($reportIdentity);
        if (!$targets) self::reportResponse(404, '上报标识未绑定启用通道');
        $lockName = 'yunalipayxd_report_' . sha1($reportIdentity . '|' . $record['flow_id']);
        $locked = (int)$DB->getColumn('SELECT GET_LOCK(:lock_name, 5)', [':lock_name' => $lockName]);
        if ($locked !== 1) self::reportResponse(409, '流水正在处理，请稍后重试');
        try {
            $processed = self::findProcessedReport($targets, $record['flow_id']);
            if ($processed) {
                self::reportResponse(200, '订单已处理', ['trade_no' => $processed['trade_no'], 'duplicate' => 1]);
            }
            $matched = null;
            $ambiguous = false;
            foreach ($targets as $target) {
                $candidate = self::matchReportOrder($target, $record);
                if ($candidate === false) continue;
                if ($candidate === null) {
                    $ambiguous = true;
                    continue;
                }
                if ($matched !== null) {
                    $ambiguous = true;
                    $matched = null;
                    break;
                }
                $matched = $candidate;
                $channel = $target;
            }
            if ($matched === null || $ambiguous) {
                self::reportResponse(409, $ambiguous ? '流水存在多个候选订单，拒绝自动匹配' : '未找到符合条件的待支付订单');
            }
            $fresh = $DB->getRow('SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1', [':trade_no' => $matched['trade_no']]);
            if (!$fresh) self::reportResponse(404, '订单不存在');
            if ((int)$fresh['status'] === 1) {
                self::reportResponse(200, '订单已处理', ['trade_no' => $fresh['trade_no'], 'duplicate' => 1]);
            }
            $fresh['plugin'] = 'yunalipayxd';
            processNotify(
                $fresh,
                $record['flow_id'],
                $record['buyer'] !== '' ? $record['buyer'] : null,
                $record['bill_trade_no'],
                $record['bill_mch_trade_no'],
                date('Y-m-d H:i:s', $record['flow_time'])
            );
            $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $fresh['trade_no']]);
            if ($status !== 1) self::reportResponse(500, '订单状态更新失败，请重试');
            self::reportResponse(200, '订单支付成功', ['trade_no' => $fresh['trade_no'], 'flow_id' => $record['flow_id']]);
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static private function reportJsonHeaders()
    {
        @header('Content-Type: application/json; charset=UTF-8');
        @header('Cache-Control: no-store');
    }

    static private function reportResponse($httpCode, $message, $data = [])
    {
        http_response_code((int)$httpCode);
        echo json_encode(['code' => (int)$httpCode, 'msg' => (string)$message, 'message' => (string)$message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    static private function readReportPayload()
    {
        $raw = file_get_contents('php://input');
        $candidates = [];
        $raw = trim((string)$raw);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $candidates[] = $decoded;
            $form = [];
            parse_str($raw, $form);
            if (!empty($form)) $candidates[] = $form;
        }
        if (!empty($_POST) && is_array($_POST)) $candidates[] = $_POST;
        if (!empty($_GET) && is_array($_GET)) {
            $query = $_GET;
            unset($query['s']);
            if ($query) $candidates[] = $query;
        }
        foreach ($candidates as $candidate) {
            $decoded = self::decodeNestedPayload($candidate);
            if (is_array($decoded) && $decoded) return $decoded;
        }
        return [];
    }

    static private function normalizeReport($payload)
    {
        $item = self::decodeNestedPayload($payload);
        $payType = strtolower(trim(self::findValue($item, ['pay_type', 'payType', 'payment_type', 'paymentType'])));
        if ($payType !== '' && !in_array($payType, ['alipay', 'ali', 'ali_qr', 'alipay_qr'], true)) {
            return ['success' => false, 'message' => '支付类型不是支付宝'];
        }
        // 流水状态和接口状态不是一回事：流水 status=0 通常表示未支付，
        // 只有明确的成功值才能进入匹配；只有完全没有流水状态时，才看顶层 code。
        $flowStatus = self::findValue($item, ['status', 'trade_status', 'pay_status', 'payStatus', 'state', 'success']);
        if ($flowStatus !== '') {
            $success = in_array(strtolower(trim($flowStatus)), [
                '1', '200', 'true', 'ok', 'success', 'succeed', 'paid', 'pay_success', 'trade_success', 'finished', 'confirmed'
            ], true);
        } elseif (array_key_exists('code', $item) && !is_array($item['code'])) {
            $apiCode = strtolower(trim((string)$item['code']));
            $success = in_array($apiCode, ['0', '1', '200', 'true', 'ok', 'success', 'succeed'], true);
        } else {
            // 旧云端上报没有状态字段，保留金额/流水/时间三项的兼容路径。
            $success = true;
        }
        if (!$success) return ['success' => false, 'message' => '流水状态未成功'];
        $rawTime = self::findValue($item, ['pay_time', 'payTime', 'paytime', 'paid_at', 'paidAt', 'paid_time', 'trans_time', 'transTime', 'trade_time', 'trans_dt', 'pay_date', 'create_time', 'created_at', 'time', 'timestamp']);
        $flowTime = self::parseFlowTime($rawTime);
        if ($flowTime <= 0) $flowTime = self::parseFlowTime(self::findValue($item, ['timestamp']));
        $amount = self::findValue($item, ['amount', 'total_amount', 'totalAmount', 'pay_amount', 'payAmount', 'paid_amount', 'paidAmount', 'trans_amount', 'real_amount', 'income_amount', 'money', 'fee']);
        $amount = (float)preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$amount));
        $orderId = self::findValue($item, ['order_id', 'orderId']);
        $outOrderId = self::findValue($item, ['out_order_id', 'outOrderId']);
        $remark = self::findValue($item, ['remark', 'memo', 'trans_memo', 'title', 'subject', 'note']);
        $flowId = self::findValue($item, ['third_order_id', 'thirdOrderId', 'alipay_order_no', 'alipayOrderNo', 'trans_id', 'transId', 'transaction_id', 'transactionId', 'flow_id', 'flowId', 'report_id', 'reportId', 'bill_no', 'billNo', 'id']);
        if ($flowId === '') {
            // 云端流水号是幂等和防重复匹配的依据，缺少真实流水号时拒绝上报。
            return ['success' => false, 'http_code' => 422, 'message' => '上报缺少真实流水号'];
        }
        return [
            'success' => true,
            'message' => '',
            'flow_id' => $flowId,
            'amount' => $amount,
            'remark' => (string)$remark,
            'buyer' => self::findValue($item, ['buyer', 'buyer_name', 'buyerName', 'payer_name', 'payerName', 'other_account', 'payer_nick_name', 'payerNickName']),
            'flow_time' => $flowTime,
            'bill_trade_no' => self::findValue($item, ['bill_trade_no', 'platform_trade_no']),
            'bill_mch_trade_no' => self::findValue($item, ['bill_mch_trade_no', 'merchant_trade_no']),
            'pay_type' => $payType,
            'channel_code' => self::findValue($item, ['channel_code', 'channelCode']),
            'order_id' => $orderId,
            'out_order_id' => $outOrderId,
            'coll_user' => self::findValue($item, ['coll_user', 'collUser', '收款账号']),
            'pay_user' => self::findValue($item, ['pay_user', 'payUser', '付款账号']),
            'uid' => self::findValue($item, ['uid', 'user_id', 'userId']),
            'channel_account_id' => self::findValue($item, ['channel_account_id', 'channelAccountId']),
            'timestamp' => self::findValue($item, ['timestamp']),
            'sign' => self::findValue($item, ['sign']),
        ];
    }

    /** 只解包协议定义的外层字段，避免把备注等普通字符串误判成表单。 */
    static private function decodeNestedPayload($value, $depth = 0)
    {
        if ($depth > 6) return $value;
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '') return $value;
            $decoded = json_decode($text, true);
            if (is_array($decoded)) return self::decodeNestedPayload($decoded, $depth + 1);
            if (strpos($text, '=') !== false && (strpos($text, '&') !== false || preg_match('/^[A-Za-z0-9_.\-]+=/', $text))) {
                $form = [];
                parse_str($text, $form);
                if ($form) return self::decodeNestedPayload($form, $depth + 1);
            }
            return $value;
        }
        if (!is_array($value)) return $value;
        $envelopes = ['data', 'result', 'payload', 'body', 'record', 'report', 'order', 'response', 'content', 'items'];
        foreach ($value as $key => $child) {
            if (in_array(strtolower((string)$key), $envelopes, true)) {
                $value[$key] = self::decodeNestedPayload($child, $depth + 1);
            }
        }
        return $value;
    }

    static private function parseFlowTime($value)
    {
        $text = trim((string)$value);
        if ($text === '') return 0;
        if (preg_match('/^\d{14}$/', $text)) {
            $date = \DateTime::createFromFormat('YmdHis', $text);
            return $date ? $date->getTimestamp() : 0;
        }
        if (is_numeric($text)) {
            $timestamp = (int)$text;
            if ($timestamp > 20000000000) $timestamp = (int)floor($timestamp / 1000);
            return $timestamp;
        }
        return (int)strtotime($text);
    }

    static private function findReportTargets($reportIdentity)
    {
        global $DB;
        $targets = [];
        $rows = $DB->getAll("SELECT id FROM pre_channel WHERE plugin='yunalipayxd' AND status=1 ORDER BY id ASC");
        foreach ($rows as $row) {
            $item = \lib\Channel::get((int)$row['id']);
            if ($item && self::reportIdentityMatches($reportIdentity, $item)) $targets[] = $item;
            $subs = $DB->getAll('SELECT id FROM pre_subchannel WHERE channel=:channel AND status=1 ORDER BY id ASC', [':channel' => (int)$row['id']]);
            foreach ($subs as $sub) {
                $subItem = \lib\Channel::getSub((int)$sub['id']);
                if ($subItem && self::reportIdentityMatches($reportIdentity, $subItem)) $targets[] = $subItem;
            }
        }
        return $targets;
    }

    /**
     * 云端版本可能把 CreateID 的 pid（本系统通道号）、登录 UID、支付宝 PID
     * 或插件自定义 report_key 放进 /api/report/{标识}/pc；这些规则只在插件内解释。
     */
    static private function reportIdentityMatches($identity, $channel)
    {
        $identity = trim((string)$identity);
        if ($identity === '') return false;
        $config = self::getConfig($channel);
        $values = [
            (string)($channel['id'] ?? ''),
            (string)($channel['subid'] ?? ''),
            $config['uid'],
            $config['pid'],
            $config['report_key'],
            $config['report_id'],
            $config['merchant_id'],
        ];
        foreach ($values as $value) {
            if ($value !== '' && hash_equals($value, $identity)) return true;
        }
        return false;
    }

    static private function matchReportOrder($channel, $record)
    {
        global $DB;
        $channelId = (int)$channel['id'];
        $subchannelId = (int)($channel['subid'] ?? 0);
        $sql = 'SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel=:channel AND A.subchannel=:subchannel AND A.status=0 AND A.addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE) ORDER BY A.addtime ASC';
        $orders = $DB->getAll($sql, [':channel' => $channelId, ':subchannel' => $subchannelId]);
        if (!$orders) return false;
        // 云端 order_id 可能是云端自己的交易号，不能仅凭数字长度当成本地订单号。
        // 只有它确实命中当前通道的待支付订单，才启用订单号硬匹配；否则回退金额+时间。
        $tradeNo = '';
        $reportedTradeNos = [];
        foreach ([$record['remark'], $record['order_id'], $record['out_order_id']] as $reference) {
            $candidate = self::extractTradeNo($reference);
            if ($candidate !== '' && !in_array($candidate, $reportedTradeNos, true)) {
                $reportedTradeNos[] = $candidate;
            }
        }
        $candidates = [];
        $matchedTradeNos = [];
        foreach ($orders as $order) {
            if (in_array((string)$order['trade_no'], $reportedTradeNos, true)) {
                $matchedTradeNos[] = (string)$order['trade_no'];
            }
        }
        if (count(array_unique($matchedTradeNos)) === 1) {
            $tradeNo = $matchedTradeNos[0];
        }
        foreach ($orders as $order) {
            if (self::isExpired($order)) {
                self::markExpired($order);
                continue;
            }
            if (!self::reportAmountMatches($order['realmoney'], $record['amount'])) continue;
            $orderTime = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
            if ($orderTime <= 0 || $record['flow_time'] <= $orderTime || $record['flow_time'] > $orderTime + self::FLOW_GRACE_SECONDS) continue;
            if ($tradeNo !== '' && $order['trade_no'] !== $tradeNo) continue;
            $candidates[] = $order;
        }
        if ($tradeNo !== '') return count($candidates) === 1 ? $candidates[0] : false;
        return count($candidates) === 1 ? $candidates[0] : (count($candidates) > 1 ? null : false);
    }

    static private function findProcessedReport($targets, $flowId)
    {
        global $DB;
        foreach ($targets as $target) {
            $row = $DB->getRow(
                'SELECT trade_no FROM pre_order WHERE channel=:channel AND subchannel=:subchannel AND status=1 AND api_trade_no=:api_trade_no LIMIT 1',
                [
                    ':channel' => (int)$target['id'],
                    ':subchannel' => (int)($target['subid'] ?? 0),
                    ':api_trade_no' => (string)$flowId,
                ]
            );
            if ($row) return $row;
        }
        return null;
    }

    static private function extractTradeNo($remark)
    {
        $remark = trim((string)$remark);
        $remark = preg_replace('/^(请勿添加备注|备注)[\s\-:：]*/u', '', $remark);
        // 只提取完整的本地订单号；云端较长交易号不能截断后参与硬匹配。
        return preg_match('/(?<!\d)\d{16,24}(?!\d)/', $remark, $match) ? $match[0] : '';
    }

    static private function reportAmountMatches($orderAmount, $flowAmount)
    {
        return abs((float)$orderAmount - (float)$flowAmount) <= 0.001 || abs((float)$orderAmount - (float)$flowAmount / 100) <= 0.001;
    }

    /** 后台扫码页通过受保护的后台入口加载，插件目录本身不提供公开页面。 */
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
        if ($action === 'start') {
            self::adminLoginResponse(self::beginLogin($channel));
        }
        if ($action === 'poll') {
            self::adminLoginResponse(self::pollLogin($channel, (string)($_GET['uid'] ?? '')));
        }
        if ($action === 'state') {
            $config = self::getConfig($channel);
            if ($config['config_error'] !== '') {
                self::adminLoginResponse(['code' => -1, 'msg' => $config['config_error']]);
            }
            self::adminLoginResponse([
                'code' => 0,
                'pid' => $config['pid'],
                'uid' => $config['uid'],
                'type' => $config['type'],
                'login_type' => $config['login_type'],
            ]);
        }

        global $cdnpublic;
        $config = self::getConfig($channel);
        $channelId = (int)$channel['id'];
        $loginState = $config['uid'] !== '' ? '已登录' : '未登录';
        $statusText = $config['uid'] !== '' ? '当前云端登录态已保存，可重新扫码覆盖。' : '尚未登录，请扫码确认。';
        $endpoint = './plugin_page.php?channel=' . $channelId . '&func=adminLogin';
        ?>
        <style>
        .yun-alipay-xd-login{max-width:430px;margin:18px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;text-align:center}.yun-alipay-xd-login h3{margin:0 0 10px;font-size:20px}.yun-alipay-xd-meta{color:#666;line-height:1.8}.yun-alipay-xd-status{min-height:24px;margin:12px 0 4px;color:#1677ff;font-weight:600}.yun-alipay-xd-status.ok{color:#16803c}.yun-alipay-xd-status.bad{color:#c62828}.yun-alipay-xd-qr{width:260px;height:260px;margin:12px auto 16px;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;color:#888;background:#fafafa}.yun-alipay-xd-qr img{max-width:240px;max-height:240px}.yun-alipay-xd-msg{min-height:24px;margin:10px 0;color:#555}.yun-alipay-xd-msg.ok{color:#16803c}.yun-alipay-xd-msg.bad{color:#c62828}.yun-alipay-xd-login button{padding:9px 20px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}.yun-alipay-xd-login button:disabled{opacity:.6;cursor:wait}
        </style>
        <div class="yun-alipay-xd-login">
            <h3>支付宝-云端XD扫码登录</h3>
            <div class="yun-alipay-xd-meta">通道：<?php echo htmlspecialchars((string)$channel['name'], ENT_QUOTES, 'UTF-8'); ?><br>云端登录态：<?php echo $loginState; ?></div>
            <div class="yun-alipay-xd-qr" id="yun-alipay-xd-qr">正在准备二维码…</div>
            <div class="yun-alipay-xd-status" id="yun-alipay-xd-status">状态：准备二维码</div>
            <div class="yun-alipay-xd-msg" id="yun-alipay-xd-msg"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></div>
            <button type="button" id="yun-alipay-xd-start">重新获取二维码</button>
        </div>
        <script src="<?php echo htmlspecialchars($cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
        <script>
        (function(){
            var endpoint=<?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES); ?>,qr=document.getElementById('yun-alipay-xd-qr'),status=document.getElementById('yun-alipay-xd-status'),msg=document.getElementById('yun-alipay-xd-msg'),start=document.getElementById('yun-alipay-xd-start'),timer=0;
            function say(text,cls){msg.className='yun-alipay-xd-msg '+(cls||'');msg.textContent=text||'';}
            function setStatus(text,cls){status.className='yun-alipay-xd-status '+(cls||'');status.textContent='状态：'+(text||'');}
            function parseResponse(text){var doc=new DOMParser().parseFromString(text,'text/html'),node=doc.querySelector('#yun-alipay-xd-response');if(!node)throw new Error('后台响应格式错误');return JSON.parse(node.value||node.textContent||'{}');}
            function draw(value){qr.innerHTML='';if(/^data:image\//i.test(value)){var img=document.createElement('img');img.alt='登录二维码';img.src=value;qr.appendChild(img);return;}if(window.jQuery&&jQuery.fn.qrcode){jQuery(qr).qrcode({text:value,width:240,height:240,typeNumber:-1});}else{qr.textContent='二维码数据已返回，请更新后台静态资源';}}
            function request(url){return fetch(url,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.text();}).then(parseResponse);}
            function poll(uid){setStatus('正在检查登录状态');request(endpoint+'&action=poll&uid='+encodeURIComponent(uid)).then(function(data){if(data.code===0){setStatus('登录成功','ok');say(data.msg||'登录成功，PID 已自动同步','ok');start.disabled=false;try{if(window.opener&&!window.opener.closed){window.opener.location.reload();}}catch(e){}return;}if(data.code===201){setStatus('等待扫码');say(data.msg||'请扫描二维码登录');timer=setTimeout(function(){poll(uid);},2000);return;}setStatus('登录失败','bad');say(data.msg||'登录失败','bad');start.disabled=false;}).catch(function(){setStatus('连接重试中','bad');say('后台请求失败，正在重试');timer=setTimeout(function(){poll(uid);},3000);});}
            function begin(){clearTimeout(timer);start.disabled=true;setStatus('准备二维码');say('正在获取登录二维码');request(endpoint+'&action=start').then(function(data){if(data.code!==0){setStatus('获取二维码失败','bad');say(data.msg||'获取二维码失败','bad');start.disabled=false;return;}draw(data.qrcode);setStatus('等待扫码');say('请使用支付宝扫码并确认');poll(data.uid);}).catch(function(e){setStatus('获取二维码失败','bad');say(e.message||'后台请求失败','bad');start.disabled=false;});}
            start.onclick=begin;begin();
        }());
        </script>
        <?php
    }

    static private function adminLoginResponse($result)
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<textarea id="yun-alipay-xd-response" style="display:none">' . htmlspecialchars($json ?: '{}', ENT_QUOTES, 'UTF-8') . '</textarea>';
        exit;
    }

    static public function beginLogin($channel)
    {
        global $siteurl;
        $config = self::getConfig($channel);
        if ($config['config_error'] !== '') return ['code' => -1, 'msg' => $config['config_error']];
        if ($config['gateway'] === '') return ['code' => -1, 'msg' => '请先填写网关地址'];
        $identity = [
            'site' => rtrim((string)$siteurl, '/'),
            // 云端 CreateID 协议字段名固定为 pid。这里传入本地通道号作为
            // 当前通道的身份命名空间；它不是支付宝收款 PID，也不是云端 UID。
            // 真正的收款 PID仍由扫码登录成功后的状态接口返回并写入配置。
            'pid' => (string)$channel['id'],
            'key' => hash('sha256', rtrim((string)$siteurl, '/') . '|channel|' . (string)$channel['id']),
        ];
        $data = base64_encode(json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result = self::gatewayRequest('POST', rtrim($config['gateway'], '/') . '/Alipay_Frame/CreateID', ['data' => $data], $config, true);
        if ($result['code'] !== 0) return $result;
        $payload = $result['data'];
        if ((string)($payload['code'] ?? '') !== '1' || empty($payload['uid'])) {
            return ['code' => -1, 'msg' => (string)($payload['msg'] ?? '云端未返回登录 UID')];
        }
        $uid = (string)$payload['uid'];
        $qr = self::gatewayRequest('POST', rtrim($config['gateway'], '/') . '/Alipay_Frame/QRCode', ['uid' => $uid], $config, true);
        if ($qr['code'] !== 0) return ['code' => -1, 'msg' => '获取支付宝登录二维码失败：' . $qr['msg']];
        $qrData = $qr['data'];
        if ((string)($qrData['code'] ?? '') !== '1' || empty($qrData['url'])) {
            return ['code' => -1, 'msg' => (string)($qrData['msg'] ?? '云端未返回登录二维码')];
        }
        return ['code' => 0, 'uid' => $uid, 'qrcode' => (string)$qrData['url']];
    }

    static public function pollLogin($channel, $uid)
    {
        $config = self::getConfig($channel);
        if ($config['config_error'] !== '') return ['code' => -1, 'msg' => $config['config_error']];
        $uid = trim((string)$uid);
        if ($uid === '') return ['code' => -1, 'msg' => '登录 UID 为空'];
        // 新扫码使用当前选择的登录模式；成功后才写入 login_type 并替换旧登录态。
        $path = $config['type'] === 'pid' ? '/Alipay_Frame/IsLoginStatus_V2' : '/Alipay_Frame/IsLoginStatus';
        $result = self::gatewayRequest('POST', rtrim($config['gateway'], '/') . $path, ['uid' => $uid], $config, true);
        if ($result['code'] !== 0) return $result;
        $payload = $result['data'];
        $status = (string)($payload['code'] ?? '');
        if ($status === '0') return ['code' => 201, 'msg' => '请扫描二维码登录'];
        if ($status !== '1') return ['code' => -1, 'msg' => (string)($payload['msg'] ?? '云端登录状态异常')];
        $updates = ['uid' => $uid, 'login_type' => $config['type']];
        if ($config['type'] === 'pid') {
            $pid = self::findValue($payload, ['pid', 'user_id', 'userId', 'alipay_user_id']);
            if ($pid === '') return ['code' => -1, 'msg' => '登录成功，但云端未返回 PID'];
            $updates['pid'] = $pid;
        } else {
            // 只在新登录成功后清理旧 PID；扫码失败、取消或轮询异常均保留旧登录态。
            $updates['pid'] = '';
        }
        // 只有完整扫码成功并写入配置后才替换旧登录态，失败路径不会清理旧值。
        $saved = self::saveChannelConfig((int)$channel['id'], $updates);
        return $saved ? ['code' => 0, 'msg' => $config['type'] === 'pid' ? '登录成功，PID 已自动同步' : '登录成功，云端登录态已同步', 'updates' => $updates] : ['code' => -1, 'msg' => '登录成功，但写入通道配置失败'];
    }

    /**
     * 实时检查云端账号状态。返回 code=1 才允许拉起订单；其它结果一律阻止收款。
     * 该检查沿用当前通道的代理请求头，不依赖云端管理页是否保存了代理字段。
     */
    static public function checkChannelStatus($channel)
    {
        return self::checkCloudStatus(self::getConfig($channel));
    }

    static private function checkCloudStatus($config)
    {
        if (($config['config_error'] ?? '') !== '') {
            return ['code' => -1, 'online' => false, 'msg' => $config['config_error']];
        }
        if ($config['gateway'] === '') {
            return ['code' => -1, 'online' => false, 'msg' => '未配置云端网关，已禁止发起订单'];
        }
        if ($config['uid'] === '') {
            return ['code' => -1, 'online' => false, 'msg' => '尚未扫码登录云端，已禁止发起订单'];
        }
        // 配置切换不改变旧登录态的接口类型；只有新扫码成功才更新 login_type。
        $path = $config['login_type'] === 'pid' ? '/Alipay_Frame/IsLoginStatus_V2' : '/Alipay_Frame/IsLoginStatus';
        $result = self::gatewayRequest('POST', rtrim($config['gateway'], '/') . $path, ['uid' => $config['uid']], $config, true);
        if ($result['code'] !== 0) {
            return ['code' => -1, 'online' => false, 'msg' => '云端状态检查失败，已禁止发起订单：' . $result['msg']];
        }
        $payload = $result['data'];
        $status = (string)($payload['code'] ?? '');
        if ($status === '1') {
            return ['code' => 1, 'online' => true, 'msg' => '云端在线', 'data' => $payload];
        }
        if ($status === '0') {
            return ['code' => 0, 'online' => false, 'msg' => '云端账号已离线，请重新扫码登录', 'data' => $payload];
        }
        return ['code' => -1, 'online' => false, 'msg' => (string)($payload['msg'] ?? '云端登录状态异常，已禁止发起订单'), 'data' => $payload];
    }

    static public function createCode($config, $order)
    {
        global $siteurl;
        if (($config['config_error'] ?? '') !== '') return ['code' => -1, 'msg' => $config['config_error']];
        if ($config['type'] === 'pid') {
            if ($config['pid'] === '') return ['code' => -1, 'msg' => '未获取 PID，请先扫码登录'];
            $money = number_format((float)$order['realmoney'], 2, '.', '');
            $tradeNo = (string)$order['trade_no'];
            $mode = (string)($config['qrcode_mod'] ?? '');
            if (!in_array($mode, ['9', '10', '11'], true)) {
                return ['code' => -1, 'msg' => 'PID 二维码模式配置无效，请重新保存通道配置'];
            }
            if ($mode === '9') {
                $inner = 'alipays://platformapi/startapp?appId=20000116&actionType=toAccount&goBack=NO&amount=' . rawurlencode($money) . '&userId=' . rawurlencode($config['pid']) . '&memo=' . rawurlencode($tradeNo);
                return ['code' => 0, 'qrcode' => 'https://render.alipay.com/p/s/i?scheme=' . rawurlencode($inner)];
            }
            if ($mode === '10') {
                // 使用插件自己的 HTTPS 启动页，避免支付宝在第二段私有 scheme 跳转时丢失参数。
                $launchUrl = rtrim((string)$siteurl, '/') . '/pay/qrcode/' . rawurlencode($tradeNo) . '/?yun_alipay_xd_transfer=1';
                return ['code' => 0, 'qrcode' => $launchUrl];
            }
            if ($mode === '11') {
                // 模式③对应参考插件的 HTML 结果：二维码直接打开本插件 HTTPS 页面，
                // 页面内由 AlipayJSBridge 调用 20000123，避免 render 中间页吞掉参数。
                $launchUrl = rtrim((string)$siteurl, '/') . '/pay/qrcode/' . rawurlencode($tradeNo) . '/?yun_alipay_xd_launch=1';
                return ['code' => 0, 'qrcode' => $launchUrl];
            }
        }
        if ($config['qrcode'] === '') return ['code' => -1, 'msg' => '请填写支付宝收款码地址'];
        return ['code' => 0, 'qrcode' => $config['qrcode']];
    }

    static private function renderTransferLaunchPage($pid, $money, $tradeNo)
    {
        @header('Content-Type: text/html; charset=UTF-8');
        @header('Cache-Control: no-store');
        $pidJson = json_encode((string)$pid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $moneyJson = json_encode((string)$money, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tradeNoJson = json_encode((string)$tradeNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>支付宝收款</title></head><body><script src="https://gw.alipayobjects.com/as/g/h5-lib/alipayjsapi/3.1.1/alipayjsapi.min.js"></script><script>(function(){var userId=' . $pidJson . ',money=' . $moneyJson . ',remark=' . $tradeNoJson . ';function exitPage(){if(window.AlipayJSBridge){AlipayJSBridge.call("exitApp");}}function ready(callback){if(window.AlipayJSBridge){callback();}else{document.addEventListener("AlipayJSBridgeReady",callback,{once:true});}}ready(function(){var param={actionType:"scan",u:userId,a:money,m:remark,biz_data:{s:"money",u:userId,a:money,m:remark}};AlipayJSBridge.call("startApp",{appId:"20000123",param:param},function(){});});document.addEventListener("resume",exitPage,false);}());</script></body></html>';
        exit;
    }

    static public function reserveFloatingAmount($channel, &$order)
    {
        global $DB;
        $base = round((float)($order['money'] ?? $order['realmoney'] ?? 0), 2);
        if ($base <= 0 || empty($order['trade_no'])) return false;
        $ext = !empty($order['ext']) ? @unserialize($order['ext']) : [];
        if (!is_array($ext)) $ext = [];
        if (isset($ext['yunalipayxd_float_amount'])) {
            $assigned = round((float)$ext['yunalipayxd_float_amount'], 2);
            if ($assigned > 0) {
                $order['realmoney'] = number_format($assigned, 2, '.', '');
                return true;
            }
        }
        $channelId = (int)($channel['id'] ?? 0);
        $subchannelId = (int)($order['subchannel'] ?? $channel['subid'] ?? 0);
        $tradeNo = daddslashes((string)$order['trade_no']);
        $lockName = 'yunalipayxd_float_' . $channelId . '_' . $subchannelId . '_' . number_format($base, 2, '.', '_');
        $locked = (int)$DB->getColumn('SELECT GET_LOCK(:lock_name, 5)', [':lock_name' => $lockName]);
        if ($locked !== 1) return false;
        try {
            $rows = $DB->getAll("SELECT trade_no,realmoney,ext FROM pre_order WHERE channel='" . $channelId . "' AND subchannel='" . $subchannelId . "' AND status=0 AND trade_no<>'" . $tradeNo . "' AND addtime >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
            $used = [];
            foreach ($rows as $row) {
                $rowExt = !empty($row['ext']) ? @unserialize($row['ext']) : [];
                $rowBase = is_array($rowExt) && isset($rowExt['yunalipayxd_float_base']) ? round((float)$rowExt['yunalipayxd_float_base'], 2) : round((float)$row['realmoney'], 2);
                if (abs($rowBase - $base) > 0.001) continue;
                $amount = is_array($rowExt) && isset($rowExt['yunalipayxd_float_amount']) ? round((float)$rowExt['yunalipayxd_float_amount'], 2) : round((float)$row['realmoney'], 2);
                $used[number_format($amount, 2, '.', '')] = true;
            }
            $assigned = $base;
            while (isset($used[number_format($assigned, 2, '.', '')])) $assigned = round($assigned + self::FLOAT_STEP, 2);
            $ext['yunalipayxd_float_base'] = $base;
            $ext['yunalipayxd_float_amount'] = $assigned;
            $oldRealMoney = (float)($order['realmoney'] ?? $base);
            $order['realmoney'] = number_format($assigned, 2, '.', '');
            $data = ['realmoney' => $order['realmoney'], 'ext' => serialize($ext)];
            if (isset($order['getmoney'])) $data['getmoney'] = number_format(max(0, (float)$order['getmoney'] + $assigned - $oldRealMoney), 2, '.', '');
            return $DB->update('order', $data, ['trade_no' => $order['trade_no']]) !== false;
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
        }
    }

    static public function markExpired($order)
    {
        global $DB;
        if ((int)($order['status'] ?? 0) !== 0) return false;
        $ext = !empty($order['ext']) ? @unserialize($order['ext']) : [];
        if (!is_array($ext)) $ext = [];
        if (!empty($ext['yunalipayxd_expired'])) return false;
        $ext['yunalipayxd_expired'] = 1;
        $ext['yunalipayxd_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static public function isExpired($order)
    {
        if (!empty($order['ext'])) {
            $ext = @unserialize($order['ext']);
            if (is_array($ext) && !empty($ext['yunalipayxd_expired'])) return true;
        }
        return !empty($order['addtime']) && strtotime($order['addtime']) + self::EXPIRE_SECONDS <= time();
    }

    static private function saveChannelConfig($channelId, $updates)
    {
        if(\lib\AdminApp\PluginConfig::activeFor('yunalipayxd'))return \lib\AdminApp\PluginConfig::capture('yunalipayxd',$channelId,$updates);
        global $DB;
        $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . (int)$channelId . "' LIMIT 1");
        if (!$row || $row['plugin'] !== 'yunalipayxd') return false;
        $config = json_decode($row['config'], true);
        if (!is_array($config)) $config = [];
        foreach ($updates as $key => $value) $config[$key] = (string)$value;
        return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }

    static private function gatewayRequest($method, $url, $params, $config, $query = false)
    {
        try {
            $proxyHeaders = self::proxyClusterHeaders($config);
        } catch (\Throwable $e) {
            // 代理模式开启时绝不允许静默退回直连，避免支付宝请求从错误出口发出。
            return ['code' => -1, 'msg' => $e->getMessage(), 'data' => []];
        }
        $headers = ['Accept: application/json, */*', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122 Safari/537.36'];
        foreach ($proxyHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $method = strtoupper($method);
        $body = null;
        if ($query && !empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($params ?: [], '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '');
        }
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $error !== '') return ['code' => -1, 'msg' => '请求云端失败：' . $error, 'data' => []];
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) return ['code' => -1, 'msg' => '云端返回不是 JSON（HTTP ' . $status . '）', 'data' => []];
        return ['code' => 0, 'msg' => '', 'data' => $data, 'http_code' => $status];
    }

    /** 云端使用这些请求头建立它自己的出口代理，PHP 不在本机连接 SOCKS。 */
    static private function proxyClusterHeaders($config)
    {
        $mode = strtolower(trim((string)($config['proxy_mode'] ?? 'no_proxy')));
        if (in_array($mode, ['', 'no_proxy', 'none', '0'], true)) return [];
        if ($mode !== 'custom_proxy') {
            throw new \InvalidArgumentException('代理模式无效，已拒绝直连云端');
        }
        $proxy = trim((string)($config['proxy'] ?? ''));
        if ($proxy === '') {
            throw new \InvalidArgumentException('代理模式已开启，但未填写代理地址，已拒绝直连云端');
        }
        if (strpos($proxy, '://') === false) $proxy = 'socks5://' . $proxy;
        $parts = parse_url($proxy);
        if (!$parts || empty($parts['host'])) {
            throw new \InvalidArgumentException('代理模式已开启，但代理地址格式无效，已拒绝直连云端');
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

    static private function findValue($data, $keys)
    {
        if (!is_array($data)) return '';
        foreach ($keys as $key) {
            if (isset($data[$key]) && (is_scalar($data[$key]) || $data[$key] === null) && (string)$data[$key] !== '') return (string)$data[$key];
        }
        foreach (['data', 'result', 'payload', 'body', 'record', 'report', 'order', 'response', 'content', 'items'] as $key) {
            if (isset($data[$key])) {
                $value = self::findValue($data[$key], $keys);
                if ($value !== '') return $value;
            }
        }
        return '';
    }

    static private function getConfig($channel)
    {
        $rawType = trim((string)($channel['type'] ?? ''));
        $type = $rawType === '' ? 'pid' : $rawType;
        $configError = '';
        if (!in_array($type, ['pid', 'qrcode'], true)) {
            $configError = '收款码类型配置无效，请选择 PID 或二维码';
            $type = 'pid';
        }
        $rawMode = trim((string)($channel['qrcode_mod'] ?? ''));
        $qrcodeMode = $rawMode === '' ? '9' : $rawMode;
        if ($type === 'pid' && !in_array($qrcodeMode, ['9', '10', '11'], true)) {
            $configError = 'PID 二维码模式配置无效，请选择有效模式';
            $qrcodeMode = '9';
        }
        $qrcode = trim((string)($channel['qrcode'] ?? ''));
        return [
            'gateway' => rtrim(trim((string)($channel['gateway'] ?? '')), '/'),
            'type' => $type,
            'pid' => trim((string)($channel['pid'] ?? '')),
            'qrcode_mod' => $qrcodeMode,
            'uid' => trim((string)($channel['uid'] ?? '')),
            'login_type' => in_array((string)($channel['login_type'] ?? ''), ['pid', 'qrcode'], true) ? (string)$channel['login_type'] : $type,
            'qrcode' => $qrcode,
            'report_key' => trim((string)($channel['report_key'] ?? '')),
            'report_id' => trim((string)($channel['report_id'] ?? '')),
            'merchant_id' => trim((string)($channel['merchant_id'] ?? '')),
            'proxy_mode' => trim((string)($channel['proxy_mode'] ?? '')) ?: 'no_proxy',
            'proxy' => trim((string)($channel['proxy'] ?? '')),
            'config_error' => $configError,
        ];
    }
}
