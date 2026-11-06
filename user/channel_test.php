<?php
include '../includes/common.php';
if(($islogin2??0)!==1){header('Location: ./login.php');exit;}
header('Cache-Control: no-store');
$service=new \lib\Merchant\OwnedChannels(\lib\AdminApp\Runtime::legacyStore($DB));
try{$service->authorize($uid);}catch(\lib\AdminApp\ApiError $e){http_response_code($e->status);sysmsg($e->getMessage());}
$trade=$_GET['trade_no']??'';
if(!is_string($trade)||!preg_match('/^[0-9]{19}$/D',$trade))sysmsg('测试订单编号无效');
$order=$DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade AND uid=:uid AND channel_test=1',[':trade'=>$trade,':uid'=>$uid]);
if(!$order)sysmsg('测试订单不存在');
try{\lib\Channel::assertOwner($uid,$order['channel'],$order['subchannel']);}catch(\Throwable $e){sysmsg('测试通道不存在或不属于当前商户');}
if((int)$order['status']!==0){
    $title='测试支付结果';include './head.php';
    $esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
    echo '<div id="content" class="app-content"><div class="app-content-body wrapper-lg"><section class="panel panel-default"><div class="panel-body"><h1 style="font-size:24px">'.((int)$order['status']===1?'支付成功':'测试订单状态已更新').'</h1><p>订单号：'.$esc($order['trade_no']).'</p><p>支付金额：¥'.$esc($order['realmoney']).'</p><a class="btn btn-success" href="channel.php">返回通道管理</a></div></section></div></div>';
    include './foot.php';exit;
}
$submit2=true;
$conf=array_merge($conf,getGroupConfig($userrow['gid']));
try{
    $row=$DB->getRow('SELECT * FROM pre_channel WHERE id=:id AND owner_uid=:uid',[':id'=>$order['channel'],':uid'=>$uid]);
    if(!$row)throw new RuntimeException('测试通道不存在');
    $type=$DB->getRow('SELECT name,status FROM pre_type WHERE id=:id',[':id'=>$order['type']]);
    if(!$type||(int)$type['status']!==1)throw new RuntimeException('支付方式已停用');
    $order['typename']=$type['name'];$order['plugin']=$row['plugin'];
    $order['profits']=\lib\Payment::updateOrderProfits($order,$row['plugin']);
    $result=\lib\Plugin::loadForSubmit($row['plugin'],$trade);
    $result['submit']=true;\lib\Payment::echoDefault($result);
}catch(\Throwable $e){sysmsg(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
