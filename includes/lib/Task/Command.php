<?php
namespace lib\Task;

final class Command
{
    public static function find($name)
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $name)) throw new \InvalidArgumentException('无效命令名');
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
            if (is_file($file) && is_executable($file)) return realpath($file);
        }
        return null;
    }
    public static function run(array $args, $timeout = 10, $limit = 32768, array $environment = null)
    {
        if (!function_exists('proc_open')) throw new \RuntimeException('PHP 必须启用 proc_open');
        // Temporary file descriptors avoid pipe deadlocks and work with bounded polling on Windows too.
        $out=tmpfile();$err=tmpfile();
        if(!$out||!$err){if($out)fclose($out);if($err)fclose($err);throw new \RuntimeException('无法创建命令输出缓冲');}
        try {
            $pipes=[];
            $process=proc_open($args,[0=>['pipe','r'],1=>$out,2=>$err],$pipes,null,$environment,['bypass_shell'=>true]);
            if(!is_resource($process))throw new \RuntimeException('无法启动命令');
            fclose($pipes[0]);$deadline=hrtime(true)+$timeout*1000000000;$timedOut=false;$limited=false;
            do {
                $state=proc_get_status($process);
                $limited=fstat($out)['size']>$limit||fstat($err)['size']>$limit;
                if(!$state['running'])break;
                if(hrtime(true)>=$deadline||$limited){$timedOut=!$limited;proc_terminate($process,9);break;}
                usleep(25000);
            }while(true);
            $closed=proc_close($process);
            $read=static function($stream)use($limit){$size=fstat($stream)['size'];fseek($stream,max(0,$size-$limit));return trim(stream_get_contents($stream,$limit));};
            $code=$timedOut?124:($limited?125:(!$state['running']&&$state['exitcode']>=0?$state['exitcode']:$closed));
            return ['code'=>$code,'stdout'=>$read($out),'stderr'=>$read($err),'timed_out'=>$timedOut,'output_limited'=>$limited];
        }finally{fclose($out);fclose($err);}
    }
}
