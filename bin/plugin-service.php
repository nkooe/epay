<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/..');chdir($root);date_default_timezone_set('Asia/Shanghai');
require $root.'/includes/autoloader.php';Autoloader::register();require $root.'/includes/lib/AdminApp/Store.php';
$context=null;$row=null;$stopMethod=null;$class=null;$channels=[];$exit=1;$started=false;$stopSeconds=25;
try{
    if(count($argv)!==2||!preg_match('/^([a-f0-9]{32})g([1-9][0-9]{0,8})$/D',$argv[1],$match))throw new \lib\Task\ServiceFault('插件实例编号无效');
    if(!function_exists('posix_geteuid')||posix_geteuid()===0)throw new \lib\Task\ServiceFault('禁止以 root 执行插件');
    $db=\lib\Task\CliRuntime::connect($root);$registry=new \lib\Task\ServiceRegistry($db);$row=$registry->get($match[1]);
    if(!(int)$row['desired']||(int)$row['generation']!==(int)$match[2])throw new \lib\Task\ServiceFault('实例代次已过期');
    $item=$registry->resolve($row);$spec=$item['spec'];$stopSeconds=$spec['stop_seconds'];$row['token']=bin2hex(random_bytes(16));
    $context=new \lib\Task\ServiceContext($db,$row,$item);
    $claimed=$db->query("UPDATE pre_plugin_service SET token=?,observed='waiting',ready_at=NULL,heartbeat_at=NULL,started_at=? WHERE id=? AND revision=? AND generation=? AND desired=1",
        [$row['token'],date('Y-m-d H:i:s'),$row['id'],$row['revision'],$row['generation']])->rowCount();
    if($claimed!==1)throw new \lib\Task\ServiceFault('服务配置已变化');
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,static function()use($context){$context->requestStop();});
    pcntl_signal(SIGINT,static function()use($context){$context->requestStop();});
    pcntl_signal(SIGALRM,static function(){throw new \lib\Task\ServiceFault('插件就绪、心跳或停止期限已超时');});
    pcntl_alarm($spec['ready_seconds']);
    \lib\Task\CliRuntime::business($db);
    if($spec['scope']==='plugin'){
        foreach($item['targets'] as $target){$value=$target['subchannel_id']?\lib\Channel::getSub($target['subchannel_id']):\lib\Channel::get($target['channel_id']);if(!$value)throw new \lib\Task\ServiceFault('通道配置无法加载');$channels[]=$value;}
    }else foreach($item['channels'] as $channel){$value=\lib\Channel::get($channel['id']);if(!$value)throw new \lib\Task\ServiceFault('通道配置无法加载');$channels[]=$value;}
    if(!$channels)throw new \lib\Task\ServiceFault('没有可监控的启用通道');
    $class=\lib\AdminApp\Runtime::prepare($channels[0],$spec['run']);
    foreach(['on_start','run','on_stop'] as $method){
        if(!method_exists($class,$spec[$method]))throw new \lib\Task\ServiceFault('插件生命周期入口不存在');
        $reflection=new ReflectionMethod($class,$spec[$method]);
        if(!$reflection->isPublic()||!$reflection->isStatic()||$reflection->getNumberOfRequiredParameters()>2||(!$reflection->isVariadic()&&$reflection->getNumberOfParameters()!==2))throw new \lib\Task\ServiceFault('插件入口必须为 public static，接收 channels 和 ServiceContext');
        foreach($reflection->getParameters() as $parameter)if($parameter->isPassedByReference())throw new \lib\Task\ServiceFault('插件入口不能使用引用参数');
    }
    if(!defined('IN_PLUGIN'))define('IN_PLUGIN',true);
    if(!defined('PAY_ROOT'))define('PAY_ROOT',\lib\Task\Manifest::directory($root.'/plugins',$row['plugin']).'/');
    $GLOBALS['channel']=$channels[0];$stopMethod=$spec['on_stop'];$started=true;
    $class::{$spec['on_start']}($channels,$context);
    $class::{$spec['run']}($channels,$context);
    if(!$context->stopRequested())throw new RuntimeException('插件持续运行入口意外返回');
    $exit=0;
}catch(Throwable $e){
    $exit=$e instanceof \lib\Task\ServiceStopped?0:($e instanceof \lib\Task\ServiceFault?78:1);
    if($exit!==0&&$row&&isset($db)){
        try{$db->query("UPDATE pre_plugin_service SET observed=?,last_error=?,desired=? WHERE id=? AND token=?",
            [$exit===78?'attention':'backoff',mb_substr(\lib\Task\SystemdController::redact($e->getMessage()),0,1000),$exit===78?0:$row['desired'],$row['id'],$row['token']]);}catch(Throwable $ignored){}
    }
    if($exit!==0)error_log('[epay-plugin] '.\lib\Task\SystemdController::redact($e->getMessage()));
}finally{
    if($context){
        pcntl_alarm($stopSeconds);
        try{if($started&&$stopMethod)$class::$stopMethod($channels,$context);}
        catch(Throwable $e){
            $exit=78;error_log('[epay-plugin-stop] '.\lib\Task\SystemdController::redact($e->getMessage()));
            try{$db->query("UPDATE pre_plugin_service SET desired=0,last_error=? WHERE id=? AND token=?",[mb_substr(\lib\Task\SystemdController::redact($e->getMessage()),0,1000),$row['id'],$row['token']]);}catch(Throwable $ignored){}
        }
        if($row&&isset($db)){try{$db->query("UPDATE pre_plugin_service SET token=NULL,observed=CASE WHEN observed='attention' THEN observed ELSE ? END WHERE id=? AND token=?",[$exit===0?'stopped':($exit===78?'attention':'backoff'),$row['id'],$row['token']]);}catch(Throwable $ignored){}}
        $context->release();pcntl_alarm(0);
    }
}
exit($exit);
