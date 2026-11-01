<?php
require __DIR__.'/../includes/common.php';
if($islogin!=1){header('Location: ./login.php');exit;}
if(empty($_SESSION['complaint_csrf']))$_SESSION['complaint_csrf']=bin2hex(random_bytes(32));
$title='投诉管理';require __DIR__.'/head.php';require __DIR__.'/complaint-view.php';
?>
</body></html>
