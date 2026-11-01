<?php
namespace lib\Task;
use lib\AdminApp\Store;

final class PluginRegistry
{
    private $db; private $plugins;
    public function __construct(Store $db, $plugins = null)
    {
        $this->db = $db;
        $this->plugins = $plugins ?: ServiceConfig::root().'/plugins';
    }
    public function jobs($plugin)
    {
        return Manifest::read(Manifest::directory($this->plugins,$plugin))['tasks'];
    }
    public function fingerprint($channelId,$expectedPlugin)
    {
        $row=$this->db->row('SELECT plugin,status,config FROM pre_channel WHERE id=?',[$channelId]);
        if(!$row||$row['plugin']!==$expectedPlugin)throw new \RuntimeException('通道插件已变更');
        return hash('sha256',Manifest::digest(Manifest::directory($this->plugins,$expectedPlugin)).json_encode($row,JSON_THROW_ON_ERROR));
    }
    public function resolve($channelId,$jobKey,$expectedPlugin=null)
    {
        $channel=$this->db->row('SELECT id,name,plugin,status FROM pre_channel WHERE id=?',[$channelId]);
        if(!$channel)throw new \RuntimeException('指定通道已删除');
        if($expectedPlugin!==null&&$expectedPlugin!==$channel['plugin'])throw new \RuntimeException('通道插件已变更，请重新接入任务');
        $jobs=$this->jobs($channel['plugin']);
        if(!isset($jobs[$jobKey]))throw new \RuntimeException('插件未声明此任务，请检查 tasks.json');
        return $jobs[$jobKey]+['plugin'=>$channel['plugin'],'channel_id'=>(int)$channelId,'channel_name'=>$channel['name'],'channel_enabled'=>(int)$channel['status']===1];
    }
    public function catalog()
    {
        $result=[];
        foreach($this->db->all('SELECT id,name,plugin,status FROM pre_channel ORDER BY id') as $channel) {
            try {
                foreach($this->jobs($channel['plugin']) as $job)$result[]=$job+['plugin'=>$channel['plugin'],'channel_id'=>(int)$channel['id'],'channel_name'=>$channel['name'],'channel_enabled'=>(int)$channel['status']===1];
            }catch(\Throwable $e){$result[]=['plugin'=>$channel['plugin'],'channel_id'=>(int)$channel['id'],'channel_name'=>$channel['name'],'error'=>$e->getMessage()];}
        }
        return $result;
    }
    public function sync(TaskService $tasks)
    {
        $created=0;$errors=[];
        foreach($this->catalog() as $job) {
            if(isset($job['error'])){$errors[]=$job;continue;}
            if($this->db->row("SELECT id FROM pre_task WHERE action='plugin' AND channel_id=? AND source=0 AND job_key=?",[$job['channel_id'],$job['key']]))continue;
            try {
                $tasks->save(['id'=>0,'revision'=>0,'action'=>'plugin','params'=>['channel'=>$job['channel_id'],'job'=>$job['key']],
                    'enabled'=>$job['channel_enabled']?1:0,'schedule_type'=>'interval','interval_seconds'=>$job['interval_seconds'],'daily_at'=>null,'acknowledge_failure'=>0]);$created++;
            } catch (\lib\AdminApp\ApiError $e) {
                if($e->status!==409||!$this->db->row("SELECT id FROM pre_task WHERE action='plugin' AND channel_id=? AND source=0 AND job_key=?",[$job['channel_id'],$job['key']]))throw $e;
            }
        }
        return ['created'=>$created,'errors'=>$errors];
    }
    /** Scheduler maintenance advances one channel per turn, never all channels. */
    public function syncChannel(TaskService $tasks, array $channel)
    {
        $created=0;$errors=[];
        try {
            foreach($this->jobs($channel['plugin']) as $job) {
                if($this->db->row("SELECT id FROM pre_task WHERE action='plugin' AND channel_id=? AND source=0 AND job_key=?",[$channel['id'],$job['key']]))continue;
                try {
                    $tasks->save(['id'=>0,'revision'=>0,'action'=>'plugin','params'=>['channel'=>$channel['id'],'job'=>$job['key']],
                        'enabled'=>(int)($channel['status']??0)===1?1:0,'schedule_type'=>'interval','interval_seconds'=>$job['interval_seconds'],'daily_at'=>null,'acknowledge_failure'=>0]);$created++;
                } catch (\lib\AdminApp\ApiError $e) {
                    if($e->status!==409||!$this->db->row("SELECT id FROM pre_task WHERE action='plugin' AND channel_id=? AND source=0 AND job_key=?",[$channel['id'],$job['key']]))throw $e;
                }
            }
        } catch (\PDOException $e) { throw $e; }
        catch (\Throwable $e) { $errors[]=['channel_id'=>$channel['id'],'error'=>$e->getMessage()]; }
        return ['created'=>$created,'errors'=>$errors];
    }
    public function execute($channelId,$jobKey,$expectedPlugin=null)
    {
        $job=$this->resolve($channelId,$jobKey,$expectedPlugin);
        if(!$job['channel_enabled'])throw new \RuntimeException('通道已停用，插件任务暂停执行');
        $channel=\lib\Channel::get($channelId);
        if(!$channel)throw new \RuntimeException('无法加载通道配置');
        $class=\lib\AdminApp\Runtime::prepare($channel,$job['method']);
        $method=new \ReflectionMethod($class,$job['method']);
        if(!$method->isPublic()||!$method->isStatic())throw new \RuntimeException('插件任务方法必须为 public static');
        if($method->getNumberOfRequiredParameters()>2||(!$method->isVariadic()&&$method->getNumberOfParameters()!==2))throw new \RuntimeException('插件任务必须接收 channel 和 context');
        foreach($method->getParameters() as $parameter)if($parameter->isPassedByReference())throw new \RuntimeException('插件任务不能使用引用参数');
        $GLOBALS['channel']=$channel;
        if(!defined('IN_PLUGIN'))define('IN_PLUGIN',true);
        if(!defined('PAY_ROOT'))define('PAY_ROOT',$this->plugins.'/'.$job['plugin'].'/');
        $context=($GLOBALS['epay_task_context']??[])+['channel_id'=>$channelId,'job'=>$jobKey,'now'=>date(DATE_ATOM)];
        $result=$class::{$job['method']}($channel,$context);
        if(is_array($result)) {
            if(!isset($result['code'])||!is_int($result['code'])||!is_string($result['msg']??null))throw new \RuntimeException('插件任务必须返回 code 和 msg');
            if($result['code']!==0)throw new \RuntimeException($result['msg']);
            return $result['msg'];
        }
        if($result!==null&&!is_string($result))throw new \RuntimeException('插件任务返回值无效');
        return $result??'插件任务执行完成';
    }
}

