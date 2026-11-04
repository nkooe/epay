<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/..');chdir($root);date_default_timezone_set('Asia/Shanghai');
require $root.'/includes/autoloader.php';Autoloader::register();
require_once $root.'/includes/lib/AdminApp/Store.php';
try{
    if(($argv[1]??'')==='--exec'){
        if(!function_exists('posix_geteuid')||posix_geteuid()===0||!posix_setpgid(0,0))throw new RuntimeException('无法建立低权限任务进程组');
        $kind=$argv[2]??'';$scripts=['worker'=>'task-worker.php','job'=>'task-runner.php','plugin'=>'plugin-service.php'];
        if(!isset($scripts[$kind]))throw new InvalidArgumentException('任务进程类型无效');
        $args=['-d','memory_limit='.($kind==='job'?'256M':'192M'),'-d','log_errors=1','-d','display_errors=0','-d','error_log=/dev/stderr',$root.'/bin/'.$scripts[$kind]];
        if($kind!=='worker')$args[]=$argv[3]??'';
        pcntl_exec(PHP_BINARY,$args);throw new RuntimeException('任务进程启动失败');
    }
    if(($argv[1]??'')==='--child'){
        $config=\lib\Task\ProcessRuntime::read($root);
        if(!$config||posix_geteuid()!==$config['uid']||posix_setsid()<0)throw new RuntimeException('无法建立任务会话');
        exit(\lib\Task\ProcessChild::run($config,$argv[2]??'',$argv[3]??''));
    }
    $limit=2;
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--service-limit=([0-9]+)$/D',$arg,$m))throw new InvalidArgumentException('只接受 --service-limit=插件服务并发数');
        $limit=\lib\Task\Installer::serviceLimit($m[1]);
    }
    $config=\lib\Task\ProcessRuntime::prepare($root,$limit);
    exit((new \lib\Task\ProcessManager($config))->run());
}catch(Throwable $e){fwrite(STDERR,\lib\Task\SystemdController::redact($e->getMessage())."\n");exit(1);}
