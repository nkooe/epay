<?php
namespace lib\Task;

final class SystemdController
{
    private $config;
    private $configError;
    private $command;
    public function __construct(array $config=null, callable $command=null)
    {
        $this->command=$command??[Command::class,'run'];
        try{$this->config=$config??ServiceConfig::read();}
        catch(\RuntimeException $e){$this->configError=$e->getMessage();}
    }
    private function invoke($action,$instance=null,$limit=32768)
    {
        if(!in_array($action,['start','stop','restart','enable','disable','status','target-status','budget','job-start','job-stop','job-status','plugin-start','plugin-stop','plugin-status'],true))throw new \InvalidArgumentException('不支持的服务操作');
        if(strpos($action,'job-')===0)Execution::id($instance);
        elseif(strpos($action,'plugin-')===0){if(!is_string($instance)||!preg_match('/^[a-f0-9]{32}g[1-9][0-9]{0,8}$/D',$instance))throw new \InvalidArgumentException('服务实例编号无效');}
        elseif($instance!==null)throw new \InvalidArgumentException('整体操作不接受实例参数');
        if($this->configError)throw new \RuntimeException($this->configError);
        if(!$this->config)throw new \RuntimeException('请先在服务器安装任务服务');
        $c=$this->config;
        if(!is_file($c['helper'])||!is_executable($c['helper']))throw new \RuntimeException('受限服务控制程序尚未安装');
        $args=[$c['sudo'],'-n','--',$c['helper'],$action];
        if($instance!==null)$args[]=$instance;
        $result=call_user_func($this->command,$args,3,$limit);
        if($result['timed_out'])throw new \RuntimeException('服务控制请求超时，请刷新实际状态，勿重复提交');
        if($result['code']!==0){
            error_log('Task service '.$action.' failed: '.$result['stderr']);
            throw new \RuntimeException('服务控制失败，请检查安装和受限 sudo 权限');
        }
        return $result['stdout'];
    }
    public function status()
    {
        if($this->configError)return ['available'=>false,'installed'=>false,'active_state'=>'invalid_config','message'=>$this->configError];
        if(!$this->config)return ['available'=>false,'installed'=>false,'active_state'=>'not_installed','service'=>'','message'=>'未安装服务；在服务器运行 php bin/install-task-worker.php --print 检查环境'];
        $output=$this->invoke('status');$values=[];
        foreach(explode("\n",$output) as $line){$pair=explode('=',$line,2);if(count($pair)===2)$values[$pair[0]]=$pair[1];}
        if(!isset($values['LoadState'],$values['ActiveState']))throw new \RuntimeException('systemd 返回状态不完整');
        $target=self::properties($this->invoke('target-status'));
        $budget=self::properties($this->invoke('budget'));
        return ['available'=>true,'installed'=>$values['LoadState']==='loaded','service'=>$this->config['service'],
            'active_state'=>$values['ActiveState'],'sub_state'=>$values['SubState']??'',
            'pid'=>(int)($values['MainPID']??0),'active_since'=>$values['ActiveEnterTimestamp']??'','result'=>$values['Result']??'','message'=>'',
            'restarts'=>(int)($values['NRestarts']??0),'status_text'=>$values['StatusText']??'',
            'watchdog_seconds'=>!empty($values['WatchdogUSec'])?Watchdog::SECONDS:0,
            'target_state'=>$target['ActiveState']??'unknown','unit_file_state'=>$target['UnitFileState']??'','budget'=>$budget];
    }
    private static function properties($text)
    {
        $values=[];foreach(explode("\n",$text) as $line){$pair=explode('=',$line,2);if(count($pair)===2)$values[$pair[0]]=$pair[1];}return $values;
    }
    public function targetReady()
    {
        return (self::properties($this->invoke('target-status'))['ActiveState']??'')==='active';
    }
    public function instance($kind,$action,$id)
    {
        if(!in_array($kind,['job','plugin'],true)||!in_array($action,['start','stop','status','logs'],true))throw new \InvalidArgumentException('实例操作无效');
        if($action==='logs')return $this->logStore()->recent($kind.':'.$id);
        $output=$this->invoke($kind.'-'.$action,$id);
        if($action!=='status')return ['accepted'=>true];
        $p=self::properties($output);
        if(!isset($p['LoadState'],$p['ActiveState']))throw new \RuntimeException('实例状态不完整');
        return ['active_state'=>$p['ActiveState'],'sub_state'=>$p['SubState']??'','result'=>$p['Result']??'',
            'exit_status'=>(int)($p['ExecMainStatus']??-1),'pid'=>(int)($p['MainPID']??0),'restarts'=>(int)($p['NRestarts']??0),
            'memory'=>$p['MemoryCurrent']??null,'tasks'=>$p['TasksCurrent']??null];
    }
    public function logs()
    {
        return $this->logStore()->recent();
    }
    private function logStore()
    {
        if($this->configError)throw new \RuntimeException($this->configError);
        if(!$this->config)throw new \RuntimeException('请先在服务器安装任务服务');
        return new ServiceLog($this->config);
    }
    public static function validateLogDate($date)
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new \InvalidArgumentException('日志日期格式无效');
        }
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $errors=\DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d')!==$date) {
            throw new \InvalidArgumentException('日志日期无效');
        }
        return $date;
    }
    public function logDays()
    {
        return $this->logStore()->days();
    }
    public function logDay($date)
    {
        $date=self::validateLogDate($date);
        return $this->logStore()->read($date);
    }
    public function deleteLogDay($date)
    {
        $this->logStore()->delete(self::validateLogDate($date));
    }
    public static function redact($text)
    {
        return preg_replace('/([\x22\x27]?(?:password|pwd|token|secret|key|sign|authorization)[\x22\x27]?\s*[=:]\s*)(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|(?:Bearer\s+)?[^\s,;&]+)/i','$1[已隐藏]',$text);
    }
    public function control($action)
    {
        if(!in_array($action,['start','stop','restart','enable','disable'],true))throw new \InvalidArgumentException('不支持的服务操作');
        $this->invoke($action);
        return ['action'=>$action,'accepted'=>true,'status'=>$this->status()];
    }
}
