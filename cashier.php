<?php
$is_defend = true;
$nosession = true;
require './includes/common.php';

@header('Content-Type: text/html; charset=UTF-8');

$other=isset($_GET['other'])?true:false;
$trade_no=daddslashes($_GET['trade_no']);
$sitename=base64_decode(daddslashes($_GET['sitename']));
$row=$DB->getRow("SELECT * FROM pre_order WHERE trade_no='{$trade_no}' limit 1");
if(!$row)sysmsg('该订单号不存在，请返回来源地重新发起请求！');
if($row['status']==1)sysmsg('该订单已完成支付，请勿重复支付');
$gid = $DB->getColumn("SELECT gid FROM pre_user WHERE uid='{$row['uid']}' limit 1");
$paytype = \lib\Channel::getTypes($row['uid'], $gid);

if(checkwechat()){
	$paytype = array_values($paytype);
	foreach($paytype as $i=>$s){
		if($s['name']=='wxpay'){
			$temp = $paytype[$i];
			$paytype[$i] = $paytype[0];
			$paytype[0] = $temp;
		}
	}
}
require __DIR__ . '/includes/pages/cashier.php';
