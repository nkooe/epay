<?php
namespace lib\Task;

use lib\AdminApp\ApiError;
use lib\AdminApp\Store;

final class TaskService
{
    private $db;
    private $handler;
    private $namespace;
    private $active;
    private $output = '';
    private $shutdownRegistered = false;
    private $registry;
    private static $held = [];

    public function __construct(Store $db, callable $handler, PluginRegistry $registry = null)
    {
        $this->db = $db;
        $this->handler = $handler;
        $this->registry = $registry;
        $this->namespace = $db->value('SELECT DATABASE()').':'.$db->tablePrefix();
    }

    private static function now()
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
    }

    private static function text($text, $length)
    {
        $text = preg_replace('~<br\s*/?>~i', "\n", (string)$text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return mb_substr(trim($text), 0, $length, 'UTF-8');
    }

    public function get($id)
    {
        $id = Definitions::integer($id, 1, 2147483647, '任务 ID');
        $task = $this->db->row('SELECT * FROM pre_task WHERE id=?', [$id]);
        if (!$task) throw new ApiError('任务不存在', 404);
        return $task;
    }

    public function assertAvailable(array $task)
    {
        if($reason=Definitions::unavailable($task['action']))throw new ApiError($reason,409);
        if($task['action']==='plugin' && $this->registry){
            try{$job=$this->registry->resolve($task['channel_id'],$task['job_key'],$task['plugin']);}
            catch(\PDOException $e){throw $e;}
            catch(\Throwable $e){throw new ApiError($e->getMessage(),409);}
            if(!$job['channel_enabled'])throw new ApiError('通道已停用',409);
        }
    }

    public function listing()
    {
        $rows = $this->db->all('SELECT t.*,c.name AS channel_name FROM pre_task t LEFT JOIN pre_channel c ON c.id=t.channel_id ORDER BY t.id');
        foreach ($rows as &$row) {
            $row['name'] = Definitions::get($row['action'])['name'];
            $row['automatic'] = in_array($row['action'], ['plugin','complain'], true);
            $row['unavailable'] = Definitions::unavailable($row['action']);
            $row['rebind_available'] = false;
            if ($row['action'] === 'plugin' && $this->registry !== null) {
                try {
                    $job = $this->registry->resolve($row['channel_id'], $row['job_key'], $row['plugin']);
                    $row['name'] = $job['name'];
                    if (!$job['channel_enabled']) $row['unavailable'] = '通道已停用';
                } catch (\Throwable $e) {
                    $row['unavailable'] = $e->getMessage();
                    try {
                        $currentJob=$this->registry->resolve($row['channel_id'],$row['job_key']);
                        $row['rebind_available']=$currentJob['plugin']!==$row['plugin'] && !(int)$row['enabled'] && $row['lock_token']===null;
                    } catch (\Throwable $ignored) {}
                }
            }
            if ((int)$row['channel_id'] > 0 && $row['channel_name'] === null) $row['unavailable'] = '指定通道已删除';
            $row['overdue_lock'] = $row['lock_token'] !== null && $row['lock_expires_at'] <= self::now()->format('Y-m-d H:i:s');
        }
        return $rows;
    }

    public function history($id, $before = 0)
    {
        $this->get($id);
        $before = Definitions::integer($before, 0, PHP_INT_MAX, '记录游标');
        if ($before > 0) {
            return $this->db->all('SELECT * FROM pre_task_run WHERE task_id=? AND id<? ORDER BY id DESC LIMIT 30', [$id, $before]);
        }
        return $this->db->all('SELECT * FROM pre_task_run WHERE task_id=? ORDER BY id DESC LIMIT 30', [$id]);
    }

    private function locked(array $task, callable $call)
    {
        $key = 'epay.task.'.substr(hash('sha256', $this->namespace.':'.Definitions::resource($task)), 0, 54);
        $local = spl_object_id($this->db->pdo).':'.$key;
        if (isset(self::$held[$local])) throw new ApiError('同类任务正在执行', 409);
        $acquired = $this->db->value('SELECT GET_LOCK(?,0)', [$key]);
        if ($acquired === null || $acquired === false) throw new \RuntimeException('无法获取任务数据库锁');
        if ((int)$acquired !== 1) throw new ApiError('同类任务正在执行，请稍后刷新', 409);
        self::$held[$local] = true;
        try {
            return $call();
        } finally {
            unset(self::$held[$local]);
            if ((int)$this->db->value('SELECT RELEASE_LOCK(?)', [$key]) !== 1) {
                throw new \RuntimeException('任务锁归属异常，释放失败');
            }
        }
    }

    private function transaction(callable $call)
    {
        $this->db->pdo->beginTransaction();
        try {
            $result = $call();
            $this->db->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack();
            throw $e;
        }
    }

    public function save(array $input)
    {
        $fields = ['id','revision','action','params','enabled','schedule_type','interval_seconds','daily_at','acknowledge_failure'];
        if (array_diff(array_keys($input), $fields) || array_diff($fields, array_keys($input))) {
            throw new \InvalidArgumentException('任务配置字段不完整或含未知字段');
        }
        $id = Definitions::integer($input['id'], 0, 2147483647, '任务 ID');
        $revision = Definitions::integer($input['revision'], 0, 2147483647, '配置版本');
        $definition = Definitions::get($input['action']);
        if (!is_array($input['params'])) throw new \InvalidArgumentException('任务参数必须是对象');
        $params = Definitions::params($input['action'], $input['params']);
        $enabled = Definitions::integer($input['enabled'], 0, 1, '启用状态');
        $acknowledged = Definitions::integer($input['acknowledge_failure'], 0, 1, '恢复确认');
        $schedule = Schedule::validate($input['schedule_type'], $input['interval_seconds'], $input['daily_at']);
        $options = $input['action'] === 'cleanup_logs' ? [
            'retention_success_days'=>$params['success_days'], 'retention_failure_days'=>$params['failure_days']
        ] : [];
        $identity = ['action'=>$input['action'], 'channel_id'=>$definition['channel'] ? $params['channel'] : 0,
            'source'=>$input['action'] === 'complain' ? $params['source'] : 0];
        $identity['job_key'] = $input['action'] === 'plugin' ? $params['job'] : '';
        $identity['plugin'] = '';
        if ($input['action'] === 'plugin' && $this->registry !== null) {
            $existing = $id ? $this->get($id) : null;
            if ($existing && !$enabled) $identity['plugin'] = $existing['plugin'];
            else {
                $job = $this->registry->resolve($params['channel'], $params['job'],$existing['plugin']??null);
                if ($enabled && !$job['channel_enabled']) throw new \InvalidArgumentException('通道已停用，不能启用插件任务');
                $identity['plugin'] = $job['plugin'];
            }
        }
        if ($id === 0 && (!$definition['channel'] || $revision !== 0)) throw new \InvalidArgumentException('只能新增通道任务');
        if ($enabled && ($reason = Definitions::unavailable($input['action']))) throw new \InvalidArgumentException($reason);
        if ($definition['channel'] && ($enabled || !$id) && !$this->db->row('SELECT id FROM pre_channel WHERE id=?', [$params['channel']])) {
            throw new \InvalidArgumentException('指定通道不存在');
        }
        return $this->locked($identity, function () use ($id, $revision, $identity, $schedule, $options, $enabled, $acknowledged) {
            return $this->transaction(function () use ($id, $revision, $identity, $schedule, $options, $enabled, $acknowledged) {
                $current = $id ? $this->get($id) : null;
                if ($current) {
                    if ((int)$current['revision'] !== $revision) throw new ApiError('任务已更新，请刷新后重试', 409);
                    foreach ($identity as $key => $value) {
                        if ((string)$current[$key] !== (string)$value) throw new \InvalidArgumentException('不能修改任务类型或通道归属');
                    }
                    if ($current['lock_token'] !== null && !$acknowledged) {
                        throw new ApiError('上次任务异常中断，请核查业务结果后勾选恢复', 409);
                    }
                    if ($enabled && $current['last_status'] === 'failed' && !$acknowledged) {
                        throw new \InvalidArgumentException('请先核查上次失败并勾选恢复');
                    }
                    if ($current['lock_token'] !== null) {
                        $changed = $this->db->query("UPDATE pre_task_run SET status='interrupted',finished_at=?,error=? WHERE token=? AND status='running'",
                            [self::now()->format('Y-m-d H:i:s'), '进程已结束，管理员核查后解除执行锁', $current['lock_token']])->rowCount();
                        if ($changed !== 1) throw new \RuntimeException('异常执行记录缺失，无法恢复');
                    }
                } elseif ($this->db->row('SELECT id FROM pre_task WHERE action=? AND channel_id=? AND source=? AND job_key=?', [$identity['action'],$identity['channel_id'],$identity['source'],$identity['job_key']])) {
                    throw new ApiError('该通道任务已存在', 409);
                }
                $data = $schedule + ['enabled'=>$enabled] + $options;
                $scheduleChanged = !$current || $acknowledged;
                if ($current) foreach ($data as $key => $value) {
                    if ($current[$key] !== $value && (string)$current[$key] !== (string)$value) $scheduleChanged = true;
                }
                if (!$scheduleChanged) return $current;
                $data['next_run_at'] = $enabled ? Schedule::next($schedule, self::now()) : null;
                if (!$current) {
                    $newId = $this->db->insert('task', $identity + $data);
                    return $this->get($newId);
                }
                if ($acknowledged) {
                    $data += ['last_status'=>'idle', 'last_error'=>null, 'lock_token'=>null, 'lock_expires_at'=>null];
                }
                $data['revision'] = $revision + 1;
                $sets = array_map(function ($key) { return '`'.$key.'`=?'; }, array_keys($data));
                $changed = $this->db->query('UPDATE pre_task SET '.implode(',', $sets).' WHERE id=? AND revision=? AND (lock_token=? OR (lock_token IS NULL AND ? IS NULL))',
                    array_merge(array_values($data), [$id, $revision, $current['lock_token'], $current['lock_token']]))->rowCount();
                if ($changed !== 1) throw new \RuntimeException('任务配置或锁归属已变化，更新失败');
                return $this->get($id);
            });
        });
    }

    public function find($action, array $params)
    {
        $definition = Definitions::get($action);
        $params = Definitions::params($action, $params);
        $row = $this->db->row('SELECT id FROM pre_task WHERE action=? AND channel_id=? AND source=? AND job_key=?',
            [$action, $definition['channel'] ? $params['channel'] : 0, $action === 'complain' ? $params['source'] : 0, $action === 'plugin' ? $params['job'] : '']);
        if (!$row) throw new ApiError('任务尚未配置，请在管理后台添加', 404);
        return $row['id'];
    }

    /** Explicitly accept a changed channel plugin; never enables or clears a failed run. */
    public function rebind($id,$revision)
    {
        $revision=Definitions::integer($revision,1,2147483647,'配置版本');
        $task=$this->get($id);
        if($task['action']!=='plugin'||!$this->registry)throw new \InvalidArgumentException('仅插件任务支持重新接入');
        return $this->locked($task,function()use($id,$revision){
            return $this->transaction(function()use($id,$revision){
                $task=$this->get($id);
                if((int)$task['revision']!==$revision)throw new ApiError('任务已更新，请刷新',409);
                if((int)$task['enabled']||$task['lock_token']!==null)throw new ApiError('请先关闭自动执行并核查运行状态',409);
                $job=$this->registry->resolve($task['channel_id'],$task['job_key']);
                if($job['plugin']===$task['plugin'])throw new \InvalidArgumentException('插件归属未变更');
                $this->db->query('UPDATE pre_task SET plugin=?,revision=revision+1 WHERE id=? AND revision=?',[$job['plugin'],$id,$revision]);
                return $this->get($id);
            });
        });
    }

    public function run($id, $origin, $revision = null, $runId = null)
    {
        if (!in_array($origin, ['admin','cron','scheduler'], true)) throw new \InvalidArgumentException('未定义的触发来源');
        if ($origin === 'admin' || $revision !== null) $revision = Definitions::integer($revision, 1, 2147483647, '配置版本');
        if ($this->active !== null) throw new \LogicException('任务不允许嵌套执行');
        $initial = $this->get($id);
        return $this->locked($initial, function () use ($id, $origin, $revision, $runId) {
            $task = $this->get($id);
            if ($revision !== null && (int)$task['revision'] !== $revision) throw new ApiError('任务已执行或配置已更新，请刷新', 409);
            if ($task['lock_token'] !== null) throw new ApiError('上次执行未正常结束，请核查后恢复；不能自动重试', 409);
            if ($task['last_status'] === 'failed') throw new ApiError('任务上次执行失败，请核查后恢复', 409);
            $now = self::now();
            if ($origin === 'scheduler' && (!(int)$task['enabled'] || $task['next_run_at'] === null || $task['next_run_at'] > $now->format('Y-m-d H:i:s'))) {
                return ['status'=>'not_due', 'task_id'=>(int)$id];
            }
            $token = $runId === null ? bin2hex(random_bytes(16)) : Execution::id($runId);
            $this->transaction(function () use ($task, $token, $origin, $now) {
                $changed = $this->db->query("UPDATE pre_task SET last_status='running',started_at=?,finished_at=NULL,lock_token=?,lock_expires_at=?,last_error=NULL,last_summary=NULL,revision=revision+1 WHERE id=? AND lock_token IS NULL AND revision=?",
                    [$now->format('Y-m-d H:i:s'), $token, $now->modify('+15 minutes')->format('Y-m-d H:i:s'), $task['id'], $task['revision']])->rowCount();
                if ($changed !== 1) throw new ApiError('任务已被其他进程认领', 409);
                $this->db->insert('task_run', ['task_id'=>$task['id'], 'token'=>$token, 'triggered_by'=>$origin, 'status'=>'running', 'started_at'=>$now->format('Y-m-d H:i:s')]);
            });
            $this->active = ['id'=>$task['id'], 'token'=>$token, 'task'=>$task, 'buffer_level'=>ob_get_level()];
            $this->output = '';
            if (!$this->shutdownRegistered) {
                register_shutdown_function([$this, 'shutdown']);
                $this->shutdownRegistered = true;
            }
            $level = ob_get_level();
            ob_start(function ($chunk) {
                $this->output = mb_strcut($this->output.$chunk, 0, 16000, 'UTF-8');
                return '';
            }, 4096);
            $error = null;
            try {
                $this->assertAvailable($task);
                Schedule::validate($task['schedule_type'], $task['interval_seconds'], $task['daily_at']);
                $GLOBALS['epay_task_context'] = ['task_id'=>(int)$id, 'run_token'=>$token, 'plugin'=>$task['plugin'] ?? ''];
                $result = call_user_func($this->handler, $task['action'], Definitions::storedParams($task));
                if ($result !== null) {
                    if (!is_string($result)) throw new \RuntimeException('任务处理器返回类型错误');
                    echo $result;
                }
            } catch (\Throwable $e) {
                $error = self::text(SystemdController::redact($e->getMessage()), 1000);
                error_log('Task '.$task['id'].' failed: '.$error);
            } finally {
                unset($GLOBALS['epay_task_context']);
                while (ob_get_level() > $level) ob_end_flush();
            }
            try {
                if ($this->db->pdo->inTransaction()) {
                    $this->db->pdo->rollBack();
                    if ($error === null) $error = '任务处理器遗留未提交事务，已回滚，需核查业务结果';
                }
                $this->finish($error);
            } finally {
                $this->active = null;
            }
            return ['status'=>$error === null ? 'success' : 'failed', 'task_id'=>(int)$id,
                'summary'=>self::text($this->output, 2000), 'error'=>$error];
        });
    }

    private function finish($error)
    {
        $active = $this->active;
        $now = self::now();
        $summary = self::text($this->output, 2000);
        $status = $error === null ? 'success' : 'failed';
        $next = $error === null && (int)$active['task']['enabled'] ? Schedule::next($active['task'], $now) : null;
        if($next!==null&&$active['task']['action']==='complain')$next=(new \lib\Complaint\SyncState($this->db))->nextForChannel($active['task']['channel_id'],$next);
        $this->transaction(function () use ($active, $now, $summary, $status, $error, $next) {
            $changed = $this->db->query('UPDATE pre_task SET last_status=?,finished_at=?,last_summary=?,last_error=?,enabled=?,next_run_at=?,lock_token=NULL,lock_expires_at=NULL,revision=revision+1 WHERE id=? AND lock_token=?',
                [$status, $now->format('Y-m-d H:i:s'), $summary, $error, $error === null ? $active['task']['enabled'] : 0, $next, $active['id'], $active['token']])->rowCount();
            if ($changed !== 1) throw new \RuntimeException('执行锁归属已变化，拒绝覆盖任务结果');
            $changed = $this->db->query("UPDATE pre_task_run SET status=?,finished_at=?,summary=?,error=? WHERE task_id=? AND token=? AND status='running'",
                [$status, $now->format('Y-m-d H:i:s'), $summary, $error, $active['id'], $active['token']])->rowCount();
            if ($changed !== 1) throw new \RuntimeException('执行记录归属异常，拒绝标记成功');
        });
    }

    public function shutdown()
    {
        if ($this->active === null) return;
        while (ob_get_level() > $this->active['buffer_level']) ob_end_flush();
        $last = error_get_last();
        $error = '任务进程异常退出，需核查业务结果后恢复';
        if ($last && in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
            $error .= '：'.$last['message'];
        }
        $recorded = false;
        try {
            if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack();
            $this->finish(self::text($error, 1000));
            $recorded = true;
        } catch (\Throwable $e) {
            error_log('Task shutdown recording failed: '.$e->getMessage());
        }
        $this->active = null;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header_remove('Location');
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
            echo json_encode(['code'=>-1, 'msg'=>$recorded ? '任务异常退出，已暂停，请核查业务结果' : '任务异常退出且状态未完整写入，请核查业务结果'], JSON_UNESCAPED_UNICODE);
        }
    }
}
