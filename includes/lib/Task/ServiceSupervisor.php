<?php
namespace lib\Task;

use lib\AdminApp\Store;

/** Coordinates desired configuration; systemd alone retries crashed processes. */
final class ServiceSupervisor
{
    private $db;private $registry;private $ctl;private $limit;private $pendingCursor='';private $attentionCursor='';
    public function __construct(Store $db,ServiceRegistry $registry,SystemdController $ctl,$limit)
    {$this->db=$db;$this->registry=$registry;$this->ctl=$ctl;$this->limit=$limit;}
    private function operation(array $op,$state,$message='')
    {
        $this->db->query('UPDATE pre_service_operation SET state=?,finished_at=?,message=? WHERE id=?',
            [$state,in_array($state,['completed','failed'],true)?date('Y-m-d H:i:s'):null,mb_substr(SystemdController::redact($message),0,1000),$op['id']]);
    }
    private function fault(array $row,$message,$live)
    {
        $this->db->query("UPDATE pre_plugin_service SET desired=0,observed='attention',last_error=? WHERE id=?",
            [mb_substr(SystemdController::redact($message),0,1000),$row['id']]);
        if($live)$this->ctl->instance('plugin','stop',ServiceRegistry::instance($row));
    }
    private function launch(array $row)
    {
        $changed=$this->db->query("UPDATE pre_plugin_service SET generation=generation+1,token=NULL,observed='starting',ready_at=NULL,heartbeat_at=NULL,started_at=?,last_error=NULL WHERE id=? AND revision=? AND generation=?",
            [date('Y-m-d H:i:s'),$row['id'],$row['revision'],$row['generation']])->rowCount();
        if($changed!==1)throw new \RuntimeException('插件配置代次变化，拒绝启动');
        $row=$this->registry->get($row['id']);$this->ctl->instance('plugin','start',ServiceRegistry::instance($row));
    }
    public function tick($draining=false)
    {
        $deadline=hrtime(true)+4000000000;
        $reserved="(token IS NOT NULL OR observed IN ('starting','waiting','running','draining','backoff'))";
        // Live services are checked every turn; idle/pending services use a
        // separate keyset page so a large stopped catalog cannot starve them.
        $rows=$this->db->all('SELECT * FROM pre_plugin_service WHERE '.$reserved.' ORDER BY id LIMIT 33');
        if(count($rows)>32)throw new \RuntimeException('活动插件实例超过安全上限，停止调度并核查');
        $liveCount=count($rows);
        $pending=$this->db->all("SELECT * FROM pre_plugin_service s WHERE NOT ".$reserved." AND
            (desired=1 OR EXISTS (SELECT 1 FROM pre_service_operation o WHERE o.service_id=s.id AND o.state IN ('pending','starting')))
            AND id>? ORDER BY id LIMIT 8",[$this->pendingCursor]);
        if(!$pending)$this->pendingCursor='';
        $attention=$this->db->all("SELECT * FROM pre_plugin_service WHERE observed='attention' AND desired=0 AND token IS NULL AND generation>0 AND id>? ORDER BY id LIMIT 8",[$this->attentionCursor]);
        if(!$attention)$this->attentionCursor='';
        $rows=array_merge($rows,$pending,$attention);
        $this->registry->beginTurn();
        try {
        foreach($rows as $row){
            if(hrtime(true)>=$deadline)break;
            $counted=$row['token']!==null||in_array($row['observed'],['starting','waiting','running','draining','backoff'],true);
            if(!$counted&&$row['observed']==='attention')$this->attentionCursor=$row['id'];
            elseif(!$counted)$this->pendingCursor=$row['id'];
            $unit=(int)$row['generation']>0?$this->ctl->instance('plugin','status',ServiceRegistry::instance($row)):['active_state'=>'inactive','result'=>'success'];
            $live=in_array($unit['active_state'],['active','activating','deactivating','reloading'],true);
            $startingGrace=$row['observed']==='starting'&&strtotime($row['started_at']??'1970-01-01')>time()-15;
            $liveCount+=(($live||$startingGrace)?1:0)-($counted?1:0);
            $op=$this->db->row("SELECT * FROM pre_service_operation WHERE service_id=? AND state IN ('pending','starting') ORDER BY created_at,id LIMIT 1",[$row['id']]);
            if($draining){
                if($live&&$row['observed']!=='attention')$this->db->query("UPDATE pre_plugin_service SET observed='draining' WHERE id=?",[$row['id']]);
                continue; // Runner sees the worker's draining heartbeat and closes cooperatively.
            }
            try{
                if($op&&$op['state']==='pending'){
                    if($live){
                        if($row['observed']!=='draining'){
                            $this->db->query("UPDATE pre_plugin_service SET observed='draining' WHERE id=?",[$row['id']]);
                            $this->ctl->instance('plugin','stop',ServiceRegistry::instance($row));
                        }
                        continue;
                    }
                    if($op['action']==='stop'){
                        $this->db->query("UPDATE pre_plugin_service SET observed=CASE WHEN observed='attention' THEN observed ELSE 'stopped' END,token=NULL,ready_at=NULL,heartbeat_at=NULL WHERE id=?",[$row['id']]);
                        $this->operation($op,'completed','服务已停止');continue;
                    }
                    $item=$this->registry->resolve($row,$op['action']==='refresh');
                    if($liveCount>=$this->limit){
                        $message='插件服务并发已达上限 '.$this->limit.'，请先停止其他插件，或通过安装器 --service-limit 调整容量后重试';
                        if ($op['message']==='随通道配置自动接管') {
                            if ($row['last_error']!==$message) $this->db->query('UPDATE pre_plugin_service SET last_error=? WHERE id=?',[$message,$row['id']]);
                            continue; // Keep one durable request while waiting for capacity.
                        }
                        $this->db->query('UPDATE pre_plugin_service SET desired=0,last_error=? WHERE id=?',[$message,$row['id']]);
                        $this->operation($op,'failed',$message);continue;
                    }
                    if($op['action']==='refresh'){
                        $this->db->query('UPDATE pre_plugin_service SET digest=?,name=? WHERE id=?',[$item['digest'],$item['name'],$row['id']]);
                        $row=$this->registry->get($row['id']);
                    }
                    $this->launch($row);$liveCount++;$this->operation($op,'starting','进程启动已提交，等待业务就绪');continue;
                }
                if(!(int)$row['desired']||$row['observed']==='attention'){
                    if($live&&$row['observed']!=='draining'){
                        if($row['observed']!=='attention')$this->db->query("UPDATE pre_plugin_service SET observed='draining' WHERE id=?",[$row['id']]);
                        // Keep attention visible while forcefully converging a
                        // stale cgroup to its disabled desired state.
                        $this->ctl->instance('plugin','stop',ServiceRegistry::instance($row));
                    }
                    if(!$live)$this->db->query("UPDATE pre_plugin_service SET observed=CASE WHEN observed='attention' THEN observed ELSE 'stopped' END,token=NULL WHERE id=?",[$row['id']]);
                    if($op)$this->operation($op,'failed',$row['last_error']?:'服务已停用');continue;
                }
                $item=$this->registry->resolve($row);
                if($unit['active_state']==='failed')throw new ServiceFault('服务启动限流或异常退出，请查看日志后应用配置恢复');
                if(!$live){
                    // An initial dispatch may not have reached systemd yet. Never create a second generation immediately.
                    if($row['observed']==='starting'&&strtotime($row['started_at'])>time()-15)continue;
                    if(in_array($row['observed'],['waiting','running','starting'],true))throw new ServiceFault('服务进程已退出且未恢复，请核查日志');
                    if($liveCount<$this->limit){$this->launch($row);$liveCount++;}
                    continue;
                }
                if(($unit['sub_state']??'')==='auto-restart'){
                    $this->db->query("UPDATE pre_plugin_service SET observed='backoff' WHERE id=?",[$row['id']]);continue;
                }
                if($unit['active_state']==='deactivating')continue;
                if($row['ready_at']===null){
                    if(strtotime($row['started_at']??'1970-01-01')<time()-$item['spec']['ready_seconds'])throw new ServiceFault('等待业务就绪超时');
                }elseif(strtotime($row['heartbeat_at']??'1970-01-01')<time()-$item['spec']['heartbeat_seconds'])throw new ServiceFault('业务心跳超时，禁止继续接收工作');
                if($op&&$row['observed']==='running'&&$row['ready_at']!==null)$this->operation($op,'completed','业务已就绪');
            }catch(\PDOException $e){throw $e;}
            catch(\Throwable $e){$this->fault($row,$e->getMessage(),$live);if($op)$this->operation($op,'failed',$e->getMessage());}
        }
        return $liveCount;
        } finally { $this->registry->endTurn(); }
    }
}
