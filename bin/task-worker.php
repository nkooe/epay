<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__.'/..');
if (!$root || !is_file($root.'/includes/autoloader.php')) { fwrite(STDERR, "无法识别项目根目录\n"); exit(78); }
chdir($root);
date_default_timezone_set('Asia/Shanghai');
require $root.'/includes/autoloader.php';
Autoloader::register();
require_once $root.'/includes/lib/AdminApp/Store.php';
try {
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('pcntl_signal') || !function_exists('posix_geteuid') || !function_exists('proc_open')) throw new RuntimeException('Worker 要求 Linux、pcntl、posix 和 proc_open');
    if (posix_geteuid() === 0) throw new RuntimeException('禁止以 root 身份执行任务');
    $config = \lib\Task\ServiceConfig::read($root);
    if (!$config) throw new RuntimeException('请先安装任务服务');
    (new \lib\Task\Worker(\lib\Task\CliRuntime::connect($root, true), $config))->run();
} catch (Throwable $e) {
    error_log('[epay-task-worker] '.$e->getMessage());
    exit(1);
}
