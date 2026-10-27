<?php
if (!defined('IN_PLUGIN')) exit();
$title = isset($title) && $title !== '' ? $title : '支付宝-收银台';
$browserTitle = isset($browserTitle) && $browserTitle !== '' ? $browserTitle : '收银台';
$paymentIcon = isset($paymentIcon) ? (string)$paymentIcon : '/assets/icon/alipay.ico';
$code_url_json = json_encode($code_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?php echo htmlspecialchars($browserTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#20242a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.wrap{width:92%;max-width:420px;margin:32px auto;padding:26px 22px 30px;background:#fff;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.08);text-align:center}h1{margin:0 0 12px;font-size:22px;font-weight:600}.payment-icon{width:20px;height:20px;margin-right:6px;vertical-align:-3px}.amount{margin-bottom:14px;color:#1677ff;font-size:30px;font-weight:700}.qr-box{position:relative;width:250px;height:250px;margin:16px auto;padding:10px;border:1px solid #e7eaee;background:#fff}#qrcode{width:230px;height:230px;display:flex;align-items:center;justify-content:center;overflow:hidden}#qrcode img,#qrcode canvas{max-width:230px;max-height:230px}.overlay{display:none;position:absolute;inset:0;z-index:2;align-items:center;justify-content:center;flex-direction:column;background:rgba(20,24,30,.82);color:#fff;font-size:18px;font-weight:600}.overlay small{margin-top:8px;font-size:13px;font-weight:400}.countdown{margin:12px 0;color:#1677ff;font-size:16px}.order-meta{margin:8px 0 14px;color:#8a919b;font-size:13px;line-height:1.8}.hint{color:#8a919b;font-size:14px;line-height:1.7}.error{margin:26px 0;color:#d93025;line-height:1.7}
    </style>
</head>
<body>
<main class="wrap">
    <h1><?php if ($paymentIcon !== ''): ?><img class="payment-icon" src="<?php echo htmlspecialchars($paymentIcon, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="amount">￥<?php echo htmlspecialchars((string)$order['realmoney'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php if ($error_msg !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <div class="qr-box"><div id="qrcode"></div><div class="overlay" id="expired"><span>二维码已失效</span><small>订单已超时，请重新发起支付</small></div></div>
        <div class="countdown">支付剩余时间：<b id="timer">--:--</b></div>
        <div class="order-meta"><div>订单号：<?php echo htmlspecialchars((string)$order['trade_no'], ENT_QUOTES, 'UTF-8'); ?></div><div>订单创建时间：<?php echo htmlspecialchars((string)$order['addtime'], ENT_QUOTES, 'UTF-8'); ?></div></div>
        <div class="hint">请使用支付宝扫一扫完成支付<br>支付完成后页面会自动跳转</div>
    <?php endif; ?>
</main>
<script src="/assets/vendor/jquery/1.12.4/jquery.min.js"></script>
<script src="/assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<?php if ($error_msg === ''): ?>
<script>
(function(){
    var code=<?php echo $code_url_json; ?>,
        remain=<?php echo (int)$paytime; ?>,
        timer=document.getElementById('timer'),
        expired=document.getElementById('expired'),
        qrcode=document.getElementById('qrcode'),
        tradeNo=<?php echo json_encode((string)$order['trade_no'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checkTimer=0;

    function showQr(){
        if(!qrcode)return;
        qrcode.innerHTML='';
        if(typeof code!=='string'||code===''){
            qrcode.textContent='二维码地址为空';
            return;
        }
        if(/^data:image\//i.test(code)||/^https?:\/\//i.test(code)&&/\.(?:png|jpe?g|gif|webp)(?:[?#].*)?$/i.test(code)){
            var img=document.createElement('img');
            img.alt='支付二维码';
            img.src=code;
            qrcode.appendChild(img);
            return;
        }
        if(window.jQuery&&window.jQuery.fn&&typeof window.jQuery.fn.qrcode==='function'){
            window.jQuery(qrcode).qrcode({text:code,width:230,height:230,typeNumber:-1});
            return;
        }
        qrcode.textContent='二维码组件加载失败，请刷新页面重试';
    }

    function timeout(){
        if(expired)expired.style.display='flex';
        if(timer)timer.textContent='00:00';
        if(checkTimer)window.clearTimeout(checkTimer);
    }

    function tick(){
        if(remain<=0){timeout();return;}
        var m=Math.floor(remain/60),s=remain%60;
        if(timer)timer.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
        remain--;
        if(remain<0)timeout();
    }

    function scheduleCheck(delay){
        if(remain>0)checkTimer=window.setTimeout(check,delay);
    }

    function check(){
        if(remain<=0)return;
        var url='/getshop.php?trade_no='+encodeURIComponent(tradeNo);
        window.fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
            .then(function(response){
                if(!response.ok)throw new Error('HTTP '+response.status);
                return response.json();
            })
            .then(function(data){
                if(data&&Number(data.code)===1&&data.backurl){
                    window.location.href=data.backurl;
                    return;
                }
                scheduleCheck(2000);
            })
            .catch(function(){scheduleCheck(3000);});
    }

    showQr();
    tick();
    window.setInterval(tick,1000);
    window.setTimeout(check,1800);
}());
</script>
<?php endif; ?>
</body>
</html>
