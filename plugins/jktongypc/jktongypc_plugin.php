<?php

/** PC 监控适配：通道配置、支付拉起、上报与心跳均由本插件处理。 */
class jktongypc_plugin
{
    const EXPIRE_SECONDS = 300;
    const TIMESTAMP_TOLERANCE = 600;
    // 连续 65 秒未收到客户端有效心跳，收银台视为通道离线。
    const HEARTBEAT_TIMEOUT = 65;
    const OFFLINE_MESSAGE = '通道已经离线，请稍后重试';

    static public $info = [
        'name' => 'jktongypc',
        'showname' => '通用监控-PC',
        'author' => 'Coco',
        'link' => '',
        'types' => ['alipay', 'wxpay'],
        'inputs' => [
            'pay_mode' => ['name' => '收款方式', 'type' => 'select', 'options' => ['self' => '固定码', 'platform' => '个码动态', 'smallbook' => '收款链接', 'receipt' => '收款单']],
            'qrcode_type' => ['name' => '收款类型', 'type' => 'select', 'options' => ['qrcode' => '二维码', 'image' => '图片', 'pid' => 'PID']],
            'qrcode' => ['name' => '收款地址', 'type' => 'textarea', 'note' => '请输入收款地址'],
            'pid' => ['name' => 'PID', 'type' => 'input', 'note' => '请输入PID'],
            'qrcode_mod' => ['name' => '二维码模式', 'type' => 'select', 'options' => ['9' => '模式9', '10' => '模式10', '11' => '模式11']],
            'sid' => ['name' => 'SID', 'type' => 'input', 'note' => '请输入SID或等待客户端上报'],
            'payment_link_sid' => ['name' => 'SID', 'type' => 'input', 'note' => '请输入SID或等待客户端上报'],
            'payment_link_shop_id' => ['name' => '店铺/商户ID', 'type' => 'input', 'note' => '请输入店铺/商户ID或等待自动获取'],
            'receipt_sid' => ['name' => 'SID', 'type' => 'input', 'note' => '请输入SID或等待客户端上报'],
            'receipt_shop_id' => ['name' => '店铺/商户ID', 'type' => 'input', 'note' => '请输入店铺/商户ID或等待自动获取'],
            // 收款单下拉选择的内部账号引用，后台自动维护，不给用户手填。
            'receipt_account_ref' => ['name' => '收款单内部选择', 'type' => 'input', 'note' => '自动维护'],
        ],
        'note' => <<<'HTML'
<style>
#form-info.jktongypc-form + br,#form-info.jktongypc-form ~ br{display:none!important}
#form-info.jktongypc-form{text-align:left!important;overflow:visible}
#form-info.jktongypc-form:not(.jktongypc-ready) .form-group{display:none!important}
#form-info.jktongypc-form .form-group[hidden]{display:none!important}
.jktongypc-config-error{padding:12px 0;color:#666}
#form-info .jktongypc-actions{display:flex!important;align-items:center;gap:4%;width:100%;margin:12px 0!important}
#form-info .jktongypc-actions #save,#form-info .jktongypc-actions .jktongypc-config{display:inline-flex!important;float:none!important;flex:1 1 0;width:auto!important;min-height:42px;box-sizing:border-box;margin:0!important;align-items:center;justify-content:center}
.jktongypc-config{padding:10px 16px;background:#1677ff;color:#fff!important;border-radius:4px;text-decoration:none!important}
.jktongypc-config:hover{background:#0e63dc;color:#fff!important}
.jktongypc-config:focus-visible{outline:2px solid #174f9c;outline-offset:3px}
.jktongypc-note{display:block;clear:both;margin-top:10px;line-height:1.8;color:#008000}
.jktongypc-shop-tools{display:flex;align-items:center;gap:8px;margin-top:8px}
.jktongypc-shop-tools button{min-height:34px;padding:6px 12px;border:1px solid #1677ff;border-radius:4px;background:#fff;color:#1677ff;cursor:pointer}
.jktongypc-shop-tools button:hover{background:#eef5ff}
.jktongypc-shop-tools button:disabled{cursor:wait;opacity:.65}
.jktongypc-shop-tools select{min-width:0;flex:1 1 auto;height:34px;padding:4px 8px;border:1px solid #d8dde3;border-radius:4px;background:#fff}
#form-info .jktongypc-account-ref{display:none!important}
</style>
<a class="jktongypc-config" href="./plugin_page.php?channel=[channel]&func=adminConfig" target="_blank" onclick="return !window.open(this.href,'jktongypc_config_[channel]','width=500,height=700,resizable=yes,scrollbars=yes');">配置信息</a>
<span class="jktongypc-note">首次收到心跳前不可支付；订单有效期 5 分钟，相同通道的待支付订单按 0.01 元递增避重。</span>
<script>
(function(){
    var form=document.getElementById('form-info'),button=document.querySelector('.jktongypc-config'),save=form&&form.querySelector('#save');
    if(!form||!button||!save||form.querySelector('.jktongypc-actions'))return;
    save.disabled=true;
    form.classList.add('jktongypc-form');
    var configError=document.createElement('div');configError.className='jktongypc-config-error';configError.setAttribute('role','alert');configError.hidden=true;form.insertBefore(configError,form.firstChild);
    var actions=document.createElement('div');actions.className='jktongypc-actions';
    save.parentNode.insertBefore(actions,save);actions.appendChild(button);actions.appendChild(save);
    var note=document.querySelector('.jktongypc-note'),defaultNote=note?note.textContent:'',reportReadFailed=false;if(note)form.appendChild(note);
    var fields={},alipay=false,payTypeReady=false,receiptPickerReady=false;
    ['pay_mode','qrcode_type','qrcode','pid','qrcode_mod','sid','payment_link_sid','payment_link_shop_id','receipt_sid','receipt_shop_id','receipt_account_ref'].forEach(function(key){
        var field=form.querySelector('[name="config['+key+']"]');if(!field)return;
        var group=field.closest('.form-group'),label=group&&group.querySelector('label');
        if(key==='receipt_account_ref'&&group)group.classList.add('jktongypc-account-ref');
        field.id='jktongypc-field-'+key;if(label)label.htmlFor=field.id;
        fields[key]={field:field,group:group,label:label};
    });
    var report={merchant_id:null,last_seen:0,values:{}},reportedKeys=['sid','payment_link_sid','payment_link_shop_id','receipt_sid','receipt_shop_id'],resetReceiptPicker=function(){};
    reportedKeys.forEach(function(key){
        if(!fields[key])return;
        var field=fields[key].field,manual=document.createElement('input');
        manual.type='hidden';manual.name=field.name;manual.value=field.value;
        field.removeAttribute('name');field.parentNode.appendChild(manual);fields[key].manual=manual;
        field.addEventListener('input',function(){manual.value=field.value;field.title=''});
    });
    function clearReceiptSelection(){
        var shop=fields.receipt_shop_id,ref=fields.receipt_account_ref;
        if(shop&&shop.manual){shop.field.value='';shop.manual.value='';shop.field.title=''}
        if(ref&&ref.field)ref.field.value='';
        resetReceiptPicker();
    }
    if(fields.receipt_sid)fields.receipt_sid.field.addEventListener('input',clearReceiptSelection);
    if(fields.receipt_shop_id)fields.receipt_shop_id.field.addEventListener('input',function(){if(fields.receipt_account_ref)fields.receipt_account_ref.field.value='';});
    function showReported(next){
        report=next;
        reportedKeys.forEach(function(key){
            var entry=fields[key];if(!entry||!entry.manual)return;
            if(entry.manual.value===''&&document.activeElement!==entry.field){
                var previous=entry.field.value;
                entry.field.value=typeof report.values[key]==='string'?report.values[key]:'';
                entry.field.title=entry.field.value?'客户端上报 · 商户 '+report.merchant_id:'';
                // 首次回显 SID 时保留已保存的选择；已显示的 SID 变更时清理旧账号。
                if(key==='receipt_sid'&&previous!==''&&previous!==entry.field.value)clearReceiptSelection();
            }
        });
    }
    showReported(report);
    function readAdminConfig(action,sid){
        var options={credentials:'same-origin',cache:'no-store'};
        if(action==='receipt_shops'){options.method='POST';options.headers={'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'};options.body='sid='+encodeURIComponent(sid||'')}
        // 后台 Cookie 只在 /admin 生效，使用系统现有后台插件入口承载查询。
        return fetch('./plugin_page.php?channel=[channel]&func=adminReportedConfig&action='+encodeURIComponent(action),options)
            .then(function(response){if(!response.ok)throw new Error('读取客户端上报失败（HTTP '+response.status+'）');return response.text()})
            .then(function(html){
                var payload=new DOMParser().parseFromString(html,'text/html').getElementById('jktongypc-reported-data');
                if(!payload)throw new Error('无法读取客户端上报，请刷新后台并确认登录状态。');
                var result=JSON.parse(payload.textContent);
                if(result.code!==0)throw new Error(result.message||result.msg||'读取客户端上报失败');
                return result.data||{};
            });
    }
    function refreshReported(){
        if(!form.isConnected||alipay)return;
        readAdminConfig('config')
            .then(function(data){if(form.isConnected){showReported(data);if(reportReadFailed&&note)note.textContent=defaultNote;reportReadFailed=false}})
            .catch(function(error){if(form.isConnected){reportReadFailed=true;if(note)note.textContent=error.message}})
            .then(function(){if(form.isConnected)window.setTimeout(refreshReported,5000)});
    }
    var mode=fields.pay_mode&&fields.pay_mode.field,type=fields.qrcode_type.field;
    function setOptions(options){
        if(!type)return;
        var current=type.value;
        type.innerHTML='';
        options.forEach(function(option){var item=document.createElement('option');item.value=option[0];item.textContent=option[1];type.appendChild(item)});
        type.value=options.some(function(option){return option[0]===current})?current:options[0][0];
    }
    function sync(){
        if(!payTypeReady)return;
        var keys;
        if(alipay){setOptions([['qrcode','收款码'],['image','图片'],['pid','pid']]);keys=['qrcode_type'].concat(type.value==='pid'?['pid','qrcode_mod']:['qrcode']);
            if(fields.qrcode){var image=type.value==='image';fields.qrcode.label.textContent=(image?'图片地址':'收款码地址')+'：';fields.qrcode.field.placeholder=image?'请输入图片地址':'请输入收款码地址'}
        }else{setOptions([['qrcode','二维码'],['image','图片']]);var choice=mode.value;keys=['pay_mode'].concat(choice==='self'?['qrcode_type','qrcode']:choice==='platform'?['sid']:choice==='smallbook'?['payment_link_sid','payment_link_shop_id']:['receipt_sid','receipt_shop_id'])}
        Object.keys(fields).forEach(function(key){if(fields[key].group)fields[key].group.hidden=keys.indexOf(key)===-1});
        if(!alipay&&choice==='receipt')setupReceiptPicker();
        form.classList.add('jktongypc-ready');configError.remove();
        window.requestAnimationFrame(function(){
            var content=form.closest('.layui-layer-content'),popup=form.closest('.layui-layer');
            if(!content||!popup)return;
            content.style.height='auto';content.style.maxHeight='calc(100vh - 80px)';content.style.overflowY='auto';
            popup.style.height='auto';popup.style.top=Math.max(12,(window.innerHeight-popup.offsetHeight)/2)+'px';
        });
    }
    function setupReceiptPicker(){
        if(receiptPickerReady||!fields.receipt_shop_id||!fields.receipt_shop_id.group)return;
        receiptPickerReady=true;
        var entry=fields.receipt_shop_id,ref=fields.receipt_account_ref,tools=document.createElement('div'),button=document.createElement('button'),select=document.createElement('select'),requestId=0;
        tools.className='jktongypc-shop-tools';button.type='button';button.textContent='获取全部店铺';select.innerHTML='<option value="">获取后选择店铺/商户ID</option>';
        tools.appendChild(button);tools.appendChild(select);entry.group.appendChild(tools);
        function message(text){var note=form.querySelector('.jktongypc-note');if(note)note.textContent=text;}
        function choose(value,accountRef){entry.field.value=value;entry.manual.value=value;entry.field.title=value?'后台已选择店铺/商户ID':'';if(ref&&ref.field)ref.field.value=accountRef||'';}
        function clearSelection(){entry.field.value='';entry.manual.value='';entry.field.title='';if(ref&&ref.field)ref.field.value='';select.value='';}
        resetReceiptPicker=function(){requestId++;select.innerHTML='<option value="">获取后选择店铺/商户ID</option>';button.disabled=false;button.textContent='获取全部店铺';};
        select.addEventListener('change',function(){var selected=select.options[select.selectedIndex];choose(selected&&selected.dataset.shopId||select.value,select.value)});
        entry.field.addEventListener('input',function(){select.value='';});
        button.addEventListener('click',function(){
            var enteredSid=fields.receipt_sid&&fields.receipt_sid.field?fields.receipt_sid.field.value.trim():'';
            if(!enteredSid){message('请先填写收款单 SID 或等待客户端上报');return;}
            var currentRequest=++requestId;
            button.disabled=true;button.textContent='获取中';message('正在读取当前 SID 的全部授权店铺，请稍候。');
            readAdminConfig('receipt_shops',enteredSid)
                .then(function(data){
                    if(currentRequest!==requestId||!form.isConnected)return;
                    var shops=Array.isArray(data.shops)?data.shops:[];select.innerHTML='<option value="">请选择店铺/商户ID</option>';
                    shops.forEach(function(shop){var option=document.createElement('option'),label=String(shop.shop_id||'');if(shop.shop_name)label+=' · '+shop.shop_name;label+='（账号 '+String(shop.account_id||'')+'）';option.value=String(shop.ref||shop.shop_id||'');option.dataset.shopId=String(shop.shop_id||'');option.textContent=label;select.appendChild(option)});
                    if(!shops.length)throw new Error('当前 SID 没有已授权店铺');
                    var savedRef=ref&&ref.field?String(ref.field.value||''):'';
                    if(savedRef&&Array.prototype.some.call(select.options,function(option){return option.value===savedRef}))select.value=savedRef;
                    if(select.value){var selected=select.options[select.selectedIndex];choose(selected.dataset.shopId||selected.value,select.value)}else clearSelection();
                    message('已获取 '+shops.length+' 个授权店铺，请选择后点击系统保存。');
                })
                .catch(function(error){if(currentRequest===requestId&&form.isConnected)message(error.message||'获取店铺失败，请检查 SID 和客户端登录状态。')})
                .then(function(){if(currentRequest===requestId){button.disabled=false;button.textContent='获取全部店铺'}});
        });
    }
    if(mode)mode.addEventListener('change',sync);if(type)type.addEventListener('change',sync);
    readAdminConfig('config')
        .then(function(data){
            if(!form.isConnected)return;
            if(data.pay_type!=='alipay'&&data.pay_type!=='wxpay')throw new Error('无法读取当前支付方式，请刷新后重试。');
            alipay=data.pay_type==='alipay';payTypeReady=true;showReported(data);sync();save.disabled=false;
            if(!alipay)window.setTimeout(refreshReported,5000);
        })
        .catch(function(error){if(form.isConnected){configError.textContent=error.message||'无法读取通道配置，请刷新后重试。';configError.hidden=false;}});
}());
</script>
HTML,
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    /** 支付方式决定表单字段；仅使用当前模式的明确配置。 */
    static public function configForm($channel, $payType)
    {
        $info = self::$info;
        $keys = $payType === 'alipay'
            ? ['qrcode_type', 'qrcode', 'pid', 'qrcode_mod']
            : ['pay_mode', 'qrcode_type', 'qrcode', 'sid', 'payment_link_sid', 'payment_link_shop_id', 'receipt_sid', 'receipt_shop_id', 'receipt_account_ref'];
        $info['inputs'] = array_intersect_key($info['inputs'], array_flip($keys));
        $info['inputs']['qrcode_type']['name'] = $payType === 'alipay' ? '收款码类型' : '收款类型';
        $info['inputs']['qrcode_type']['options'] = $payType === 'alipay'
            ? ['qrcode' => '收款码', 'image' => '图片', 'pid' => 'pid'] : ['qrcode' => '二维码', 'image' => '图片'];
        $info['note'] = str_replace('[paytype]', $payType === 'alipay' ? 'alipay' : 'wxpay', $info['note']);
        $reported = $payType === 'alipay' ? ['merchant_id' => null, 'values' => []] : self::reportedConfig($channel);
        $info['note'] = str_replace('[reported]', json_encode($reported, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), $info['note']);
        return $info;
    }

    /** 仅用于已登录后台回显；上报值不写成全局手填配置，支付仍按商户读取。 */
    static public function reportedConfig($channel)
    {
        global $DB;
        $empty = ['merchant_id' => null, 'last_seen' => 0, 'values' => []];
        $id = (int)($channel['id'] ?? 0);
        if ($id <= 0 || !$DB) return $empty;
        $value = $DB->getColumn('SELECT v FROM pre_cache WHERE k=:key LIMIT 1', [':key' => self::reportDisplayKey($id)]);
        $pointer = is_string($value) ? json_decode($value, true) : null;
        $merchantId = !empty($channel['subid']) ? (int)$DB->getColumn('SELECT uid FROM pre_subchannel WHERE id=:id', [':id'=>(int)$channel['subid']]) : (int)($pointer['merchant_id'] ?? 0);
        if (!empty($channel['subid'])) $pointer = ['channel_id'=>$id];
        if ($merchantId <= 0 || (int)($pointer['channel_id'] ?? 0) !== $id) return $empty;
        $record = self::heartbeat($merchantId, $id);
        if ((int)($record['channel_id'] ?? 0) !== $id) return $empty;
        $values = array_intersect_key($record['options'] ?? [], array_flip(['sid', 'payment_link_sid', 'payment_link_shop_id', 'receipt_sid', 'receipt_shop_id']));
        return ['merchant_id' => $merchantId, 'last_seen' => (int)($record['last_seen'] ?? 0), 'reported_at' => (int)($record['reported_at'] ?? 0), 'values' => $values];
    }

    /** 使用后台原有插件页携带 /admin 登录 Cookie，查询逻辑保留在插件目录。 */
    static public function adminReportedConfig($channel)
    {
        global $islogin;
        if (!defined('IN_PLUGIN') || !is_array($channel)) {
            http_response_code(404);
            exit;
        }
        if (!headers_sent()) header('Cache-Control: no-store');
        try {
            if ((int)$islogin !== 1) throw new \RuntimeException('请先登录后台', 401);
            if (!checkRefererHost()) throw new \RuntimeException('非法来源', 403);
            if (($channel['plugin'] ?? '') !== 'jktongypc') throw new \RuntimeException('当前通道插件不匹配', 404);
            $action = $_GET['action'] ?? 'config';
            if ($action === 'receipt_shops') {
                $sid = $_POST['sid'] ?? '';
                if (!is_string($sid) || strlen($sid) > 4096) throw new \RuntimeException('SID 格式无效', 400);
                $data = self::adminReceiptShops($channel, $sid);
            } elseif ($action === 'config') {
                $data = self::adminFormConfig($channel);
            } else {
                throw new \RuntimeException('不支持的查询动作', 400);
            }
            $result = ['code' => 0, 'data' => $data];
        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
            $result = ['code' => $status, 'message' => $e->getMessage()];
        }
        // 后台页已输出 HTML，返回数据块供前端解析，不执行其中的脚本。
        echo '<script type="application/json" id="jktongypc-reported-data">', json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), '</script>';
        exit;
    }

    /** 一次查询返回支付方式及上报值；支付宝不读取微信登录态。 */
    static public function adminFormConfig($channel)
    {
        $current = self::reportChannel((int)($channel['id'] ?? 0));
        $reported = $current['typename'] === 'wxpay' ? self::reportedConfig($current) : ['merchant_id' => null, 'last_seen' => 0, 'values' => []];
        return ['pay_type' => $current['typename']] + $reported;
    }

    /** 后台收款单选择器：使用已保存 SID 或最近一次心跳 SID，返回全部授权店铺。 */
    static public function adminReceiptShops($channel, $sidOverride = '')
    {
        $stored = json_decode((string)($channel['config'] ?? ''), true);
        $config = self::config(array_merge(is_array($stored) ? $stored : [], $channel));
        $reported = self::reportedConfig($channel);
        $sidOverride = trim((string)$sidOverride);
        $sid = $sidOverride !== '' ? $sidOverride : ($config['receipt_sid'] !== '' ? $config['receipt_sid'] : trim((string)($reported['values']['receipt_sid'] ?? '')));
        if ($sid === '') throw new \RuntimeException('请先填写收款单 SID 或等待客户端上报', 400);
        require_once __DIR__ . '/Wechat.php';
        $shops = (new jktongypc_wechat())->receiptShops($sid);
        return ['merchant_id' => $reported['merchant_id'], 'sid' => $sid, 'shops' => $shops];
    }

    /** 后台临时工具：仅在浏览器内生成 Base64，不接收提交或保存配置。 */
    static public function adminConfig($channel)
    {
        global $siteurl;
        $platformUrl = rtrim((string)$siteurl, '/');
        $jktongypcPage = 'admin-config';
        include __DIR__ . '/pages.php';
    }

    static public function submit()
    {
        global $order, $channel;
        try {
            self::preparePayment($order, $channel);
            return ['type' => 'jump', 'url' => '/pay/qrcode/' . TRADE_NO . '/'];
        } catch (\RuntimeException $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }

    static public function mapi()
    {
        return self::submit();
    }

    static public function qrcode()
    {
        global $order, $channel, $conf, $cdnpublic;
        $error_msg = '';
        $code_url = '';
        $config = self::effectiveConfig((int)$order['uid'], $channel);
        try {
            self::preparePayment($order, $channel, true);
            $paymentCode = self::paymentCode($order, $channel);
            $code_url = $paymentCode['code'];
            $config['qrcode_type'] = $paymentCode['kind'];
        } catch (\RuntimeException $e) {
            $error_msg = $e->getMessage();
        }
        $payType = (string)($order['typename'] ?? '');
        $browserTitle = '收银台' . (!empty($conf['sitename']) ? '-' . $conf['sitename'] : '');
        $title = ($payType === 'alipay' ? '支付宝' : '微信') . '-收银台';
        $qrcodeType = $config['qrcode_type'];
        $paytime = max(0, self::parseTime($order['addtime'] ?? '') + self::EXPIRE_SECONDS - time());
        $state = self::onlineState((int)$order['uid'], $channel);
        $onlineUntil = $state['online'] ? $state['online_until'] : 0;
        $statusUrl = '/pay/pcstatus/' . $order['trade_no'] . '/?token=' . self::statusToken($order['trade_no']);
        $jktongypcPage = 'qrcode';
        header('Cache-Control: no-store');
        include __DIR__ . '/pages.php';
        exit;
    }

    static public function notify()
    {
        return ['type' => 'error', 'msg' => '请使用 /api/report/{商户ID}/pc 上报收款'];
    }

    /** 仅持有收银台生成的订单令牌才能读取支付及在线状态。 */
    static public function pcstatus()
    {
        global $order, $channel;
        $token = $_GET['token'] ?? '';
        if (!is_string($token) || !hash_equals(self::statusToken($order['trade_no']), $token)) {
            self::respond(403, '收银台状态令牌无效');
        }
        self::respond(200, '操作成功', self::paymentState($order, $channel));
    }

    static private function paymentState($order, $channel)
    {
        $state = self::onlineState((int)$order['uid'], $channel);
        $remaining = max(0, self::parseTime($order['addtime']) + self::EXPIRE_SECONDS - time());
        $paid = (int)$order['status'] === 1;
        $backurl = null;
        if ($paid) {
            $backurl = !empty($order['endtime']) && time() - self::parseTime($order['endtime']) > 300
                ? '/payok.html' : creat_callback($order)['return'];
        }
        $snapshot = self::snapshot($order);
        $configUnchanged = $snapshot && (int)$snapshot['channel_id'] === (int)$channel['id'];
        if (self::paymentConfigChanged(self::readExt($order), self::effectiveConfig((int)$order['uid'], $channel), $order['typename'])) $configUnchanged = false;
        return [
            'online' => $state['online'] && $configUnchanged,
            'blocked_reason' => !$snapshot ? 'order_data' : (!$configUnchanged ? 'config_changed' : null),
            'last_seen' => $state['last_seen'],
            'online_until' => $state['online'] && $configUnchanged ? $state['online_until'] : 0,
            'server_time' => time(),
            'expires_in' => $remaining,
            'paid' => $paid,
            'closed' => !$paid && ((int)$order['status'] !== 0 || $remaining === 0),
            'backurl' => $backurl,
        ];
    }

    /** 系统 api.php 只交付路径；商户和业务规则在插件内解析。 */
    static public function apiEndpoint($path = null)
    {
        $path = trim((string)($path === null ? ($_GET['s'] ?? '') : $path), '/');
        if (!preg_match('#^report/([1-9][0-9]*)/(pc|heart)$#D', $path, $match)) return false;
        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (!in_array($method, ['GET', 'POST'], true)) self::fail(405, '只接受 GET 或 POST 请求');
            $data = self::handleReport((int)$match[1], $match[2], self::readPayload());
            self::respond(200, '操作成功', $data);
        } catch (\RuntimeException $e) {
            self::respond($e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500, $e->getMessage());
        } catch (\Throwable $e) {
            self::respond(500, '接口处理失败，请稍后重试');
        }
        return true;
    }

    static private function handleReport($merchantId, $action, $payload)
    {
        global $DB;
        foreach ($payload as $value) {
            if (!is_string($value) && !is_int($value) && $value !== null) self::fail(400, '上报字段必须是字符串或整数，ext_data 请传 JSON 字符串');
        }
        if (json_encode($payload) === false) self::fail(400, '上报字段必须使用 UTF-8 编码');
        $merchant = self::merchant($merchantId);
        $timestamp = self::parseTime($payload['timestamp'] ?? '');
        if ($timestamp <= 0 || $timestamp > time() + 60 || time() - $timestamp > self::TIMESTAMP_TOLERANCE) self::fail(408, '上报时间戳已过期或无效');
        $sign = (string)($payload['sign'] ?? '');
        if (!preg_match('/^[a-fA-F0-9]{32}$/D', $sign)) self::fail(403, '缺少有效 MD5 签名');
        $signData = $payload;
        $signData['sign_type'] = 'MD5';
        $expected = \lib\Payment::makeSign($signData, $merchant['key']);
        if (!hash_equals($expected, strtolower($sign))) self::fail(403, 'MD5 签名校验失败');

        if ($action === 'heart') {
            $channelId = self::positiveInt($payload['channel_id'] ?? '');
            $client = trim((string)($payload['client_name'] ?? ''));
            if ($client === '' || strlen($client) > 200) self::fail(400, '心跳需要有效 client_name');
            if (isset($payload['channel_id']) && $payload['channel_id'] !== '' && !$channelId) self::fail(400, 'channel_id 必须为有效通道 ID');
            $channel = $channelId ? self::reportChannel($channelId) : null;
            $timeout = $channel ? self::config($channel)['heartbeat_timeout'] : self::HEARTBEAT_TIMEOUT;
            if (time() - $timestamp >= $timeout) self::fail(408, '心跳已超过通道超时时间');
            $extData = trim((string)($payload['ext_data'] ?? ''));
            if ($extData !== '' && (!is_array(json_decode($extData, true)) || strlen($extData) > 65536)) self::fail(400, 'ext_data 需要 JSON 对象或数组');
            require_once __DIR__ . '/Wechat.php';
            $extension = jktongypc_wechat::heartbeatData($extData === '' ? [] : json_decode($extData, true));
            // A channel-less client lease must never enable an account or supply its SID.
            $key = $channelId ? self::heartbeatKey($merchantId, $channelId) : self::clientHeartbeatKey($merchantId, $client);
            return self::withLock('heart|' . $key, function () use ($merchantId, $channelId, $client, $timestamp, $extension, $key) {
                $previous = self::cachedHeartbeat($key);
                // 相同或更旧的心跳不续期；同一秒状态冲突时离线优先。
                $reportedAt = (int)($previous['reported_at'] ?? 0);
                if ($reportedAt >= $timestamp) {
                    if ($reportedAt === $timestamp && $extension['status'] === 'offline' && ($previous['status'] ?? '') !== 'offline') {
                        $previous['status'] = 'offline';
                        self::saveState($key, $previous);
                    }
                    unset($previous['options']);
                    return $previous;
                }
                $options = array_intersect_key($previous['options'] ?? [], array_flip(['sid', 'payment_link_sid', 'payment_link_shop_id', 'receipt_sid', 'receipt_shop_id']));
                $record = ['channel_id' => $channelId, 'client_name' => $client, 'last_seen' => time(), 'reported_at' => $timestamp, 'status' => $extension['status'], 'options' => array_merge($options, $extension['options'])];
                self::saveState($key, $record);
                if ($channelId) self::saveState(self::reportDisplayKey($channelId), ['merchant_id' => $merchantId, 'channel_id' => $channelId]);
                unset($record['options']); // SID 仅供服务端使用，不在心跳响应中回显。
                return $record;
            });
        }

        $record = self::paymentRecord($merchantId, $payload);
        return self::withLock(self::paymentScope($merchantId, $record['pay_type'], $record['channel_id']), function () use ($merchantId, $record) {
            global $DB, $channel;
            $done = $DB->getRow('SELECT trade_no FROM pre_order WHERE uid=:uid AND api_trade_no=:flow_id AND status=1 LIMIT 1', [':uid' => $merchantId, ':flow_id' => $record['flow_id']]);
            if ($done) return ['trade_no' => $done['trade_no'], 'duplicate' => true];
            $rows = $DB->getAll(
                "SELECT A.*,T.name typename,T.showname typeshowname FROM pre_order A INNER JOIN pre_type T ON A.type=T.id INNER JOIN pre_channel C ON A.channel=C.id WHERE A.uid=:uid AND A.status=0 AND T.name=:pay_type AND C.plugin='jktongypc' AND A.channel=:channel_id AND A.realmoney=:amount AND A.addtime>=:earliest AND A.addtime<=:paid_at",
                [':uid' => $merchantId, ':pay_type' => $record['pay_type'], ':channel_id' => $record['channel_id'], ':amount' => self::yuan($record['amount_cents']), ':earliest' => date('Y-m-d H:i:s', $record['paid_at'] - self::EXPIRE_SECONDS), ':paid_at' => date('Y-m-d H:i:s', $record['paid_at'])]
            );
            if ($rows === false) self::fail(500, '订单查询失败，请重试');
            $matches = [];
            foreach ($rows as $row) {
                $snapshot = self::snapshot($row);
                $created = self::parseTime($row['addtime']);
                if (!$snapshot || (int)$snapshot['channel_id'] !== $record['channel_id'] || (int)$snapshot['amount_cents'] !== $record['amount_cents']) continue;
                if ((int)($snapshot['issued_at'] ?? $created) > $record['paid_at']) continue;
                if ($record['paid_at'] < $created || $record['paid_at'] >= $created + self::EXPIRE_SECONDS) continue;
                $matches[] = $row;
            }
            if (count($matches) !== 1) self::fail(409, count($matches) > 1 ? '存在多个候选订单，拒绝自动匹配' : '未找到符合商户、支付方式、通道、金额及付款时间的未支付订单');
            $fresh = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $matches[0]['trade_no']]);
            if (!$fresh || (int)$fresh['status'] !== 0 || (int)$fresh['uid'] !== $merchantId || $fresh['type'] != $matches[0]['type'] || $fresh['channel'] != $record['channel_id'] || $fresh['subchannel'] != $matches[0]['subchannel'] || (int)round((float)$fresh['realmoney'] * 100) !== $record['amount_cents'] || self::snapshot($fresh) !== self::snapshot($matches[0])) self::fail(409, '订单状态或通道已改变，请重试');
            $channel = self::orderChannel($fresh);
            $fresh['typename'] = $record['pay_type'];
            $fresh['plugin'] = 'jktongypc';
            // 收款成功上报不依赖当前心跳；允许客户端补报有效付款时间内的收款。
            processNotify($fresh, $record['flow_id'], $record['pay_user'] ?: null, $record['order_id'] ?: null, null, date('Y-m-d H:i:s', $record['paid_at']));
            if ((int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $fresh['trade_no']]) !== 1) self::fail(500, '订单入账失败，请重试');
            return ['trade_no' => $fresh['trade_no'], 'amount' => self::yuan($record['amount_cents']), 'pay_type' => $record['pay_type']];
        });
    }

    static private function paymentRecord($merchantId, $payload)
    {
        $amount = self::positiveInt($payload['amount'] ?? '');
        $channelId = self::positiveInt($payload['channel_account_id'] ?? '');
        $payType = trim((string)($payload['pay_type'] ?? ''));
        $code = trim((string)($payload['channel_code'] ?? ''));
        if (!$amount || $amount > 9999999999) self::fail(400, 'amount 必须是正整数，单位为分');
        if (!$channelId) self::fail(400, 'channel_account_id 请填写后台通道 ID');
        if ($code === '' || strlen($code) > 100) self::fail(400, '缺少有效 channel_code');
        // 到达此处时原报文已验签。Lua 的收款模式共用微信编码，支付宝存在两种编码。
        // 仅归一已确认的编码；模式名、进程名不能当作支付方式。
        $types = ['jk_wechat_pc' => 'wxpay', 'wxpay' => 'wxpay', 'alipay_app' => 'alipay', 'alipay_dianyuan' => 'alipay', 'alipay' => 'alipay'];
        $payType = $types[$payType] ?? $payType;
        $codeType = $types[$code] ?? '';
        if ($payType === '') $payType = $codeType;
        if (!in_array($payType, ['alipay', 'wxpay'], true)) self::fail(400, '只支持 alipay 或 wxpay，或可识别的监控通道编码');
        if ($codeType !== '' && $codeType !== $payType) self::fail(400, '通道编码 channel_code 与支付方式 pay_type 不匹配');
        self::reportChannel($channelId, $payType);
        $hasPayTime = trim((string)($payload['pay_time'] ?? '')) !== '';
        // 缺少付款时间时只能采用已验签的原始上报时间，不能改用服务器接收时间。
        // 非空但格式错误的 pay_time 必须拒绝，不能静默回退。
        $paidAt = self::parseTime($hasPayTime ? $payload['pay_time'] : ($payload['timestamp'] ?? ''));
        if (!$paidAt || $paidAt > time() + 60) self::fail(400, '付款时间 pay_time 或上报时间 timestamp 无效');
        $orderId = trim((string)($payload['order_id'] ?? ''));
        $outOrderId = trim((string)($payload['out_order_id'] ?? ''));
        $payer = trim((string)($payload['pay_user'] ?? ''));
        if (strlen($orderId) > 150 || strlen($outOrderId) > 150 || strlen($payer) > 100) self::fail(400, '订单号或付款账户过长');
        // 同一事件重试必须保留原始流水号或 timestamp；通道始终参与去重。
        $identity = $orderId !== '' ? ['id', $orderId] : ($hasPayTime
            ? ['event', $paidAt, $amount, $payer, $outOrderId, (string)($payload['remark'] ?? '')]
            : ['reported_event', $channelId, (string)$payload['timestamp'], $amount, $payer, $outOrderId, (string)($payload['remark'] ?? '')]);
        $flowId = 'jkpc_' . hash('sha256', json_encode([$merchantId, $payType, $channelId, $identity], JSON_UNESCAPED_UNICODE));
        return ['channel_id' => $channelId, 'pay_type' => $payType, 'amount_cents' => $amount, 'paid_at' => $paidAt, 'flow_id' => $flowId, 'pay_user' => $payer, 'order_id' => $orderId];
    }

    static private function preparePayment(&$order, $channel, $allowOfflineView = false)
    {
        if ((int)($order['status'] ?? 0) !== 0) self::fail(409, '订单状态已改变，请返回订单页面查看结果');
        if (self::parseTime($order['addtime'] ?? '') + self::EXPIRE_SECONDS <= time()) self::fail(409, '订单已超时，请重新发起支付');
        self::merchant((int)$order['uid']);
        if (!in_array($order['typename'] ?? '', ['alipay', 'wxpay'], true)) self::fail(400, '当前支付方式不受支持');
        $config = self::effectiveConfig((int)$order['uid'], $channel);
        if (self::paymentConfigChanged(self::readExt($order), $config, $order['typename'])) self::fail(409, '收款配置已变更，请重新发起订单');
        if ($order['typename'] !== 'wxpay' || $config['pay_mode'] === 'self') {
            if ($config['qrcode_type'] === 'pid') {
                if ($order['typename'] !== 'alipay' || !preg_match('/^2088[0-9]{12}$/D', $config['pid'])) self::fail(400, '支付宝 PID 请填写 2088 开头的 16 位数字');
                if (!in_array($config['qrcode_mod'], ['9', '10', '11'], true)) self::fail(400, '二维码模式请选择模式9、模式10或模式11');
            } else {
                if ($config['qrcode'] === '' || strlen($config['qrcode']) > 1048576) self::fail(400, '请先配置有效收款码');
                if ($config['qrcode_type'] === 'image' && !preg_match('#^(?:https?://|data:image/(?:png|jpeg|gif|webp);base64,)#i', $config['qrcode'])) self::fail(400, '图片类型请填写图片 URL 或 PNG/JPEG/GIF/WebP 数据');
            }
        }
        // 发起支付和支付宝启动页每次都检查在线状态；只有已发起订单的
        // 收银台可离线查看，由页面初始遮罩和 paymentState 持续阻止扫码。
        if ((!$allowOfflineView || !self::snapshot($order)) && !self::onlineState((int)$order['uid'], $channel)['online']) self::fail(409, self::OFFLINE_MESSAGE);
        self::reserveAmount($order);
    }

    /** 锁覆盖该商户同支付方式、同通道的所有金额，防止跨基础金额撞尾数。 */
    static private function reserveAmount(&$order)
    {
        self::withLock(self::paymentScope((int)$order['uid'], $order['typename'], (int)$order['channel']), function () use (&$order) {
            global $DB;
            $fresh = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $order['trade_no']]);
            if (!$fresh || (int)$fresh['status'] !== 0 || $fresh['channel'] != $order['channel'] || $fresh['subchannel'] != $order['subchannel']) self::fail(409, '订单状态或支付通道已改变');
            if (self::parseTime($fresh['addtime']) + self::EXPIRE_SECONDS <= time()) self::fail(409, '订单已超时，请重新发起支付');
            $ext = self::readExt($fresh, true);
            $snapshot = self::snapshot($fresh);
            if (isset($ext['jktongypc']) && !$snapshot) self::fail(409, '订单收款信息无效，请重新发起支付');
            if ($snapshot) {
                if ((int)$snapshot['channel_id'] !== (int)$order['channel']) self::fail(409, '订单通道已变更，请重新发起支付');
                $order = array_merge($order, $fresh);
                return;
            }
            $base = (int)round((float)($fresh['realmoney'] ?? $fresh['money']) * 100);
            if ($base <= 0 || $base > 9999999999) self::fail(400, '订单金额无效');
            $rows = $DB->getAll("SELECT A.ext FROM pre_order A INNER JOIN pre_channel C ON A.channel=C.id WHERE A.uid=:uid AND A.type=:type AND A.channel=:channel_id AND A.status=0 AND C.plugin='jktongypc' AND A.trade_no<>:trade_no AND A.addtime>:earliest", [':uid' => $order['uid'], ':type' => $order['type'], ':channel_id' => $order['channel'], ':trade_no' => $order['trade_no'], ':earliest' => date('Y-m-d H:i:s', time() - self::EXPIRE_SECONDS)]);
            if ($rows === false) self::fail(500, '订单金额查询失败');
            $used = [];
            foreach ($rows as $row) {
                $other = self::snapshot($row);
                if ($other && (int)$other['channel_id'] === (int)$order['channel']) $used[(int)$other['amount_cents']] = true;
            }
            $amount = $base;
            while (isset($used[$amount])) $amount++;
            if ($amount > 9999999999) self::fail(409, '没有可用支付金额，请稍后重试');
            $ext['jktongypc'] = ['version' => 2, 'channel_id' => (int)$order['channel'], 'base_cents' => $base, 'amount_cents' => $amount, 'issued_at' => time()];
            $data = ['realmoney' => self::yuan($amount)];
            // 与现有监控插件保持一致：尾数差额同时调整结算金额，保留原服务费差额。
            $data['getmoney'] = self::yuan(max(0, (int)round((float)$fresh['getmoney'] * 100) + $amount - $base));
            $data['ext'] = self::writeOrderExt($fresh, $ext, $data);
            $order = array_merge($order, $fresh, $data);
        });
    }

    static private function merchant($merchantId)
    {
        global $DB;
        $merchant = $DB->getRow('SELECT uid,`key`,keytype,status,pay FROM pre_user WHERE uid=:uid LIMIT 1', [':uid' => $merchantId]);
        if (!$merchant) self::fail(400, '订单所属商户不存在');
        if ((int)$merchant['keytype'] !== 0 || empty($merchant['key'])) self::fail(403, '商户需启用 V1 MD5 密钥');
        if ((int)$merchant['status'] !== 1 || (int)$merchant['pay'] !== 1) self::fail(403, '商户当前不可用');
        return $merchant;
    }

    static private function reportChannel($channelId, $payType = null)
    {
        global $DB;
        $row = $DB->getRow('SELECT C.*,T.name typename FROM pre_channel C INNER JOIN pre_type T ON C.type=T.id WHERE C.id=:id LIMIT 1', [':id' => $channelId]);
        if (!$row || $row['plugin'] !== 'jktongypc' || !in_array($row['typename'], ['alipay', 'wxpay'], true) || ($payType !== null && $row['typename'] !== $payType)) self::fail(400, '通道 ID、插件或支付方式不匹配');
        $config = json_decode((string)$row['config'], true);
        return array_merge(is_array($config) ? $config : [], $row);
    }

    static private function orderChannel($order)
    {
        global $DB;
        $channel = (int)($order['subchannel'] ?? 0) > 0 ? \lib\Channel::getSub((int)$order['subchannel']) : \lib\Channel::get((int)$order['channel'], $DB->getColumn('SELECT channelinfo FROM pre_user WHERE uid=:uid LIMIT 1', [':uid' => $order['uid']]));
        if (!$channel || $channel['plugin'] !== 'jktongypc') self::fail(409, '订单通道不匹配');
        return $channel;
    }

    /** 原生诊断与收银台使用相同的商户和通道判定逻辑。 */
    static public function monitorStatus($channel, $merchantId = 0)
    {
        $reported = self::reportedConfig($channel);
        if ($merchantId <= 0) $merchantId = (int)($reported['merchant_id'] ?? 0);
        $state = self::onlineState($merchantId, $channel);
        $record = self::heartbeat($merchantId, (int)$channel['id']);
        global $DB;
        $enabled = (int)$DB->getColumn('SELECT status FROM pre_channel WHERE id=:id LIMIT 1', [':id'=>(int)$channel['id']]) === 1;
        if (!empty($channel['subid'])) $enabled = $enabled && (int)$DB->getColumn('SELECT status FROM pre_subchannel WHERE id=:id AND uid=:uid', [':id'=>(int)$channel['subid'], ':uid'=>$merchantId]) === 1;
        $reason = !$enabled ? '通道已停用' : ($merchantId <= 0 || !$state['last_seen'] ? '尚未收到该商户在此通道的心跳' : (($record['status'] ?? '') !== 'online' ? '客户端上报支付账号离线' : ($state['last_seen'] > time() ? '心跳时间异常' : (!$state['online'] ? '心跳已超时' : '心跳正常'))));
        return $state + ['merchant_id'=>$merchantId,'channel_enabled'=>$enabled,'heartbeat_enabled'=>true,'timeout'=>self::HEARTBEAT_TIMEOUT,'server_time'=>time(),'client_name'=>(string)($record['client_name'] ?? ''),'reason'=>$reason,'reported'=>$merchantId===(int)($reported['merchant_id'] ?? 0)?$reported:['merchant_id'=>$merchantId,'last_seen'=>(int)($record['last_seen'] ?? 0),'values'=>array_intersect_key($record['options'] ?? [],array_flip(['sid','payment_link_sid','payment_link_shop_id','receipt_sid','receipt_shop_id']))]];
    }

    static private function onlineState($merchantId, $channel)
    {
        global $DB;
        $last = self::heartbeat($merchantId, (int)$channel['id']);
        $lastSeen = (int)($last['last_seen'] ?? 0);
        $until = $lastSeen + self::config($channel)['heartbeat_timeout'];
        $enabled = (int)$DB->getColumn('SELECT status FROM pre_channel WHERE id=:id LIMIT 1', [':id' => (int)$channel['id']]) === 1;
        if (!empty($channel['subid'])) $enabled = $enabled && (int)$DB->getColumn('SELECT status FROM pre_subchannel WHERE id=:id AND uid=:uid LIMIT 1', [':id' => (int)$channel['subid'], ':uid' => $merchantId]) === 1;
        return ['online' => $enabled && ($last['status'] ?? 'offline') === 'online' && $lastSeen > 0 && $lastSeen <= time() && time() < $until, 'last_seen' => $lastSeen, 'online_until' => $until];
    }

    static private function config($channel)
    {
        $mode = trim((string)($channel['pay_mode'] ?? ''));
        if ($mode === '') $mode = 'self';
        if (!in_array($mode, ['self', 'platform', 'smallbook', 'receipt'], true)) self::fail(400, '不支持的微信收款方式');
        $type = trim((string)($channel['qrcode_type'] ?? '')) ?: 'qrcode';
        if (!in_array($type, ['qrcode', 'image', 'pid'], true)) self::fail(400, '不支持的收款码类型');
        $config = ['pay_mode' => $mode, 'qrcode_type' => $type, 'qrcode' => trim((string)($channel['qrcode'] ?? '')), 'qrcode_mod' => trim((string)($channel['qrcode_mod'] ?? '')) ?: '9', 'heartbeat_timeout' => self::HEARTBEAT_TIMEOUT];
        foreach (['pid', 'sid', 'payment_link_sid', 'payment_link_shop_id', 'receipt_sid', 'receipt_shop_id', 'receipt_account_ref'] as $key) $config[$key] = trim((string)($channel[$key] ?? ''));
        return $config;
    }

    static private function effectiveConfig($merchantId, $channel)
    {
        $config = self::config($channel);
        $reported = self::heartbeat($merchantId, (int)$channel['id'])['options'] ?? [];
        foreach (['sid', 'payment_link_sid', 'payment_link_shop_id', 'receipt_sid', 'receipt_shop_id'] as $key) {
            if ($config[$key] === '' && isset($reported[$key]) && is_string($reported[$key])) $config[$key] = $reported[$key];
        }
        return $config;
    }

    /** 只计算当前模式实际使用的收款参数，不保存明文 SID，不受隐藏字段变化影响。 */
    static private function paymentConfigHash($config, $payType)
    {
        $keys = ['qrcode_type'];
        if ($payType === 'wxpay' && $config['pay_mode'] !== 'self') {
            $keys = ['pay_mode'];
            $modeKeys = ['platform' => ['sid'], 'smallbook' => ['payment_link_sid', 'payment_link_shop_id'], 'receipt' => ['receipt_sid', 'receipt_shop_id', 'receipt_account_ref']];
            $keys = array_merge($keys, $modeKeys[$config['pay_mode']]);
        } else {
            $keys = array_merge($keys, $config['qrcode_type'] === 'pid' ? ['pid', 'qrcode_mod'] : ['qrcode']);
            if ($payType === 'wxpay') $keys[] = 'pay_mode';
        }
        $values = ['pay_type' => $payType];
        foreach ($keys as $key) $values[$key] = $config[$key];
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    static private function paymentConfigChanged($ext, $config, $payType)
    {
        if (!array_key_exists('jktongypc_config_hash', $ext)) return isset($ext['jktongypc_code']) || !empty($ext['jktongypc_code_started']);
        return !is_string($ext['jktongypc_config_hash']) || !hash_equals(self::paymentConfigHash($config, $payType), $ext['jktongypc_config_hash']);
    }

    static private function paymentCode(&$order, $channel, $client = null)
    {
        $config = self::effectiveConfig((int)$order['uid'], $channel);
        $configHash = self::paymentConfigHash($config, $order['typename']);
        return self::withLock('code|' . $order['uid'] . '|' . $order['trade_no'], function () use (&$order, $channel, $config, $configHash, $client) {
            $fresh = self::codeContext($order, $configHash);
            $ext = self::readExt($fresh, true);
            if (self::paymentConfigChanged($ext, $config, $order['typename'])) self::fail(409, '收款配置已变更，请重新发起订单');
            if ($order['typename'] !== 'wxpay' || $config['pay_mode'] === 'self') {
                $code = $config['qrcode_type'] === 'pid' ? self::alipayCode($config, $order) : ['kind' => $config['qrcode_type'], 'code' => $config['qrcode']];
                if (!isset($ext['jktongypc_config_hash'])) {
                    $ext['jktongypc_config_hash'] = $configHash;
                    $order['ext'] = self::writeOrderExt($fresh, $ext);
                }
                return $code;
            }
            $cached = $ext['jktongypc_code'] ?? null;
            if ($cached) {
                if (($cached['mode'] ?? '') !== $config['pay_mode'] || (int)($cached['channel_id'] ?? 0) !== (int)$channel['id']) self::fail(409, '收款方式已变更，请重新发起订单');
                return self::readPaymentCode($fresh, $cached);
            }
            // 动态码尚未生成时，客户端离线无法可靠获取新码；已有缓存则允许进入收银台，
            // 由前端依据实时心跳临时遮挡，恢复后自动解除。
            if (!self::onlineState((int)$fresh['uid'], $channel)['online']) self::fail(409, self::OFFLINE_MESSAGE);
            require_once __DIR__ . '/Wechat.php';
            $client = $client ?: new jktongypc_wechat();
            $client->validate($config);
            if (!empty($ext['jktongypc_code_started'])) self::fail(409, '上次出码结果未确认，请重新发起订单');
            $code = $client->create($config, $order, function ($state) use (&$order, &$ext, $fresh, $configHash) {
                if ($state === 'started') {
                    // 账号查询完成后再次检查配置，确认仍可支付才发送真正的创建请求。
                    $current = self::codeContext($order, $configHash);
                    $ext = self::readExt($current, true);
                    $ext['jktongypc_code_started'] = time();
                    $ext['jktongypc_config_hash'] = $configHash;
                } elseif ($state === 'rejected') {
                    // 仅创建接口明确拒绝时解除标记；超时和创建后取图失败保留标记。
                    unset($ext['jktongypc_code_started'], $ext['jktongypc_config_hash']);
                } else {
                    self::fail(500, '出码阶段无效');
                }
                $order['ext'] = self::writeOrderExt($fresh, $ext);
            });
            $latest = self::codeContext($order, $configHash);
            $ext = self::readExt($latest, true);
            if (($ext['jktongypc_config_hash'] ?? '') !== $configHash || empty($ext['jktongypc_code_started'])) self::fail(500, '出码状态保存失败，请重新发起订单');
            $code += ['mode' => $config['pay_mode'], 'channel_id' => (int)$channel['id']];
            $ext['jktongypc_code'] = self::cachePaymentCode($latest, $code);
            $order['ext'] = self::writeOrderExt($latest, $ext);
            return $code;
        });
    }

    /** 在出码前后复核订单和实际通道配置，避免查询期间变更收款方。在线状态由收银台实时展示。 */
    static private function codeContext($order, $configHash)
    {
        global $DB;
        $fresh = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $order['trade_no']]);
        if (!$fresh || !self::snapshot($order) || (int)$fresh['status'] !== 0 || $fresh['uid'] != $order['uid'] || $fresh['type'] != $order['type'] || $fresh['channel'] != $order['channel'] || $fresh['subchannel'] != $order['subchannel'] || $fresh['realmoney'] != $order['realmoney'] || self::snapshot($fresh) !== self::snapshot($order)) self::fail(409, '订单状态或收款信息已改变');
        if (self::parseTime($fresh['addtime']) + self::EXPIRE_SECONDS <= time()) self::fail(409, '订单已超时，请重新发起支付');
        $currentChannel = self::orderChannel($fresh);
        if ((int)$currentChannel['type'] !== (int)$fresh['type']) self::fail(409, '订单支付方式已变更，请重新发起订单');
        $currentConfig = self::effectiveConfig((int)$fresh['uid'], $currentChannel);
        if (!hash_equals($configHash, self::paymentConfigHash($currentConfig, $order['typename']))) self::fail(409, '收款配置已变更，请重新发起订单');
        return $fresh;
    }

    static private function alipayCode($config, $order)
    {
        global $siteurl;
        $money = self::yuan((int)round((float)$order['realmoney'] * 100));
        $mode = $config['qrcode_mod'];
        if ($mode === '9') return ['kind' => 'qrcode', 'code' => 'https://ds.alipay.com/?' . http_build_query(['from' => 'pc', 'appId' => '20000116', 'actionType' => 'toAccount', 'goBack' => 'NO', 'amount' => $money, 'userId' => $config['pid'], 'memo' => $order['trade_no']])];
        if ($mode === '10') {
            $biz = json_encode(['s' => 'money', 'u' => $config['pid'], 'a' => $money, 'm' => $order['trade_no']], JSON_UNESCAPED_UNICODE);
            $inner = 'alipays://platformapi/startapp?' . http_build_query(['appId' => '20000123', 'actionType' => 'scan', 'biz_data' => $biz], '', '&', PHP_QUERY_RFC3986);
            $scheme = 'alipayqr://platformapi/startapp?saId=20000032&url=' . rawurlencode($inner);
            return ['kind' => 'qrcode', 'code' => 'https://render.alipay.com/p/s/i?scheme=' . rawurlencode($scheme)];
        }
        if ($mode === '11') return ['kind' => 'qrcode', 'code' => rtrim((string)$siteurl, '/') . '/pay/pcalipay/' . rawurlencode($order['trade_no']) . '/'];
        self::fail(400, '二维码模式请选择模式9、模式10或模式11');
    }

    /** 模式11的 HTTPS 启动页仍先检查商户、订单、通道和心跳。 */
    static public function pcalipay()
    {
        global $order, $channel;
        try {
            self::preparePayment($order, $channel);
            $config = self::effectiveConfig((int)$order['uid'], $channel);
            if ($order['typename'] !== 'alipay' || $config['qrcode_type'] !== 'pid' || $config['qrcode_mod'] !== '11') self::fail(409, '当前订单启动模式已变更，请重新发起支付');
            self::paymentCode($order, $channel);
            $launch = ['pid' => $config['pid'], 'money' => self::yuan((int)round((float)$order['realmoney'] * 100)), 'trade_no' => (string)$order['trade_no']];
            header('Cache-Control: no-store');
            $jktongypcPage = 'alipay-launch';
            include __DIR__ . '/pages.php';
            exit;
        } catch (\RuntimeException $e) {
            return ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }

    static private function codeCacheKey($order)
    {
        return md5('jktongypc:code:' . $order['uid'] . ':' . $order['trade_no']);
    }

    /** 大图片存入现有 LONGTEXT 缓存；订单 TEXT 字段仅存账户快照和小型索引。 */
    static private function cachePaymentCode($order, $code)
    {
        global $DB;
        $key = self::codeCacheKey($order);
        $expire = self::parseTime($order['addtime']) + self::EXPIRE_SECONDS;
        $json = json_encode($code, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $DB->exec('REPLACE INTO pre_cache (k,v,expire) VALUES (:key,:value,:expire)', [':key' => $key, ':value' => $json, ':expire' => $expire]) === false || $DB->getColumn('SELECT v FROM pre_cache WHERE k=:key LIMIT 1', [':key' => $key]) !== $json) self::fail(500, '收款码缓存保存失败，请重新发起支付');
        return ['mode' => $code['mode'], 'channel_id' => $code['channel_id'], 'kind' => $code['kind'], 'cache_key' => $key, 'code_hash' => hash('sha256', $code['code'])];
    }

    static private function readPaymentCode($order, $metadata)
    {
        global $DB;
        $key = self::codeCacheKey($order);
        if (($metadata['cache_key'] ?? '') !== $key) self::fail(409, '订单收款码索引异常，请重新发起支付');
        $raw = $DB->getColumn('SELECT v FROM pre_cache WHERE k=:key AND expire>:now LIMIT 1', [':key' => $key, ':now' => time()]);
        $code = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($code) || !isset($code['code'], $code['kind']) || !is_string($code['code']) || !in_array($code['kind'], ['image', 'qrcode'], true) || ($code['mode'] ?? '') !== $metadata['mode'] || (int)($code['channel_id'] ?? 0) !== (int)($metadata['channel_id'] ?? 0) || !hash_equals((string)($metadata['code_hash'] ?? ''), hash('sha256', $code['code']))) self::fail(409, '收款码缓存已失效，请重新发起支付');
        return $code;
    }

    static private function writeOrderExt($order, $ext, $data = [])
    {
        global $DB;
        $serialized = serialize($ext);
        if (strlen($serialized) > 65535) self::fail(409, '订单收款信息超出存储容量，请重新发起支付');
        $data['ext'] = $serialized;
        if ($DB->update('order', $data, ['trade_no' => $order['trade_no'], 'status' => 0]) === false || $DB->getColumn('SELECT ext FROM pre_order WHERE trade_no=:trade_no AND uid=:uid AND status=0 LIMIT 1', [':trade_no' => $order['trade_no'], ':uid' => $order['uid']]) !== $serialized) self::fail(500, '订单收款信息保存失败，请重新发起支付');
        return $serialized;
    }

    static private function heartbeatKey($merchantId, $channelId)
    {
        // pre_cache.k 的长度为 32。
        return md5('jktongypc:heart:' . $merchantId . ':' . $channelId);
    }

    static private function reportDisplayKey($channelId)
    {
        return md5('jktongypc:display:' . $channelId);
    }

    static private function clientHeartbeatKey($merchantId, $clientName)
    {
        return md5('jktongypc:client-heart:' . (int)$merchantId . ':' . $clientName);
    }

    static private function heartbeat($merchantId, $channelId)
    {
        return self::cachedHeartbeat(self::heartbeatKey($merchantId, $channelId));
    }

    static private function cachedHeartbeat($key)
    {
        global $DB;
        $value = $DB->getColumn('SELECT v FROM pre_cache WHERE k=:key LIMIT 1', [':key' => $key]);
        $record = is_string($value) ? json_decode($value, true) : null;
        return is_array($record) ? $record : [];
    }

    static private function saveState($key, $record)
    {
        global $DB;
        if ($DB->exec('REPLACE INTO pre_cache (k,v,expire) VALUES (:key,:value,0)', [':key' => $key, ':value' => json_encode($record, JSON_UNESCAPED_UNICODE)]) === false) self::fail(500, '心跳状态保存失败');
    }

    static private function paymentScope($merchantId, $payType, $channelId)
    {
        return 'payment|' . $merchantId . '|' . $payType . '|' . (int)$channelId;
    }

    static private function withLock($scope, $callback)
    {
        global $DB;
        $name = 'jktongypc_' . sha1($scope);
        if ((int)$DB->getColumn('SELECT GET_LOCK(:name,5)', [':name' => $name]) !== 1) self::fail(409, '通知或订单正在处理，请稍后重试');
        try {
            return $callback();
        } finally {
            $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name' => $name]);
        }
    }

    static private function readExt($order, $strict = false)
    {
        $ext = empty($order['ext']) ? [] : @unserialize($order['ext'], ['allowed_classes' => false]);
        if ($strict && !is_array($ext)) self::fail(409, '订单收款信息已损坏，请重新发起支付');
        return is_array($ext) ? $ext : [];
    }

    static private function snapshot($order)
    {
        $snapshot = self::readExt($order)['jktongypc'] ?? null;
        return is_array($snapshot) && ($snapshot['version'] ?? null) === 2 && isset($snapshot['channel_id'], $snapshot['amount_cents'], $snapshot['issued_at']) ? $snapshot : null;
    }

    static private function positiveInt($value)
    {
        $text = (string)$value;
        return preg_match('/^[1-9][0-9]{0,9}$/D', $text) ? (int)$text : 0;
    }

    static private function yuan($cents)
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    static private function parseTime($value)
    {
        $value = trim((string)$value);
        if (preg_match('/^[0-9]{10}$/D', $value)) return (int)$value;
        if (preg_match('/^[0-9]{13}$/D', $value)) return intdiv((int)$value, 1000);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value)) return 0;
        $parts = date_parse($value);
        if ($parts['warning_count'] || $parts['error_count']) return 0;
        return strtotime($value) ?: 0;
    }

    static private function readPayload()
    {
        $query = $_GET;
        unset($query['s']);
        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') return $query;
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > 131072) self::fail(413, '请求正文过大');
        $body = $_POST;
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== false || preg_match('/^\s*\{/', $raw)) {
            $body = json_decode($raw, true);
            if (!is_array($body) || !preg_match('/^\s*\{/', $raw)) self::fail(400, '请求正文不是 JSON 对象');
        } elseif (!$body && $raw !== '') {
            parse_str($raw, $body);
        }
        foreach ($query as $key => $value) {
            if (is_array($value) || (isset($body[$key]) && is_array($body[$key]))) self::fail(400, '上报字段不能是数组');
            if (array_key_exists($key, $body) && (string)$body[$key] !== (string)$value) self::fail(400, '查询参数与正文参数冲突');
        }
        return array_merge($query, $body);
    }

    static private function statusToken($tradeNo)
    {
        return hash_hmac('sha256', 'jktongypc:status:' . $tradeNo, SYS_KEY);
    }

    static private function fail($code, $message)
    {
        throw new \RuntimeException($message, $code);
    }

    static private function respond($code, $message, $data = null)
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        http_response_code($code);
        echo json_encode(['code' => $code, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

