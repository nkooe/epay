<?php
require __DIR__.'/../includes/common.php';
if ($islogin != 1) { header('Location: ./login.php'); exit; }
$uid = $_GET['uid'] ?? '';
if (!is_string($uid) || !preg_match('/^[1-9][0-9]{0,8}$/D', $uid)) { http_response_code(400); exit('商户编号不正确'); }
if (!$DB->getColumn('SELECT uid FROM pre_user WHERE uid=:uid', [':uid'=>$uid])) { http_response_code(404); exit('商户不存在'); }
if (empty($_SESSION['merchant_policy_csrf'])) $_SESSION['merchant_policy_csrf'] = bin2hex(random_bytes(32));
$title = '商户通道策略';
require __DIR__.'/head.php';
require __DIR__.'/merchant-policy-view.php';
?>
</body></html>
