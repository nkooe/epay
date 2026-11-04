<?php
namespace lib\Task;

use lib\AdminApp\Store;

final class ServiceSupervisor
{
    private $db;private $registry;private $ctl;private $limit;
    public function __construct(Store $db,ServiceRegistry $registry,Controller $ctl,$limit)
    {$this->db=$db;$this->registry=$registry;$this->ctl=$ctl;$this->limit=$limit;}
    private function operation(array $op,$state,$message='')
    {
        $this->db->query('UPDATE pre_service_operation SET state=?,finished_at=?,message=? WHERE id=?',
            [$state,in_array($state,['completed','failed'],true)?date('Y-m-d H:i:s'):null,mb_substr(Controller::redact($message),0,1000),$op['id']]);
    }
    private function failure(array $row,\Throwable $error,$live)
    {
        $message=mb_substr(Controller::redact($error->getMessage()),0,1000);
        $this->db->query("UPDATE pre_plugin_service SET observed='backoff',last_error=?,heartbeat_at=? WHERE id=?",[$message,date('Y-m-d H:i:s'),$row['id']]);
        error_log('通道 '.$row['channel_id'].' 任务执行失败：'.$message.'；下轮继续');
        if($live)$this->ctl->instance('plugin','stop',ServiceRegistry::instance($row));
    }
    private function launch(array $row,array $item)
    {
        if((int)$row['generation']>0)$this->ctl->instance('plugin','ack',ServiceRegistry::instance($row));
        $count=$this->db->query("UPDATE pre_plugin_service SET generation=generation+1,token=NULL,observed='starting',ready_at=NULL,started_at=?,digest=?,name=? WHERE id=? AND revision=? AND generation=? AND desired=1",
            [date('Y-m-d H:i:s'),$item['digest'],$item['name'],$row['id'],$row['revision'],$row['generation']])->rowCount();
        if($count!==1)return false;
        $this->ctl->instance('plugin','start',ServiceRegistry::instance($this->registry->get($row['id'])));return true;
    }
    private function collect(array $results,$deadline)
    {
        foreach($results as $result){
            if(hrtime(true)>=$deadline)break;
            $instance=$result['id'];
            if(!preg_match('/^([a-f0-9]{32})g([1-9][0-9]{0,8})$/D',$instance,$match))throw new \RuntimeException('已完成插件实例编号无效');
            $unit=$result['unit'];
            if(!in_array($unit['active_state'],['inactive','failed'],true))throw new \RuntimeException('插件结果尚未结束，拒绝回收');
            $this->db->pdo->beginTransaction();
            try{
                $row=$this->db->row('SELECT * FROM pre_plugin_service WHERE id=?'.$this->db->lockClause(),[$match[1]]);
                if($row&&(int)$row['generation']===(int)$match[2]){
                    $success=$unit['result']==='success'&&(int)$unit['exit_status']===0;
                    $message=$success?null:($row['last_error']?:'本轮任务进程异常退出，下轮继续');
                    $observed=!(int)$row['desired']?'stopped':($success?'idle':'backoff');
                    $finished=$row['token']===null&&in_array($row['observed'],['idle','backoff','stopped'],true)&&$row['heartbeat_at']!==null?$row['heartbeat_at']:date('Y-m-d H:i:s');
                    $this->db->query('UPDATE pre_plugin_service SET observed=?,token=NULL,heartbeat_at=?,last_error=? WHERE id=? AND generation=?',[$observed,$finished,$message,$row['id'],$row['generation']]);
                    $this->db->query("UPDATE pre_service_operation SET state=?,finished_at=?,message=? WHERE service_id=? AND state='starting'",[$success?'completed':'failed',date('Y-m-d H:i:s'),mb_substr(Controller::redact($message?:'本轮处理完成'),0,1000),$row['id']]);
                }
                $this->db->pdo->commit();
            }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
            $this->ctl->instance('plugin','ack',$instance);
        }
    }
    public function tick($draining=false)
    {
        $deadline=hrtime(true)+4000000000;
        $completed=$this->ctl->pluginResults();$this->collect($completed['results'],$deadline);
        $reserved="(token IS NOT NULL OR observed IN ('starting','waiting','running','draining'))";
        $active=$this->db->all('SELECT * FROM pre_plugin_service WHERE '.$reserved.' ORDER BY id LIMIT 33');
        if(count($active)>32)throw new \RuntimeException('活动通道任务超过并发上限');
        $pending=$this->db->all("SELECT * FROM pre_plugin_service s WHERE NOT ".$reserved." AND (desired=1 OR EXISTS (SELECT 1 FROM pre_service_operation o WHERE o.service_id=s.id AND o.state IN ('pending','starting'))) ORDER BY COALESCE(heartbeat_at,started_at,'1970-01-01'),id LIMIT 16");
        $liveCount=max((int)$completed['active_count'],count($active));$this->registry->beginTurn();
        try{
            foreach(array_merge($active,$pending) as $row){
                if(hrtime(true)>=$deadline)break;
                $counted=$row['token']!==null||in_array($row['observed'],['starting','waiting','running','draining'],true);
                $unit=(int)$row['generation']>0?$this->ctl->instance('plugin','status',ServiceRegistry::instance($row)):['active_state'=>'inactive','result'=>'unknown'];
                $live=in_array($unit['active_state'],['active','activating','deactivating','reloading'],true);
                $grace=$row['observed']==='starting'&&strtotime($row['started_at']??'1970-01-01')>time()-15;
                $op=$this->db->row("SELECT * FROM pre_service_operation WHERE service_id=? AND state IN ('pending','starting') ORDER BY created_at,id LIMIT 1",[$row['id']]);
                if($draining)continue;
                try{
                    if((int)$row['generation']>0&&in_array($unit['active_state'],['inactive','failed'],true)&&($unit['result']??'unknown')!=='unknown'){
                        $this->collect([['id'=>ServiceRegistry::instance($row),'unit'=>$unit]],$deadline);
                        continue;
                    }
                    if(!(int)$row['desired']){
                        if($live){$this->ctl->instance('plugin','stop',ServiceRegistry::instance($row));$this->db->query("UPDATE pre_plugin_service SET observed='draining' WHERE id=?",[$row['id']]);}
                        else{
                            if((int)$row['generation']>0)$this->ctl->instance('plugin','ack',ServiceRegistry::instance($row));
                            $this->db->query("UPDATE pre_plugin_service SET observed='stopped',token=NULL,ready_at=NULL WHERE id=?",[$row['id']]);
                            if($op)$this->operation($op,'completed','通道已关闭，任务已停止');
                        }
                        continue;
                    }
                    $item=$this->registry->resolve($row,true);
                    $round=$item['spec']['scope']==='target';
                    $changed=!hash_equals($row['digest'],$item['digest']);
                    if($live){
                        if($changed||($op&&$op['state']==='pending')){
                            if($row['observed']!=='draining'){$this->ctl->instance('plugin','stop',ServiceRegistry::instance($row));$this->db->query("UPDATE pre_plugin_service SET observed='draining' WHERE id=?",[$row['id']]);}
                            continue;
                        }
                        $elapsed=time()-strtotime($row['started_at']??'1970-01-01');
                        if($round&&$elapsed>30)throw new \RuntimeException('本轮通道任务超时，回收后继续下轮');
                        if(!$round){
                            if($row['ready_at']===null&&$elapsed>$item['spec']['ready_seconds'])throw new \RuntimeException('通道任务启动超时');
                            if($row['ready_at']!==null&&strtotime($row['heartbeat_at']??'1970-01-01')<time()-$item['spec']['heartbeat_seconds'])throw new \RuntimeException('通道任务执行超时');
                        }
                        if($op&&$row['ready_at']!==null)$this->operation($op,'completed','任务已接管');
                        continue;
                    }
                    if($grace)continue;
                    if((int)$row['generation']>0&&$counted){
                        $this->collect([['id'=>ServiceRegistry::instance($row),'unit'=>$unit]],$deadline);
                        continue;
                    }
                    if(!$changed&&(!$op||$op['state']!=='pending')&&strtotime($row['heartbeat_at']??'1970-01-01')>time()-Automatic::POLL_SECONDS)continue;
                    if($liveCount>=$this->limit)continue;
                    if($this->launch($row,$item)){$liveCount++;if($op)$this->operation($op,'starting','本轮处理已派发');}
                }catch(\PDOException $e){throw $e;}
                catch(\Throwable $e){$this->failure($row,$e,$live);if($op)$this->operation($op,'failed',$e->getMessage());}
            }
            return $liveCount;
        }finally{$this->registry->endTurn();}
    }
}
