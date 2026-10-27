<?php
namespace lib\Task;
use lib\AdminApp\Store;
use lib\AdminApp\ApiError;

final class Dispatch
{
    private $db;
    public function __construct(Store $db){$this->db=$db;}
    public function heartbeat()
    {
        $row=$this->db->row('SELECT * FROM pre_task_worker WHERE id=1');
        if(!$row)return ['state'=>'stopped','healthy'=>false,'active'=>[],'display_state'=>'stopped','reason'=>'尚无调度心跳'];
        $row['heartbeat_age']=max(0,time()-strtotime($row['heartbeat_at']));
        $fresh=$row['heartbeat_age']<=15;
        $row['healthy']=$row['state']==='running'&&$fresh;
        $row['display_state']=$row['state']==='stopped'?'stopped':($fresh?$row['state']:'timed_out');
        $row['reason']=$row['display_state']==='timed_out'?'主循环心跳超时，看门狗将在30秒无进度时终止并恢复':
            ($row['state']==='draining'?'已停止派发，等待在途任务结束':($row['state']==='stopped'?'调度器已排空停止':'主循环进度正常'));
        $row['active']=json_decode($row['active_tasks'],true,16,JSON_THROW_ON_ERROR);
        unset($row['token']);return $row;
    }
    public function enqueue(TaskService $tasks,$id,$revision)
    {
        $id=Definitions::integer($id,1,2147483647,'任务 ID');$revision=Definitions::integer($revision,1,2147483647,'配置版本');
        if(!$this->heartbeat()['healthy'])throw new ApiError('调度服务未就绪，请先启动并等待心跳正常',409);
        $task=$tasks->get($id);
        $tasks->assertAvailable($task);
        if((int)$task['revision']!==$revision)throw new ApiError('任务已更新，请刷新',409);
        if($task['lock_token']!==null||$task['last_status']==='failed')throw new ApiError('请先核查执行状态后恢复',409);
        $this->db->pdo->beginTransaction();
        try{
            if($this->db->row("SELECT id FROM pre_task_request WHERE task_id=? AND revision=?",[$id,$revision]))throw new ApiError('该版本任务已提交，请刷新查看结果',409);
            $requestId=bin2hex(random_bytes(16));
            $this->db->insert('task_request',['id'=>$requestId,'task_id'=>$id,'revision'=>$revision,'state'=>'pending','created_at'=>date('Y-m-d H:i:s')]);
            $this->db->pdo->commit();return ['status'=>'queued','request_id'=>$requestId,'task_id'=>$id];
        }catch(\Throwable $e){
            if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();
            if($e instanceof \PDOException&&$e->getCode()==='23000')throw new ApiError('任务已提交，请刷新查看结果',409);
            throw $e;
        }
    }
    public function finishRequest($id,$code,$message)
    {
        $this->db->query("UPDATE pre_task_request SET state=?,finished_at=?,message=? WHERE id=? AND state='running'",
            [$code===0?'completed':'failed',date('Y-m-d H:i:s'),mb_strcut($message,0,2000,'UTF-8'),$id]);
    }
}

