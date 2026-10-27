<?php
require __DIR__.'/../includes/common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function complaintResponse($status,array $body){http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
try{
    if($islogin!=1)complaintResponse(401,['code'=>-1,'msg'=>'请先登录管理员账号']);
    $method=$_SERVER['REQUEST_METHOD'];$act=$_GET['act']??'';
    if(!is_string($act))throw new \lib\AdminApp\ApiError('操作不正确');
    $service=new \lib\Complaint\Service(\lib\AdminApp\Runtime::legacyStore($DB),$conf);
    if($method==='GET'){
        session_write_close();
        if($act==='schema')$data=$service->schema();
        elseif($act==='list')$data=$service->listing($_GET);
        elseif($act==='detail')$data=$service->detail($_GET['id']??null);
        elseif($act==='image'){
            $image=$service->image($_GET['id']??null,$_GET['media']??null);
            header('Content-Type: '.$image['mime']);header('Content-Disposition: inline');header("Content-Security-Policy: default-src 'none'");echo $image['bytes'];exit;
        }else throw new \lib\AdminApp\ApiError('接口不存在',404);
    }elseif($method==='POST'){
        $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
        if(!isset($_SESSION['complaint_csrf'])||!is_string($csrf)||!hash_equals($_SESSION['complaint_csrf'],$csrf))complaintResponse(403,['code'=>-1,'msg'=>'页面凭证已失效，请刷新页面']);
        if(!checkRefererHost())complaintResponse(403,['code'=>-1,'msg'=>'请求来源不正确']);
        if($act==='upload'){
            session_write_close();$data=$service->upload($_POST['id']??null,$_FILES['file']??[]);
        }else{
            if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')complaintResponse(415,['code'=>-1,'msg'=>'请使用 JSON 提交']);
            $raw=file_get_contents('php://input',false,null,0,65537);
            if(strlen($raw)>65536)complaintResponse(413,['code'=>-1,'msg'=>'请求内容过长']);
            $input=json_decode($raw,true,20,JSON_THROW_ON_ERROR);
            if(!is_array($input)||substr(ltrim($raw),0,1)!=='{')throw new \lib\AdminApp\ApiError('请求必须为 JSON 对象');
            session_write_close();
            if($act==='save-account')$data=$service->saveAccount($input,$clientip);
            elseif($act==='check-connection')$data=$service->checkConnection($input);
            elseif($act==='queue-sync')$data=$service->requestSync($input['id']??null);
            elseif($act==='sync')$data=$service->sync($input);
            elseif($act==='refresh')$data=$service->refresh($input['id']??null);
            elseif($act==='action')$data=$service->action($input,$clientip);
            elseif($act==='resolve')$data=$service->resolve($input,$clientip);
            elseif($act==='subscribe')$data=$service->subscribe($input['id']??null);
            else throw new \lib\AdminApp\ApiError('接口不存在',404);
        }
    }else{header('Allow: GET, POST');complaintResponse(405,['code'=>-1,'msg'=>'请求方式不正确']);}
    complaintResponse(200,['code'=>0,'data'=>$data]);
}catch(\lib\AdminApp\ApiError $e){complaintResponse($e->status,['code'=>-1,'msg'=>$e->getMessage()]);}
catch(\lib\Complaint\Rejected $e){complaintResponse(502,['code'=>-1,'msg'=>mb_substr($e->getMessage(),0,400)]);}
catch(InvalidArgumentException|JsonException $e){complaintResponse(400,['code'=>-1,'msg'=>$e instanceof JsonException?'JSON 格式错误':$e->getMessage()]);}
catch(Throwable $e){error_log('Complaint API: '.get_class($e).': '.$e->getMessage());complaintResponse(503,['code'=>-1,'msg'=>'投诉操作未完成，请检查接口权限、凭证及服务器日志']);}
