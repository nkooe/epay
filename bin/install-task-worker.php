<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/..');
require $root.'/includes/autoloader.php';
Autoloader::register();
require_once $root.'/includes/lib/AdminApp/Store.php';
try{
    $options=[];
    foreach(array_slice($argv,1) as $arg){
        if(in_array($arg,['--print','--install'],true))continue;
        if(!preg_match('/^--(user|php|service-limit)=(.+)$/D',$arg,$m))throw new InvalidArgumentException('只接受 --print、--install、--user=、--php=、--service-limit=');
        $options[$m[1]]=$m[2];
    }
    $config=\lib\Task\Installer::detect($root,$options);
    echo json_encode(array_intersect_key($config,array_flip(['root','php','user','service','helper','concurrency','service_limit','memory_max_mb'])),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    if(in_array('--install',$argv,true)){
        $result=\lib\Task\Installer::install($config);
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        echo "安装完成；尚未启动或启用开机自启。请先停用站点旧 cron，然后在后台启动。\n";
    }else foreach(\lib\Task\SystemdUnits::files($config) as $name=>$text)echo "\n# ".$name."\n".$text;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
