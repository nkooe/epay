<?php
if(!defined('IN_CRON')&&($islogin??0)!=1)exit;
if(!isset($_SESSION['mall_csrf']))$_SESSION['mall_csrf']=bin2hex(random_bytes(32));
$bridgeRow=\lib\BridgeSettings::row();$bridge=$bridgeRow['config'];
$escape=static function($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
?>
<div class="panel panel-primary">
 <div class="panel-heading"><h3 class="panel-title">商城桥接</h3></div>
 <div class="panel-body">
  <p class="help-block">指定商户的微信、支付宝订单进入商品收银台。付款沿用原支付通道，原商户订单名称和回调参数保留。</p>
  <?php if(!$bridgeRow['installed']){ ?>
  <div class="alert alert-warning">请先备份数据库，再执行 <code>php bin/upgrade-mall-bridge.php --apply</code> 完成增量升级。</div>
  <?php } ?>
  <form id="mall-settings" class="form-horizontal" autocomplete="off">
   <fieldset <?= !$bridgeRow['installed']?'disabled':'' ?>>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-enabled">启用商城桥接</label><div class="col-sm-9"><select id="mall-enabled" class="form-control"><option value="0" <?= !$bridge['enabled']?'selected':'' ?>>关闭</option><option value="1" <?= $bridge['enabled']?'selected':'' ?>>开启</option></select><span class="help-block">关闭后新订单走原流程；已有桥接订单继续付款与同步。</span></div></div>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-pids">商户 PID</label><div class="col-sm-9"><input id="mall-pids" class="form-control" value="<?= $escape(implode(',',$bridge['merchant_ids'])) ?>" placeholder="多个 PID 用逗号分隔"><span class="help-block">可先保存配置，再添加商户。实际支付时须启用商户收款、使用扣费模式并关闭随机增减金额。</span></div></div>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-app">接入标识</label><div class="col-sm-9"><input id="mall-app" class="form-control" maxlength="64" value="<?= $escape($bridge['app_id']) ?>"><span class="help-block">与商城接入配置保持一致。</span></div></div>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-gateway">支付平台地址</label><div class="col-sm-9"><input id="mall-gateway" class="form-control" type="url" placeholder="https://epay.973700.xyz" value="<?= $escape($bridge['gateway_url']) ?>"></div></div>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-url">商城地址</label><div class="col-sm-9"><input id="mall-url" class="form-control" type="url" placeholder="https://www.973700.xyz" value="<?= $escape($bridge['mall_url']) ?>"></div></div>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-secret">独立桥接密钥</label><div class="col-sm-9"><div class="input-group"><input id="mall-secret" class="form-control" type="password" autocomplete="new-password" maxlength="64" aria-describedby="mall-secret-help" placeholder="<?= $bridge['secret']!==''?str_repeat('•',32):'64 位十六进制，与商城一致' ?>"><span class="input-group-btn"><button type="button" id="mall-secret-toggle" class="btn btn-default" aria-controls="mall-secret" aria-pressed="false">显示</button><button type="button" id="mall-secret-generate" class="btn btn-default">生成</button></span></div><span id="mall-secret-help" class="help-block">64 位十六进制密钥，独立于商户支付密钥。留空保留现有密钥；生成后须保存，并同步到商城，两端须使用相同密钥。</span></div></div>
   <div class="form-group"><label class="col-sm-3 control-label" for="mall-ttl">支付有效期（秒）</label><div class="col-sm-9"><input id="mall-ttl" class="form-control" type="number" min="60" max="3600" value="<?= (int)$bridge['ttl'] ?>"></div></div>
   <div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button type="submit" class="btn btn-primary">保存配置</button> <button type="button" id="mall-test" class="btn btn-default">检测已保存连接</button> <a href="tasks.php?view=services" class="btn btn-default">查看同步进程</a></div></div>
   </fieldset>
  </form>
  <p id="mall-feedback" role="status" aria-live="polite"></p>
 </div>
</div>
<script>
(() => {
 const el=id=>document.getElementById(id);let revision=<?= $bridgeRow['revision'] ?>;
 let hasSavedSecret=<?= $bridge['secret']!==''?'true':'false' ?>;
 const csrf=<?= json_encode($_SESSION['mall_csrf'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
 function secretVisible(visible){el('mall-secret').type=visible?'text':'password';el('mall-secret-toggle').textContent=visible?'隐藏':'显示';el('mall-secret-toggle').setAttribute('aria-pressed',String(visible));}
 async function send(action,data){
  const buttons=el('mall-settings').querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
  try{
   const response=await fetch('mall-api.php?act='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},credentials:'same-origin',body:JSON.stringify(data),signal:AbortSignal.timeout(30000)});
   const result=await response.json();if(!response.ok||result.code!==0)throw new Error(result.msg||'操作失败');
   if(action==='save'){revision=result.data.revision;hasSavedSecret=hasSavedSecret||el('mall-secret').value!=='';el('mall-secret').value='';el('mall-secret').placeholder=hasSavedSecret?'•'.repeat(32):'64 位十六进制，与商城一致';secretVisible(false);}
   el('mall-feedback').textContent=result.data.message||(action==='secret'?'密钥已读取':'配置已保存');el('mall-feedback').className='text-success';
   return result.data;
  }catch(error){el('mall-feedback').textContent=error.message;el('mall-feedback').className='text-danger';}
  finally{buttons.forEach(b=>b.disabled=false);}
 }
 el('mall-settings').addEventListener('submit',event=>{
  event.preventDefault();const text=el('mall-pids').value.trim();
  const ids=text?text.split(/[,，\s]+/).map(Number):[];
  if(ids.some(id=>!Number.isSafeInteger(id)||id<1)){el('mall-feedback').textContent='商户 PID 必须为正整数';return;}
  send('save',{revision,config:{enabled:el('mall-enabled').value==='1',app_id:el('mall-app').value.trim(),secret:el('mall-secret').value.trim(),gateway_url:el('mall-gateway').value.trim(),mall_url:el('mall-url').value.trim(),merchant_ids:ids,ttl:Number(el('mall-ttl').value)}});
 });
 el('mall-test').addEventListener('click',()=>send('test',{}));
 el('mall-secret-toggle').addEventListener('click',async()=>{
  if(el('mall-secret').type==='text'){secretVisible(false);return;}
  if(!el('mall-secret').value&&hasSavedSecret){const data=await send('secret',{});if(!data)return;el('mall-secret').value=data.secret;}
  secretVisible(true);
 });
 el('mall-secret-generate').addEventListener('click',()=>{
  const bytes=new Uint8Array(32);crypto.getRandomValues(bytes);
  el('mall-secret').value=Array.from(bytes,byte=>byte.toString(16).padStart(2,'0')).join('');secretVisible(false);
  el('mall-feedback').textContent='新密钥已生成，保存配置后生效；请同步更新商城密钥。';el('mall-feedback').className='text-success';
 });
})();
</script>
