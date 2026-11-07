<?php
include '../includes/common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if(($islogin2??0)!==1)throw new \lib\AdminApp\ApiError('请先登录商户中心',401);
    $service=new \lib\Merchant\OwnedChannels(\lib\AdminApp\Runtime::legacyStore($DB));
    $service->authorizeRead($uid);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $action=$_GET['act']??'';
    if(!is_string($action))throw new \lib\AdminApp\ApiError('操作无效');
    $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!is_string($token)||empty($_SESSION['merchant_channel_csrf'])||!hash_equals($_SESSION['merchant_channel_csrf'],$token))throw new \lib\AdminApp\ApiError('页面已过期，请刷新后重试',403);
    $writes=['save','credentials-save','status','delete','source','test','plugin-run'];
    if(in_array($action,$writes,true)){
        if($method!=='POST')throw new \lib\AdminApp\ApiError('请使用POST提交',405);
        if(!checkRefererHost())throw new \lib\AdminApp\ApiError('请求来源无效',403);
        if(strtolower(explode(';',$_SERVER['CONTENT_TYPE']??'')[0])!=='application/json')throw new \lib\AdminApp\ApiError('请使用JSON提交',415);
        $raw=file_get_contents('php://input',false,null,0,131073);
        if(strlen($raw)>131072)throw new \lib\AdminApp\ApiError('提交内容过大',413);
        $data=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_is_list($data))throw new \lib\AdminApp\ApiError('提交格式无效');
    }else{
        if($method!=='GET')throw new \lib\AdminApp\ApiError('请求方法无效',405);
        $data=$_GET;unset($data['act']);
    }
    $result=match($action){
        'metadata'=>$service->metadata($uid),
        'list'=>$service->listing($uid),
        'detail'=>$service->detail($uid,$data['id']??''),
        'credentials'=>$service->credentials($uid,$data['id']??''),
        'plugin-schema'=>(new \lib\Merchant\OwnedChannelPlugins(\lib\AdminApp\Runtime::legacyStore($DB)))->schema($uid,$data['id']??'',session_id(),$clientip),
        'plugin-run'=>(new \lib\Merchant\OwnedChannelPlugins(\lib\AdminApp\Runtime::legacyStore($DB)))->run($uid,$data,session_id(),$clientip),
        'save'=>$service->save($uid,$data,$clientip),
        'credentials-save'=>$service->saveCredentials($uid,$data,$clientip),
        'status','delete'=>$service->action($uid,$data['id']??'',$action,$data,$clientip),
        'source'=>$service->saveSource($uid,$data['type']??'',$data['source']??'',$clientip),
        'test'=>$service->test($uid,$data['id']??'',$data,$clientip,$siteurl),
        default=>throw new \lib\AdminApp\ApiError('操作不存在',404)
    };
    echo json_encode(['code'=>0,'data'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(\lib\AdminApp\ApiError $e){http_response_code($e->status);echo json_encode(['code'=>-1,'msg'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(\JsonException $e){http_response_code(400);echo json_encode(['code'=>-1,'msg'=>'JSON格式无效'],JSON_UNESCAPED_UNICODE);}
catch(\Throwable $e){error_log('商户通道管理异常：'.get_class($e).':'.$e->getCode());http_response_code(500);echo json_encode(['code'=>-1,'msg'=>'操作失败，请稍后重试'],JSON_UNESCAPED_UNICODE);}
