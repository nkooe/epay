<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/..');chdir($root);date_default_timezone_set('Asia/Shanghai');
require $root.'/includes/autoloader.php';Autoloader::register();
require_once $root.'/includes/lib/AdminApp/Store.php';
$execution=null;$run=null;
try {
    if(count($argv)!==2)throw new InvalidArgumentException('只接受运行编号');
    if(!function_exists('posix_geteuid')||posix_geteuid()===0)throw new RuntimeException('任务必须以站点低权限用户执行');
    $db=\lib\Task\CliRuntime::connect($root);
    $execution=new \lib\Task\Execution($db);$run=$execution->claim($argv[1]);
    $registry=new \lib\Task\PluginRegistry($db);
    $task=$db->row('SELECT * FROM pre_task WHERE id=?',[$run['task_id']]);
    if(!$task||(int)$task['revision']!==(int)$run['revision'])throw new \lib\AdminApp\ApiError('配置已更新，本次不执行业务',409);
    if($task['action']==='plugin'&&!hash_equals($run['plugin_digest'],$registry->fingerprint($task['channel_id'],$task['plugin'])))throw new RuntimeException('插件文件或通道配置已变化，请核查后重新接入');
    \lib\Task\CliRuntime::business($db);
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,static function(){throw new RuntimeException('任务收到停止信号，请核查外部业务结果');});
    pcntl_signal(SIGALRM,static function(){throw new RuntimeException('任务执行超时，请核查外部业务结果');});
    pcntl_alarm((int)$run['timeout_seconds']);
    $tasks=new \lib\Task\TaskService($db,[\lib\Task\CronHandlers::class,'execute'],$registry);
    $result=$tasks->run($run['task_id'],$run['origin'],$run['revision'],$run['id']);pcntl_alarm(0);
    $state=$result['status']==='success'?'success':($result['status']==='not_due'?'rejected':'failed');
    if($execution->finish($run['id'],$state,$result['error']??$result['summary']??'尚未到期')!==1)throw new RuntimeException('运行结果写入失败');
    exit($state==='success'?0:($state==='rejected'?75:1));
}catch(Throwable $e){
    $conflict=$e instanceof \lib\AdminApp\ApiError&&$e->status===409;
    if($run&&$execution){try{$execution->finish($run['id'],$conflict?'rejected':'failed',$e->getMessage());}catch(Throwable $ignored){}}
    error_log('[epay-task-runner] '.\lib\Task\SystemdController::redact($e->getMessage()));exit($conflict?75:1);
}
