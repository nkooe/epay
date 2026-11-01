<?php
error_reporting(0);
define('DB_VERSION', '2063');
require '../config.php';

@header('Content-Type: text/html; charset=UTF-8');

try{
	$db=new PDO("mysql:host=".$dbconfig['host'].";dbname=".$dbconfig['dbname'].";port=".$dbconfig['port'],$dbconfig['user'],$dbconfig['pwd']);
}catch(Exception $e){
	exit('链接数据库失败:'.$e->getMessage());
}
date_default_timezone_set("PRC");
$date = date("Y-m-d");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$db->exec("set sql_mode = ''");
$db->exec("set names utf8");

if(!preg_match('/^[a-zA-Z0-9_]+$/D', $dbconfig['dbqz']))exit('数据库前缀无效');
$rs = $db->query("SELECT v FROM `".$dbconfig['dbqz']."_config` WHERE k='version'");
if(!$rs || !is_string($version = $rs->fetchColumn()) || !preg_match('/^[0-9]+$/D', $version)){
	http_response_code(500);
	exit('无法读取有效数据库版本，升级已停止');
}

function readUpgradeSql($filename){
	$sql = file_get_contents(__DIR__.'/'.$filename);
	if($sql===false || trim($sql)===''){
		http_response_code(500);
		exit('缺少数据库升级文件：'.htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'));
	}
	return $sql;
}

if($version==DB_VERSION){
	exit('你的网站已经升级到最新版本了');
}elseif($version<2044){
	$sqls = readUpgradeSql('update2.sql');
	$sqls .= readUpgradeSql('update3.sql');
	$sqls .= readUpgradeSql('update4.sql');
	$sqls .= readUpgradeSql('update5.sql');
	$sqls .= readUpgradeSql('update6.sql');
	$sqls .= readUpgradeSql('update7.sql');
	$sqls=explode(';', $sqls);
}elseif($version==2062 || $version==2061 || $version==2060 || $version==2059 || $version==2058){
	$sqls=[];
}elseif($version==2057){
	$sqls=explode(';', readUpgradeSql('update7.sql'));
}elseif($version==2056){
	$sqls=explode(';', readUpgradeSql('update6.sql').readUpgradeSql('update7.sql'));
}elseif($version==2055){
	$sqls=explode(';', readUpgradeSql('update5.sql').readUpgradeSql('update6.sql').readUpgradeSql('update7.sql'));
}elseif($version==2054){
	$sqls=explode(';', readUpgradeSql('update4.sql').readUpgradeSql('update5.sql').readUpgradeSql('update6.sql').readUpgradeSql('update7.sql'));
}elseif($version<DB_VERSION){
	$sqls = readUpgradeSql('update3.sql');
	$sqls .= readUpgradeSql('update4.sql');
	$sqls .= readUpgradeSql('update5.sql');
	$sqls .= readUpgradeSql('update6.sql');
	$sqls .= readUpgradeSql('update7.sql');
	$sqls=explode(';', $sqls);
}else{
	exit('数据库不兼容，请重新安装！');
}
$sqls=array_merge($sqls,explode(';',readUpgradeSql('update8.sql')));
$sqls=array_merge($sqls,explode(';',readUpgradeSql('update9.sql')));
$sqls=array_merge($sqls,explode(';',readUpgradeSql('update10.sql')));
$sqls=array_merge($sqls,explode(';',readUpgradeSql('update11.sql')));
require __DIR__.'/task-schema.php';
$sqls[]="UPDATE `pre_cache` SET `v` = '' where `k` = 'config'";
$sqls[]="UPDATE `pre_config` SET `v` = '".DB_VERSION."' where `k` = 'version'";
$success=0;
foreach ($sqls as $value) {
	$value=trim($value);
	if(empty($value))continue;
	if(strpos($value,"UPDATE `pre_cache`")===0) {
		try { epayUpgradeTaskSchema($db,$dbconfig['dbqz']); } catch (Throwable $e) { http_response_code(500); exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }
	}
	$value = str_replace('pre_',$dbconfig['dbqz'].'_',$value);
	if($db->exec($value)===false){
		$dberror=$db->errorInfo();
		http_response_code(500);
		exit('数据库升级失败，版本号未更新：'.htmlspecialchars($dberror[2], ENT_QUOTES, 'UTF-8'));
	}else{
		$success++;
	}
}
echo '成功执行SQL语句'.$success.'条！<br/>';
echo '<hr/><a href="/">点此返回首页</a>';
?>
