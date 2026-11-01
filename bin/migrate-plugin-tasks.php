<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/..');require $root.'/includes/autoloader.php';Autoloader::register();
try{
    if(count($argv)!==3||!in_array($argv[1],['--check','--write'],true))throw new InvalidArgumentException('用法：php bin/migrate-plugin-tasks.php --check|--write 插件名');
    $directory=\lib\Task\Manifest::directory($root.'/plugins',$argv[2]);$file=$directory.'/tasks.json';
    if(!is_file($file))throw new RuntimeException('此插件没有 tasks.json');
    $raw=file_get_contents($file);$old=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(substr(ltrim($raw),0,1)!=='['||!is_array($old)||!array_is_list($old))throw new RuntimeException('只迁移旧任务数组；新版声明请直接校验');
    foreach($old as &$job){
        \lib\Task\Manifest::fields($job,['key','name','method','interval_seconds','timeout_seconds']);
        // The migration never enables automatic execution or claims gateway idempotency.
        $job['idempotency']='manual_review';
    }
    unset($job);
    $new=json_encode(['schema_version'=>1,'tasks'=>$old,'services'=>[]],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    \lib\Task\Manifest::parse($new);
    if($argv[1]==='--check'){echo $new;exit;}
    $db=\lib\Task\CliRuntime::connect($root);
    if($db->value("SELECT COUNT(*) FROM pre_task WHERE plugin=? AND (enabled=1 OR lock_token IS NOT NULL)",[$argv[2]])||
       $db->value("SELECT COUNT(*) FROM pre_plugin_service WHERE plugin=? AND (desired=1 OR token IS NOT NULL)",[$argv[2]]))throw new RuntimeException('请先停用并排空相关插件后台能力');
    $backup=$file.'.before-v1-'.date('YmdHis').'.bak';
    if(!copy($file,$backup))throw new RuntimeException('无法保存迁移备份');
    $tmp=$file.'.migrate-'.bin2hex(random_bytes(4));
    if(file_put_contents($tmp,$new,LOCK_EX)===false)throw new RuntimeException('无法写入迁移文件');
    if(!rename($tmp,$file))throw new RuntimeException('无法替换声明');
    \lib\Task\Manifest::read($directory);echo "迁移完成；能力保持停用，请验证后启用。\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
