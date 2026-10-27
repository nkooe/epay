<?php

/**
 * 支付宝账单云端自动版-XD：通过网关开放平台登录获取应用数据 URL，
 * 再由插件使用应用凭据调用支付宝官方账单接口。
 */
class alipaybillautoxd_plugin
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        require_once __DIR__.'/monitor.php';
        \lib\Task\PaymentMonitor::start('alipaybillautoxd', $channels, $context, static function($channel){
            $loaded=self::loadCredentials($channel);
            if((int)($loaded['code']??-1)!==0)throw new \lib\Task\ServiceFault((string)$loaded['msg']);
        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            foreach($targets as $target){
                \lib\Task\PaymentMonitor::activate($target);
                \lib\Task\PaymentMonitor::checkpoint();
                \EpayMonitor\alipaybillautoxd\monitorTarget($target,$state);
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
    const FLOAT_STEP = 0.01;
    const ALIPAY_GATEWAY = 'https://openapi.alipay.com/gateway.do';

    static public $info = [
        'name' => 'alipaybillautoxd',
        'showname' => '支付宝账单云端自动版-XD',
        'author' => 'Coco',
        'link' => '',
        'types' => ['alipay'],
        'inputs' => [
            'gateway' => [
                'name' => '网关地址（完整 URL）',
                'type' => 'input',
                'note' => '填写云端完整地址，保存后再点击自动配置',
            ],
            'app_data_url' => [
                'name' => '地址',
                'type' => 'input',
                'note' => '粘贴登录后获取的应用数据同步地址',
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
                'name' => '支付宝 PID',
                'type' => 'input',
                'note' => '自动配置获取，也可手动填写',
            ],
            'qrcode_mod' => [
                'name' => '二维码模式',
                'type' => 'select',
                'options' => [
                    '9' => '免输-转账模式①',
                    '10' => '免输-转账模式②',
                    '11' => '免输-二维码模式③',
                ],
                'note' => 'PID 模式下选择支付模式',
            ],
            'qrcode' => [
                'name' => '收款码地址',
                'type' => 'input',
                'note' => '填写支付宝收款码地址',
            ],
            'app_id' => [
                'name' => '应用 ID',
                'type' => 'input',
                'note' => '自动配置获取，也可手动填写',
            ],
            'app_secret' => [
                'name' => '应用私钥',
                'type' => 'textarea',
                'note' => '自动配置获取，也可手动填写',
            ],
            'alipay_public_key' => [
                'name' => '支付宝公钥（投诉同步）',
                'type' => 'textarea',
                'note' => '用于验证支付宝官方投诉响应，从支付宝开放平台获取；绑定投诉时自动复用此配置，不影响账单收款',
            ],
            'app_auth_token' => [
                'name' => '应用授权令牌（可选）',
                'type' => 'input',
                'note' => '仅服务商代调用投诉接口时配置',
            ],
            'proxy_enabled' => [
                'name' => '代理是否开启',
                'type' => 'select',
                'options' => [
                    '0' => '否',
                    '1' => '是',
                ],
            ],
            'proxy' => [
                'name' => '代理地址',
                'type' => 'input',
                'note' => '填写 Socks5 或 HTTP 代理地址',
            ],
        ],
        'note' => '<style>form#form-info + br,form#form-info ~ br{display:none!important}#form-info{text-align:left!important;overflow:visible}#form-info #save,#form-info .alipay-bill-auto-xd-auto-action{display:inline-block!important;float:none!important;width:48%!important;box-sizing:border-box;vertical-align:top;margin:0 0 12px!important}#form-info #save{margin-left:4%!important}.alipay-bill-auto-xd-auto-action{text-align:center;line-height:20px;padding:10px 16px;background:#1677ff;color:#fff!important;border-radius:4px;text-decoration:none}.alipay-bill-auto-xd-auto-action:hover{background:#0e63dc;text-decoration:none}.alipay-bill-auto-xd-config-note{display:block;margin-top:12px;line-height:1.8;clear:both}#form-info input[name="config[pid]"]{background:#f5f7fa;cursor:not-allowed}#form-info input[name="config[app_id]"],#form-info textarea[name="config[app_secret]"]{display:none}</style><a class="alipay-bill-auto-xd-auto-action" href="#" target="_blank" onclick="var g=document.querySelector(\'input[name=\"config[gateway]\"]\');var u=(g&&g.value?g.value.trim():\'\').replace(/\\/$/,\'\');if(!u){alert(\'请先填写网关地址\');return false;}this.href=u+\'/alipayopen/login\';return true;">自动配置</a><span class="alipay-bill-auto-xd-config-note">点击“自动配置”打开网关 /alipayopen/login，登录后复制应用数据 URL，粘贴到上方并保存。插件会从该 URL 导入 APPID、应用私钥和 PID，随后直接调用支付宝官方账单接口。应用数据 URL 未导入成功前不会清理旧凭据。</span><script>(function(){var f=document.getElementById(\'form-info\'),a=document.querySelector(\'.alipay-bill-auto-xd-auto-action\'),s=document.getElementById(\'save\'),type=document.querySelector("select[name=\'config[type]\']"),pid=document.querySelector("input[name=\'config[pid]\']"),mode=document.querySelector("select[name=\'config[qrcode_mod]\']"),qr=document.querySelector("input[name=\'config[qrcode]\']"),proxy=document.querySelector("select[name=\'config[proxy_mode]\']"),box=function(el){return el&&el.closest?el.closest(".form-group"):null};if(f&&a&&s&&a.parentNode!==f){f.insertBefore(a,s);}if(pid){pid.readOnly=true;pid.setAttribute(\'aria-readonly\',\'true\');}function sync(){var q=box(qr),p=box(pid),m=box(mode),pr=box(proxy);if(q)q.style.display=type&&type.value===\"qrcode\"?\"\":\"none\";if(p)p.style.display=type&&type.value===\"pid\"?\"\":\"none\";if(m)m.style.display=type&&type.value===\"pid\"?\"\":\"none\";if(pr)pr.style.display=proxy&&proxy.value===\"custom_proxy\"?\"\":\"none\";}if(type)type.addEventListener(\'change\',sync);if(proxy)proxy.addEventListener(\'change\',sync);sync();}());</script>',
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    /**
     * 管理端“获取”按钮的服务端代理。浏览器不直接跨域请求应用数据地址，
     * 由插件按通道代理配置请求并返回已提取的字段。
     */
    /** 共用导入流程：完整获取并验证数据后，才替换现有凭据。 */
    static public function fetchApplicationData($channel, $url)
    {
        if (!is_string($url) || strlen($url) > 8192 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) throw new \RuntimeException('应用数据地址格式无效');
        $config = self::getConfig($channel);
        if ($config['config_error'] !== '') throw new \RuntimeException('请先核对并保存网关与代理配置');
        $response = self::gatewayRequest('GET', $url, [], $config, false);
        if (($response['code'] ?? -1) !== 0) throw new \RuntimeException('应用数据获取失败，请核对同步地址');
        $data = self::unwrapAppData($response['data'] ?? []);
        $appId = self::findValue($data, ['app_id', 'appid', 'appId', 'application_id']);
        $secret = self::findValue($data, ['private_key', 'privateKey', 'app_secret', 'appSecret', 'app_private_key']);
        if ($appId === '' || $secret === '') throw new \RuntimeException('地址未返回完整应用信息');
        $updates = ['app_id'=>$appId,'app_secret'=>$secret,'app_data_hash'=>hash('sha256',$url),'app_data_url'=>''];
        $pid = self::findValue($data, ['pid', 'user_id', 'userId', 'alipay_user_id', 'principal_id']);
        if ($pid !== '') $updates['pid']=$pid;
        return $updates;
    }

    static public function adminFetch($channel)
    {
        $result = ['code'=>-1,'msg'=>'','data'=>[]];
        try {
            $updates = self::fetchApplicationData($channel, trim((string)($_POST['url'] ?? '')));
            $result = ['code'=>0,'msg'=>'','data'=>array_intersect_key($updates,array_flip(['app_id','app_secret','pid']))];
        } catch (\Throwable $e) { $result['msg']=$e->getMessage(); }
        @header('Content-Type: text/html; charset=UTF-8');
        echo '<textarea id="alipay-bill-auto-xd-fetch-response" style="display:none">' . htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '</textarea>';
        exit;
    }

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

        $loaded = self::loadCredentials($channel);
        if (($loaded['code'] ?? -1) === 0) {
            $config = $loaded['config'];
        }

        if (isset($_GET['alipay_bill_auto_xd_transfer']) && (string)$_GET['alipay_bill_auto_xd_transfer'] === '1') {
            if ($config['config_error'] !== '' || $config['type'] !== 'pid' || $config['qrcode_mod'] !== '10') {
                exit('当前订单启动模式不可用');
            }
            if ((int)($order['status'] ?? 0) !== 0 || self::isExpired($order)) {
                self::markExpired($order);
                exit('订单已失效');
            }
            if (!self::reserveFloatingAmount($channel, $order)) exit('订单金额准备失败');
            self::renderTransferLaunchPage($config['pid'], number_format((float)$order['realmoney'], 2, '.', ''), (string)$order['trade_no']);
        } elseif (isset($_GET['alipay_bill_auto_xd_launch']) && (string)$_GET['alipay_bill_auto_xd_launch'] === '1') {
            if ($config['config_error'] !== '' || $config['type'] !== 'pid' || $config['qrcode_mod'] !== '11') {
                exit('当前订单启动模式不可用');
            }
            if ((int)($order['status'] ?? 0) !== 0 || self::isExpired($order)) {
                self::markExpired($order);
                exit('订单已失效');
            }
            if (!self::reserveFloatingAmount($channel, $order)) exit('订单金额准备失败');
            self::renderTransferLaunchPage($config['pid'], number_format((float)$order['realmoney'], 2, '.', ''), (string)$order['trade_no']);
        } elseif ($config['config_error'] !== '') {
            $error_msg = $config['config_error'];
        } elseif ((int)($order['status'] ?? 0) !== 0) {
            $error_msg = '订单状态已改变，请返回订单页面查看结果';
        } elseif (self::isExpired($order)) {
            self::markExpired($order);
            $error_msg = '订单已超时，请重新发起支付';
        } elseif (($loaded['code'] ?? -1) !== 0) {
            $error_msg = $loaded['msg'] ?? '应用数据尚未导入，请先完成自动配置';
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
        return ['type' => 'error', 'msg' => '该插件使用 受管收款监控 轮询支付宝官方账单，不提供 HTTP 回调'];
    }

    static public function createCode($config, $order)
    {
        global $siteurl;
        if (($config['config_error'] ?? '') !== '') return ['code' => -1, 'msg' => $config['config_error']];
        if ($config['type'] === 'pid') {
            if ($config['pid'] === '') return ['code' => -1, 'msg' => '尚未从应用数据 URL 获取 PID，请先完成自动配置'];
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
                $launchUrl = rtrim((string)$siteurl, '/') . '/pay/qrcode/' . rawurlencode($tradeNo) . '/?alipay_bill_auto_xd_transfer=1';
                return ['code' => 0, 'qrcode' => $launchUrl];
            }
            if ($mode === '11') {
                // 模式③对应参考插件的 HTML 结果：二维码直接打开本插件 HTTPS 页面，
                // 页面内由 AlipayJSBridge 调用 20000123，避免 render 中间页吞掉参数。
                $launchUrl = rtrim((string)$siteurl, '/') . '/pay/qrcode/' . rawurlencode($tradeNo) . '/?alipay_bill_auto_xd_launch=1';
                return ['code' => 0, 'qrcode' => $launchUrl];
            }
        }
        if ($config['qrcode'] === '') return ['code' => -1, 'msg' => '请填写支付宝收款码地址'];
        return ['code' => 0, 'qrcode' => $config['qrcode']];
    }

    /**
     * 读取支付宝官方账单。网关只用于 /alipayopen/login 获取应用数据 URL，
     * 账单查询由本插件直接签名调用 alipay.data.bill.accountlog.query。
     */
    static public function getBills($channel, $startTime, $endTime)
    {
        $loaded = self::loadCredentials($channel);
        if (($loaded['code'] ?? -1) !== 0) return ['code' => -1, 'msg' => $loaded['msg'], 'items' => []];
        $config = $loaded['config'];
        $start = date('Y-m-d H:i:s', max(0, (int)$startTime));
        $end = date('Y-m-d H:i:s', max((int)$startTime + 1, (int)$endTime));
        try {
            $result = self::officialBillQuery($config, $start, $end);
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => '支付宝官方账单查询失败：' . $e->getMessage(), 'items' => []];
        }
        $items = self::extractBillItems($result);
        return ['code' => 0, 'msg' => '', 'items' => $items, 'raw' => $result];
    }

    static private function loadCredentials($channel)
    {
        $config = self::getConfig($channel);
        if ($config['config_error'] !== '') return ['code' => -1, 'msg' => $config['config_error']];
        $url = trim((string)$config['app_data_url']);
        $hash = $url !== '' ? hash('sha256', $url) : '';
        $needsImport = $url !== '' && ($config['app_id'] === '' || $config['app_secret'] === '' || $config['app_data_hash'] !== $hash);
        if ($needsImport) {
            // A newly entered URL starts a new credential set. Clear imported values
            // before fetching it, while leaving unrelated channel settings intact.
            if ($config['app_data_hash'] !== $hash) {
                $cleared = ['app_id' => '', 'app_secret' => '', 'pid' => '', 'app_data_hash' => ''];
                if (!self::saveChannelConfig($channel, $cleared)) return ['code' => -1, 'msg' => '新应用数据 URL 已填写，但清理旧凭据失败'];
                $config = array_merge($config, $cleared);
            }
            $response = self::gatewayRequest('GET', $url, [], $config, false);
            if (($response['code'] ?? -1) !== 0) return ['code' => -1, 'msg' => '请求应用数据 URL 失败：' . $response['msg']];
            $data = self::unwrapAppData($response['data']);
            $appId = self::findValue($data, ['app_id', 'appid', 'appId', 'application_id']);
            $secret = self::findValue($data, ['private_key', 'privateKey', 'app_secret', 'appSecret', 'app_private_key']);
            if ($appId === '' || $secret === '') return ['code' => -1, 'msg' => '应用数据 URL 未返回完整 APPID 和应用私钥'];
            // 成功导入后清空一次性 URL，避免把可读取私钥的链接长期留在通道配置中。
            $updates = ['app_id' => $appId, 'app_secret' => $secret, 'app_data_hash' => $hash, 'app_data_url' => ''];
            $pid = self::findValue($data, ['pid', 'user_id', 'userId', 'alipay_user_id', 'principal_id']);
            if ($pid !== '') $updates['pid'] = $pid;
            if (!self::saveChannelConfig($channel, $updates)) return ['code' => -1, 'msg' => '应用数据已取得，但写入通道配置失败'];
            $config = array_merge($config, $updates);
        }
        if ($config['app_id'] === '' || $config['app_secret'] === '') return ['code' => -1, 'msg' => '尚未配置应用数据 URL，请先点击自动配置并填写 URL'];
        return ['code' => 0, 'config' => $config];
    }

    static private function unwrapAppData($data)
    {
        if (!is_array($data)) return [];
        foreach (['data', 'result', 'payload', 'app'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $nested = self::unwrapAppData($data[$key]);
                if ($nested) return $nested;
            }
        }
        return $data;
    }

    static private function officialBillQuery($config, $start, $end)
    {
        // 使用插件内签名请求，确保自定义 HTTP/Socks5 出口也能生效；
        // 应用数据 URL 未返回支付宝公钥时也不需要额外手工配置。
        return self::signedOfficialRequest(['app_id' => $config['app_id'], 'app_private_key' => self::normalizePrivateKey($config['app_secret'])], $start, $end, $config);
    }

    static private function signedOfficialRequest($config, $start, $end, $requestConfig)
    {
        $params = [
            'app_id' => $config['app_id'], 'method' => 'alipay.data.bill.accountlog.query',
            'format' => 'json', 'charset' => 'UTF-8', 'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'), 'version' => '1.0',
            'biz_content' => json_encode(['start_time' => $start, 'end_time' => $end, 'page_no' => 1, 'page_size' => 2000], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        ksort($params);
        $signContent = [];
        foreach ($params as $key => $value) if ($value !== '' && $value !== null) $signContent[] = $key . '=' . $value;
        $private = openssl_pkey_get_private($config['app_private_key']);
        if (!$private || !openssl_sign(implode('&', $signContent), $signature, $private, OPENSSL_ALGO_SHA256)) throw new \RuntimeException('应用私钥无效');
        $params['sign'] = base64_encode($signature);
        $raw = self::curlRequest('POST', self::ALIPAY_GATEWAY, $params, [], $requestConfig);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // 支付宝账单接口常返回 text/html;charset=GBK 的 JSON，先转 UTF-8 再解析。
            $utf8 = function_exists('mb_convert_encoding')
                ? @mb_convert_encoding($raw, 'UTF-8', 'GBK,GB2312,BIG5')
                : @iconv('GBK', 'UTF-8//IGNORE', $raw);
            if (is_string($utf8) && $utf8 !== '') $data = json_decode($utf8, true);
        }
        if (!is_array($data)) throw new \RuntimeException('支付宝返回不是 JSON');
        $response = $data['alipay_data_bill_accountlog_query_response'] ?? $data['response'] ?? $data;
        if ((string)($response['code'] ?? '10000') !== '10000') throw new \RuntimeException((string)($response['sub_msg'] ?? $response['msg'] ?? '官方账单接口返回失败'));
        return $response;
    }

    static private function extractBillItems($payload)
    {
        if (!is_array($payload)) return [];
        foreach (['items', 'list', 'records', 'bills', 'bill_list', 'detail_list', 'data', 'result'] as $key) {
            if (!array_key_exists($key, $payload)) continue;
            $value = $payload[$key];
            if (is_array($value)) {
                if (self::isBillRow($value)) return [$value];
                $rows = [];
                foreach ($value as $row) {
                    if (is_array($row) && self::isBillRow($row)) $rows[] = $row;
                }
                if ($rows) return $rows;
                $rows = self::extractBillItems($value);
                if ($rows) return $rows;
            }
        }
        $rows = [];
        foreach ($payload as $row) {
            if (is_array($row) && self::isBillRow($row)) $rows[] = $row;
        }
        return $rows;
    }

    static private function isBillRow($row)
    {
        if (!is_array($row)) return false;
        foreach (['amount', 'trans_amount', 'total_amount', 'pay_amount', 'paid_amount', 'money'] as $key) {
            if (array_key_exists($key, $row)) return true;
        }
        return false;
    }

    static public function billValues($row)
    {
        $amount = self::findValue($row, ['amount', 'trans_amount', 'total_amount', 'pay_amount', 'paid_amount', 'money']);
        $centAmount = self::findValue($row, ['amount_cent', 'amount_fen', 'total_fee', 'fee']);
        if ($centAmount !== '' && $amount === '') $amount = (string)((float)$centAmount / 100);
        $time = self::parseBillTime(self::findValue($row, ['pay_time', 'payTime', 'trans_time', 'transTime', 'trans_dt', 'pay_date', 'trade_time', 'create_time', 'created_at', 'time', 'timestamp']));
        return [
            'id' => self::findValue($row, ['alipay_order_no', 'alipayOrderNo', 'trade_no', 'tradeNo', 'trans_id', 'transId', 'bill_no', 'billNo', 'order_id', 'orderId', 'id']),
            'amount' => (float)$amount,
            'remark' => self::findValue($row, ['trans_memo', 'remark', 'memo', 'subject', 'body', 'order_title']),
            'buyer' => self::findValue($row, ['other_account', 'buyer_id', 'buyerId', 'payer', 'payer_name', 'buyer_name']),
            'time' => $time,
            'direction' => strtolower(self::findValue($row, ['direction', 'income_type', 'in_out', 'type'])),
            'type' => strtolower(self::findValue($row, ['type', 'trade_type', 'transaction_type'])),
            'merchant_order_no' => self::findValue($row, ['merchant_order_no', 'merchantOrderNo', 'out_trade_no']),
            'state' => strtolower(self::findValue($row, ['status', 'trade_status', 'pay_status', 'state', 'result_status'])),
            'raw' => $row,
        ];
    }

    static private function parseBillTime($value)
    {
        if ($value === '' || $value === null) return 0;
        if (is_numeric($value)) {
            $value = (int)$value;
            if ($value > 20000000000) $value = (int)floor($value / 1000);
            return $value;
        }
        $time = strtotime((string)$value);
        return $time === false ? 0 : $time;
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
        if (isset($ext['alipaybillautoxd_float_amount'])) {
            $assigned = round((float)$ext['alipaybillautoxd_float_amount'], 2);
            if ($assigned > 0) {
                $order['realmoney'] = number_format($assigned, 2, '.', '');
                return true;
            }
        }
        $channelId = (int)($channel['id'] ?? 0);
        $subchannelId = (int)($order['subchannel'] ?? $channel['subid'] ?? 0);
        $tradeNo = daddslashes((string)$order['trade_no']);
        $lockName = 'alipaybillautoxd_float_' . $channelId . '_' . $subchannelId . '_' . number_format($base, 2, '.', '_');
        $locked = (int)$DB->getColumn('SELECT GET_LOCK(:lock_name, 5)', [':lock_name' => $lockName]);
        if ($locked !== 1) return false;
        try {
            $rows = $DB->getAll("SELECT trade_no,realmoney,ext FROM pre_order WHERE channel='" . $channelId . "' AND subchannel='" . $subchannelId . "' AND status=0 AND trade_no<>'" . $tradeNo . "' AND addtime >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
            $used = [];
            foreach ($rows as $row) {
                $rowExt = !empty($row['ext']) ? @unserialize($row['ext']) : [];
                $rowBase = is_array($rowExt) && isset($rowExt['alipaybillautoxd_float_base']) ? round((float)$rowExt['alipaybillautoxd_float_base'], 2) : round((float)$row['realmoney'], 2);
                if (abs($rowBase - $base) > 0.001) continue;
                $amount = is_array($rowExt) && isset($rowExt['alipaybillautoxd_float_amount']) ? round((float)$rowExt['alipaybillautoxd_float_amount'], 2) : round((float)$row['realmoney'], 2);
                $used[number_format($amount, 2, '.', '')] = true;
            }
            $assigned = $base;
            while (isset($used[number_format($assigned, 2, '.', '')])) $assigned = round($assigned + self::FLOAT_STEP, 2);
            $ext['alipaybillautoxd_float_base'] = $base;
            $ext['alipaybillautoxd_float_amount'] = $assigned;
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
        if (!empty($ext['alipaybillautoxd_expired'])) return false;
        $ext['alipaybillautoxd_expired'] = 1;
        $ext['alipaybillautoxd_expired_at'] = date('Y-m-d H:i:s');
        return $DB->update('order', ['ext' => serialize($ext)], ['trade_no' => $order['trade_no']]) !== false;
    }

    static public function isExpired($order)
    {
        if (!empty($order['ext'])) {
            $ext = @unserialize($order['ext']);
            if (is_array($ext) && !empty($ext['alipaybillautoxd_expired'])) return true;
        }
        return !empty($order['addtime']) && strtotime($order['addtime']) + self::EXPIRE_SECONDS <= time();
    }

    static private function saveChannelConfig($channel, $updates)
    {
        if(\lib\Task\PaymentMonitor::activeFor('alipaybillautoxd'))return \lib\Task\PaymentMonitor::saveCredentials($channel,$updates,['app_id', 'app_secret', 'pid', 'app_data_hash', 'app_data_url'],true);
        global $DB;
        $channelId = (int)($channel['id'] ?? 0);
        $subchannelId = (int)($channel['subid'] ?? 0);
        if ($channelId <= 0) return false;
        if ($subchannelId > 0) {
            $row = $DB->getRow("SELECT A.plugin,B.info FROM pre_subchannel B INNER JOIN pre_channel A ON B.channel=A.id WHERE B.id='" . $subchannelId . "' LIMIT 1");
            if (!$row || $row['plugin'] !== 'alipaybillautoxd') return false;
            $config = json_decode((string)($row['info'] ?? ''), true);
            if (!is_array($config)) $config = [];
            foreach ($updates as $key => $value) $config[$key] = (string)$value;
            return $DB->update('subchannel', ['info' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $subchannelId]) !== false;
        }
        $row = $DB->getRow("SELECT plugin,config FROM pre_channel WHERE id='" . $channelId . "' LIMIT 1");
        if (!$row || $row['plugin'] !== 'alipaybillautoxd') return false;
        $config = json_decode((string)$row['config'], true);
        if (!is_array($config)) $config = [];
        foreach ($updates as $key => $value) $config[$key] = (string)$value;
        return $DB->update('channel', ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $channelId]) !== false;
    }

    static private function normalizePrivateKey($key)
    {
        $key = trim((string)$key);
        $key = str_replace(["\\r", "\\n", "\r", "\n"], ['', "\n", '', "\n"], $key);
        if (strpos($key, '-----BEGIN') === false) {
            $body = str_replace(["\r", "\n", ' ', "\t"], '', $key);
            $der = base64_decode($body, true);
            // 应用数据地址常返回 PKCS#1 RSA 私钥（MIIEow...），
            // 不能误包成 PKCS#8 的 BEGIN PRIVATE KEY。
            $label = ($der !== false && strpos(substr($der, 0, 24), "\x02\x01\x00\x02") !== false) ? 'RSA PRIVATE KEY' : 'PRIVATE KEY';
            $key = "-----BEGIN {$label}-----\n" . wordwrap($body, 64, "\n", true) . "\n-----END {$label}-----";
        }
        return $key;
    }

    static private function curlRequest($method, $url, $params, $extraHeaders, $config)
    {
        $headers = array_merge(['Accept: application/json, */*', 'User-Agent: epay-alipaybillautoxd/1.0'], $extraHeaders);
        $method = strtoupper($method);
        $body = null;
        if ($method === 'GET' && $params) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($params ?: [], '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init($url);
        $curlOptions = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35, CURLOPT_HTTPHEADER => $headers];
        foreach (self::proxyCurlOptions($config) as $key => $value) $curlOptions[$key] = $value;
        curl_setopt_array($ch, $curlOptions);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: ''); }
        $raw = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $error !== '') throw new \RuntimeException('HTTP 请求失败（' . $status . '）：' . $error);
        if ($status < 200 || $status >= 300) throw new \RuntimeException('HTTP ' . $status);
        return (string)$raw;
    }

    static private function gatewayRequest($method, $url, $params, $config, $query = false)
    {
        $headers = ['Accept: application/json, */*', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122 Safari/537.36'];
        $method = strtoupper($method);
        $body = null;
        if ($query && !empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($params ?: [], '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => $headers,
        ];
        foreach (self::proxyCurlOptions($config) as $key => $value) $curlOptions[$key] = $value;
        curl_setopt_array($ch, $curlOptions);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '');
        }
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $error !== '') return ['code' => -1, 'msg' => '请求网关失败：' . $error, 'data' => []];
        if ($status < 200 || $status >= 300) return ['code' => -1, 'msg' => '网关 HTTP ' . $status, 'data' => []];
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) return ['code' => -1, 'msg' => '网关返回不是 JSON（HTTP ' . $status . '）', 'data' => []];
        return ['code' => 0, 'msg' => '', 'data' => $data, 'http_code' => $status];
    }

    /** 将配置的代理转换为 PHP cURL 的实际出口。 */
    static private function proxyCurlOptions($config)
    {
        $mode = strtolower(trim((string)($config['proxy_mode'] ?? 'no_proxy')));
        if (in_array($mode, ['', 'no_proxy', 'none', '0'], true)) return [CURLOPT_PROXY => ''];
        $proxy = trim((string)($config['proxy'] ?? ''));
        if ($proxy === '') throw new \InvalidArgumentException('代理模式已开启，但未填写代理地址');
        if (strpos($proxy, '://') === false) $proxy = 'socks5h://' . $proxy;
        $parts = parse_url($proxy);
        if (!$parts || empty($parts['host']) || empty($parts['port']) || !empty($parts['path']) || !empty($parts['query']) || !empty($parts['fragment'])) throw new \InvalidArgumentException('代理地址格式无效');
        $scheme = strtolower((string)($parts['scheme'] ?? 'socks5h'));
        if (!in_array($scheme, ['http', 'https', 'socks5', 'socks5h'], true)) throw new \InvalidArgumentException('代理协议只支持 Socks5 或 HTTP');
        $type = in_array($scheme, ['socks5', 'socks5h'], true) ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP;
        $host = $parts['host'];
        if (strpos($host, ':') !== false && $host[0] !== '[') $host = '[' . $host . ']';
        $options = [CURLOPT_PROXY => $host . ':' . (int)$parts['port'], CURLOPT_PROXYTYPE => $type, CURLOPT_NOPROXY => ''];
        if (isset($parts['user']) || isset($parts['pass'])) $options[CURLOPT_PROXYUSERPWD] = rawurldecode((string)($parts['user'] ?? '')) . ':' . rawurldecode((string)($parts['pass'] ?? ''));
        return $options;
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
        // 子通道的动态凭据保存在 pre_subchannel.info，优先于父通道默认值。
        $subchannelId = (int)($channel['subid'] ?? 0);
        if ($subchannelId > 0) {
            global $DB;
            $info = $DB->getColumn("SELECT info FROM pre_subchannel WHERE id='" . $subchannelId . "' LIMIT 1");
            $info = json_decode((string)$info, true);
            if (is_array($info)) $channel = array_merge($channel, $info);
        }
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
            'app_data_url' => trim((string)($channel['app_data_url'] ?? '')),
            'type' => $type,
            'pid' => trim((string)($channel['pid'] ?? '')),
            'qrcode_mod' => $qrcodeMode,
            'app_id' => trim((string)($channel['app_id'] ?? '')),
            'app_secret' => trim((string)($channel['app_secret'] ?? '')),
            'app_data_hash' => trim((string)($channel['app_data_hash'] ?? '')),
            'qrcode' => $qrcode,
            'proxy_mode' => ((string)($channel['proxy_enabled'] ?? '') === '1' || (string)($channel['proxy_mode'] ?? '') === 'custom_proxy') ? 'custom_proxy' : 'no_proxy',
            'proxy_enabled' => ((string)($channel['proxy_enabled'] ?? '') === '1' || (string)($channel['proxy_mode'] ?? '') === 'custom_proxy') ? '1' : '0',
            'proxy' => trim((string)($channel['proxy'] ?? '')),
            'config_error' => $configError,
        ];
    }
}

// The footer contains only the automatic configuration action. Field behavior is
// kept in JavaScript so the form stays compact without changing host admin files.
alipaybillautoxd_plugin::$info['note'] = '<style>form#form-info + br,form#form-info ~ br{display:none!important}#form-info{text-align:left!important;overflow:visible}#form-info .alipay-bill-auto-xd-actions{display:flex!important;align-items:stretch;gap:8px;width:100%;margin:12px 0 0!important}#form-info .alipay-bill-auto-xd-actions>a,#form-info .alipay-bill-auto-xd-actions>#save{display:flex!important;align-items:center;justify-content:center;flex:1 1 0!important;float:none!important;width:auto!important;min-height:40px;margin:0!important;box-sizing:border-box}.alipay-bill-auto-xd-auto-action{text-align:center;line-height:20px;padding:10px 16px;background:#1677ff;color:#fff!important;border-radius:4px;text-decoration:none}.alipay-bill-auto-xd-auto-action:hover{background:#0e63dc;text-decoration:none}#form-info input[name="config[pid]"]{background:#f5f7fa;cursor:not-allowed}</style><a class="alipay-bill-auto-xd-auto-action" href="#" target="_blank" onclick="var f=this.closest(\'form\'),g=f&&f.elements[\'config[gateway]\'];var u=(g&&g.value?g.value.trim():\'\').replace(/\\/$/,\'\');if(!u){alert(\'请先配置网关地址并保存后，再重试\');return false;}this.href=u+\'/alipayopen/login\';return true;">自动配置</a><script>(function(){var f=document.getElementById(\'form-info\');if(!f)return;var pick=function(selector){return f.querySelector(selector);};var a=document.currentScript?document.currentScript.previousElementSibling:null,s=f.querySelector(\'#save\');if(!a||!a.classList.contains(\'alipay-bill-auto-xd-auto-action\')){a=document.querySelector(\'.alipay-bill-auto-xd-auto-action\');}var type=pick(\'select[name="config[type]"]\'),pid=pick(\'input[name="config[pid]"]\'),mode=pick(\'select[name="config[qrcode_mod]"]\'),qr=pick(\'input[name="config[qrcode]"]\'),proxy=pick(\'select[name="config[proxy_enabled]"]\'),proxyAddr=pick(\'input[name="config[proxy]"]\'),row=function(el){return el&&el.closest?el.closest(\'.form-group\'):null};if(a&&s&&s.parentNode){var actions=document.createElement(\'div\');actions.className=\'alipay-bill-auto-xd-actions\';s.parentNode.insertBefore(actions,s);actions.appendChild(a);actions.appendChild(s);}if(pid){pid.readOnly=true;pid.setAttribute(\'aria-readonly\',\'true\');}function setVisible(el,visible){if(!el)return;if(visible){el.style.removeProperty(\'display\');}else{el.style.setProperty(\'display\',\'none\',\'important\');}}function sync(){var kind=type?String(type.value).toLowerCase():\'\';var proxyOn=proxy&&String(proxy.value)===\'1\';setVisible(row(pid),kind===\'pid\');setVisible(row(mode),kind===\'pid\');setVisible(row(qr),kind===\'qrcode\');setVisible(row(proxyAddr),proxyOn);}if(type)type.addEventListener(\'change\',sync);if(proxy)proxy.addEventListener(\'change\',sync);sync();}());</script>';

// PID is auto-filled when available, but remains editable for manual configuration.
alipaybillautoxd_plugin::$info['note'] = str_replace(
    [
        '#form-info input[name="config[pid]"]{background:#f5f7fa;cursor:not-allowed}',
        "if(pid){pid.readOnly=true;pid.setAttribute('aria-readonly','true');}",
    ],
    ['', ''],
    alipaybillautoxd_plugin::$info['note']
);

// Add a compact fetch action beside the address field. The returned JSON is
// parsed in the browser and only fills the editable form fields; saving remains
// an explicit admin action.
alipaybillautoxd_plugin::$info['note'] .= '<style>#form-info .alipay-bill-auto-xd-data-row{display:flex!important;gap:8px;align-items:stretch}#form-info .alipay-bill-auto-xd-data-row>input{flex:1 1 auto;min-width:0}#form-info .alipay-bill-auto-xd-fetch{flex:0 0 72px;border:1px solid #1677ff;background:#fff;color:#1677ff;border-radius:4px;cursor:pointer}#form-info .alipay-bill-auto-xd-fetch:hover{background:#eef5ff}</style><script>(function(){var f=document.getElementById(\'form-info\');if(!f)return;var input=f.querySelector(\'input[name="config[app_data_url]"]\');if(!input||input.dataset.xdFetchReady)return;input.dataset.xdFetchReady=\'1\';var group=input.closest(\'.form-group\');if(!group)return;var wrap=document.createElement(\'div\');wrap.className=\'alipay-bill-auto-xd-data-row\';input.parentNode.insertBefore(wrap,input);wrap.appendChild(input);var button=document.createElement(\'button\');button.type=\'button\';button.className=\'alipay-bill-auto-xd-fetch\';button.textContent=\'获取\';wrap.appendChild(button);var value=function(keys,data){if(!data||typeof data!==\'object\')return\'\';for(var i=0;i<keys.length;i++){if(data[keys[i]]!==undefined&&data[keys[i]]!==null&&String(data[keys[i]])!==\'\')return String(data[keys[i]]);}for(var k in data){if(data[k]&&typeof data[k]===\'object\'){var found=value(keys,data[k]);if(found)return found;}}return\'\';};button.addEventListener(\'click\',function(){var url=input.value.trim();if(!url){alert(\'请先填写地址\');return;}button.disabled=true;button.textContent=\'获取中\';fetch(url,{credentials:\'omit\'}).then(function(response){if(!response.ok)throw new Error(\'HTTP \'+response.status);return response.json();}).then(function(data){var appId=value([\'app_id\',\'appid\',\'appId\',\'application_id\'],data),secret=value([\'private_key\',\'privateKey\',\'app_secret\',\'appSecret\',\'app_private_key\'],data),pid=value([\'pid\',\'user_id\',\'userId\',\'alipay_user_id\',\'principal_id\'],data);if(!appId||!secret)throw new Error(\'地址未返回完整应用信息\');var app=f.querySelector(\'input[name="config[app_id]"]\'),key=f.querySelector(\'textarea[name="config[app_secret]"]\'),pidInput=f.querySelector(\'input[name="config[pid]"]\');if(app)app.value=appId;if(key)key.value=secret;if(pid&&pid!==\'\')pidInput.value=pid;alert(\'获取成功，请点击保存\');}).catch(function(error){alert(\'获取失败：\'+error.message);}).finally(function(){button.disabled=false;button.textContent=\'获取\';});});}());</script>';

alipaybillautoxd_plugin::$info['note'] = str_replace(
    "fetch(url,{credentials:'omit'}).then(function(response){if(!response.ok)throw new Error('HTTP '+response.status);return response.json();})",
    "fetch('./plugin_page.php?channel=[channel]&func=adminFetch',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'url='+encodeURIComponent(url)}).then(function(response){return response.text();}).then(function(text){var doc=new DOMParser().parseFromString(text,'text/html'),box=doc.querySelector('#alipay-bill-auto-xd-fetch-response');if(!box)throw new Error('响应格式错误');var result=JSON.parse(box.value||box.textContent||'{}');if(result.code!==0)throw new Error(result.msg||'获取失败');return result.data;})",
    alipaybillautoxd_plugin::$info['note']
);
