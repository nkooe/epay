<?php
// URL-based task execution is retired. There is exactly one scheduler: systemd.
http_response_code(410);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(['code'=>410,'msg'=>'定时 URL 已停用，请在后台任务与服务管理中启动 systemd 调度服务'],JSON_UNESCAPED_UNICODE);
