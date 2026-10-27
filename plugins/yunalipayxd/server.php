<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
fwrite(STDERR, "支付成功由上报接口处理，无需常驻观察进程；过期订单检查已接入进程管理的计划任务列表。\n");
exit(78);
