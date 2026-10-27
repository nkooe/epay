<?php
/** 插件页面：商户信息工具、收银台及支付宝启动页。 */
if (!defined('IN_PLUGIN') || !isset($jktongypcPage)) {
    http_response_code(404);
    exit;
}
switch ($jktongypcPage) {
    case 'admin-config':
?>
<style>
.jktongypc-setup{max-width:460px;margin:20px auto;padding:22px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;color:#20242a;text-align:left}
.jktongypc-setup h3{margin:0 0 20px;text-align:center;font-size:20px}
.jktongypc-setup .field{margin-bottom:18px}
.jktongypc-setup label{display:block;margin-bottom:6px;font-weight:600}
.jktongypc-setup input,.jktongypc-setup textarea{box-sizing:border-box;width:100%;padding:8px 10px;border:1px solid #d8dde3;border-radius:4px;background:#fff;color:#20242a;font-size:16px}
.jktongypc-setup input{height:44px}
.jktongypc-setup button{width:100%;min-height:44px;padding:8px 14px;border:0;border-radius:4px;background:#1677ff;color:#fff;font-size:16px;cursor:pointer}
.jktongypc-setup button:hover{background:#0e63dc}
.jktongypc-setup button:focus-visible,.jktongypc-setup input:focus-visible,.jktongypc-setup textarea:focus-visible{outline:2px solid #174f9c;outline-offset:2px}
.jktongypc-setup .error{margin:12px 0 0;color:#b42318;line-height:1.6}
.jktongypc-setup .error:empty{display:none}
.jktongypc-setup .result{margin-top:18px;padding-top:18px;border-top:1px solid #e8ebef}
.jktongypc-setup .result[hidden]{display:none}
.jktongypc-setup textarea{min-height:110px;resize:vertical;overflow-wrap:anywhere;font-family:monospace;font-size:14px;line-height:1.6}
@media(max-width:480px){.jktongypc-setup{margin:10px auto;padding:16px}}
</style>
<section class="jktongypc-setup" aria-labelledby="jktongypc-setup-title">
    <h3 id="jktongypc-setup-title">PC 监控配置信息</h3>
    <div class="field">
        <label for="jktongypc-site">平台地址</label>
        <input id="jktongypc-site" type="url" value="<?php echo htmlspecialchars($platformUrl, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" spellcheck="false">
    </div>
    <div class="field">
        <label for="jktongypc-pid">商户 ID</label>
        <input id="jktongypc-pid" type="text" inputmode="numeric" value="1000" autocomplete="off" spellcheck="false">
    </div>
    <div class="field">
        <label for="jktongypc-key">商户密钥</label>
        <input id="jktongypc-key" type="text" value="" autocomplete="off" spellcheck="false">
    </div>
    <button type="button" id="jktongypc-generate">生成商户信息</button>
    <div class="error" id="jktongypc-error" role="alert"></div>
    <div class="result" id="jktongypc-result" hidden>
        <label for="jktongypc-encoded">Base64 配置</label>
        <textarea id="jktongypc-encoded" readonly spellcheck="false" autocomplete="off"></textarea>
    </div>
</section>
<script>
(function(){
    var site=document.getElementById('jktongypc-site'),pid=document.getElementById('jktongypc-pid'),key=document.getElementById('jktongypc-key'),button=document.getElementById('jktongypc-generate'),error=document.getElementById('jktongypc-error'),result=document.getElementById('jktongypc-result'),encoded=document.getElementById('jktongypc-encoded');
    function reset(){
        error.textContent='';result.hidden=true;encoded.value='';
        [site,pid,key].forEach(function(field){field.removeAttribute('aria-invalid')});
    }
    function invalid(field,message){
        error.textContent=message;field.setAttribute('aria-invalid','true');field.focus();
    }
    function base64Utf8(text){
        if(window.TextEncoder){
            var bytes=new TextEncoder().encode(text),binary='',step=8192;
            for(var i=0;i<bytes.length;i+=step)binary+=String.fromCharCode.apply(null,bytes.subarray(i,i+step));
            return btoa(binary);
        }
        return btoa(unescape(encodeURIComponent(text)));
    }
    [site,pid,key].forEach(function(field){field.addEventListener('input',reset)});
    button.addEventListener('click',function(){
        reset();
        var cleanSite=site.value.trim().replace(/\/+$/,''),idText=pid.value.trim(),merchantId=Number(idText),merchantKey=key.value.trim(),parsed;
        try{parsed=new URL(cleanSite)}catch(e){invalid(site,'请填写正确的平台地址');return}
        if(!/^https?:$/.test(parsed.protocol)||!parsed.hostname){invalid(site,'请填写正确的平台地址');return}
        if(!/^[1-9][0-9]*$/.test(idText)||!Number.isSafeInteger(merchantId)){invalid(pid,'请填写正确的商户 ID');return}
        if(!merchantKey){invalid(key,'请填写商户密钥');return}
        encoded.value=base64Utf8(JSON.stringify({site:cleanSite,pid:merchantId,key:merchantKey}));
        result.hidden=false;
    });
}());
</script>
<?php
        break;
    case 'qrcode':
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars($browserTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#20242a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.wrap{width:92%;max-width:420px;margin:32px auto;padding:26px 22px 30px;background:#fff;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.08);text-align:center}h1{margin:0 0 12px;font-size:22px;font-weight:600}.amount{margin-bottom:14px;color:#e64340;font-size:30px;font-weight:700}.qr-box{position:relative;width:250px;height:250px;margin:16px auto;padding:10px;border:1px solid #e7eaee;background:#fff}.qr{width:230px;height:230px;display:flex;align-items:center;justify-content:center;overflow:hidden}.qr img,.qr canvas{max-width:230px;max-height:230px}.blocked{display:none;position:absolute;inset:0;z-index:2;align-items:center;justify-content:center;flex-direction:column;padding:16px;background:rgba(20,24,30,.94);color:#fff;font-size:18px;font-weight:600}.blocked small{margin-top:8px;font-size:13px;font-weight:400}.error{margin:26px 0;color:#d93025;line-height:1.7}.countdown{margin:12px 0;color:#e64340;font-size:16px}.meta{margin:8px 0 14px;color:#8a919b;font-size:13px;line-height:1.8;overflow-wrap:anywhere}.hint{color:#8a919b;font-size:14px;line-height:1.7}.retry{display:inline-block;margin-top:15px;padding:10px 18px;border:0;border-radius:4px;background:#1677ff;color:#fff;cursor:pointer}
    </style>
</head>
<body>
<main class="wrap">
    <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($error_msg !== ''): ?>
        <div class="error" role="alert"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <button type="button" class="retry" onclick="location.reload()">重新检测</button>
    <?php else: ?>
        <div class="amount">￥<?php echo htmlspecialchars((string)$order['realmoney'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="qr-box"><div class="qr" id="qrcode"></div><div class="blocked" id="blocked" role="alert"<?php if (!$state['online']): ?> style="display:flex"<?php endif; ?>><span id="block-title"><?php if (!$state['online']): ?>通道已经离线<?php endif; ?></span><small id="block-detail"><?php if (!$state['online']): ?>请稍候，通道恢复后可继续支付<?php endif; ?></small></div></div>
        <div class="countdown">支付剩余时间：<b id="timer">--:--</b></div>
        <div class="meta">订单号：<?php echo htmlspecialchars((string)$order['trade_no'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="hint">请使用<?php echo $payType === 'alipay' ? '支付宝' : '微信'; ?>扫码支付<br>必须支付页面显示的实际金额</div>
        <button type="button" class="retry" id="retry" hidden onclick="location.reload()">重新检测</button>
    <?php endif; ?>
</main>
<?php if ($error_msg === ''): ?>
<script src="<?php echo htmlspecialchars((string)$cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery/1.12.4/jquery.min.js"></script>
<script src="<?php echo htmlspecialchars((string)$cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<script>
(function(){
    var code=<?php echo json_encode((string)$code_url, $jsonFlags); ?>,kind=<?php echo json_encode($qrcodeType, $jsonFlags); ?>,url=<?php echo json_encode($statusUrl, $jsonFlags); ?>;
    var deadline=Date.now()+<?php echo (int)$paytime; ?>*1000,onlineDeadline=Date.now()+Math.max(0,<?php echo (int)$onlineUntil - time(); ?>)*1000;
    var timer=document.getElementById('timer'),root=document.getElementById('qrcode'),blocked=document.getElementById('blocked'),title=document.getElementById('block-title'),detail=document.getElementById('block-detail'),retry=document.getElementById('retry'),stopped=false,channelOffline=false;
    function block(message,description){root.innerHTML='';blocked.style.display='flex';title.textContent=message;detail.textContent=description;retry.hidden=false;stopped=true}
    function setChannelOffline(offline){
        if(stopped||channelOffline===offline)return;
        channelOffline=offline;
        if(offline){
            // 保留二维码 DOM，遮罩期间继续轮询；心跳恢复后自动恢复扫码。
            blocked.style.display='flex';title.textContent='通道已经离线';detail.textContent='请稍候，通道恢复后可继续支付';retry.hidden=true;
        }else{
            blocked.style.display='none';title.textContent='';detail.textContent='';retry.hidden=true;
        }
    }
    function tick(){
        if(stopped)return;
        var left=Math.max(0,Math.ceil((deadline-Date.now())/1000));
        timer.textContent=('0'+Math.floor(left/60)).slice(-2)+':'+('0'+left%60).slice(-2);
        if(left===0)block('订单已超时','请重新发起支付');
        else if(Date.now()>=onlineDeadline)setChannelOffline(true);
    }
    function poll(){
        if(stopped)return;
        var xhr=new XMLHttpRequest();xhr.open('GET',url,true);xhr.timeout=5000;
        xhr.onload=function(){
            var result;try{result=JSON.parse(xhr.responseText)}catch(e){}
            if(result&&result.code===200&&result.data){
                var state=result.data;
                if(state.paid&&state.backurl){location.replace(state.backurl);return}
                if(state.closed){block('订单已失效','请重新发起支付');return}
                if(state.blocked_reason==='order_data'){block('订单收款信息异常','请重新发起支付');return}
                if(state.blocked_reason==='config_changed'){block('收款配置已变更','请重新发起支付');return}
                setChannelOffline(!state.online);
                deadline=Date.now()+Math.max(0,state.expires_in)*1000;
                // 按服务端剩余在线时间换算，避免用户设备时间影响状态判断。
                onlineDeadline=Date.now()+Math.max(0,state.online_until-state.server_time)*1000;
            }else if(xhr.status===403){block('收银台状态已失效','请刷新页面');return}
            tick();if(!stopped)setTimeout(poll,3000);
        };
        xhr.onerror=xhr.ontimeout=function(){tick();if(!stopped)setTimeout(poll,3000)};
        xhr.send();
    }
    if(kind==='image'){var img=document.createElement('img');img.src=code;img.alt='支付二维码';root.appendChild(img)}
    else if(window.jQuery&&jQuery.fn.qrcode){jQuery(root).qrcode({text:code,width:230,height:230,typeNumber:-1})}
    else{block('收款码加载失败','请刷新页面')}
    tick();setInterval(tick,1000);if(!stopped)poll();
    document.addEventListener('visibilitychange',function(){if(!document.hidden)tick()});
}());
</script>
<?php endif; ?>
</body>
</html>
<?php
        break;
    case 'alipay-launch':
$launchJson = json_encode($launch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>支付宝收款</title></head>
<body>
<p id="launch-status" role="status">正在打开支付宝付款页面…</p>
<script>
(function(){
    var config=<?php echo $launchJson; ?>,opened=false;
    function launch(){
        if(opened||!window.AlipayJSBridge)return;
        opened=true;
        AlipayJSBridge.call('startApp',{appId:'20000123',param:{actionType:'scan',u:config.pid,a:config.money,m:config.trade_no,biz_data:{s:'money',u:config.pid,a:config.money,m:config.trade_no}}},function(){});
    }
    if(window.AlipayJSBridge)launch();
    else document.addEventListener('AlipayJSBridgeReady',launch,{once:true});
    document.addEventListener('resume',function(){if(opened&&window.AlipayJSBridge)AlipayJSBridge.call('exitApp')});
    if(!/AlipayClient/i.test(navigator.userAgent))document.getElementById('launch-status').textContent='请使用支付宝扫描收银台二维码';
}());
</script>
</body>
</html>
<?php
        break;
    default:
        http_response_code(404);
        break;
}
