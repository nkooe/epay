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
$locked=file_exists(__DIR__.'/install.lock');
$requestedStep=$_GET['step'] ?? '1';
if (!is_string($requestedStep) || !in_array($requestedStep,['1','2','3'],true)) {
    header('Location: ?step=1',true,302);exit;
}
$step=$locked ? 3 : (int)$requestedStep;
if (!$locked && $step===3) { header('Location: ?step=1',true,302);exit; }
if (!$locked && empty($_SESSION['installation_csrf'])) $_SESSION['installation_csrf']=bin2hex(random_bytes(24));
$configWritable=file_exists($configFile) ? (is_file($configFile) && is_writable($configFile)) : is_writable($root);
$checks=[
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
$error='';
$fields=['host'=>'127.0.0.1','port'=>'3306','user'=>'','dbname'=>'','dbqz'=>'pay'];
foreach ($fields as $key=>$value) {
    if (isset($_SESSION['installation_fields'][$key]) && is_string($_SESSION['installation_fields'][$key])) $fields[$key]=$_SESSION['installation_fields'][$key];
}
if (!$locked && $step===2 && (!$ready || empty($_SESSION['installation_environment_checked']))) {
    header('Location: ?step=1',true,302);exit;
}
if (!$locked && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $guard=null;
    try {
        if (!$ready) throw new RuntimeException('安装环境检测未通过，请先修复不支持的项目。');
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['installation_csrf'],$_POST['csrf'])) throw new RuntimeException('安装页面已过期，请刷新重试。');
        $action=$_POST['action'] ?? '';
        if ($step===1 && $action==='continue') {
            $_SESSION['installation_environment_checked']=true;
            header('Location: ?step=2',true,303);exit;
        }
        if ($step!==2 || $action!=='install' || empty($_SESSION['installation_environment_checked'])) throw new RuntimeException('请先完成环境检测，再填写数据库配置。');
        foreach ($fields as $key=>$value) {
            if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('请填写完整的数据库信息。');
            $fields[$key]=trim($_POST[$key]);
        }
        $_SESSION['installation_fields']=$fields;
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
        unset($_SESSION['installation_csrf'],$_SESSION['installation_environment_checked'],$_SESSION['installation_fields']);
        $_SESSION['installation_complete']=true;
        header('Location: ?step=3',true,303);exit;
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
function installationIcon($name,$class='') { return '<svg class="icon '.installationEscape($class).'" aria-hidden="true"><use href="#icon-'.installationEscape($name).'"></use></svg>'; }
$steps=[1=>['环境检测','确认服务器环境'],2=>['数据库配置','连接并安装数据库'],3=>['安装完成','进入管理后台']];
$titles=[1=>'检查安装环境',2=>'配置数据库',3=>'安装完成'];
$passed=count(array_filter($checks));
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo $titles[$step]; ?> · 易支付安装</title>
<style>
:root{color-scheme:light;--primary:#078744;--primary-hover:#066e38;--ink:#191919;--muted:#6b6b6b;--border:#e8e8e8;--success:#066e38;--danger:#b42332;--surface:#fff;--background:#ededed}
*{box-sizing:border-box}body{margin:0;background:var(--background);color:var(--ink);font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}a{color:var(--primary);text-decoration:none}button,input{font:inherit}a,button{touch-action:manipulation}button,a.button{cursor:pointer}button:disabled{cursor:not-allowed}button:focus-visible,a:focus-visible,input:focus-visible{outline:3px solid #078744;outline-offset:3px}.icon{width:20px;height:20px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.symbols{position:absolute;width:0;height:0;overflow:hidden}.shell{width:min(1024px,calc(100% - 64px));margin:42px auto 24px}.masthead{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:16px}.brand{display:flex;align-items:center;gap:12px}.brand-mark{width:42px;height:42px;display:grid;place-items:center;background:var(--primary);color:white;border-radius:12px}.brand-mark .icon{width:27px;height:27px}.brand-name{font-size:21px;line-height:1.2;font-weight:700;letter-spacing:1px}.brand-caption{font-size:12px;color:var(--muted);margin-top:4px}.version{color:var(--muted);font-size:13px}.wizard{display:grid;grid-template-columns:236px minmax(0,1fr);border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 8px 36px #173b240a;overflow:hidden;min-height:632px}.sidebar{background:#f7f9f8;border-right:1px solid var(--border);padding:36px 28px;display:flex;flex-direction:column}.sidebar-title{font-weight:650;font-size:18px;margin:0 0 6px}.sidebar-note{margin:0;color:var(--muted);font-size:13px}.steps{list-style:none;margin:36px 0 0;padding:0}.step{position:relative;display:flex;align-items:flex-start;gap:13px;padding-bottom:34px}.step:not(:last-child)::after{content:"";position:absolute;left:16px;top:39px;width:1px;height:calc(100% - 46px);background:#e0e7e2}.step-number{width:34px;height:34px;border:1px solid #d8e0da;background:white;border-radius:50%;display:grid;place-items:center;color:var(--muted);font-weight:600;flex-shrink:0}.step-title{display:block;font-size:14px;font-weight:600;color:var(--muted);margin:2px 0 3px}.step-description{display:block;color:var(--muted);font-size:12px}.step.current .step-number{background:var(--primary);border-color:var(--primary);color:white;box-shadow:0 0 0 5px #0787440c}.step.current .step-title{color:var(--primary)}.step.done .step-number{color:var(--primary);background:#eaf8ef;border-color:#d1e8d9}.sidebar-foot{display:flex;gap:8px;align-items:flex-start;margin-top:auto;padding-top:40px;color:var(--muted);font-size:12px}.sidebar-foot .icon{width:16px;height:16px;margin-top:2px}.content{padding:34px 40px;min-width:0;display:flex;flex-direction:column}.eyebrow{margin:0 0 7px;color:var(--primary);font-size:12px;font-weight:600;letter-spacing:1px}h1{font-size:25px;line-height:1.4;letter-spacing:-.3px;margin:0;font-weight:700}.intro{color:var(--muted);margin:8px 0 24px;font-size:14px}.summary{display:flex;align-items:center;gap:9px;border:1px solid #dceee4;border-radius:9px;background:#f3faf6;color:var(--success);padding:11px 14px;margin-bottom:21px;font-size:13px}.summary.failed{background:#fff5f5;border-color:#f3d9dd;color:var(--danger)}.check-head,.check-row{display:grid;grid-template-columns:minmax(0,1fr) 66px;gap:12px;align-items:center}.check-head{color:var(--muted);font-size:12px;background:#f3f5f4;padding:10px 14px;border-radius:7px}.check-head span:last-child{text-align:right}.check-row{padding:11px 14px;border-bottom:1px solid #edf0ee;font-size:13px}.check-row:last-child{border-bottom:0}.check-label{overflow-wrap:anywhere}.ok,.bad{display:inline-flex;align-items:center;justify-content:flex-end;gap:5px;white-space:nowrap;font-size:12px;font-weight:500}.ok{color:var(--success)}.bad{color:var(--danger)}.ok .icon,.bad .icon{width:15px;height:15px}.actions{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:auto;padding-top:25px}.actions-note{color:var(--muted);font-size:12px;max-width:260px;margin:0}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:11px 18px;border:1px solid transparent;border-radius:8px;font-weight:600;line-height:1.4;font-size:14px;white-space:nowrap;transition:background .18s,border-color .18s}.button.primary{color:white;background:var(--primary)}.button.primary:hover{background:var(--primary-hover)}.button.secondary{background:white;color:#405047;border-color:#d8e0da}.button.secondary:hover{background:#f3f6f4;border-color:#b5c9bc}.button:disabled{color:#758078;background:#edf1ee;border-color:#e8e8e8}.button .icon{width:17px;height:17px}.env-form{display:flex;flex-direction:column;flex:1}.error{display:flex;gap:9px;align-items:flex-start;padding:12px 14px;color:var(--danger);background:#fff3f3;border:1px solid #f1d3d7;border-radius:8px;margin:0 0 20px;font-size:13px;overflow-wrap:anywhere}.error .icon{margin-top:1px;width:18px;height:18px}.database-form{display:flex;flex-direction:column;flex:1}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:19px 20px}.field{min-width:0}.address-row{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1fr) 120px;gap:20px}label{display:block;font-size:13px;font-weight:600;margin-bottom:7px}input{display:block;width:100%;height:44px;border:1px solid #d8e0da;border-radius:7px;padding:10px 12px;background:#fff;color:var(--ink);font-size:15px;transition:border-color .18s,box-shadow .18s}input:hover{border-color:#a8bbae}input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px #07874414}input::placeholder{color:#7b877f}.field-help{font-size:12px;color:var(--muted);margin:6px 0 0}.password-wrap{position:relative}.password-wrap input{padding-right:60px}.password-toggle{position:absolute;right:3px;top:0;height:44px;min-width:50px;background:transparent;border:0;color:#536159;font-size:12px;border-radius:6px}.password-toggle[hidden]{display:none}.notice{display:flex;gap:9px;align-items:flex-start;margin-top:24px;background:#f5f7f6;border:1px solid var(--border);border-radius:8px;padding:12px 14px;color:var(--muted);font-size:12px}.notice .icon{width:17px;height:17px;margin-top:1px}.notice p{margin:0}.database-form .actions{padding-top:28px}.success-content{align-items:center;justify-content:center;text-align:center}.success-mark{width:76px;height:76px;background:#ecf8f1;color:var(--success);border:1px solid #d8efe2;border-radius:50%;display:grid;place-items:center;margin-bottom:24px}.success-mark .icon{width:36px;height:36px;stroke-width:2}.success-content .intro{max-width:400px;margin-bottom:28px}.completed-list{list-style:none;text-align:left;margin:0 0 28px;padding:16px 22px;background:#f7f9f8;border:1px solid var(--border);border-radius:10px;width:100%;max-width:380px}.completed-list li{display:flex;align-items:center;gap:10px;padding:7px 0;color:#405047;font-size:13px}.completed-list .icon{width:17px;height:17px;color:var(--success)}.success-note{margin:18px 0 0;color:var(--muted);font-size:12px;max-width:380px}.footer{text-align:center;color:var(--muted);font-size:12px;margin:22px 0 0}.skip{position:absolute;top:-100px;left:16px;background:white;padding:12px;z-index:10}.skip:focus{top:12px}.loading .icon{display:none}.loading::before{content:"";width:14px;height:14px;border:2px solid #ffffff60;border-top-color:white;border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
@media(min-width:1400px){.shell{margin-top:64px}}@media(max-width:800px){.shell{width:calc(100% - 32px);margin-top:24px}.wizard{grid-template-columns:190px minmax(0,1fr)}.sidebar{padding:30px 22px}.content{padding:30px 26px}.actions-note{max-width:180px}.field-grid{gap:17px 14px}.address-row{gap:14px;grid-template-columns:minmax(0,1fr) 90px}}@media(max-width:600px){body{font-size:14px}.shell{width:calc(100% - 24px);margin:20px auto}.masthead{margin:0 4px 18px}.brand-mark{width:36px;height:36px;border-radius:10px}.brand-name{font-size:19px}.brand-caption{font-size:11px}.version{font-size:12px}.wizard{display:flex;flex-direction:column;border-radius:14px;min-height:0}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:20px 16px}.sidebar-title,.sidebar-note,.sidebar-foot,.step-description{display:none}.steps{margin:0;display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.step{display:flex;align-items:center;flex-direction:column;gap:8px;padding:0;text-align:center}.step:not(:last-child)::after{top:15px;left:calc(50% + 24px);height:1px;width:calc(100% - 36px)}.step-number{width:30px;height:30px;font-size:12px}.step-title{font-size:12px;margin:0}.content{padding:26px 20px}.eyebrow{font-size:11px}h1{font-size:23px}.intro{font-size:13px;margin-bottom:20px}.check-row{font-size:12px;padding:12px 10px}.check-head{padding:9px 10px}.check-label{max-width:100%}.summary{font-size:12px;padding:10px 12px;margin-bottom:16px}.actions{padding-top:22px;gap:12px}.actions-note{max-width:140px;font-size:11px}.button{padding:11px 14px;font-size:13px}.field-grid{grid-template-columns:1fr;gap:17px}.address-row{grid-column:auto;grid-template-columns:minmax(0,1fr) 82px;gap:12px}input{font-size:16px}.notice{margin-top:20px}.success-content{padding-top:42px;padding-bottom:36px}.success-mark{width:68px;height:68px}.success-content .intro{margin-bottom:22px}.footer{font-size:11px;margin-top:18px}}@media(prefers-reduced-motion:reduce){*,*::before{animation:none!important;transition:none!important}}
.process-guide{text-align:left;max-width:460px;border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:24px}.process-guide p{font-size:12px;color:var(--muted);margin-bottom:0}.process-guide code{overflow-wrap:anywhere;color:var(--success)}
</style></head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
<symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
<symbol id="icon-back" viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></symbol>
<symbol id="icon-shield" viewBox="0 0 24 24"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-6"/></symbol>
<symbol id="icon-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-9h.01"/></symbol>
<symbol id="icon-close" viewBox="0 0 24 24"><path d="m7 7 10 10M7 17 17 7"/></symbol>
<symbol id="icon-brand" viewBox="0 0 28 28"><path d="M7 7h14M7 14h10M7 21h14" stroke-width="2.5"/><path d="m17 10 4 4-4 4" stroke-width="2.5"/></symbol>
</defs></svg>
<a class="skip" href="#main">跳转到当前安装步骤</a>
<div class="shell"><header class="masthead"><div class="brand"><span class="brand-mark"><?php echo installationIcon('brand'); ?></span><div><div class="brand-name">易支付</div><div class="brand-caption">支付管理系统</div></div></div><span class="version">安装向导</span></header>
<div class="wizard"><nav class="sidebar" aria-label="安装步骤"><p class="sidebar-title">欢迎使用易支付</p><p class="sidebar-note">只需三步，完成系统安装。</p><ol class="steps">
<?php foreach($steps as $number=>$item){ $done=$locked || $number<$step; ?>
<li class="step <?php echo $number===$step?'current':($done?'done':''); ?>" <?php if($number===$step)echo 'aria-current="step"'; ?>><span class="step-number"><?php echo $done?installationIcon('check'):$number; ?></span><span><span class="step-title"><?php echo $item[0]; ?></span><span class="step-description"><?php echo $item[1]; ?></span></span></li>
<?php } ?></ol><div class="sidebar-foot"><?php echo installationIcon('shield'); ?><span>安装完成后，安装入口将自动锁定。</span></div></nav>
<main id="main" class="content <?php if($step===3)echo 'success-content'; ?>" tabindex="-1">
<?php if($step===3){ ?>
<div class="success-mark"><?php echo installationIcon('check'); ?></div><h1>安装完成</h1>
<p class="intro"><?php echo !empty($_SESSION['installation_complete'])?'易支付已准备就绪，现在可以进入后台，开始配置你的支付系统。':'你已经成功安装，安装入口已锁定。可以直接进入管理后台。'; ?></p>
<ul class="completed-list"><li><?php echo installationIcon('check'); ?>支付系统数据库已创建</li><li><?php echo installationIcon('check'); ?>易管理接口与任务数据表已就绪</li><li><?php echo installationIcon('check'); ?>站点配置已保存，安装入口已锁定</li></ul>
<div class="process-guide"><strong>启动后台任务</strong><p>在进程管理器添加本站根目录，启动命令填写 <code>对应版本的命令行PHP路径 bin/task-process.php</code>，运行用户选择 root，进程数设为 1，开启自动重启和开机自启，停止等待时间设为 940 秒。首次启动会自动完成初始化。</p></div>
<a class="button primary" href="/admin/">进入管理后台<?php echo installationIcon('arrow'); ?></a><p class="success-note">安装入口已锁定。首次登录后，请修改管理员密码和支付密码，再配置支付通道。</p>
<?php }else{ ?>
<p class="eyebrow">第 <?php echo $step; ?> 步 / 共 3 步</p><h1><?php echo $titles[$step]; ?></h1><p class="intro"><?php echo $step===1?'先确认服务器满足运行要求，通过后即可继续配置数据库。':'填写已创建的空数据库信息，确认后开始安装。'; ?></p>
<?php if($error!==''){ ?><div id="installation-error" class="error" role="alert" tabindex="-1"><?php echo installationIcon('info'); ?><span><?php echo installationEscape($error); ?></span></div><?php } ?>
<?php if($step===1){ ?>
<div class="summary <?php if(!$ready)echo 'failed'; ?>"><?php echo installationIcon($ready?'check':'info'); ?><span><?php echo $ready?'全部检查通过，可以继续安装。':'有 '.(count($checks)-$passed).' 项需要处理，请修复后重新检测。'; ?></span></div>
<div class="check-head"><span>检测项目</span><span>检测结果</span></div><div class="checks" aria-label="安装环境检测结果">
<?php foreach($checks as $label=>$supported){ ?><div class="check-row"><span class="check-label"><?php echo installationEscape($label); ?></span><span class="<?php echo $supported?'ok':'bad'; ?>"><?php echo installationIcon($supported?'check':'close'); ?><?php echo $supported?'通过':'未通过'; ?></span></div><?php } ?></div>
<form class="env-form" method="post" action="?step=1"><input type="hidden" name="csrf" value="<?php echo installationEscape($_SESSION['installation_csrf']); ?>"><input type="hidden" name="action" value="continue"><div class="actions"><p class="actions-note"><?php echo $ready?'环境已就绪，下一步连接数据库。':'修复后可刷新本页重新检测。'; ?></p><button class="button primary" type="submit" <?php echo $ready?'':'disabled'; ?>>下一步<?php echo installationIcon('arrow'); ?></button></div></form>
<?php }else{ ?>
<form class="database-form" method="post" action="?step=2" id="database-form"><input type="hidden" name="csrf" value="<?php echo installationEscape($_SESSION['installation_csrf']); ?>"><input type="hidden" name="action" value="install">
<div class="field-grid"><div class="address-row"><div class="field"><label for="host">数据库地址</label><input id="host" name="host" value="<?php echo installationEscape($fields['host']); ?>" required maxlength="255" autocomplete="off" spellcheck="false"></div><div class="field"><label for="port">端口</label><input id="port" name="port" value="<?php echo installationEscape($fields['port']); ?>" required pattern="[0-9]{1,5}" maxlength="5" inputmode="numeric" autocomplete="off"></div></div>
<div class="field"><label for="dbname">数据库名称</label><input id="dbname" name="dbname" value="<?php echo installationEscape($fields['dbname']); ?>" placeholder="填写空数据库名称" required maxlength="64" autocomplete="off" spellcheck="false"></div>
<div class="field"><label for="user">数据库用户名</label><input id="user" name="user" value="<?php echo installationEscape($fields['user']); ?>" placeholder="填写数据库用户名" required maxlength="80" autocomplete="off" spellcheck="false"></div>
<div class="field"><label for="pwd">数据库密码</label><div class="password-wrap"><input id="pwd" type="password" name="pwd" required maxlength="512" autocomplete="new-password"><button class="password-toggle" type="button" id="password-toggle" aria-controls="pwd" aria-pressed="false" hidden>显示</button></div></div>
<div class="field"><label for="dbqz">数据表前缀</label><input id="dbqz" name="dbqz" value="<?php echo installationEscape($fields['dbqz']); ?>" required maxlength="32" autocomplete="off" spellcheck="false" aria-describedby="prefix-help"><p id="prefix-help" class="field-help">保留默认值即可。</p></div></div>
<div class="notice"><?php echo installationIcon('info'); ?><p>请先在服务器面板创建空数据库。已有数据表的数据库不能用于此次安装。</p></div>
<div class="actions"><a class="button secondary" href="?step=1"><?php echo installationIcon('back'); ?>上一步</a><button class="button primary" type="submit" id="install-submit"><span>开始安装</span><?php echo installationIcon('arrow'); ?></button></div></form>
<?php }} ?></main></div><footer class="footer">易支付管理系统 · 搭配易管理使用</footer></div>
<script>
const passwordToggle=document.getElementById('password-toggle');
if(passwordToggle){passwordToggle.hidden=false;passwordToggle.addEventListener('click',function(){const input=document.getElementById('pwd');const show=input.type==='password';input.type=show?'text':'password';this.textContent=show?'隐藏':'显示';this.setAttribute('aria-pressed',String(show));});}
document.querySelectorAll('input[required]').forEach(function(input){input.addEventListener('invalid',function(){this.setCustomValidity(this.validity.valueMissing?'请填写'+document.querySelector('label[for="'+this.id+'"]').textContent:'请检查此项填写格式。');});input.addEventListener('input',function(){this.setCustomValidity('');});});
const databaseForm=document.getElementById('database-form');
if(databaseForm){databaseForm.addEventListener('submit',function(){const button=document.getElementById('install-submit');button.disabled=true;button.classList.add('loading');button.setAttribute('aria-busy','true');button.querySelector('span').textContent='正在安装…';});window.addEventListener('pageshow',function(){const button=document.getElementById('install-submit');button.disabled=false;button.classList.remove('loading');button.removeAttribute('aria-busy');button.querySelector('span').textContent='开始安装';});}
const installationError=document.getElementById('installation-error');if(installationError)installationError.focus();
</script></body></html>
