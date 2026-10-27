<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
fwrite(STDERR, "独立监控入口已停用。请在后台【进程管理】同步插件能力并启动本插件；迁移前停止旧守护进程，禁止双跑。\n");
exit(78);
