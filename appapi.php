<?php
/** Native administrator API. Does not accept website cookies or merchant API keys. */
declare(strict_types=1);
ini_set('display_errors','0');
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$requestId=bin2hex(random_bytes(8));
header('X-Request-Id: '.$requestId);
require __DIR__.'/includes/lib/AdminApp/Store.php';
require __DIR__.'/includes/lib/AdminApp/Auth.php';
require __DIR__.'/includes/lib/AdminApp/Menu.php';
require __DIR__.'/includes/lib/AdminApp/Management.php';
require __DIR__.'/includes/lib/AdminApp/Settings.php';
require __DIR__.'/includes/lib/AdminApp/Runtime.php';
require __DIR__.'/includes/lib/Notification/HttpTransport.php';
require __DIR__.'/includes/lib/Notification/Delivery.php';
require __DIR__.'/includes/lib/Merchant/ChannelPolicy.php';
require __DIR__.'/includes/lib/AdminApp/Credentials.php';
require __DIR__.'/includes/lib/AdminApp/Operations.php';
require __DIR__.'/includes/lib/AdminApp/Tools.php';
require __DIR__.'/includes/lib/AdminApp/NativeManagement.php';
require __DIR__.'/includes/lib/AdminApp/Api.php';
try {
    $ip=$_SERVER['REMOTE_ADDR'] ?? '';
    $secure=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (int)($_SERVER['SERVER_PORT'] ?? 0)===443;
    if(getenv('EPAY_APP_TRUST_PROXY')==='1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https')$secure=true;
    if(!$secure&&!in_array($ip,['127.0.0.1','::1'],true))throw new \lib\AdminApp\ApiError('管理员接口必须通过 HTTPS 访问',426);
    if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>1048576)throw new \lib\AdminApp\ApiError('请求内容过大',413);
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data=$_GET;
    if($method==='POST'){
        $contentType=strtolower(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]);
        if($contentType!=='application/json')throw new \lib\AdminApp\ApiError('请使用 application/json',415);
        $body=file_get_contents('php://input',false,null,0,1048577);
        if(strlen($body)>1048576)throw new \lib\AdminApp\ApiError('请求内容过大',413);
        $data=json_decode($body,true,32);
        if(!is_array($data)||json_last_error()!==JSON_ERROR_NONE)throw new \lib\AdminApp\ApiError('JSON 请求格式错误');
    }
    require __DIR__.'/config.php';
    if(empty($dbconfig['dbname'])||empty($dbconfig['user']))throw new \lib\AdminApp\ApiError('平台尚未配置数据库',503);
    $pdo=new PDO("mysql:host={$dbconfig['host']};port={$dbconfig['port']};dbname={$dbconfig['dbname']};charset=utf8mb4",$dbconfig['user'],$dbconfig['pwd'],[PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone='+08:00'");
    $store=new \lib\AdminApp\Store($pdo,$dbconfig['dbqz']);
    $authHeader=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $bearer=preg_match('/^Bearer ([a-f0-9]{64})$/D',$authHeader,$m)?$m[1]:'';
    if(($_GET['route'] ?? '')==='v1/notifications/stream'){
        if($method!=='GET')throw new \lib\AdminApp\ApiError('请求方法不允许',405);
        require __DIR__.'/includes/lib/AdminApp/NotificationStream.php';
        $stream=new \lib\AdminApp\NotificationStream($store,$ip);
        $stream->open($bearer,$data);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');header('X-Accel-Buffering: no');
        ini_set('zlib.output_compression','0');set_time_limit(35);
        while(ob_get_level()>0)ob_end_clean();
        $emit=function($type,$event){echo 'event: '.$type."\n".'data: '.json_encode($event,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";flush();};
        try{$stream->run($emit,function(){return !connection_aborted();});}
        catch(Throwable $e){error_log('App receipt stream '.$requestId.': '.get_class($e));if(!connection_aborted())$emit('error',['status'=>503,'message'=>'收款连接暂时中断，正在重连']);}
        exit;
    }
    $api=new \lib\AdminApp\Api($store,$ip);
    $result=$api->handle($method,(string)($_GET['route'] ?? ''),$data,$bearer,(string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    echo json_encode(['code'=>0,'msg'=>'success','data'=>$result,'request_id'=>$requestId],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (\lib\AdminApp\ApiError $e) {
    http_response_code($e->status);echo json_encode(['code'=>-1,'msg'=>$e->getMessage(),'request_id'=>$requestId],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('App API '.$requestId.': '.$e->getMessage());http_response_code(503);echo json_encode(['code'=>-1,'msg'=>'接口暂时不可用，请联系平台管理员并提供请求编号','request_id'=>$requestId],JSON_UNESCAPED_UNICODE);
}
