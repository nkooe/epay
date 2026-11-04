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
    public static function prepare($root,$limit=2)
    {
        if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()===0)throw new \RuntimeException('请在进程管理器中选择站点低权限用户，不能使用 root');
        foreach(['pcntl_signal','pcntl_exec','posix_setsid','posix_setpgid','posix_kill','proc_open','stream_socket_server'] as $fn)if(!function_exists($fn))throw new \RuntimeException('命令行环境缺少函数：'.$fn);
        if(is_file($root.'/includes/task-service.php'))throw new \RuntimeException('本站已配置 systemd，请先停止并卸载原任务服务，不能同时接管');
        CliRuntime::connect($root,true);
        $dir=self::directory($root);$uid=posix_geteuid();
        if(!file_exists($dir)&&!mkdir($dir,0700))throw new \RuntimeException('无法创建进程运行目录');
        self::trusted($dir,$uid);
        $c=['mode'=>'process','protocol'=>4,'root'=>realpath($root),'php'=>PHP_BINARY,'uid'=>$uid,'runtime'=>$dir,
            'service'=>'epay-task-'.substr(hash('sha256',realpath($root)),0,12).'.process','helper'=>$dir.'/control',
            'concurrency'=>2,'service_limit'=>Installer::serviceLimit($limit),'memory_max_mb'=>1024,'memory_high_mb'=>819];
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
