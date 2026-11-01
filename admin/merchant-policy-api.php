<?php
require __DIR__.'/../includes/common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function merchantPolicyResponse($status, array $body) { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
try {
    if ($islogin != 1) merchantPolicyResponse(401, ['code'=>-1,'msg'=>'请先登录管理员账号']);
    $method = $_SERVER['REQUEST_METHOD'];
    $service = new \lib\Merchant\ChannelPolicy(\lib\AdminApp\Runtime::legacyStore($DB));
    if ($method === 'GET') {
        $uid = $_GET['uid'] ?? '';
        if (!is_string($uid) || !preg_match('/^[1-9][0-9]{0,8}$/D', $uid)) throw new \lib\AdminApp\ApiError('商户编号不正确');
        session_write_close();
        $result = $service->schema((int)$uid);
    } elseif ($method === 'POST') {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['merchant_policy_csrf']) || !is_string($csrf) || !hash_equals($_SESSION['merchant_policy_csrf'], $csrf)) merchantPolicyResponse(403, ['code'=>-1,'msg'=>'页面凭证已失效，请刷新页面']);
        if (!checkRefererHost()) merchantPolicyResponse(403, ['code'=>-1,'msg'=>'请求来源不正确']);
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') merchantPolicyResponse(415, ['code'=>-1,'msg'=>'请使用 JSON 提交']);
        $raw = file_get_contents('php://input', false, null, 0, 32769);
        if (strlen($raw) > 32768) merchantPolicyResponse(413, ['code'=>-1,'msg'=>'请求内容过长']);
        $input = json_decode($raw, true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($input) || substr(ltrim($raw),0,1) !== '{') throw new \lib\AdminApp\ApiError('请求必须为 JSON 对象');
        session_write_close();
        $result = $service->save($input, $clientip);
    } else { header('Allow: GET, POST'); merchantPolicyResponse(405, ['code'=>-1,'msg'=>'请求方式不正确']); }
    merchantPolicyResponse(200, ['code'=>0,'data'=>$result]);
} catch (\lib\AdminApp\ApiError $e) { merchantPolicyResponse($e->status, ['code'=>-1,'msg'=>$e->getMessage()]); }
catch (JsonException $e) { merchantPolicyResponse(400, ['code'=>-1,'msg'=>'JSON 格式错误']); }
catch (Throwable $e) { error_log('Merchant channel policy: '.$e->getMessage()); merchantPolicyResponse(500, ['code'=>-1,'msg'=>'通道策略操作失败，请检查服务器日志']); }
