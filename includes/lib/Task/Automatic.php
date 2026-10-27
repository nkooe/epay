<?php
namespace lib\Task;

use lib\AdminApp\Store;

/** Channel/account configuration is the source of truth for background work. */
final class Automatic
{
    const POLL_SECONDS = 3;
    const COMPLAINT_TIME = '03:00';
    private $db;
    private $plugins;

    public function __construct(Store $db, $plugins = null)
    {
        $this->db = $db;
        $this->plugins = $plugins ?? ServiceConfig::root().'/plugins';
    }

    public function channel(array $channel)
    {
        $registry = new PluginRegistry($this->db, $this->plugins);
        $tasks = new TaskService($this->db, [CronHandlers::class, 'execute'], $registry);
        $result = $registry->syncChannel($tasks, $channel);
        foreach ($this->db->all("SELECT * FROM pre_task WHERE action='plugin' AND channel_id=?", [$channel['id']]) as $row) {
            try {
                $job = $registry->resolve($channel['id'], $row['job_key'], $row['plugin']);
                $this->task($tasks, $row, $job['channel_enabled'] ? 1 : 0);
            } catch (\PDOException $e) { throw $e; }
            catch (\Throwable $e) {
                $this->task($tasks, $row, 0);
                $result['errors'][] = ['channel_id'=>$channel['id'], 'error'=>$e->getMessage()];
            }
        }
        $this->complaints($channel['id']);
        return $result;
    }

    public function complaints($channelId)
    {
        $states=new \lib\Complaint\SyncState($this->db);
        foreach($this->db->all('SELECT a.*,c.plugin,c.config FROM pre_complaint_account a INNER JOIN pre_channel c ON c.id=a.channel WHERE a.channel=? AND a.enabled=1',[$channelId]) as $binding)$states->reconcile($binding);
        $account = $this->db->row('SELECT id FROM pre_complaint_account WHERE channel=? AND enabled=1 LIMIT 1', [$channelId]);
        $row = $this->db->row("SELECT * FROM pre_task WHERE action='complain' AND channel_id=? AND source=1 AND job_key=''", [$channelId]);
        $tasks = new TaskService($this->db, [CronHandlers::class, 'execute']);
        if (!$row) {
            if (!$account) return;
            try {
                $tasks->save(['id'=>0, 'revision'=>0, 'action'=>'complain', 'params'=>['channel'=>$channelId, 'source'=>1],
                    'enabled'=>1, 'schedule_type'=>'daily', 'interval_seconds'=>null, 'daily_at'=>self::COMPLAINT_TIME,
                    'acknowledge_failure'=>0]);
            } catch (\lib\AdminApp\ApiError $e) {
                if ($e->status !== 409) throw $e;
            }
            $row=$this->db->row("SELECT * FROM pre_task WHERE action='complain' AND channel_id=? AND source=1 AND job_key=''",[$channelId]);
        }
        if(!$row)return;
        $this->task($tasks, $row, $account ? 1 : 0);
        $row=$tasks->get($row['id']);
        if($account&&(int)$row['enabled']&&$row['lock_token']===null&&$row['last_status']!=='failed'&&!$this->db->row('SELECT id FROM pre_task_execution WHERE active_task_id=?',[$row['id']])){
            $next=$states->nextForChannel($channelId,Schedule::next($row,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai'))));
            if($row['next_run_at']===null||$next<$row['next_run_at'])$this->db->query('UPDATE pre_task SET next_run_at=?,revision=revision+1 WHERE id=? AND revision=? AND lock_token IS NULL',[$next,$row['id'],$row['revision']]);
        }
    }

    public function retireChannel($channelId)
    {
        $tasks = new TaskService($this->db, [CronHandlers::class, 'execute']);
        foreach ($this->db->all("SELECT * FROM pre_task WHERE channel_id=? AND action IN ('plugin','complain')", [$channelId]) as $row) {
            $this->task($tasks, $row, 0);
        }
    }

    private function task(TaskService $tasks, array $row, $enabled)
    {
        if ((int)$row['enabled'] === $enabled || $row['lock_token'] !== null) return;
        // Never clear an unknown business result or replay an interrupted owner.
        if ($enabled && in_array($row['last_status'], ['failed', 'interrupted'], true)) return;
        if ($this->db->row('SELECT id FROM pre_task_execution WHERE active_task_id=?', [$row['id']])) return;
        try {
            $tasks->save(['id'=>$row['id'], 'revision'=>$row['revision'], 'action'=>$row['action'],
                'params'=>Definitions::storedParams($row), 'enabled'=>$enabled, 'schedule_type'=>$row['schedule_type'],
                'interval_seconds'=>$row['interval_seconds'], 'daily_at'=>$row['daily_at'], 'acknowledge_failure'=>0]);
        } catch (\lib\AdminApp\ApiError $e) { if ($e->status !== 409) throw $e; }
    }

    public function services($plugin)
    {
        $registry = new ServiceRegistry($this->db, $this->plugins);
        $registry->beginTurn(); // One coherent discovery snapshot per reconciliation.
        $result = $registry->sync($plugin);
        $catalog = $registry->discover($plugin);
        $invalid = array_column($catalog['errors'], 'channel_id');
        foreach ($this->db->all('SELECT * FROM pre_plugin_service WHERE plugin=? ORDER BY id', [$plugin]) as $before) {
            $item = $catalog['services'][$before['id']] ?? null;
            // Malformed manifests must never silently launch a partial group.
            $enabled = $item && $item['channel_enabled'] && !$invalid;
            $this->db->pdo->beginTransaction();
            try {
                $row = $this->db->row('SELECT * FROM pre_plugin_service WHERE id=?'.$this->db->lockClause(), [$before['id']]);
                if (!$enabled) {
                    if ((int)$row['desired']) $this->db->query('UPDATE pre_plugin_service SET desired=0,revision=revision+1 WHERE id=?', [$row['id']]);
                    $this->db->query("UPDATE pre_service_operation SET state='completed',finished_at=?,message='通道已停用，取消启动并停止旧实例' WHERE service_id=? AND state IN ('pending','starting')", [date('Y-m-d H:i:s'), $row['id']]);
                } elseif (!$this->db->row("SELECT id FROM pre_service_operation WHERE service_id=? AND state IN ('pending','starting')", [$row['id']])) {
                    $changed = !hash_equals($row['digest'], $item['digest']);
                    if ($changed || (!(int)$row['desired'] && $row['observed'] !== 'attention')) {
                        // The supervisor drains the old generation before accepting this snapshot.
                        $this->db->insert('service_operation', ['id'=>bin2hex(random_bytes(16)), 'service_id'=>$row['id'],
                            'revision'=>$row['revision'], 'action'=>'refresh', 'state'=>'pending',
                            'created_at'=>date('Y-m-d H:i:s'), 'message'=>'随通道配置自动接管']);
                        $this->db->query('UPDATE pre_plugin_service SET desired=1,revision=revision+1 WHERE id=?', [$row['id']]);
                    }
                }
                $this->db->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack();
                throw $e;
            }
        }
        return $result;
    }
}
