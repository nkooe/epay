<?php
namespace lib\Task;

/** Rebuildable provider sessions live outside both the web root and code digest. */
final class PluginRuntime
{
    public static function directory($plugin)
    {
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$plugin))throw new \InvalidArgumentException('插件运行目录名称无效');
        $base=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'epay-session-'.substr(hash('sha256',ServiceConfig::root()),0,24);
        $directory=$base.DIRECTORY_SEPARATOR.$plugin;
        foreach([$base,$directory] as $path){
            if(is_link($path))throw new \RuntimeException('插件运行目录不能为符号链接');
            if(!is_dir($path)&&!mkdir($path,0700)&&!is_dir($path))throw new \RuntimeException('无法创建插件运行目录');
            if(!is_writable($path))throw new \RuntimeException('插件运行目录不可写，请核对网站与任务运行用户');
            if(PHP_OS_FAMILY!=='Windows'&&((fileperms($path)&0077)!==0||fileowner($path)!==posix_geteuid()))throw new \RuntimeException('插件会话目录权限或所属用户不正确');
        }
        return $directory;
    }
}
