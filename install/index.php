<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_start();
$root=dirname(__DIR__);
$configFile=$root.'/config.php';
if (file_exists(__DIR__.'/install.lock')) {
    exit('你已经成功安装，安装入口已锁定。');
}
if (empty($_SESSION['installation_csrf'])) $_SESSION['installation_csrf']=bin2hex(random_bytes(24));
$configWritable=file_exists($configFile) ? (is_file($configFile) && is_writable($configFile)) : is_writable($root);
$checks=[
    '运行环境版本不低于 8.1'=>version_compare(PHP_VERSION,'8.1.0','>='),
    '数据库驱动'=>extension_loaded('pdo_mysql'),
    '网络请求组件'=>extension_loaded('curl'),
    '加密组件'=>extension_loaded('openssl'),
    '多字节字符组件'=>extension_loaded('mbstring'),
    '高精度计算组件'=>extension_loaded('bcmath'),
    '配置文件写入权限（根目录 config.php）'=>$configWritable,
    '安装锁写入权限'=>is_writable(__DIR__),
    '数据库安装文件'=>is_file(__DIR__.'/install.sql') && is_readable(__DIR__.'/install.sql'),
];
$ready=!in_array(false,$checks,true);
$error='';$success=false;
$fields=['host'=>'127.0.0.1','port'=>'3306','user'=>'','dbname'=>'','dbqz'=>'pay'];
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $guard=null;
    try {
        if (!$ready) throw new RuntimeException('安装环境检测未通过，请先修复不支持的项目。');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['installation_csrf'],$_POST['csrf'])) throw new RuntimeException('安装页面已过期，请刷新重试。');
        foreach ($fields as $key=>$value) {
            if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('请填写完整的数据库信息。');
            $fields[$key]=trim($_POST[$key]);
        }
        $password=$_POST['pwd'] ?? null;
        if (!is_string($password) || $password==='' || strlen($password)>512 || preg_match('/[\x00-\x1f\x7f]/',$password)) throw new RuntimeException('数据库密码格式不正确。');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,255}$/D',$fields['host'])) throw new RuntimeException('数据库地址格式不正确。');
        if (!ctype_digit($fields['port']) || (int)$fields['port']<1 || (int)$fields['port']>65535) throw new RuntimeException('数据库端口格式不正确。');
        if ($fields['user']==='' || strlen($fields['user'])>80 || preg_match('/[\x00-\x1f\x7f]/',$fields['user'])) throw new RuntimeException('数据库用户名格式不正确。');
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/D',$fields['dbname'])) throw new RuntimeException('数据库名称格式不正确。');
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/D',$fields['dbqz'])) throw new RuntimeException('数据表前缀格式不正确。');
        $guard=fopen(__DIR__.'/.installing','c');
        if (!$guard || !flock($guard,LOCK_EX|LOCK_NB)) throw new RuntimeException('安装正在进行，请等待当前安装完成。');
        if (file_exists(__DIR__.'/install.lock')) throw new RuntimeException('站点已经安装，停止重复安装。');
        $db=new PDO('mysql:host='.$fields['host'].';port='.$fields['port'].';dbname='.$fields['dbname'].';charset=utf8mb4',$fields['user'],$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
        if ($db->query('SHOW TABLES')->fetchColumn()!==false) throw new RuntimeException('请选择空数据库。安装程序不会覆盖或升级已有数据。');
        $db->exec("SET time_zone='+08:00'");
        $db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $sql=file_get_contents(__DIR__.'/install.sql');
        if ($sql===false || trim($sql)==='') throw new RuntimeException('数据库安装文件为空或不可读。');
        foreach (explode(';',$sql) as $statement) {
            if (trim($statement)!=='') $db->exec(str_replace('pre_',$fields['dbqz'].'_',$statement));
        }
        $stmt=$db->prepare('INSERT INTO `'.$fields['dbqz'].'_config` (k,v) VALUES (?,?)');
        foreach (['syskey'=>bin2hex(random_bytes(16)),'build'=>date('Y-m-d'),'localurl'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https://' : 'http://').($_SERVER['HTTP_HOST'] ?? 'localhost').'/'] as $key=>$value) $stmt->execute([$key,$value]);
        $config=['host'=>$fields['host'],'port'=>(int)$fields['port'],'user'=>$fields['user'],'pwd'=>$password,'dbname'=>$fields['dbname'],'dbqz'=>$fields['dbqz']];
        $content="<?php\n// 数据库连接配置。\n\$dbconfig = ".var_export($config,true).";\n";
        if (file_put_contents($configFile,$content,LOCK_EX)!==strlen($content)) throw new RuntimeException('数据库已导入，但配置保存失败，请检查根目录写入权限后恢复配置。');
        if (!chmod($configFile,0600)) throw new RuntimeException('配置文件已生成，但无法限制读取权限，请检查文件权限。');
        if (file_put_contents(__DIR__.'/install.lock','安装完成',LOCK_EX)===false) throw new RuntimeException('安装数据和配置已保存，但安装锁写入失败，请修复安装目录权限并建立安装锁。');
        unset($_SESSION['installation_csrf']);
        $success=true;
    } catch (PDOException $e) {
        error_log('数据库安装失败：'.$e->getMessage());
        $error='数据库连接或结构导入失败，请核对数据库资料并查看服务器错误日志。若已生成部分数据，请使用新的空数据库重新安装。';
        http_response_code(400);
    } catch (Throwable $e) {
        $error=$e->getMessage();
        http_response_code(400);
    } finally {
        if (is_resource($guard)) { flock($guard,LOCK_UN);fclose($guard); }
    }
}
function installationEscape($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>易支付安装</title>
<style>body{margin:0;background:#f4f5f7;color:#263238;font:15px/1.6 system-ui,sans-serif}main{max-width:760px;margin:40px auto;padding:24px;background:white;border:1px solid #e3e7e9;border-radius:10px}h1{font-size:24px;margin:0 0 20px}h2{font-size:18px}ul{padding:0;list-style:none}li{padding:9px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;gap:12px}.ok{color:#168348}.bad,.error{color:#b3261e}.error{background:#fce8e6;padding:12px}label{display:block;margin:14px 0}input{display:block;box-sizing:border-box;width:100%;padding:10px;border:1px solid #bcc5cb;border-radius:5px;font:inherit}button,.button{display:inline-block;background:#168348;color:white;border:0;border-radius:5px;padding:10px 18px;font:inherit;text-decoration:none}button:disabled{opacity:.45;cursor:not-allowed}p{color:#52616b}@media(max-width:800px){main{margin:12px;padding:18px}}</style></head>
<body><main><h1>易支付安装</h1>
<?php if($success){ ?>
<h2>安装完成</h2><p>支付系统、管理员应用接口和后台任务的数据表已一次创建完成，无需额外导入数据库。</p><p>请登录管理后台修改初始管理员密码和支付密码，再配置支付通道。</p><a class="button" href="/admin/">进入管理后台</a>
<?php }else{ ?>
<h2>安装环境检测</h2><ul><?php foreach($checks as $label=>$supported){ ?><li><span><?php echo installationEscape($label); ?></span><span class="<?php echo $supported?'ok':'bad'; ?>"><?php echo $supported?'支持':'不支持'; ?></span></li><?php } ?></ul>
<?php if($error!==''){ ?><p class="error" role="alert"><?php echo installationEscape($error); ?></p><?php } ?>
<h2>数据库配置</h2><p>请先创建空数据库。已有表的数据库不能用于此次安装。</p>
<form method="post"><input type="hidden" name="csrf" value="<?php echo installationEscape($_SESSION['installation_csrf']); ?>">
<?php foreach(['host'=>'数据库地址','port'=>'数据库端口','user'=>'数据库用户名','dbname'=>'数据库名称','dbqz'=>'数据表前缀'] as $key=>$label){ ?><label><?php echo $label; ?><input name="<?php echo $key; ?>" value="<?php echo installationEscape($fields[$key]); ?>" required maxlength="255" autocomplete="off"></label><?php } ?>
<label>数据库密码<input type="password" name="pwd" required maxlength="512" autocomplete="new-password"></label>
<button type="submit" <?php echo $ready?'':'disabled'; ?>>安装系统</button></form>
<?php } ?></main></body></html>
