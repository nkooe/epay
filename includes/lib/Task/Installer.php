<?php
namespace lib\Task;

final class Installer
{
    public static function quote($value, $command = false)
    {
        if(preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('路径含控制字符');
        $value = str_replace(['\\','"','%'],['\\\\','\\"','%%'],$value);
        if ($command) $value = str_replace('$','$$',$value);
        return '"'.$value.'"';
    }
    public static function directory($value)
    {
        if (preg_match('/[\x00-\x1f\x7f]/',$value) || substr($value,0,1)!=='/') throw new \InvalidArgumentException('工作目录必须为无控制字符的绝对路径');
        // WorkingDirectory does not unquote or C-unescape. The trailing slash
        // protects a literal trailing space/backslash from the unit file parser.
        return str_replace('%','%%',rtrim($value,'/').'/');
    }
    public static function shellQuote($value){return "'".str_replace("'","'\\''",$value)."'";}
    public static function journalConfig()
    {
        return "[Journal]\nStorage=persistent\nCompress=yes\nSystemMaxUse=32M\nSystemMaxFileSize=4M\nRuntimeMaxUse=16M\nRuntimeMaxFileSize=4M\nMaxRetentionSec=14day\nMaxFileSec=1day\nForwardToSyslog=no\nForwardToKMsg=no\nForwardToConsole=no\nForwardToWall=no\n";
    }
    public static function unit(array $c)
    {
        return SystemdUnits::files($c)[$c['service']];
    }
    public static function helper(array $c)
    {
        return SystemdUnits::helper($c);
    }
    /** Inspect direct commands and panel wrapper files without executing them. */
    public static function referencesLegacyCron($text, $root, $url, callable $read, $depth = 0)
    {
        if ($depth > 2) return false;
        $parsed=parse_url($url);
        $address=($parsed['host']??'').(isset($parsed['port'])?':'.$parsed['port']:'').rtrim($parsed['path']??'','/').'/cron.php';
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (trim($line)==='' || preg_match('/^\s*#/', $line)) continue;
            if (strpos($line,'cron.php')!==false &&
                (strpos($line,rtrim($root,'/').'/')!==false || preg_match('~https?://'.preg_quote($address,'~').'(?=[?\s\x22\x27#]|$)~i',$line))) return true;
            // Only inspect literal absolute paths; never evaluate shell variables or substitutions.
            preg_match_all('~(?:^|[\s\x22\x27])(/[^\s\x22\x27;&|<>]+)~', $line, $paths);
            foreach (array_unique($paths[1]) as $path) {
                if (strpos($path,'$')!==false || strpos($path,'`')!==false) continue;
                $body=$read($path);
                if (is_string($body) && self::referencesLegacyCron($body,$root,$url,$read,$depth+1)) return true;
            }
        }
        return false;
    }
    public static function legacyCron(array $c, $url)
    {
        $sources=[];$env=getenv();$env['LC_ALL']='C';
        $users=['root',$c['user']];
        foreach(self::fpm() as $pool){$account=posix_getpwuid($pool['uid']);if($account)$users[]=$account['name'];}
        $users=array_unique($users);
        foreach($users as $user){
            $r=Command::run([$c['crontab'],'-u',$user,'-l'],10,1048576,$env);
            if($r['code']!==0){
                if($r['code']===1 && strpos($r['stderr'],'no crontab for')!==false)continue;
                throw new \RuntimeException('无法检查旧计划任务 crontab：'.$user);
            }
            $sources['crontab:'.$user]=$r['stdout'];
        }
        foreach(array_merge(['/etc/crontab'],glob('/etc/cron.d/*')?:[]) as $path){
            if(is_file($path)){
                if(!is_readable($path))throw new \RuntimeException('无法检查旧计划任务：'.$path);
                $sources[$path]=file_get_contents($path);
            }
        }
        $read=static function($path){return is_file($path)&&is_readable($path)&&filesize($path)<=131072?file_get_contents($path):null;};
        $found=[];
        foreach($sources as $name=>$body)if(self::referencesLegacyCron($body,$c['root'],$url,$read))$found[]=$name;
        return $found;
    }
    private static function fpm()
    {
        $ps=Command::find('ps');if(!$ps)return [];
        $result=Command::run([$ps,'-eo','pid=,uid=,args=']);$pools=[];
        foreach(explode("\n",$result['stdout']) as $line){
            if(preg_match('/^\s*(\d+)\s+(\d+)\s+php-fpm:\s+pool\s+(.+)$/D',$line,$m)&&(int)$m[2]>0){
                $pools[]=['pid'=>(int)$m[1],'uid'=>(int)$m[2],'pool'=>$m[3]];
            }
        }
        return $pools;
    }
    public static function detect($root,array $options)
    {
        if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_getpwuid'))throw new \RuntimeException('安装器要求 Linux 和 posix；当前系统只能运行测试');
        $root=realpath($root);if(!$root||!is_file($root.'/bin/task-worker.php'))throw new \RuntimeException('项目根目录无效');
        $pools=self::fpm();$user=$options['user']??getenv('EPAY_SERVICE_USER');
        if(!$user){
            $owners=[];
            foreach([$root.'/config.php',$root.'/index.php',$root] as $path){
                if(is_file($path)||is_dir($path)){$uid=fileowner($path);if($uid>0)$owners[$uid]=true;}
            }
            $poolUids=array_values(array_unique(array_column($pools,'uid')));
            $matches=array_values(array_intersect(array_keys($owners),$poolUids));
            if(count($matches)===1)$uid=$matches[0];
            elseif(count($poolUids)===1)$uid=$poolUids[0];
            elseif(count($owners)===1)$uid=array_key_first($owners);
            else throw new \RuntimeException('无法唯一判断运行用户，请用 --user 指定；不会猜测 www/www-data');
            $account=posix_getpwuid($uid);$user=$account['name']??null;
        }
        if(!$user||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/D',$user))throw new \RuntimeException('运行用户无效');
        $account=posix_getpwnam($user);if(!$account||$account['uid']===0)throw new \RuntimeException('运行用户必须为存在的非 root 用户');
        $php=$options['php']??getenv('EPAY_PHP_BIN');
        if(!$php){
            $candidates=[];
            foreach($pools as $pool)if($pool['uid']===$account['uid']){
                $exe=@readlink('/proc/'.$pool['pid'].'/exe');
                if($exe)foreach([dirname($exe).'/php',dirname(dirname($exe)).'/bin/php'] as $candidate){
                    if(is_executable($candidate))$candidates[realpath($candidate)]=true;
                }
            }
            if(count($candidates)>1)throw new \RuntimeException('检测到多个 PHP CLI，请用 --php 指定');
            $php=$candidates?array_key_first($candidates):PHP_BINARY;
        }
        $php=realpath($php);if(!$php||!is_executable($php))throw new \RuntimeException('PHP CLI 不可执行');
        $probe=Command::run([$php,'-r','echo json_encode(["sapi"=>PHP_SAPI,"version"=>PHP_VERSION_ID,"extensions"=>get_loaded_extensions(),"proc"=>function_exists("proc_open"),"pcntl"=>function_exists("pcntl_signal"),"posix"=>function_exists("posix_geteuid")]);']);
        $info=json_decode($probe['stdout'],true);
        if($probe['code']!==0||!is_array($info)||$info['sapi']!=='cli'||$info['version']<80100)throw new \RuntimeException('需要 PHP 8.1 或更新的 CLI');
        $missing=array_diff(['pdo_mysql','mbstring','curl','openssl','bcmath'],array_map('strtolower',$info['extensions']));
        if($missing||!$info['proc']||!$info['pcntl']||!$info['posix'])throw new \RuntimeException('PHP 缺少扩展或函数：'.implode(',',$missing).'；需要 proc_open/pcntl/posix');
        $commands=[];
        foreach(['systemctl','systemd-analyze','journalctl','sudo','visudo','sh','runuser','crontab'] as $name){
            $commands[$name]=Command::find($name);
            if(!$commands[$name])throw new \RuntimeException('缺少服务器工具：'.$name);
        }
        if(!is_dir('/run/systemd/system'))throw new \RuntimeException('systemd 不是当前服务器的服务管理器');
        $version=Command::run([$commands['systemctl'],'--version']);
        if(!preg_match('/systemd (\d+)/',$version['stdout'],$match)||(int)$match[1]<250||!is_file('/sys/fs/cgroup/cgroup.controllers'))throw new \RuntimeException('需要 systemd 250+ 与 cgroup v2，不降级运行');
        $controllers=preg_split('/\s+/',trim(file_get_contents('/sys/fs/cgroup/cgroup.controllers')));
        if(array_diff(['memory','pids','cpu'],$controllers))throw new \RuntimeException('cgroup 缺少 memory/pids/cpu 资源控制');
        if(!preg_match('/MemAvailable:\s+(\d+)/',file_get_contents('/proc/meminfo'),$memory)||(int)$memory[1]<524288)throw new \RuntimeException('可用内存不足 512MB，安装已停止');
        // Cap the whole site at half the currently available memory; per-process caps are not reservations.
        $budget=min(1024,(int)floor((int)$memory[1]/2048));
        $id=substr(hash('sha256',$root),0,12);
        return $commands+['root'=>$root,'php'=>$php,'user'=>$user,'uid'=>$account['uid'],'id'=>$id,'service'=>'epay-task-'.$id.'.service',
            'helper'=>'/usr/local/libexec/epay-task-'.$id.'/control','concurrency'=>2,'protocol'=>4,
            'memory_max_mb'=>$budget,'memory_high_mb'=>(int)floor($budget*0.8),'service_limit'=>self::serviceLimit($options['service-limit']??2)];
    }
    public static function serviceLimit($value)
    {
        return Definitions::integer($value,1,32,'插件服务并发上限');
    }
    private static function trustedDirectory($path)
    {
        for($dir=$path;$dir!=='/'&&$dir!=='.';$dir=dirname($dir)){
            if(is_link($dir))throw new \RuntimeException('控制程序目录不能为符号链接：'.$dir);
            if(!is_dir($dir))continue;
            if(fileowner($dir)!==0||(fileperms($dir)&0022))throw new \RuntimeException('控制程序目录必须归 root 所有且不可由其他用户写入：'.$dir);
        }
    }
    private static function rootFile($path,$content,$mode)
    {
        if(is_link($path))throw new \RuntimeException('拒绝覆盖符号链接：'.$path);
        if(is_file($path)&&fileowner($path)!==0)throw new \RuntimeException('拒绝覆盖非 root 文件：'.$path);
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,$content,LOCK_EX)===false||!chmod($tmp,$mode)||!rename($tmp,$path))throw new \RuntimeException('写入失败：'.$path);
    }
    public static function install(array $c)
    {
        if(posix_geteuid()!==0)throw new \RuntimeException('--install 需要以 root 运行');
        $old=ServiceConfig::read($c['root'],true);
        if($old){
            $status=Command::run([$c['systemctl'],'show','--property=ActiveState','--value',$old['service']]);
            if($status['code']!==0)throw new \RuntimeException('无法确认原调度服务状态，安装已停止');
            if(in_array(trim($status['stdout']),['active','activating','deactivating','reloading'],true))throw new \RuntimeException('请先停止现有调度服务，再重新安装');
            if($old['service']!==$c['service'])throw new \RuntimeException('项目已移动：请先卸载原服务 '.$old['service'].' 及其 sudoers/控制程序，再删除复制来的 includes/task-service.php 后安装');
        }
        $db=CliRuntime::connect($c['root']);
        if((int)$db->value("SELECT COUNT(*) FROM pre_task WHERE lock_token IS NOT NULL")>0)throw new \RuntimeException('存在执行中或中断任务，请核查后再切换');
        if((int)$db->value('SELECT COUNT(*) FROM pre_task_execution WHERE active_task_id IS NOT NULL')>0)throw new \RuntimeException('存在尚未对账的任务实例，安装已停止');
        if((int)$db->value("SELECT COUNT(*) FROM pre_plugin_service WHERE token IS NOT NULL OR observed NOT IN ('stopped','attention')")>0)throw new \RuntimeException('请先排空所有插件服务');
        foreach($db->all('SELECT id,generation FROM pre_plugin_service WHERE generation>0') as $instance){
            Execution::id($instance['id']);
            $generation=Definitions::integer($instance['generation'],1,999999999,'实例代次');
            $name=SystemdUnits::base($c).'-plugin@'.$instance['id'].'g'.$generation.'.service';
            $state=Command::run([$c['systemctl'],'show','--property=ActiveState','--value',$name]);
            if(!in_array($state['code'],[0,4],true))throw new \RuntimeException('无法确认插件实例实际状态');
            if(in_array(trim($state['stdout']),['active','activating','deactivating','reloading'],true))throw new \RuntimeException('插件进程树尚未退出，安装已停止');
        }
        $url=$db->value("SELECT v FROM pre_config WHERE k='localurl'");
        if(!is_string($url)||!preg_match('~^https?://~D',$url))throw new \RuntimeException('请先配置网站地址 localurl');
        $legacy=self::legacyCron($c,$url);
        if($legacy)throw new \RuntimeException('请先停用旧计划任务，再安装服务：'.implode('、',$legacy));
        $access=Command::run([$c['runuser'],'-u',$c['user'],'--',$c['php'],'-r','exit(is_readable($argv[1]."/config.php")&&is_readable($argv[1]."/bin/task-worker.php")?0:1);',$c['root']]);
        if($access['code']!==0)throw new \RuntimeException('运行用户无法读取站点配置或 Worker');
        $files=SystemdUnits::files($c);
        $unit='/etc/systemd/system/'.$c['service'];
        $helperDir=dirname($c['helper']);
        self::trustedDirectory(dirname($helperDir));
        if(!is_dir($helperDir)&&!mkdir($helperDir,0755,true))throw new \RuntimeException('创建控制程序目录失败');
        self::trustedDirectory($helperDir);
        $logDir=ServiceLog::directory($c);
        if(is_link($logDir)||(file_exists($logDir)&&!is_dir($logDir)))throw new \RuntimeException('服务日志目录类型不正确');
        if(!is_dir($logDir)&&!mkdir($logDir,0700))throw new \RuntimeException('创建服务日志目录失败');
        $account=posix_getpwnam($c['user']);
        if(!$account||!chown($logDir,$account['uid'])||!chgrp($logDir,$account['gid'])||!chmod($logDir,0700))throw new \RuntimeException('设置服务日志目录权限失败');
        // Keep a recoverable snapshot before writing installation state.
        $backup=dirname($helperDir).'/backup-'.$c['id'].'-'.date('YmdHis');
        if(!mkdir($backup,0700))throw new \RuntimeException('创建安装备份目录失败');
        $journalDir='/etc/systemd/journald@'.substr($c['service'],0,-8).'.conf.d';
        $journalConfig=$journalDir.'/70-epay-retention.conf';
        $paths=array_merge(array_map(static function($name){return '/etc/systemd/system/'.$name;},array_keys($files)),
            [$c['helper'],$c['root'].'/includes/task-service.php','/etc/sudoers.d/epay-task-'.$c['id'],$journalConfig]);
        foreach($paths as $file)if(is_file($file)&&!copy($file,$backup.'/'.basename($file)))throw new \RuntimeException('备份现有安装文件失败');
        $rules=array_map(static function($action)use($c){return $c['helper'].' '.$action;},['start','stop','restart','enable','disable','status','target-status','budget']);
        foreach(['job','plugin'] as $kind)foreach(['start','stop','status'] as $action)$rules[]=$c['helper'].' '.$kind.'-'.$action.' *';
        $sudoers=$c['user'].' ALL=(root) NOPASSWD: '.implode(', ',$rules)."\n";
        $sudoTmp=$backup.'/sudoers-check';
        file_put_contents($sudoTmp,$sudoers);chmod($sudoTmp,0440);
        $check=Command::run([$c['visudo'],'-cf',$sudoTmp]);
        if($check['code']!==0)throw new \RuntimeException('sudoers 校验失败：'.$check['stderr']);
        $checks=[];
        foreach($files as $name=>$text){$path=$backup.'/'.$name;file_put_contents($path,$text);chmod($path,0644);$checks[]=$path;}
        $check=Command::run(array_merge([$c['systemd-analyze'],'verify','--man=no'],$checks),20);
        if($check['code']!==0)throw new \RuntimeException('systemd 服务配置校验失败：'.$check['stderr']);
        self::trustedDirectory('/etc/systemd');
        if(!is_dir($journalDir)&&!mkdir($journalDir,0755))throw new \RuntimeException('创建独立日志配置目录失败');
        self::trustedDirectory($journalDir);
        self::rootFile($journalConfig,self::journalConfig(),0644);
        foreach($files as $name=>$text)self::rootFile('/etc/systemd/system/'.$name,$text,0644);
        self::rootFile($c['helper'],self::helper($c),0755);
        self::rootFile('/etc/sudoers.d/epay-task-'.$c['id'],$sudoers,0440);
        $reload=Command::run([$c['systemctl'],'daemon-reload']);
        if($reload['code']!==0)throw new \RuntimeException('systemd 重载失败：'.$reload['stderr']);
        $journalReload=Command::run([$c['systemctl'],'try-restart','systemd-journald@'.substr($c['service'],0,-8).'.service']);
        if($journalReload['code']!==0)throw new \RuntimeException('独立日志服务重载失败：'.$journalReload['stderr']);
        $config="<?php\n// Generated by install-task-worker.php; contains no credentials.\nreturn ".var_export($c,true).";\n";
        self::rootFile($c['root'].'/includes/task-service.php',$config,0644);
        return ['service'=>$c['service'],'backup'=>$backup,'installed'=>true];
    }
}
