<?php
namespace lib\Task;

final class Controller
{
    private $config;
    private $configError;
    private $transport;
    public function __construct(array $config=null,callable $transport=null)
    {
        $this->transport=$transport??[ProcessRuntime::class,'request'];
        try{$this->config=$config??ServiceConfig::read();}
        catch(\RuntimeException $e){$this->configError=$e->getMessage();}
    }
    public function status()
    {
        if($this->configError)return ['available'=>false,'installed'=>false,'mode'=>'process','managed_externally'=>true,'active_state'=>'invalid_config','message'=>$this->configError];
        if(!$this->config)return ['available'=>false,'installed'=>false,'mode'=>'process','managed_externally'=>true,'active_state'=>'not_installed','service'=>'','message'=>'请在进程管理器添加网站根目录和启动命令，使用 root，单实例运行并开启自动重启和开机自启。首次启动会自动初始化。'];
        return call_user_func($this->transport,$this->config,'status');
    }
    public function accepting()
    {
        return $this->config&&!empty(call_user_func($this->transport,$this->config,'status')['accepting']);
    }
    public function pluginResults()
    {
        if($this->configError)throw new \RuntimeException($this->configError);
        if(!$this->config)throw new \RuntimeException('请先在进程管理器启动本站主进程');
        return call_user_func($this->transport,$this->config,'plugin-results');
    }
    public function instance($kind,$action,$id)
    {
        if(!in_array($kind,['job','plugin'],true)||!in_array($action,['start','stop','status','logs','ack'],true))throw new \InvalidArgumentException('实例操作无效');
        if($kind==='job')Execution::id($id);
        elseif(!is_string($id)||!preg_match('/^[a-f0-9]{32}g[1-9][0-9]{0,8}$/D',$id))throw new \InvalidArgumentException('插件实例编号无效');
        if($action==='logs')return $this->logStore()->recent($kind.':'.$id);
        if($this->configError)throw new \RuntimeException($this->configError);
        if(!$this->config)throw new \RuntimeException('请先在进程管理器启动本站主进程');
        return call_user_func($this->transport,$this->config,$kind.'-'.$action,$id);
    }
    public function logs(){return $this->logStore()->recent();}
    private function logStore()
    {
        if($this->configError)throw new \RuntimeException($this->configError);
        if(!$this->config)throw new \RuntimeException('首次启动后才会生成本站日志，请在进程管理器启动主进程');
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
}
