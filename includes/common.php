<?php

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);
if(defined('IN_CRONLITE'))return;
define('VERSION', '3097');
define('DB_VERSION', '2064');
define('IN_CRONLITE', true);
define('SYSTEM_ROOT', dirname(__FILE__).'/');
define('ROOT', dirname(SYSTEM_ROOT).'/');
define('PAYPAGE_ROOT', SYSTEM_ROOT.'pages/');
define('TEMPLATE_ROOT', ROOT.'template/');
define('PLUGIN_ROOT', ROOT.'plugins/');

// 首次安装先进入安装器；已有安装锁时，配置丢失必须修复而非重新安装。
$configFile = ROOT.'config.php';
$installLocked = file_exists(ROOT.'install/install.lock');
if (!is_file($configFile) || !is_readable($configFile)) {
    if (!file_exists($configFile) && !is_link($configFile) && !$installLocked && is_file(ROOT.'install/index.php')) {
        header('Location: /install/', true, 302);
        exit;
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    exit('站点配置文件缺失或不可读，请恢复 config.php 或检查读取权限。');
}
date_default_timezone_set('Asia/Shanghai');
$date = date("Y-m-d H:i:s");

require_once SYSTEM_ROOT.'lib/Security/ClientAddress.php';
if(!isset($nosession) || !$nosession){
    session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>\lib\Security\ClientAddress::secure($_SERVER)]);
}
if(!function_exists('is_https')){
    function is_https(){return \lib\Security\ClientAddress::secure($_SERVER);}
}

$siteurl = (is_https() ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].'/';

include_once(SYSTEM_ROOT."autoloader.php");
Autoloader::register();

if($is_defend){
	include_once(SYSTEM_ROOT."txprotect.php");
}

require ROOT.'config.php';
define('DBQZ', $dbconfig['dbqz']);

if(empty($dbconfig['user'])||empty($dbconfig['pwd'])||empty($dbconfig['dbname']))//检测安装1
{
if(!$installLocked && is_file(ROOT.'install/index.php')){
header('Location: /install/', true, 302);
exit();
}
http_response_code(503);
header('Content-type:text/html;charset=utf-8');
echo '站点数据库配置不完整，请修复 config.php。';
exit();
}

$DB = new \lib\PdoHelper($dbconfig);

if($DB->query("select * from pre_config where 1")==FALSE)//检测安装2
{
header('Content-type:text/html;charset=utf-8');
echo '你还没安装！<a href="/install/">点此安装</a>';
exit();
}


$CACHE=new \lib\Cache();
$conf=$CACHE->pre_fetch();
define('SYS_KEY', $conf['syskey']);
if(!$conf['localurl'])$conf['localurl'] = $siteurl;
$password_hash='!@#%!s!0';

if ((string)$conf['version'] !== DB_VERSION) {
    http_response_code(503);
    header('Content-type:text/html;charset=utf-8');
    exit('数据库结构与当前程序不一致，请使用本版安装文件建立新站点。');
}

include_once(SYSTEM_ROOT."functions.php");
include_once(SYSTEM_ROOT."member.php");

require_once SYSTEM_ROOT."vendor/autoload.php";

if (!file_exists(ROOT.'install/install.lock') && file_exists(ROOT.'install/index.php')) {
	sysmsg('<h2>检测到无 install.lock 文件</h2><ul><li><font size="4">如果您尚未安装本程序，请<a href="/install/">前往安装</a></font></li><li><font size="4">如果您已经安装本程序，请手动放置一个空的 install.lock 文件到 /install 文件夹下，<b>为了您站点安全，在您完成它之前我们不会工作。</b></font></li></ul><br/><h4>为什么必须建立 install.lock 文件？</h4>它是安装保护文件，如果检测不到它，就会认为站点还没安装，此时任何人都可以安装/重装你的网站。<br/><br/>');exit;
}

if($conf['cdnpublic']==1){
	$cdnpublic = '//lib.baomitu.com/';
}elseif($conf['cdnpublic']==2){
	$cdnpublic = 'https://s4.zstatic.net/ajax/libs/';
}elseif($conf['cdnpublic']==4){
	$cdnpublic = 'https://cdnjs.snrat.com/ajax/libs/';
}else{
	$cdnpublic = '/assets/vendor/';
}

if(empty($conf['public_key'])){
	$key_pair = generate_key_pair();
	if($key_pair){
		$conf['public_key'] = $key_pair['public_key'];
		$conf['private_key'] = $key_pair['private_key'];
		saveSetting('public_key', $conf['public_key']);
		saveSetting('private_key', $conf['private_key']);
		$CACHE->clear();
		unset($key_pair);
	}
}
?>
