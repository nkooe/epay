<?php
namespace lib\Task;

final class ServiceConfig
{
    public static function root()
    {
        $root = realpath(dirname(__DIR__, 3));
        if (!$root || !is_file($root.'/includes/autoloader.php')) throw new \RuntimeException('无法识别项目根目录');
        return $root;
    }
    public static function read($root = null, $allowMoved = false)
    {
        $root = $root ?: self::root();
        $path = $root.'/includes/task-service.php';
        if (!is_file($path)) return null;
        $config = require $path;
        if (!is_array($config) || (!$allowMoved && ($config['protocol']??null)!==4) || (! $allowMoved && ($config['root'] ?? '') !== realpath($root)) ||
            !preg_match('/^epay-task-[a-f0-9]{12}\.service$/D', $config['service'] ?? '') ||
            !is_string($config['helper'] ?? null) || !is_string($config['sudo'] ?? null)) {
            throw new \RuntimeException('任务服务配置与项目目录不一致，请重新运行安装器');
        }
        return $config;
    }
}
