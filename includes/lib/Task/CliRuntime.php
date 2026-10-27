<?php
namespace lib\Task;

use lib\AdminApp\Store;
use lib\AdminApp\Runtime;

final class CliRuntime
{
    public static function connect($root, $scheduler = false)
    {
        $file = $root.'/config.php';
        if (!is_file($file)) throw new \RuntimeException('缺少站点 config.php');
        $dbconfig = null;
        require $file;
        if (!is_array($dbconfig) || !preg_match('/^[a-zA-Z0-9_]+$/D', $dbconfig['dbqz'] ?? '')) throw new \RuntimeException('数据库配置无效');
        // mysqlnd's default read timeout is 24 hours; ATTR_TIMEOUT only limits
        // connection establishment. Bound stalled reads as well as server waits.
        if (ini_set('mysqlnd.net_read_timeout', $scheduler ? '7' : '35') === false) throw new \RuntimeException('无法设置任务数据库读取超时，需要 mysqlnd');
        $pdo = new \PDO('mysql:host='.$dbconfig['host'].';port='.$dbconfig['port'].';dbname='.$dbconfig['dbname'].';charset=utf8mb4',
            $dbconfig['user'], $dbconfig['pwd'], [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT=>5]);
        $pdo->exec("SET time_zone='+08:00'");
        // The installed runtime uses MariaDB. Do not silently drop statement
        // deadlines on a different engine: the installer must fail explicitly.
        if (stripos((string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') === false) throw new \RuntimeException('任务服务要求支持 max_statement_time 的 MariaDB');
        $pdo->exec('SET SESSION max_statement_time='.($scheduler ? '5' : '30').', innodb_lock_wait_timeout=3, lock_wait_timeout=3');
        $db = new Store($pdo, $dbconfig['dbqz']);
        if ((int)$db->value("SELECT v FROM pre_config WHERE k='version'") < 2063) throw new \RuntimeException('请先完成数据库升级至 2063');
        return $db;
    }
    public static function business(Store $db)
    {
        $config = $db->config();
        if (!preg_match('~^https?://[^/\s]+(?:/[^\s]*)?/?$~D', $config['localurl'] ?? '')) throw new \RuntimeException('CLI 任务需要设置有效的站点地址 localurl');
        $url = parse_url($config['localurl']);
        $_SERVER['HTTP_HOST'] = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        $_SERVER['REQUEST_SCHEME'] = $url['scheme']; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Runtime::boot($db, $config);
        $GLOBALS['siteurl'] = rtrim($config['localurl'], '/').'/';
        $GLOBALS['date'] = date('Y-m-d H:i:s');
    }
}
