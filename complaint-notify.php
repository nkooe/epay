<?php
$nosession=true;
require __DIR__.'/includes/common.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try{
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');throw new RuntimeException('Invalid method');}
    $body=file_get_contents('php://input',false,null,0,1048577);
    if(!$body||strlen($body)>1048576)throw new RuntimeException('Invalid body');
    $headers=[];foreach(['timestamp','nonce','serial','signature'] as $key)$headers[$key]=$_SERVER['HTTP_WECHATPAY_'.strtoupper($key)]??'';
    $service=new \lib\Complaint\Service(\lib\AdminApp\Runtime::legacyStore($DB),$conf);
    $service->notification($_GET['account_id']??null,$headers,$body);
    echo '{"code":"SUCCESS","message":"成功"}';
}catch(Throwable $e){
    if(http_response_code()===200)http_response_code(503);
    error_log('Complaint notification rejected: '.get_class($e));echo '{"code":"FAIL","message":"通知未处理完成"}';
}
