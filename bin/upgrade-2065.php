<?php
/** CLI-only additive upgrade. Back up application files and database before deployment. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);require $root.'/config.php';
if(!is_array($dbconfig)||!preg_match('/^[A-Za-z0-9_]+$/D',$dbconfig['dbqz']??''))throw new RuntimeException('数据库配置无效');
$pdo=new PDO('mysql:host='.$dbconfig['host'].';port='.$dbconfig['port'].';dbname='.$dbconfig['dbname'].';charset=utf8mb4',$dbconfig['user'],$dbconfig['pwd'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix=$dbconfig['dbqz'].'_';
$version=(string)$pdo->query("SELECT v FROM `{$prefix}config` WHERE k='version'")->fetchColumn();
if(!in_array($version,['2064','2065'],true))throw new RuntimeException('仅支持从2064升级到2065');
$pdo->exec('SET SESSION lock_wait_timeout=5');
foreach(['channel'=>['owner_uid'=>'int unsigned NOT NULL DEFAULT 0'],'order'=>['channel_test'=>'tinyint NOT NULL DEFAULT 0']] as $table=>$columns){
    foreach($columns as $name=>$ddl){
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute([$prefix.$table,$name]);
        if(!(int)$stmt->fetchColumn())$pdo->exec('ALTER TABLE `'.$prefix.$table.'` ADD COLUMN `'.$name.'` '.$ddl);
    }
}
$stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$stmt->execute([$prefix.'channel','owner_type_status']);
if(!(int)$stmt->fetchColumn())$pdo->exec('ALTER TABLE `'.$prefix.'channel` ADD INDEX `owner_type_status` (`owner_uid`,`type`,`status`)');
$pdo->exec('CREATE TABLE IF NOT EXISTS `'.$prefix.'merchant_channel_source` (`uid` int unsigned NOT NULL,`type` int unsigned NOT NULL,`source` varchar(8) NOT NULL DEFAULT \'platform\',PRIMARY KEY (`uid`,`type`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->beginTransaction();try{
    $pdo->exec("INSERT IGNORE INTO `{$prefix}config` (k,v) VALUES ('merchant_channel_open','0')");
    $pdo->exec("UPDATE `{$prefix}config` SET v='2065' WHERE k='version'");
    $pdo->exec("UPDATE `{$prefix}cache` SET v='' WHERE k='config'");$pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo "Schema 2065 ready; merchant channel management is disabled unless already enabled.\n";
