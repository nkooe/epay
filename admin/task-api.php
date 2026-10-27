<?php
require __DIR__.'/../includes/common.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function taskResponse($status, array $data)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    if ($islogin != 1) taskResponse(401, ['code'=>-1, 'msg'=>'请先登录管理员账号']);
    $method = $_SERVER['REQUEST_METHOD'];
    $act = $_GET['act'] ?? null;
    $input = [];
    if ($method === 'POST') {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!isset($_SESSION['task_csrf']) || !is_string($csrf) || !hash_equals($_SESSION['task_csrf'], $csrf)) {
            taskResponse(403, ['code'=>-1, 'msg'=>'页面凭证已失效，请刷新后重试']);
        }
        if (!checkRefererHost()) taskResponse(403, ['code'=>-1, 'msg'=>'请求来源不正确']);
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            taskResponse(415, ['code'=>-1, 'msg'=>'请求必须为 JSON']);
        }
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) taskResponse(413, ['code'=>-1, 'msg'=>'请求内容过长']);
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || substr(ltrim($raw), 0, 1) !== '{') throw new InvalidArgumentException('请求必须为 JSON 对象');
    } elseif ($method !== 'GET') {
        header('Allow: GET, POST');
        taskResponse(405, ['code'=>-1, 'msg'=>'请求方式不正确']);
    }
    session_write_close();
    $store = \lib\AdminApp\Runtime::legacyStore($DB);
    $data = (new \lib\Task\Management($store, $clientip))->handle($method, $act, $method === 'GET' ? $_GET : $input);
    taskResponse(200, ['code'=>0, 'data'=>$data]);
} catch (\lib\AdminApp\ApiError $e) {
    taskResponse($e->status, ['code'=>-1, 'msg'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    taskResponse(400, ['code'=>-1, 'msg'=>$e->getMessage()]);
} catch (JsonException $e) {
    taskResponse(400, ['code'=>-1, 'msg'=>'JSON 格式错误']);
} catch (Throwable $e) {
    error_log('Task API failed: '.$e->getMessage());
    taskResponse(500, ['code'=>-1, 'msg'=>'任务管理异常，请查看服务器错误日志']);
}
