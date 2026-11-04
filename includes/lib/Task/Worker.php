<?php
namespace lib\Task;
use lib\AdminApp\Store;


final class Worker
{
    private $db;private $config;private $children=[];private $stopping=false;private $token;
    private $lock;private $lastSync=0;private $lastBeat=0;
    private $controller;private $executions;private $services;private $progress;private $reapBacklog=false;private $reapCursor='';private $activeCount=0;
    private $syncCursor=0;private $syncedPlugins=[];private $retiredTaskCursor=0;private $retiredPluginCursor='';
    public function __construct(Store $db,array $config,Controller $controller=null)
    {
        $this->db=$db;$this->config=$config;$this->token=bin2hex(random_bytes(16));
        $this->controller=$controller??new Controller($config);$this->executions=new Execution($db);
        $this->services=new ServiceSupervisor($db,new ServiceRegistry($db,$config['root'].'/plugins'),$this->controller,$config['service_limit']);
        $this->lock='epay.worker.'.substr(hash('sha256',$db->value('SELECT DATABASE()').':'.$db->tablePrefix()),0,48);
    }
    private function log($message){error_log('[epay-task-worker] '.Controller::redact($message));}
    private function beat($state)
    {
        $active=[];foreach($this->children as $run)$active[]=['task_id'=>(int)$run['task_id'],'run_id'=>$run['id'],'state'=>$run['state']];
        $this->db->query("UPDATE pre_task_worker SET state=?,heartbeat_at=?,active_tasks=? WHERE id=1 AND token=?",
            [$state,date('Y-m-d H:i:s'),json_encode($active,JSON_THROW_ON_ERROR),$this->token]);
        if((int)$this->db->value('SELECT IS_USED_LOCK(?)',[$this->lock])!==(int)$this->db->value('SELECT CONNECTION_ID()'))throw new \RuntimeException('调度主锁已丢失');
        $this->lastBeat=hrtime(true);
        $this->progress->advance($state);
    }
    public function run()
    {
        if((int)$this->db->value('SELECT GET_LOCK(?,0)',[$this->lock])!==1)throw new \RuntimeException('同一数据库已有调度器运行');
        $this->progress=new Progress();
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM,function(){$this->stopping=true;});pcntl_signal(SIGINT,function(){$this->stopping=true;});
        $this->db->query("INSERT INTO pre_task_worker (id,token,pid,state,heartbeat_at,started_at,active_tasks) VALUES (1,?,?,'running',?,?,'[]')
            ON DUPLICATE KEY UPDATE token=VALUES(token),pid=VALUES(pid),state=VALUES(state),heartbeat_at=VALUES(heartbeat_at),started_at=VALUES(started_at),active_tasks=VALUES(active_tasks)",
            [$this->token,getmypid(),date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
        $this->db->query("UPDATE pre_task_request SET state='interrupted',finished_at=?,message='旧执行器请求缺少运行编号，需核查；不自动重放'
            WHERE state='running' AND NOT EXISTS (SELECT 1 FROM pre_task_execution e WHERE e.request_id=pre_task_request.id)",[date('Y-m-d H:i:s')]);
        Health::request($this->db,$this->token);
        $this->log('调度启动 '.$this->config['service']);
        try{
            do{
                if(hrtime(true)-$this->lastBeat>=1000000000){
                    $deadline=hrtime(true)+8000000000;
                    $this->reap($deadline);


                    $accepting=$this->stopping||$this->controller->accepting();
                    $this->services->tick($this->stopping||!$accepting);
                    if(!$this->stopping&&$accepting){
                        $probeBusy=Health::tick($this->db,$this->controller,$this->token,!$this->reapBacklog&&count($this->children)<$this->config['concurrency']);
                        if(!$probeBusy&&!$this->reapBacklog&&hrtime(true)<$deadline&&count($this->children)<$this->config['concurrency'])$this->dispatch($deadline);
                        if(hrtime(true)<$deadline&&time()-$this->lastSync>=Automatic::POLL_SECONDS){
                            $syncEnd=min($deadline,hrtime(true)+2000000000);
                            for($i=0;$i<128&&hrtime(true)<$syncEnd;$i++){$this->syncChannel();if($this->syncCursor===0)break;}
                        }
                    }


                    $this->beat($this->stopping?'draining':'running');
                }



                if($this->stopping&&!$this->children&&!$this->reapBacklog)break;
                usleep(200000);
            }while(true);
            $this->beat('stopped');
        }catch(\Throwable $e){

            $this->log('调度已停止：'.$e->getMessage());throw $e;
        }finally{try{$this->db->value('SELECT RELEASE_LOCK(?)',[$this->lock]);}catch(\Throwable $ignored){}}
        $this->log('在途任务已结束，调度退出');
    }
    private function resourceFree(array $task)
    {
        $plugin=0;
        foreach($this->children as $run){
            if($run['resource_key']===Definitions::resource($task))return false;
            if(strpos($run['resource_key'],'plugin:')===0)$plugin++;
        }
        if($task['action']==='plugin'){
            if($plugin>=1)return false;
            if($this->db->value("SELECT COUNT(*) FROM pre_plugin_service WHERE token IS NOT NULL AND (resource_key=? OR resource_key LIKE 'shared:%')",[Definitions::resource($task)])>0)return false;
        }
        return true;
    }
    private function syncChannel()
    {
        $channel=$this->db->row('SELECT id,plugin,status FROM pre_channel WHERE id>? ORDER BY id LIMIT 1',[$this->syncCursor]);
        $automatic=new Automatic($this->db,$this->config['root'].'/plugins');
        if(!$channel){
            $retired=$this->db->row("SELECT DISTINCT t.channel_id FROM pre_task t WHERE t.channel_id>? AND t.action IN ('plugin','complain') AND NOT EXISTS (SELECT 1 FROM pre_channel c WHERE c.id=t.channel_id) ORDER BY t.channel_id LIMIT 1",[$this->retiredTaskCursor]);
            if($retired){$automatic->retireChannel($retired['channel_id']);$this->retiredTaskCursor=(int)$retired['channel_id'];return;}
            $retired=$this->db->row('SELECT DISTINCT s.plugin FROM pre_plugin_service s WHERE s.plugin>? AND NOT EXISTS (SELECT 1 FROM pre_channel c WHERE c.plugin=s.plugin) ORDER BY s.plugin LIMIT 1',[$this->retiredPluginCursor]);
            if($retired){$automatic->services($retired['plugin']);$this->retiredPluginCursor=$retired['plugin'];return;}
            $this->db->query("INSERT INTO pre_config(k,v) VALUES ('task_channel_scan',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[$this->token]);
            $this->syncCursor=0;$this->syncedPlugins=[];$this->retiredTaskCursor=0;$this->retiredPluginCursor='';$this->lastSync=time();return;
        }
        try{
            $synced=$automatic->channel($channel);$errors=$synced['errors'];
            if(!isset($this->syncedPlugins[$channel['plugin']])){
                $services=$automatic->services($channel['plugin']);$errors=array_merge($errors,$services['errors']);$this->syncedPlugins[$channel['plugin']]=true;
            }
            foreach($errors as $error)$this->log('通道 '.$error['channel_id'].'：'.$error['error']);
        }catch(\PDOException $e){throw $e;}
        catch(\Throwable $e){$this->log('通道 '.$channel['id'].' 自动接管失败：'.$e->getMessage().'；下轮继续');}
        $this->syncCursor=(int)$channel['id'];
    }
    private function dispatch($deadline)
    {
        $registry=new PluginRegistry($this->db,$this->config['root'].'/plugins');
        $tasks=new TaskService($this->db,[CronHandlers::class,'execute'],$registry);
        $requests=$this->db->all("SELECT r.*,t.action FROM pre_task_request r LEFT JOIN pre_task t ON t.id=r.task_id WHERE r.state='pending'
            ORDER BY CASE WHEN t.action IN ('notify','notify2') THEN 0 ELSE 1 END,r.created_at,r.id LIMIT 16");
        $examined=0;
        foreach($requests as $request){
            if(++$examined>8||hrtime(true)>=$deadline)return;
            $task=$this->db->row('SELECT * FROM pre_task WHERE id=?',[$request['task_id']]);
            if(!$task||(int)$task['revision']!==(int)$request['revision']||$task['lock_token']!==null||($task['last_status']==='failed'&&!Definitions::automatic($task))){
                $this->db->query("UPDATE pre_task_request SET state='rejected',finished_at=?,message='任务已更新或需核查' WHERE id=? AND state='pending'",[date('Y-m-d H:i:s'),$request['id']]);continue;
            }
            if(!$this->resourceFree($task)||isset($this->children[$task['id']]))continue;
            $this->spawn($task,$request,$tasks,$registry);return;
        }
        $due=$this->db->all("SELECT * FROM pre_task WHERE enabled=1 AND next_run_at<=? AND lock_token IS NULL AND (last_status<>'failed' OR action IN ('plugin','complain'))
            ORDER BY CASE WHEN action IN ('notify','notify2') THEN 0 ELSE 1 END,next_run_at,id LIMIT 16",[date('Y-m-d H:i:s')]);
        $examined=0;
        foreach($due as $task){
            if(++$examined>8||hrtime(true)>=$deadline)return;
            if(isset($this->children[$task['id']])||!$this->resourceFree($task))continue;
            $this->spawn($task,null,$tasks,$registry);return;
        }
    }
    private function spawn(array $task,$request,TaskService $tasks,PluginRegistry $registry)
    {
        try{
            $tasks->assertAvailable($task);$timeout=900;$digest='';
            if($task['action']==='plugin'){
                $job=$registry->resolve($task['channel_id'],$task['job_key'],$task['plugin']);
                $timeout=$job['timeout_seconds'];$digest=$registry->fingerprint($task['channel_id'],$task['plugin']);
            }
        }catch(\PDOException $e){throw $e;}
        catch(\Throwable $e){
            $enabled=Definitions::automatic($task)&&(int)$this->db->value('SELECT status FROM pre_channel WHERE id=?',[$task['channel_id']])===1;
            $next=$enabled?Schedule::next($task,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai'))):null;
            $this->db->query("UPDATE pre_task SET enabled=?,next_run_at=?,last_status='failed',last_error=? WHERE id=? AND revision=?",
                [(int)$enabled,$next,mb_substr(Controller::redact($e->getMessage()),0,1000),$task['id'],$task['revision']]);
            if($request)$this->db->query("UPDATE pre_task_request SET state='rejected',finished_at=?,message='能力校验失败，请核查' WHERE id=?",[date('Y-m-d H:i:s'),$request['id']]);
            return;
        }
        $id=$this->executions->reserve($task,$request,$timeout,$digest);
        $this->controller->instance('job','start',$id);
        $this->children[$task['id']]=$this->db->row('SELECT * FROM pre_task_execution WHERE id=?',[$id]);
    }
    private function reap($deadline)
    {
        $this->activeCount=(int)$this->db->value('SELECT COUNT(*) FROM pre_task_execution WHERE active_task_id IS NOT NULL');
        if($this->activeCount===0){$this->children=[];$this->reapBacklog=false;$this->reapCursor='';return;}



        $this->reapBacklog=$this->activeCount>16;
        $active=$this->executions->activePage($this->reapBacklog?$this->reapCursor:'',16);
        if(!$active&&$this->reapBacklog){$this->reapCursor='';return;}
        $this->children=[];
        $page=array_slice($active,0,16);
        foreach($page as $run)$this->children[$run['task_id']]=$run;
        $lastProcessed=null;
        foreach($page as $run){
            if(hrtime(true)>=$deadline){$this->reapBacklog=true;break;}
            $unit=$this->controller->instance('job','status',$run['id']);
            if($run['state']==='running'&&$run['claimed_at']&&strtotime($run['claimed_at'])<time()-(int)$run['timeout_seconds']-5&&$unit['active_state']==='active')$this->controller->instance('job','stop',$run['id']);
            if($this->executions->reap($run,$unit)){
                $this->controller->instance('job','ack',$run['id']);
                unset($this->children[$run['task_id']]);
            }
            $lastProcessed=$run['id'];
        }


        $this->reapCursor=$this->reapBacklog&&$lastProcessed!==null?$lastProcessed:'';
    }
}

