<?php
/** Additive, CLI-only bridge installation for an existing schema 2065 site. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(array_diff(array_slice($argv,1),['--apply']))throw new InvalidArgumentException('用法：php bin/upgrade-mall-bridge.php [--apply]');
$root=dirname(__DIR__);require $root.'/config.php';
if(!is_array($dbconfig)||!preg_match('/^[A-Za-z0-9_]+$/D',$dbconfig['dbqz']??''))throw new RuntimeException('数据库配置无效');
$pdo=new PDO('mysql:host='.$dbconfig['host'].';port='.$dbconfig['port'].';dbname='.$dbconfig['dbname'].';charset=utf8mb4',$dbconfig['user'],$dbconfig['pwd'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix=$dbconfig['dbqz'].'_';
if((string)$pdo->query("SELECT v FROM `{$prefix}config` WHERE k='version'")->fetchColumn()!=='2065')throw new RuntimeException('请先完成数据库 2065 升级');
echo "商城桥接增量：创建会话、待同步状态、验签 nonce 和设置表；保留现有订单、通道、资金与进件。\n";
if(!in_array('--apply',$argv,true)){echo "当前为预览。备份后加 --apply 执行；新功能默认关闭。\n";exit;}
$lock='epay.mall.schema.'.substr(hash('sha256',$dbconfig['dbname'].':'.$prefix),0,40);
$stmt=$pdo->prepare('SELECT GET_LOCK(?,5)');$stmt->execute([$lock]);
if((int)$stmt->fetchColumn()!==1)throw new RuntimeException('其他升级进程正在运行');
try{
    $pdo->exec('SET SESSION lock_wait_timeout=5');
    // Both new installations and upgrades use the same canonical table definitions and seeds.
    foreach(explode(';',file_get_contents(__DIR__.'/../install/install.sql')) as $statement){
        if(preg_match('/^\s*CREATE TABLE `pre_mall_\w+`/i',$statement))$statement=preg_replace('/CREATE TABLE/i','CREATE TABLE IF NOT EXISTS',$statement,1);
        elseif(preg_match('/^\s*INSERT INTO `pre_mall_settings`/i',$statement)||preg_match("/^\\s*INSERT INTO `pre_config`.*'mall_bridge_schema'/is",$statement))$statement=preg_replace('/INSERT INTO/i','INSERT IGNORE INTO',$statement,1);
        else continue;
        $pdo->exec(str_replace('pre_',$prefix,$statement));
    }
    $pdo->exec("UPDATE `{$prefix}cache` SET v='' WHERE k='config'");
    echo "商城桥接 schema 1 已就绪。可重复运行；请在系统设置中配置后启用。\n";
}finally{$stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lock]);}
