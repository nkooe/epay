<?php
namespace lib\Complaint;

use lib\AdminApp\Store;

/** Durable read-only synchronization progress, independent of complaint actions. */
final class SyncState
{
    private $db;
    public function __construct(Store $db){$this->db=$db;}
    public function ensure($id)
    {
        $insert=$this->db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
        $this->db->query($insert." INTO pre_complaint_sync (account_id,state,next_at,updated_at) VALUES (?,'pending',?,?)",[$id,date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
        return $this->get($id);
    }
    public function get($id){return $this->db->row('SELECT * FROM pre_complaint_sync WHERE account_id=?',[$id]);}
    public function update($id,array $data){$this->db->update('complaint_sync',$data+['updated_at'=>date('Y-m-d H:i:s')],'account_id',$id);}
    public static function message(\Throwable $e){return mb_substr(\lib\Task\SystemdController::redact($e->getMessage()),0,1000);}
    public function request($id)
    {
        $this->ensure($id);
        $this->db->query("UPDATE pre_complaint_sync SET state='pending',processed=CASE WHEN `cursor` IS NULL THEN 0 ELSE processed END,started_at=CASE WHEN `cursor` IS NULL THEN NULL ELSE started_at END,failures=0,last_error=NULL,next_at=?,updated_at=? WHERE account_id=? AND state<>'running'",[date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$id]);
    }
    public function reconcile(array $account)
    {
        $row=$this->ensure($account['id']);
        $digest=hash('sha256',($account['credentials']??'')."\0".($account['plugin']??'')."\0".($account['config']??''));
        if(!hash_equals($row['config_digest'],$digest)&&$row['state']!=='running'){
            // A task can claim the channel between the discovery read and this write.
            $this->db->query("UPDATE pre_complaint_sync SET config_digest=?,state='pending',`cursor`=NULL,processed=0,failures=0,last_error=NULL,next_at=?,updated_at=? WHERE account_id=? AND config_digest=? AND state<>'running' AND NOT EXISTS (SELECT 1 FROM pre_task t WHERE t.action='complain' AND t.channel_id=? AND (t.lock_token IS NOT NULL OR EXISTS (SELECT 1 FROM pre_task_execution e WHERE e.active_task_id=t.id)))",[$digest,date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$account['id'],$row['config_digest'],$account['channel']]);
        }
    }
    public function nextForChannel($channel,$daily)
    {
        $next=$this->db->value('SELECT MIN(s.next_at) FROM pre_complaint_sync s INNER JOIN pre_complaint_account a ON a.id=s.account_id WHERE a.channel=? AND a.enabled=1',[$channel]);
        return $next&&$next<$daily?$next:$daily;
    }
    public function status(array $account,$task=null)
    {
        $row=$this->get($account['id']);
        $labels=['pending'=>'等待同步','running'=>'正在同步','waiting'=>'等待重试','blocked'=>'等待修复配置或权限','success'=>'同步成功'];
        $state=!(int)$account['enabled']?'paused':($row['state']??'pending');
        if($state!=='paused'&&$task&&$task['last_status']==='failed')$state='attention';
        return ['state'=>$state,'label'=>$state==='paused'?'已暂停':($state==='attention'?'同步任务需恢复':($labels[$state]??'等待同步')),
            'last_success_at'=>$row['last_success_at']??null,'last_count'=>(int)($row['last_count']??0),
            'processed'=>(int)($row['processed']??0),'failures'=>(int)($row['failures']??0),
            'next_at'=>$state==='paused'?null:($row['next_at']??($task['next_run_at']??null)),
            'error'=>$row['last_error']??($task['last_error']??null),
            'checked_at'=>$row['checked_at']??null,'check_ok'=>isset($row['check_ok'])?(bool)$row['check_ok']:null,
            'check_message'=>$row['check_message']??null];
    }
    public function failed($id,\Throwable $error,$local=false)
    {
        $row=$this->get($id);$failures=(int)$row['failures']+1;
        $transient=$error instanceof TransientFailure||($error instanceof \lib\AdminApp\ApiError&&$error->status===409);
        $delays=[60,300,900];$retry=$transient&&$failures<=count($delays);
        $next=$retry?date('Y-m-d H:i:s',time()+$delays[$failures-1]):($local?date('Y-m-d H:i:s',time()+300):null);
        $this->update($id,['state'=>$transient?'waiting':'blocked','failures'=>$failures,'last_error'=>self::message($error),'next_at'=>$next]);
    }
}
