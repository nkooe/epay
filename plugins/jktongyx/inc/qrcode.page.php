<?php
if (!defined('IN_PLUGIN')) exit;
$codeJson = json_encode((string)$code_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($codeJson === false) $codeJson = '""';
$heartbeatEnabled = (bool)$checkoutState['heartbeat_enabled'];
$initiallyOffline = $heartbeatEnabled && !$checkoutState['online'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
    <title><?php echo htmlspecialchars($browserTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
    *{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#20242a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.wrap{width:92%;max-width:420px;margin:32px auto;padding:26px 22px 30px;background:#fff;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.08);text-align:center}h1{margin:0 0 12px;font-size:22px;font-weight:600}.icon{width:20px;height:20px;margin-right:6px;vertical-align:-3px}.amount{margin-bottom:14px;color:#e64340;font-size:30px;font-weight:700}.qr-box{position:relative;width:250px;height:250px;margin:16px auto;padding:10px;border:1px solid #e7eaee;background:#fff}.qr{width:230px;height:230px;display:flex;align-items:center;justify-content:center;overflow:hidden}.qr img,.qr canvas{max-width:230px;max-height:230px}.expired{display:none;position:absolute;inset:0;z-index:2;align-items:center;justify-content:center;flex-direction:column;background:rgba(20,24,30,.82);color:#fff;font-size:18px;font-weight:600}.expired small{margin-top:8px;font-size:13px;font-weight:400}.error{margin:26px 0;color:#d93025;line-height:1.7}.countdown{margin:12px 0;color:#e64340;font-size:16px}.meta{margin:8px 0 14px;color:#8a919b;font-size:13px;line-height:1.8}.hint{color:#8a919b;font-size:14px;line-height:1.7}
    .channel-offline{background:#20242a}.qr-blocked{visibility:hidden}
    </style>
</head>
<body>
<main class="wrap">
    <h1><?php if ($paymentIcon !== ''): ?><img class="icon" src="<?php echo htmlspecialchars($paymentIcon, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="amount">￥<?php echo htmlspecialchars((string)$order['realmoney'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php if ($error_msg !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <div class="qr-box"><div class="qr<?php if ($initiallyOffline): ?> qr-blocked<?php endif; ?>" id="qrcode"></div><div class="expired" id="expired"><span>二维码已失效</span><small>订单已超过五分钟，请重新发起支付</small></div><div class="expired channel-offline" id="offline"<?php if ($initiallyOffline): ?> style="display:flex"<?php endif; ?>><span>通道已离线</span><small id="offline-message">请稍后重试，恢复在线后自动显示二维码</small></div></div>
        <div class="countdown">支付剩余时间：<b id="timer">--:--</b></div>
        <div class="meta">订单号：<?php echo htmlspecialchars((string)$order['trade_no'], ENT_QUOTES, 'UTF-8'); ?><br>订单创建时间：<?php echo htmlspecialchars((string)$order['addtime'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="hint">请使用<?php echo $payType === 'alipay' ? '支付宝' : '微信'; ?>扫描二维码完成支付<br>必须支付页面显示的实际金额</div>
    <?php endif; ?>
</main>
<script src="<?php echo htmlspecialchars((string)$cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery/1.12.4/jquery.min.js"></script>
<script src="<?php echo htmlspecialchars((string)$cdnpublic, ENT_QUOTES, 'UTF-8'); ?>jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<?php if ($error_msg === ''): ?>
<script>
(function(){
    var code=<?php echo $codeJson; ?>,kind=<?php echo json_encode($qrcodeType); ?>,left=<?php echo (int)$paytime; ?>,timer=document.getElementById('timer'),expired=document.getElementById('expired'),root=document.getElementById('qrcode'),offline=document.getElementById('offline'),offlineMessage=document.getElementById('offline-message');
    var heartbeatEnabled=<?php echo $heartbeatEnabled ? 'true' : 'false'; ?>,state=<?php echo json_encode($checkoutState, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,statusUrl=<?php echo json_encode($statusUrl); ?>,pollTimer=null,inFlight=false,closed=state.closed;
    function clock(){return window.performance&&performance.now?performance.now():Date.now()}
    var expiresAt=clock()+left*1000,onlineDeadline=clock()+Math.max(0,state.online_until-state.server_time)*1000;
    if(kind==='image'){var image=document.createElement('img');image.src=code;image.alt='支付二维码';root.appendChild(image)}else if(window.jQuery&&jQuery.fn.qrcode){jQuery(root).qrcode({text:code,width:230,height:230,typeNumber:-1})}
    function tick(){
        left=Math.max(0,Math.ceil((expiresAt-clock())/1000));
        if(left<=0||closed){timer.textContent='00:00';expired.style.display='flex';offline.style.display='none';root.classList.add('qr-blocked');return}
        var m=Math.floor(left/60),s=left%60;timer.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
        var blocked=heartbeatEnabled&&(!state.online||state.heartbeat_enabled&&clock()>=onlineDeadline);
        offline.style.display=blocked?'flex':'none';root.classList.toggle('qr-blocked',blocked);
    }
    tick();setInterval(tick,1000);
    function check(){
        if(inFlight||left<=0||closed)return;
        if(!heartbeatEnabled){jQuery.getJSON('/getshop.php',{trade_no:<?php echo json_encode((string)$order['trade_no']); ?>},function(data){if(data&&data.code===1&&data.backurl){location.href=data.backurl}else if(left>0)pollTimer=setTimeout(check,2000)}).fail(function(){if(left>0)pollTimer=setTimeout(check,3000)});return}
        inFlight=true;var started=clock();
        jQuery.ajax({url:statusUrl,dataType:'json',cache:false,timeout:4000}).done(function(data){
            if(!data||data.code!==200||!data.data){state.online=false;offlineMessage.textContent='通道状态暂不可用，请稍后重试';return}
            state=data.data;
            if(state.paid&&state.backurl){location.href=state.backurl;return}
            closed=state.closed;expiresAt=started+Math.max(0,state.expires_in)*1000;
            onlineDeadline=started+Math.max(0,state.online_until-state.server_time)*1000;
            offlineMessage.textContent=state.channel_enabled?'请稍后重试，恢复在线后自动显示二维码':'通道已关闭，请重新发起支付';
        }).fail(function(){state.online=false;offlineMessage.textContent='通道状态暂不可用，请稍后重试'}).always(function(){inFlight=false;tick();if(left>0&&!closed)pollTimer=setTimeout(check,2000)});
    }
    document.addEventListener('visibilitychange',function(){if(!document.hidden&&heartbeatEnabled){tick();clearTimeout(pollTimer);check()}});
    pollTimer=setTimeout(check,heartbeatEnabled?0:1800);
}());
</script>
<?php endif; ?>
</body>
</html>
