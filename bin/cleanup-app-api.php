<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if(!in_array('--apply',$argv,true)){echo "用法：php bin/cleanup-app-api.php --apply\n";exit;}
$root=dirname(__DIR__);
if (!is_file($root.'/config.php')) { fwrite(STDERR,"请先配置 config.php 数据库连接。\n"); exit(1); }
require $root.'/config.php';
require $root.'/includes/lib/AdminApp/Store.php';
try{
    $pdo=new PDO("mysql:host={$dbconfig['host']};port={$dbconfig['port']};dbname={$dbconfig['dbname']};charset=utf8mb4",$dbconfig['user'],$dbconfig['pwd']);
    $db=new \lib\AdminApp\Store($pdo,$dbconfig['dbqz']);
    foreach(['app_challenge','app_limit','app_session'] as $table)$db->query('DELETE FROM pre_'.$table.' WHERE expires<?',[time()-86400]);
    // 幂等记录保留九十天；结果待核实的记录保留供人工对账。
    $db->query('DELETE FROM pre_app_request WHERE created<? AND response IS NOT NULL',[time()-90*86400]);
    // 停用离线三十天的通知设备，收款事件保留九十天。
    $db->query('UPDATE pre_app_notification_device SET enabled=0 WHERE enabled=1 AND last_seen<?',[time()-30*86400]);
    $db->query('DELETE FROM pre_app_notification WHERE created<?',[time()-90*86400]);
    echo "管理员接口的过期记录已清理。\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
