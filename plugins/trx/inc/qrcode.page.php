<?php
if (!defined('IN_PLUGIN') || !defined('IN_PLUGIN_PAGE')) exit();
$title = isset($title) && $title !== '' ? $title : '链上支付收银台';
$browserTitle = isset($browserTitle) && $browserTitle !== '' ? $browserTitle : '收银台';
$addressJson = json_encode((string)$address, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$tradeJson = json_encode((string)$order['trade_no'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#f3f5f7">
<title><?php echo htmlspecialchars($browserTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f7;color:#1f2933;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.checkout{width:calc(100% - 28px);max-width:460px;margin:24px auto 40px;padding:24px 20px 28px;background:#fff;border:1px solid #dfe5ea;border-radius:8px;box-shadow:0 8px 24px rgba(31,41,51,.07);text-align:center}.heading{display:flex;align-items:center;justify-content:center;gap:8px;margin:0 0 18px;font-size:20px}.heading img{width:25px;height:25px;object-fit:contain}.network{margin-bottom:5px;color:#637381;font-size:14px}.amount{color:#0b7a4b;font-size:34px;font-weight:700;overflow-wrap:anywhere}.fiat{margin:4px 0 16px;color:#8a96a3;font-size:13px}.qr-shell{position:relative;width:246px;height:246px;margin:14px auto;padding:8px;border:1px solid #dfe5ea;background:#fff}.qrcode{width:228px;height:228px;display:flex;align-items:center;justify-content:center}.qrcode canvas,.qrcode img{display:block;max-width:228px;max-height:228px}.expired{display:none;position:absolute;inset:0;align-items:center;justify-content:center;flex-direction:column;background:rgba(21,29,36,.9);color:#fff;font-size:18px;font-weight:600}.expired small{margin-top:7px;font-size:13px;font-weight:400}.countdown{margin:12px 0 16px;color:#0b7a4b;font-size:15px}.field{text-align:left;margin:12px 0}.label{margin-bottom:5px;color:#73808c;font-size:12px}.value-row{display:flex;align-items:stretch;gap:8px}.value{min-width:0;flex:1;padding:10px;background:#f6f8fa;border:1px solid #e2e7eb;border-radius:5px;font-family:Consolas,monospace;font-size:13px;line-height:1.5;overflow-wrap:anywhere}.copy{flex:0 0 58px;border:0;border-radius:5px;background:#e7f4ed;color:#096b42;cursor:pointer}.hint{margin-top:18px;padding:12px;background:#fff7e8;border:1px solid #f1d8a6;border-radius:6px;color:#76551c;font-size:13px;line-height:1.7;text-align:left}.error{margin:28px 0;color:#c62828;line-height:1.7}.order{margin-top:14px;color:#8a96a3;font-size:12px;overflow-wrap:anywhere}@media(max-width:520px){.checkout{margin:12px auto 24px;padding:20px 14px 24px}.amount{font-size:30px}.qr-shell{width:236px;height:236px}.qrcode{width:218px;height:218px}.qrcode canvas,.qrcode img{max-width:218px;max-height:218px}}
</style>
</head>
<body>
<main class="checkout">
<h1 class="heading"><img src="<?php echo htmlspecialchars($payment_icon, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
<?php if ($error_msg !== ''): ?>
<div class="error"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php else: ?>
<div class="network"><?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?> 网络</div>
<div class="amount"><?php echo htmlspecialchars($display_amount, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?></div>
<div class="fiat">订单金额 ￥<?php echo htmlspecialchars((string)$order['realmoney'], ENT_QUOTES, 'UTF-8'); ?></div>
<div class="qr-shell"><div class="qrcode" id="qrcode"></div><div class="expired" id="expired"><span>订单已失效</span><small>请重新发起支付</small></div></div>
<div class="countdown">剩余支付时间：<b id="timer">--:--</b></div>
<div class="field"><div class="label">收款地址</div><div class="value-row"><div class="value" id="address-value"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></div><button class="copy" type="button" data-copy="address-value">复制</button></div></div>
<div class="field"><div class="label">精确支付金额</div><div class="value-row"><div class="value" id="amount-value"><?php echo htmlspecialchars($display_amount, ENT_QUOTES, 'UTF-8'); ?></div><button class="copy" type="button" data-copy="amount-value">复制</button></div></div>
<div class="hint">只能使用 <?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?> 网络转账。必须支付上方精确金额，少付、多付或使用其他网络都不会自动到账。</div>
<div class="order">订单号：<?php echo htmlspecialchars((string)$order['trade_no'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
</main>
<script src="/assets/vendor/jquery/1.12.4/jquery.min.js"></script>
<script src="/assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<?php if ($error_msg === ''): ?>
<script>(function(){var address=<?php echo $addressJson; ?>,trade=<?php echo $tradeJson; ?>,remain=<?php echo (int)$paytime; ?>,timer=document.getElementById('timer'),expired=document.getElementById('expired'),poll=0,tick=0,box=document.getElementById('qrcode');function draw(){if(window.jQuery&&jQuery.fn.qrcode){jQuery(box).qrcode({text:address,width:218,height:218,typeNumber:-1})}else{box.textContent='二维码组件加载失败'}}function stop(){if(timer)timer.textContent='00:00';if(expired)expired.style.display='flex';if(poll)clearTimeout(poll);if(tick)clearInterval(tick)}function clock(){if(remain<=0){stop();return}var m=Math.floor(remain/60),s=remain%60;timer.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s;remain--}function check(){if(remain<=0)return;fetch('/getshop.php?trade_no='+encodeURIComponent(trade),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}).then(function(r){return r.json()}).then(function(d){if(d&&Number(d.code)===1&&d.backurl){location.href=d.backurl;return}poll=setTimeout(check,2500)}).catch(function(){poll=setTimeout(check,4000)})}function copy(id,button){var text=document.getElementById(id).textContent;var done=function(){var old=button.textContent;button.textContent='已复制';setTimeout(function(){button.textContent=old},1200)};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done);return}var t=document.createElement('textarea');t.value=text;t.style.position='fixed';t.style.opacity='0';document.body.appendChild(t);t.select();try{document.execCommand('copy');done()}catch(_){}document.body.removeChild(t)}document.querySelectorAll('[data-copy]').forEach(function(b){b.onclick=function(){copy(b.getAttribute('data-copy'),b)}});draw();clock();tick=setInterval(clock,1000);poll=setTimeout(check,2000)}())</script>
<?php endif; ?>
</body>
</html>
