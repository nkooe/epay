<?php
$clientip=real_ip($conf['ip_type']?$conf['ip_type']:0);

if(isset($_COOKIE["admin_token"]))
{
	$token=authcode(daddslashes($_COOKIE['admin_token']), 'DECODE', SYS_KEY);
	list($user, $sid, $expiretime) = explode("\t", $token);
	$session=webAdminSessionStamp($conf);
	if(is_string($sid) && hash_equals($session,$sid) && $user===$conf['admin_user'] && $expiretime>time()) {
		$islogin=1;
	}
}
if(isset($_COOKIE["user_token"]))
{
	$token=authcode(daddslashes($_COOKIE['user_token']), 'DECODE', SYS_KEY);
	$parts = explode("\t", $token);
	if(count($parts)===3 && ctype_digit($parts[0]) && ctype_digit($parts[2]) && $parts[2]>time()) {
		list($uid, $sid, $expiretime) = $parts;
		$uid = intval($uid);
		$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid=:uid limit 1", [':uid'=>$uid]);
		if($userrow && !empty($userrow['status']) && hash_equals(webMerchantSessionStamp($userrow),$sid)) {
			$islogin2=1;
		}
	}
}
?>