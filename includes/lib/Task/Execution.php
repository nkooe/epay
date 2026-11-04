<?php
namespace lib\Task;

use lib\AdminApp\Store;
use lib\AdminApp\ApiError;


final class Execution
{
    private $db;
    public function __construct(Store $db) { $this->db=$db; }
    public static function id($value)
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))throw new \InvalidArgumentException('运行编号无效');
        return $value;
    }
    public function active($limit = null)
    {
        $sql='SELECT * FROM pre_task_execution WHERE active_task_id IS NOT NULL ORDER BY created_at,id';
        if($limit!==null){$limit=Definitions::integer($limit,1,64,'活动实例上限');$sql.=' LIMIT '.$limit;}
        return $this->db->all($sql);
    }
    public function activePage($after, $limit)
    {
        $limit=Definitions::integer($limit,1,64,'活动实例分页上限');
        if(!is_string($after)||($after!==''&&!preg_match('/^[a-f0-9]{32}$/D',$after)))throw new \InvalidArgumentException('活动实例分页游标无效');
        $params=[];$where='active_task_id IS NOT NULL';
        if($after!==''){$where.=' AND id>?';$params[]=$after;}
        return $this->db->all('SELECT * FROM pre_task_execution WHERE '.$where.' ORDER BY id LIMIT '.($limit+1),$params);
    }
    public function reserve(array $task,$request,$timeout,$digest='')
    {
        $id=bin2hex(random_bytes(16));
        $this->db->pdo->beginTransaction();
        try {
            $current=$this->db->row('SELECT * FROM pre_task WHERE id=?'.$this->db->lockClause(),[$task['id']]);
            if(!$current||(int)$current['revision']!==(int)$task['revision']||$current['lock_token']!==null||($current['last_status']==='failed'&&!Definitions::automatic($current)))throw new ApiError('任务已变更或需核查',409);
            if($this->db->row('SELECT id FROM pre_task_execution WHERE active_task_id=?',[$task['id']]))throw new ApiError('任务已派发',409);
            $this->db->insert('task_execution',['id'=>$id,'task_id'=>$task['id'],'active_task_id'=>$task['id'],'revision'=>$task['revision'],
                'origin'=>$request?'admin':'scheduler','request_id'=>$request['id']??null,'resource_key'=>Definitions::resource($task),
                'plugin_digest'=>$digest,'timeout_seconds'=>$timeout,'state'=>'queued','created_at'=>date('Y-m-d H:i:s')]);
            if($request && $this->db->query("UPDATE pre_task_request SET state='running',started_at=? WHERE id=? AND state='pending'",[date('Y-m-d H:i:s'),$request['id']])->rowCount()!==1)throw new ApiError('执行请求已被处理',409);
            $this->db->pdo->commit();return $id;
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public function claim($id)
    {
        self::id($id);
        if($this->db->query("UPDATE pre_task_execution SET state='running',claimed_at=? WHERE id=? AND state='queued' AND active_task_id IS NOT NULL",[date('Y-m-d H:i:s'),$id])->rowCount()!==1)throw new ApiError('运行编号已领取或失效',409);
        return $this->db->row('SELECT * FROM pre_task_execution WHERE id=?',[$id]);
    }
    public function finish($id,$state,$message='')
    {
        if(!in_array($state,['success','failed','rejected'],true))throw new \InvalidArgumentException('运行结果无效');
        return $this->db->query("UPDATE pre_task_execution SET state=?,finished_at=?,message=? WHERE id=? AND state='running'",
            [$state,date('Y-m-d H:i:s'),mb_substr(Controller::redact($message),0,2000),self::id($id)])->rowCount();
    }

    public function reap(array $run,array $unit)
    {
        if(in_array($unit['active_state'],['active','activating','deactivating','reloading'],true))return false;
        if($run['state']==='queued'&&strtotime($run['created_at'])>time()-15)return false;
        $this->db->pdo->beginTransaction();
        try {
            $task=$this->db->row('SELECT * FROM pre_task WHERE id=?'.$this->db->lockClause(),[$run['task_id']]);
            $run=$this->db->row('SELECT * FROM pre_task_execution WHERE id=?'.$this->db->lockClause(),[$run['id']]);
            if(!$run||$run['active_task_id']===null){$this->db->pdo->commit();return true;}
            $history=$this->db->row('SELECT status FROM pre_task_run WHERE token=?'.$this->db->lockClause(),[$run['id']]);
            $success=$run['state']==='success'&&$history&&$history['status']==='success'&&($unit['result']??'')==='success'&&(int)($unit['exit_status']??-1)===0;
            $state=$success?'success':($run['state']==='rejected'?'rejected':'interrupted');
            if(!$success&&$run['state']==='failed')$state='failed';
            $message=$run['message']?:'本轮任务进程异常退出，结果未完整确认';
            // 只允许本轮执行收尾，管理员保存后的配置不再属于旧进程。
            $owns=$task&&(
                ($task['lock_token']===$run['id']&&(int)$task['revision']===(int)$run['revision']+1)||
                ($task['lock_token']===null&&(int)$task['revision']===(int)$run['revision']&&!$history)||
                ($task['lock_token']===null&&(int)$task['revision']===(int)$run['revision']+2&&$history&&in_array($history['status'],['success','failed'],true)&&$task['last_status']===$history['status'])
            );
            if(!$success&&$state!=='rejected'&&$owns){

                if(Definitions::automatic($task)){
                    $enabled=(int)$task['enabled']&&(int)$this->db->value('SELECT status FROM pre_channel WHERE id=?',[$task['channel_id']])===1;
                    $next=$enabled?Schedule::next($task,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai'))):null;
                    $this->db->query("UPDATE pre_task SET enabled=?,next_run_at=?,last_status='failed',last_error=?,lock_token=NULL,lock_expires_at=NULL,finished_at=?,revision=revision+1 WHERE id=? AND revision=?",[(int)$enabled,$next,mb_substr($message,0,1000),date('Y-m-d H:i:s'),$run['task_id'],$task['revision']]);
                }else{
                    $this->db->query("UPDATE pre_task SET enabled=0,next_run_at=NULL,last_status='failed',last_error=?,revision=revision+1 WHERE id=? AND revision=?",[mb_substr($message,0,1000),$run['task_id'],$task['revision']]);
                }
            }
            if(!$success&&$task&&Definitions::automatic($task))$this->db->query("UPDATE pre_task_run SET status='interrupted',finished_at=?,error=? WHERE token=? AND status='running'",[date('Y-m-d H:i:s'),mb_substr($message,0,1000),$run['id']]);
            $this->db->query('UPDATE pre_task_execution SET state=?,active_task_id=NULL,finished_at=?,message=? WHERE id=? AND active_task_id IS NOT NULL',[$state,date('Y-m-d H:i:s'),$success?'业务执行成功':$message,$run['id']]);
            if($run['request_id'])$this->db->query("UPDATE pre_task_request SET state=?,finished_at=?,message=? WHERE id=? AND state='running'",[$success?'completed':($state==='rejected'?'rejected':'failed'),date('Y-m-d H:i:s'),$success?'业务执行成功':$message,$run['request_id']]);
            $this->db->pdo->commit();return true;
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
}
