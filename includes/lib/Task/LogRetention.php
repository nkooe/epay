<?php
namespace lib\Task;

use lib\AdminApp\Store;

/** Deletes expired task diagnostics only; never deletes business data or active work. */
final class LogRetention
{
    public const SUCCESS_DAYS = 30;
    public const FAILURE_DAYS = 90;
    public const BATCH_SIZE = 500;
    private $db;
    private $successDays;
    private $failureDays;
    private $activeCleanupId = 0;

    public function __construct(Store $db, $successDays = self::SUCCESS_DAYS, $failureDays = self::FAILURE_DAYS, array $context = null)
    {
        $this->db = $db;
        $this->successDays = Definitions::integer($successDays, 1, 3650, '成功记录保留天数');
        $this->failureDays = Definitions::integer($failureDays, 1, 3650, '失败记录保留天数');
        if ($context !== null) {
            $id = Definitions::integer($context['task_id'] ?? null, 1, 2147483647, '清理任务 ID');
            $token = $context['run_token'] ?? null;
            if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token) ||
                !$db->row("SELECT id FROM pre_task WHERE id=? AND action='cleanup_logs' AND last_status='running' AND lock_token=?", [$id, $token])) {
                throw new \RuntimeException('清理任务执行身份不匹配');
            }
            $this->activeCleanupId = $id;
        }
    }

    public static function policy()
    {
        return ['success_days'=>self::SUCCESS_DAYS, 'failure_days'=>self::FAILURE_DAYS];
    }

    public function prune(\DateTimeImmutable $now = null)
    {
        $now = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $dates = [$now->modify('-'.$this->successDays.' days')->format('Y-m-d H:i:s'),
            $now->modify('-'.$this->failureDays.' days')->format('Y-m-d H:i:s')];

        // Keep the latest execution and all evidence of a currently failed/locked task.
        // A terminal status without a finish timestamp is incomplete, not disposable.
        $runs = "finished_at IS NOT NULL AND ((status='success' AND finished_at<?)
            OR (status IN ('failed','interrupted') AND finished_at<?))
            AND NOT EXISTS (SELECT 1 FROM pre_task t WHERE t.id=pre_task_run.task_id
                AND (t.last_status='failed' OR ((t.lock_token IS NOT NULL OR t.last_status='running') AND t.id<>".$this->activeCleanupId.")
                    OR t.started_at IS NULL OR pre_task_run.started_at>=t.started_at))";
        $requests = "finished_at IS NOT NULL AND ((state IN ('completed','rejected') AND finished_at<?)
            OR (state IN ('failed','interrupted') AND finished_at<?))
            AND NOT EXISTS (SELECT 1 FROM pre_task t WHERE t.id=pre_task_request.task_id
                AND (t.last_status='failed' OR ((t.lock_token IS NOT NULL OR t.last_status='running') AND t.id<>".$this->activeCleanupId.")
                    OR pre_task_request.revision>=t.revision))";

        $history = $this->batch('task_run', $runs, $dates);
        $queue = $this->batch('task_request', $requests, $dates);
        return ['history_deleted'=>$history['deleted'], 'requests_deleted'=>$queue['deleted'],
            'more'=>$history['more'] || $queue['more'],
            'policy'=>['success_days'=>$this->successDays, 'failure_days'=>$this->failureDays]];
    }

    private function batch($table, $predicate, array $dates)
    {
        $ids = array_column($this->db->all('SELECT id FROM pre_'.$table.' WHERE '.$predicate.
            ' ORDER BY id LIMIT '.self::BATCH_SIZE, $dates), 'id');
        if (!$ids) return ['deleted'=>0, 'more'=>false];
        // Recheck safety at deletion time: a task may have started after SELECT.
        $deleted = $this->db->query('DELETE FROM pre_'.$table.' WHERE id IN ('.
            implode(',', array_fill(0, count($ids), '?')).') AND '.$predicate,
            array_merge($ids, $dates))->rowCount();
        return ['deleted'=>$deleted, 'more'=>count($ids) === self::BATCH_SIZE];
    }

    public function pruneSupervision(\DateTimeImmutable $now=null)
    {
        $now=($now??new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai')))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $dates=[$now->modify('-'.$this->successDays.' days')->format('Y-m-d H:i:s'),
            $now->modify('-'.$this->failureDays.' days')->format('Y-m-d H:i:s')];
        $execution="active_task_id IS NULL AND finished_at IS NOT NULL AND
            ((state='success' AND finished_at<?) OR (state IN ('failed','rejected','interrupted') AND finished_at<?))
            AND NOT EXISTS (SELECT 1 FROM pre_task t WHERE t.id=pre_task_execution.task_id
                AND (t.last_status='failed' OR ((t.lock_token IS NOT NULL OR t.last_status='running') AND t.id<>".$this->activeCleanupId.") OR pre_task_execution.revision>=t.revision-2))";
        $operation="finished_at IS NOT NULL AND ((state='completed' AND finished_at<?) OR (state='failed' AND finished_at<?))
            AND NOT EXISTS (SELECT 1 FROM pre_plugin_service s WHERE s.id=pre_service_operation.service_id
                AND (s.observed='attention' OR pre_service_operation.revision>=s.revision-1))";
        $runs=$this->batch('task_execution',$execution,$dates);$ops=$this->batch('service_operation',$operation,$dates);
        return ['executions_deleted'=>$runs['deleted'],'operations_deleted'=>$ops['deleted'],'more'=>$runs['more']||$ops['more']];
    }

    /** Bounded CLI work; remaining expired rows can be safely processed on the next run. */
    public function execute()
    {
        $totals=['history_deleted'=>0,'requests_deleted'=>0,'executions_deleted'=>0,'operations_deleted'=>0];
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai'));
        $deadline=microtime(true)+30;
        for($batch=0;$batch<100;$batch++) {
            $history=$this->prune($now);$supervision=$this->pruneSupervision($now);
            foreach($totals as $key=>$count)$totals[$key]+=$history[$key]??$supervision[$key]??0;
            $more=$history['more']||$supervision['more'];
            if(!$more||microtime(true)>=$deadline)break;
        }
        return '已清理执行历史 '.$totals['history_deleted'].' 条、已结束请求 '.$totals['requests_deleted'].
            ' 条、运行记录 '.$totals['executions_deleted'].' 条、服务操作 '.$totals['operations_deleted'].
            ' 条；成功记录保留 '.$this->successDays.' 天，失败与中断记录保留 '.$this->failureDays.' 天。'.
            ($more?'本轮达到处理上限，剩余过期记录下次继续清理。':'');
    }
}
