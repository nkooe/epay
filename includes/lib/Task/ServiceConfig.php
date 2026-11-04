<?php
namespace lib\Task;

final class ServiceConfig
{
    public static function root()
    {
        $root=realpath(dirname(__DIR__,3));
        if(!$root||!is_file($root.'/includes/autoloader.php'))throw new \RuntimeException('无法识别项目根目录');
        return $root;
    }
    public static function read($root=null)
    {
        return ProcessRuntime::read($root?:self::root());
    }
}
