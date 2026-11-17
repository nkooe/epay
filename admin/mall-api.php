<?php
require __DIR__.'/../includes/common.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mallAdminResponse($status,array $result){http_response_code($status);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
try{
    if($islogin!=1)mallAdminResponse(401,['code'=>-1,'msg'=>'请先登录管理员账号']);
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');mallAdminResponse(405,['code'=>-1,'msg'=>'仅允许 POST']);}
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!is_string($csrf)||!isset($_SESSION['mall_csrf'])||!hash_equals($_SESSION['mall_csrf'],$csrf)||!checkRefererHost())
        mallAdminResponse(403,['code'=>-1,'msg'=>'页面凭证已失效，请刷新后重试']);
    if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')throw new RuntimeException('请求必须为 JSON');
    $raw=file_get_contents('php://input',false,null,0,16385);
    if(strlen($raw)>16384)throw new RuntimeException('请求过大');
    $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||substr(ltrim($raw),0,1)!=='{')throw new RuntimeException('请求必须为 JSON 对象');
    session_write_close();
    $act=$_GET['act']??'';
    if($act==='save'){
        if(array_diff(array_keys($input),['config','revision'])||!is_array($input['config']??null)||!is_int($input['revision']??null))throw new RuntimeException('保存参数无效');
        $data=\lib\BridgeSettings::save($input['config'],$input['revision']);
    }elseif($act==='secret'){
        if($input)throw new RuntimeException('查看密钥不接受额外参数');
        $data=['secret'=>\lib\BridgeSettings::read()['secret']];
    }elseif($act==='test'){
        if($input)throw new RuntimeException('连接检测不接受额外参数，请先保存配置');
        $config=\lib\BridgeSettings::read();
        $data=\lib\bridge\Protocol::post($config['mall_url'],'/api/bridge/cashier',['action'=>'quote','token'=>'','type_id'=>0],$config,true);
        $data=['message'=>'双向签名验证通过，未创建支付订单'];
    }else throw new RuntimeException('未知操作');
    mallAdminResponse(200,['code'=>0,'data'=>$data]);
}catch(JsonException $e){mallAdminResponse(400,['code'=>-1,'msg'=>'JSON 格式错误']);}
catch(RuntimeException $e){mallAdminResponse(400,['code'=>-1,'msg'=>$e->getMessage()]);}
catch(Throwable $e){error_log('商城配置接口失败：'.get_class($e));mallAdminResponse(500,['code'=>-1,'msg'=>'商城配置处理失败，请检查服务器日志']);}
