<?php
require __DIR__.'/../includes/common.php';
if ($islogin != 1) { header('Location: ./login.php'); exit; }
if (!isset($_SESSION['task_csrf'])) $_SESSION['task_csrf'] = bin2hex(random_bytes(32));
$taskCsrf = $_SESSION['task_csrf'];
$taskApi = './task-api.php';
$title = '任务与服务管理';
include __DIR__.'/head.php';
include __DIR__.'/task-view.php';
?>
</body>
</html>
