<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__.'/..');
require $root.'/includes/autoloader.php'; Autoloader::register();
require_once $root.'/includes/lib/AdminApp/Store.php';
chdir($root); date_default_timezone_set('Asia/Shanghai');
try {
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() === 0) throw new RuntimeException('日志进程必须以站点低权限用户运行于 Linux');
    $config = \lib\Task\ServiceConfig::read($root);
    if (!$config) throw new RuntimeException('请先安装任务服务');
    $kind = $argv[1] ?? '';
    $scripts = ['worker'=>'task-worker.php', 'job'=>'task-runner.php', 'plugin'=>'plugin-service.php'];
    if (!isset($scripts[$kind])) throw new InvalidArgumentException('日志进程类型无效');
    $command = [PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=/dev/stderr', $root.'/bin/'.$scripts[$kind]];
    $source = $kind;
    if ($kind === 'worker') {
        if (count($argv) !== 2) throw new InvalidArgumentException('调度日志不接受实例参数');
    } else {
        if (count($argv) !== 3) throw new InvalidArgumentException('必须指定服务实例');
        if ($kind === 'job') \lib\Task\Execution::id($argv[2]);
        elseif (!preg_match('/^[a-f0-9]{32}g[1-9][0-9]{0,8}$/D', $argv[2])) throw new InvalidArgumentException('插件实例编号无效');
        $command[] = $argv[2]; $source .= ':'.$argv[2];
    }
    exit(\lib\Task\LogProcess::run(new \lib\Task\ServiceLog($config), $command, $source));
} catch (Throwable $e) {
    // Only collector/installation faults go to systemd journal; no daily log replay.
    fwrite(STDERR, '[epay-task-log] '.\lib\Task\SystemdController::redact($e->getMessage())."\n");
    exit(78);
}
