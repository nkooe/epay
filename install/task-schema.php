<?php
function epayUpgradeTaskSchema(PDO $db, $prefix)
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix)) throw new RuntimeException('数据库前缀无效');
    $table=$prefix.'_task';
    $columns=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
    foreach(['job_key'=>"varchar(48) NOT NULL DEFAULT ''",'plugin'=>"varchar(64) NOT NULL DEFAULT ''",
        'retention_success_days'=>'smallint unsigned NOT NULL DEFAULT 30',
        'retention_failure_days'=>'smallint unsigned NOT NULL DEFAULT 90'] as $name=>$definition) {
        if(!in_array($name,$columns,true) && $db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition)===false)throw new RuntimeException('任务字段升级失败：'.$name);
    }
    $indices=$db->query('SHOW INDEX FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$identity=[];
    foreach($indices as $index)if($index['Key_name']==='task_identity')$identity[(int)$index['Seq_in_index']]=$index['Column_name'];
    ksort($identity);
    if(array_values($identity)!==['action','channel_id','source','job_key']) {
        if($identity && $db->exec('ALTER TABLE '.$table.' DROP INDEX task_identity')===false)throw new RuntimeException('任务唯一索引升级失败');
        if($db->exec('ALTER TABLE '.$table.' ADD UNIQUE KEY task_identity (action,channel_id,source,job_key)')===false)throw new RuntimeException('任务唯一索引创建失败');
    }
    if($db->exec("UPDATE {$table} t LEFT JOIN {$prefix}_channel c ON c.id=t.channel_id SET t.job_key='cron',t.plugin=COALESCE(c.plugin,'') WHERE t.action='plugin' AND t.job_key=''")===false)throw new RuntimeException('插件任务身份迁移失败');
    epayRemoveUnimplementedComplaintTask($db, $prefix);
}

/** Permanently remove the retired placeholder and only its own scheduling metadata. */
function epayRemoveUnimplementedComplaintTask(PDO $db, $prefix)
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new RuntimeException('数据库前缀无效');
    if (!$db->beginTransaction()) throw new RuntimeException('无法开始已移除任务清理');
    try {
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $db->query("SELECT id,lock_token,last_status FROM {$prefix}_task WHERE action='complain_complete'".$lock);
        if (!$query) throw new RuntimeException('无法读取已移除任务');
        $tasks = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as $task) {
            if ($task['lock_token'] !== null || $task['last_status'] === 'running') throw new RuntimeException('待移除任务仍有执行锁，请停止服务并核查后重试升级');
            $id = (int)$task['id'];
            foreach (["SELECT COUNT(*) FROM {$prefix}_task_execution WHERE task_id={$id} AND active_task_id IS NOT NULL",
                "SELECT COUNT(*) FROM {$prefix}_task_request WHERE task_id={$id} AND state='running'"] as $sql) {
                $active = $db->query($sql);
                if (!$active) throw new RuntimeException('无法检查待移除任务的执行状态');
                if ((int)$active->fetchColumn() > 0) throw new RuntimeException('待移除任务仍有活动执行，请停止服务并核查后重试升级');
            }
            foreach (['task_request','task_execution','task_run'] as $table) {
                if ($db->exec("DELETE FROM {$prefix}_{$table} WHERE task_id={$id}") === false) throw new RuntimeException('清理已移除任务的记录失败');
            }
            if ($db->exec("DELETE FROM {$prefix}_task WHERE id={$id} AND action='complain_complete'") !== 1) throw new RuntimeException('移除无业务逻辑任务失败');
        }
        if (!$db->commit()) throw new RuntimeException('无法提交已移除任务清理');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

