<?php
include("../includes/common.php");

if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

$uid=intval($_GET['uid']);

$userrow=$DB->getRow("select * from pre_user where uid='$uid' limit 1");
if(!$userrow)sysmsg('当前用户不存在！');

$session=webMerchantSessionStamp($userrow);
$expiretime=time()+604800;
$token=authcode("{$uid}\t{$session}\t{$expiretime}", 'ENCODE', SYS_KEY);
setAuthCookie("user_token", $token, time() + 604800, '/user');

exit("<script language='javascript'>window.location.href='../user/';</script>");
