<?php
namespace lib\Task;

final class ProcessRuntime
{
    public static function directory($root)
    {
        $owner=fileowner($root.'/config.php');
        if(!$owner)throw new \RuntimeException('站点配置必须属于站点低权限用户');
        return sys_get_temp_dir().'/epay-process-'.$owner.'-'.substr(hash('sha256',realpath($root)),0,12);
    }
    private static function trusted($directory,$uid)
    {
        clearstatcache(true,$directory);
        if(is_link($directory)||!is_dir($directory)||fileowner($directory)!==$uid||(fileperms($directory)&0077))throw new \RuntimeException('进程运行目录权限不正确');
    }
    public static function read($root)
    {
        if(!is_file($root.'/config.php'))return null;
        $dir=self::directory($root);
        if(!file_exists($dir))return null;
        self::trusted($dir,fileowner($root.'/config.php'));
        $path=$dir.'/config.json';
        if(!is_file($path))return null;
        if(is_link($path))throw new \RuntimeException('进程配置不能为符号链接');
        $c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(($c['mode']??null)!=='process'||($c['root']??null)!==realpath($root)||($c['runtime']??null)!==$dir||($c['protocol']??null)!==4||($c['uid']??null)!==fileowner($root.'/config.php'))throw new \RuntimeException('进程管理器配置与站点不一致');
        return $c;
    }
    public static function enter($root)
    {
        self::environment();
        if(posix_geteuid()===0){
            $uid=fileowner($root.'/config.php');$account=$uid?posix_getpwuid($uid):false;
            if(!$account)throw new \RuntimeException('无法识别站点用户，请将 config.php 的所属用户设置为网站运行用户');
            if(!posix_initgroups($account['name'],$account['gid'])||!posix_setgid($account['gid'])||!posix_setuid($account['uid'])||posix_geteuid()!==$account['uid'])throw new \RuntimeException('无法切换到站点运行用户，请检查系统用户权限');
        }
        umask(0077);
        if(fileowner($root.'/config.php')!==posix_geteuid())throw new \RuntimeException('运行用户与网站配置所属用户不一致，请在进程管理器选择 root 启动');
    }
    public static function environment()
    {
        if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux')throw new \RuntimeException('后台主进程需要 Linux 命令行 PHP');
        foreach(['pdo_mysql','mysqlnd','mbstring','curl','openssl','bcmath'] as $ext)if(!extension_loaded($ext))throw new \RuntimeException('命令行 PHP 缺少扩展：'.$ext.'，请在当前 PHP 版本中启用');
        foreach(['pcntl_signal','pcntl_async_signals','pcntl_exec','posix_geteuid','posix_getpwuid','posix_initgroups','posix_setgid','posix_setuid','posix_setsid','posix_setpgid','posix_kill','proc_open','stream_socket_server'] as $fn)if(!function_exists($fn))throw new \RuntimeException('命令行 PHP 缺少或禁用了函数：'.$fn.'，请在当前 PHP 版本中启用');
    }
    public static function inspect($root)
    {
        self::environment();
        if(posix_geteuid()===0)throw new \RuntimeException('内部任务未切换到站点用户，停止执行');
        if(fileowner($root.'/config.php')!==posix_geteuid())throw new \RuntimeException('运行用户必须与网站配置所属用户一致');
        CliRuntime::connect($root,true);
    }
    public static function prepare($root,$limit=2)
    {
        self::inspect($root);
        $dir=self::directory($root);$uid=posix_geteuid();
        if(!file_exists($dir)&&!mkdir($dir,0700))throw new \RuntimeException('无法创建进程运行目录');
        self::trusted($dir,$uid);
        $c=['mode'=>'process','protocol'=>4,'root'=>realpath($root),'php'=>PHP_BINARY,'uid'=>$uid,'runtime'=>$dir,
            'service'=>'epay-task-'.substr(hash('sha256',realpath($root)),0,12).'.process','helper'=>$dir.'/control',
            'concurrency'=>2,'service_limit'=>Definitions::integer($limit,1,32,'插件服务并发上限'),'memory_max_mb'=>1024,'memory_high_mb'=>819];
        if(!is_dir($dir.'/logs')&&!mkdir($dir.'/logs',0700))throw new \RuntimeException('无法创建服务日志目录');
        self::trusted($dir.'/logs',$uid);
        return $c;
    }
    public static function write($path,array $value)
    {
        if(is_link($path))throw new \RuntimeException('运行文件不能为符号链接');
        $tmp=$path.'.'.bin2hex(random_bytes(6));
        if(file_put_contents($tmp,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false||!chmod($tmp,0600)||!rename($tmp,$path))throw new \RuntimeException('无法保存进程运行状态');
    }
    public static function identity($pid)
    {
        if(!is_int($pid)||$pid<2)return null;
        $stat=@file_get_contents('/proc/'.$pid.'/stat');
        if($stat===false)return null;
        $offset=strrpos($stat,')');if($offset===false)return null;
        $fields=explode(' ',trim(substr($stat,$offset+1)));
        if(($fields[0]??'Z')==='Z')return null;
        return $fields[19]??null;
    }
    public static function alive(array $record)
    {
        return isset($record['pid'],$record['identity'])&&$record['identity']!==null&&self::identity((int)$record['pid'])===$record['identity'];
    }
    public static function request(array $c,$action,$id=null)
    {
        $socket=@stream_socket_client('unix://'.$c['runtime'].'/control.sock',$code,$message,.5);
        if(!$socket){
            if($action==='status')return ['available'=>true,'installed'=>true,'active_state'=>'inactive','pid'=>0,'service'=>$c['service'],'managed_externally'=>true,'mode'=>'process','message'=>'由进程管理器负责启动、停止、重启和开机自启'];
            throw new \RuntimeException('主进程未运行，请在进程管理器中启动');
        }
        try{
            stream_set_timeout($socket,2);
            $body=json_encode(['action'=>$action,'id'=>$id],JSON_THROW_ON_ERROR)."\n";
            if(fwrite($socket,$body)!==strlen($body))throw new \RuntimeException('进程控制请求发送失败');
            $line=fgets($socket,32769);
            if($line===false||strlen($line)>32768)throw new \RuntimeException('进程控制响应超时或无效');
            $result=json_decode($line,true,16,JSON_THROW_ON_ERROR);
            if(isset($result['error']))throw new \RuntimeException($result['error']);
            return $result;
        }finally{fclose($socket);}
    }
}
