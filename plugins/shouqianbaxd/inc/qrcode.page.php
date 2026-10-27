<?php
if (!defined('IN_PLUGIN') || !defined('IN_PLUGIN_PAGE')) exit();
$title = isset($title) && $title !== '' ? $title : '收钱吧小店支付-收银台';
$browserTitle = isset($browserTitle) && $browserTitle !== '' ? $browserTitle : '收银台';
$paymentIcon = isset($paymentIcon) ? (string)$paymentIcon : '/assets/icon/bank.ico';
$paymentType = isset($paymentType) ? (string)$paymentType : 'bank';
$codeJson = json_encode((string)$code_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$tradeJson = json_encode((string)$order['trade_no'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$openUrl = htmlspecialchars((string)$open_url, ENT_QUOTES, 'UTF-8');
$mobileUrl = $paymentType === 'wxpay' ? (string)$wechat_url : ($paymentType === 'alipay' ? (string)$alipay_url : (string)$open_url);
$mobileLabel = $paymentType === 'wxpay' ? '打开微信支付' : ($paymentType === 'alipay' ? '打开支付宝支付' : '打开收钱吧支付页');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
    <meta name="theme-color" content="#f4f7fb">
    <title><?php echo htmlspecialchars($browserTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#20242a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.wrap{width:calc(100% - 32px);max-width:440px;margin:32px auto;padding:26px 22px 30px;background:#fff;border:1px solid #e4e9ee;border-radius:8px;box-shadow:0 10px 30px rgba(20,40,60,.08);text-align:center}h1{margin:0 0 12px;font-size:21px;font-weight:650}.payment-icon{width:21px;height:21px;margin-right:7px;vertical-align:-4px}.amount{margin-bottom:14px;color:#1677ff;font-size:32px;font-weight:700}.qr-box{position:relative;width:250px;height:250px;margin:16px auto;padding:10px;border:1px solid #e4e9ee;background:#fff}.qrcode{width:230px;height:230px;display:flex;align-items:center;justify-content:center;overflow:hidden}.qrcode img,.qrcode canvas{display:block;max-width:230px;max-height:230px}.overlay{display:none;position:absolute;inset:0;z-index:2;align-items:center;justify-content:center;flex-direction:column;background:rgba(20,24,30,.86);color:#fff;font-size:18px;font-weight:650}.overlay small{margin-top:8px;font-size:13px;font-weight:400}.countdown{margin:12px 0;color:#1677ff;font-size:15px}.order-meta{margin:8px 0 14px;color:#8a919b;font-size:13px;line-height:1.8;overflow-wrap:anywhere}.hint{color:#687684;font-size:14px;line-height:1.7}.error{margin:26px 0;color:#d93025;line-height:1.7}.actions{display:flex;justify-content:center;gap:10px;margin-top:18px;flex-wrap:wrap}.button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 18px;border:0;border-radius:6px;color:#fff;background:#1677ff;text-decoration:none}.button.secondary{color:#334155;background:#eef2f6}.mobile-button{display:none}@media(max-width:720px){.wrap{margin:16px auto;padding:22px 16px 26px}.mobile-button{display:inline-flex}.desktop-button{display:none}}
    </style>
</head>
<body>
<main class="wrap">
    <h1><img class="payment-icon" src="<?php echo htmlspecialchars($paymentIcon, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="amount">￥<?php echo htmlspecialchars((string)$order['realmoney'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php if ($error_msg !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="actions"><a class="button secondary" href="/">返回首页</a></div>
    <?php else: ?>
        <div class="qr-box"><div class="qrcode" id="qrcode"></div><div class="overlay" id="expired"><span>二维码已失效</span><small>订单已超时，请重新发起支付</small></div></div>
        <div class="countdown">支付剩余时间：<b id="timer">--:--</b></div>
        <div class="order-meta"><div>订单号：<?php echo htmlspecialchars((string)$order['trade_no'], ENT_QUOTES, 'UTF-8'); ?></div><div>订单创建时间：<?php echo htmlspecialchars((string)$order['addtime'], ENT_QUOTES, 'UTF-8'); ?></div></div>
        <div class="hint">请使用微信或支付宝扫描二维码完成支付<br>支付完成后页面会自动跳转</div>
        <div class="actions"><a class="button mobile-button" href="<?php echo htmlspecialchars($mobileUrl, ENT_QUOTES, 'UTF-8'); ?>" rel="noopener"><?php echo htmlspecialchars($mobileLabel, ENT_QUOTES, 'UTF-8'); ?></a><a class="button desktop-button" href="<?php echo $openUrl; ?>" target="_blank" rel="noopener">打开支付页</a></div>
    <?php endif; ?>
</main>
<script src="/assets/vendor/jquery/1.12.4/jquery.min.js"></script>
<script src="/assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<?php if ($error_msg === ''): ?>
<script>(function(){var code=<?php echo $codeJson; ?>,remain=<?php echo (int)$paytime; ?>,trade=<?php echo $tradeJson; ?>,box=document.getElementById('qrcode'),timer=document.getElementById('timer'),expired=document.getElementById('expired'),checkTimer=0,tickTimer=0;function draw(){if(!code){box.textContent='支付链接为空';return}if(window.jQuery&&jQuery.fn.qrcode){jQuery(box).qrcode({text:code,width:230,height:230,typeNumber:-1})}else{box.textContent='二维码组件加载失败，请刷新页面'}}function timeout(){if(expired)expired.style.display='flex';if(timer)timer.textContent='00:00';if(checkTimer)clearTimeout(checkTimer);if(tickTimer)clearInterval(tickTimer)}function tick(){if(remain<=0){timeout();return}var m=Math.floor(remain/60),s=remain%60;if(timer)timer.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s;remain--}function check(){if(remain<=0)return;fetch('/getshop.php?trade_no='+encodeURIComponent(trade),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}).then(function(r){return r.json()}).then(function(d){if(d&&Number(d.code)===1&&d.backurl){location.href=d.backurl;return}checkTimer=setTimeout(check,2000)}).catch(function(){checkTimer=setTimeout(check,3000)})}draw();tick();tickTimer=setInterval(tick,1000);checkTimer=setTimeout(check,1800)}())</script>
<?php endif; ?>
</body>
</html>
