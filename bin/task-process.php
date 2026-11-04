<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/..');chdir($root);date_default_timezone_set('Asia/Shanghai');
require $root.'/includes/autoloader.php';Autoloader::register();
require_once $root.'/includes/lib/AdminApp/Store.php';
try{
    \lib\Task\ProcessRuntime::enter($root);
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
    if(($argv[1]??'')==='--inspect'){\lib\Task\ProcessRuntime::inspect($root);echo "进程管理器环境检查通过\n";exit(0);}
    if(in_array($argv[1]??'',['--configure','--check'],true)){
        $limit=2;
        foreach(array_slice($argv,2) as $arg){
            if($argv[1]!=='--configure'||!preg_match('/^--service-limit=([0-9]+)$/D',$arg,$m))throw new InvalidArgumentException('配置命令仅接受 --service-limit，验收命令不接受其他参数');
            $limit=\lib\Task\Definitions::integer($m[1],1,32,'插件服务并发上限');
        }
        if($argv[1]==='--configure'){
            $config=\lib\Task\ProcessRuntime::prepare($root,$limit);
            $old=\lib\Task\ProcessRuntime::read($root);
            if($old&&($old['service_limit']!==$config['service_limit']||$old['php']!==$config['php'])&&(\lib\Task\ProcessRuntime::request($old,'status')['pid']??0)>0)throw new RuntimeException('主进程正在运行，请先在进程管理器中停止后修改配置');
            \lib\Task\ProcessRuntime::write($config['runtime'].'/config.json',$config);
            echo "进程管理器配置已保存。在进程管理器使用 root 运行 php bin/task-process.php，进程数 1，启用自启和自动重启，停止等待 940 秒。\n";
        }else{
            $db=\lib\Task\CliRuntime::connect($root);
            echo json_encode(\lib\Task\Health::verify($db),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        }
        exit(0);
    }
    $limit=2;
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--service-limit=([0-9]+)$/D',$arg,$m))throw new InvalidArgumentException('只接受 --service-limit=插件服务并发数');
        $limit=\lib\Task\Definitions::integer($m[1],1,32,'插件服务并发上限');
    }
    $config=\lib\Task\ProcessRuntime::prepare($root,$limit);
    exit((new \lib\Task\ProcessManager($config))->run());
}catch(Throwable $e){fwrite(STDERR,\lib\Task\Controller::redact($e->getMessage())."\n");exit(1);}
