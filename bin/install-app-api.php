<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--apply',$argv,true)) { echo "用法：php bin/install-app-api.php --apply\n增量创建管理员接口数据表，保留原有业务数据。\n"; exit; }
$root=dirname(__DIR__);
if (!is_file($root.'/config.php')) { fwrite(STDERR,"请先配置 config.php 数据库连接。\n"); exit(1); }
require $root.'/config.php';
require $root.'/includes/lib/AdminApp/Store.php';
if (empty($dbconfig['dbname']) || empty($dbconfig['user'])) { fwrite(STDERR,"请先配置 config.php 数据库连接。\n"); exit(1); }
try {
    $pdo=new PDO("mysql:host={$dbconfig['host']};port={$dbconfig['port']};dbname={$dbconfig['dbname']};charset=utf8mb4",$dbconfig['user'],$dbconfig['pwd']);
    $db=new \lib\AdminApp\Store($pdo,$dbconfig['dbqz']);
    foreach (explode(';',file_get_contents($root.'/install/app-api.sql')) as $sql) if (trim($sql)!=='') $db->query($sql);
    if(!$db->row("SHOW COLUMNS FROM pre_app_operation LIKE 'detail'"))$db->query('ALTER TABLE pre_app_operation ADD COLUMN detail TEXT NULL');
    // 二维码和门店列表可能超过普通文本字段的容量。
    foreach(['app_request'=>['response','NULL'],'app_challenge'=>['data','NOT NULL']] as $table=>[$field,$nullable]){
        $column=$db->row("SHOW COLUMNS FROM pre_".$table." LIKE '".$field."'");
        if(!in_array(strtolower($column['Type']),['mediumtext','longtext'],true))$db->query('ALTER TABLE pre_'.$table.' MODIFY COLUMN `'.$field.'` MEDIUMTEXT '.$nullable);
    }
    echo "管理员接口数据表安装完成。\n";
} catch (Throwable $e) { fwrite(STDERR,"安装失败：".$e->getMessage()."\n"); exit(1); }
