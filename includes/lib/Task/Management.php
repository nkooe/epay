<?php
namespace lib\Task;

use lib\AdminApp\Store;
use InvalidArgumentException;
use Throwable;

/** Shared authenticated task operations for web and native clients. */
final class Management
{
    private $db;
    private $ip;
    private $controller;
    public function __construct(Store $db, $ip, SystemdController $controller=null)
    { $this->db=$db; $this->ip=$ip; $this->controller=$controller??new SystemdController(); }
    public function handle($method, $act, array $input=[])
    {
        $store=$this->db; $clientip=$this->ip;
        $registry = new \lib\Task\PluginRegistry($store);
        $tasks = new \lib\Task\TaskService($store, [\lib\Task\CronHandlers::class, 'execute'], $registry);
        $service = $this->controller;
        $dispatch = new \lib\Task\Dispatch($store);
        $pluginServices = new \lib\Task\ServiceRegistry($store);
        if ($method === 'GET' && $act === 'list') {
            $definitions = \lib\Task\Definitions::all();
            foreach ($definitions as $action => &$definition) $definition['unavailable'] = \lib\Task\Definitions::unavailable($action);
            unset($definition);
            try { $serviceState = $service->status(); }
            catch (Throwable $e) { $serviceState = ['available'=>false,'installed'=>true,'message'=>$e->getMessage()]; }
            $data = ['rows'=>$tasks->listing(), 'definitions'=>$definitions, 'service'=>$serviceState,
                'worker'=>$dispatch->heartbeat(), 'plugin_jobs'=>$registry->catalog(), 'plugin_services'=>$pluginServices->listing(),
                'executions'=>$store->all('SELECT id,task_id,state,created_at,claimed_at FROM pre_task_execution WHERE active_task_id IS NOT NULL ORDER BY created_at'),
                'requests'=>$store->all("SELECT id,task_id,state,message FROM pre_task_request WHERE state IN ('pending','running') ORDER BY created_at")];
        } elseif ($method === 'GET' && $act === 'service') {
            $data = $service->status();
        } elseif ($method === 'POST' && $act === 'service') {
            if (count($input) !== 1 || !isset($input['action']) || !is_string($input['action'])) {
                throw new InvalidArgumentException('服务操作参数不正确');
            }
            if (!in_array($input['action'], ['start','stop','restart','enable','disable'], true)) throw new InvalidArgumentException('不支持的服务操作');
            $store->insert('log', ['uid'=>0,'type'=>'任务服务控制','date'=>date('Y-m-d H:i:s'),'ip'=>$clientip,
                'data'=>json_encode(['action'=>$input['action']],JSON_UNESCAPED_UNICODE)]);
            $data = $service->control($input['action']);
        } elseif ($method === 'GET' && $act === 'service_logs') {
            $data = ['text'=>$service->logs()];
        } elseif ($method === 'GET' && $act === 'service_log_days') {
            $data = ['days'=>$service->logDays(), 'today'=>date('Y-m-d')];
        } elseif ($method === 'GET' && $act === 'service_log') {
            $date = \lib\Task\SystemdController::validateLogDate($input['date'] ?? '');
            $data = ['date'=>$date, 'text'=>$service->logDay($date)];
        } elseif ($method === 'POST' && $act === 'service_log_delete') {
            if (count($input) !== 1 || !isset($input['date']) || !is_string($input['date'])) {
                throw new InvalidArgumentException('删除日志必须指定日期');
            }
            $date = \lib\Task\SystemdController::validateLogDate($input['date']);
            $service->deleteLogDay($date);
            $store->insert('log', ['uid'=>0,'type'=>'服务日志删除','date'=>date('Y-m-d H:i:s'),'ip'=>$clientip,
                'data'=>json_encode(['date'=>$date], JSON_UNESCAPED_UNICODE)]);
            $data = ['date'=>$date, 'days'=>$service->logDays()];
        } elseif ($method === 'POST' && $act === 'plugin_service') {
            $data = $pluginServices->submit($input);
            $store->insert('log',['uid'=>0,'type'=>'插件服务控制','date'=>date('Y-m-d H:i:s'),'ip'=>$clientip,
                'data'=>json_encode(['service_id'=>$input['id'],'action'=>$input['action'],'operation_id'=>$data['operation_id']],JSON_UNESCAPED_UNICODE)]);
        } elseif ($method === 'GET' && $act === 'plugin_service_detail') {
            $row=$pluginServices->get($input['id']??null);
            $row['automatic']=true;
            $row['poll_interval_seconds']=Automatic::POLL_SECONDS;
            $data=['service'=>array_diff_key($row,['token'=>true]),'unit'=>null,'text'=>'尚未启动'];
            $data['operation']=$store->row('SELECT id,action,state,message FROM pre_service_operation WHERE service_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$row['id']]);
            if((int)$row['generation']>0){
                $instance=\lib\Task\ServiceRegistry::instance($row);
                $data['unit']=$service->instance('plugin','status',$instance);
                $data['text']=$service->instance('plugin','logs',$instance);
            }
        } elseif ($method === 'POST' && $act === 'sync_plugins') {
            if ($input) throw new InvalidArgumentException('同步任务不接受参数');
            $data = $registry->sync($tasks);
            $data['services']=$pluginServices->sync();
        } elseif ($method === 'GET' && $act === 'history') {
            $data = ['rows'=>$tasks->history($input['id'] ?? null, $input['before'] ?? 0)];
        } elseif ($method === 'POST' && $act === 'save') {
            if(isset($input['id'])&&$store->row('SELECT id FROM pre_task_execution WHERE active_task_id=?',[$input['id']]))throw new \lib\AdminApp\ApiError('任务实例尚未结束，请等待实际结果',409);
            $data = $tasks->save($input);
        } elseif ($method === 'POST' && $act === 'run') {
            if (count($input) !== 2 || !array_key_exists('id', $input) || !array_key_exists('revision', $input)) {
                throw new InvalidArgumentException('执行参数必须为任务 ID 和配置版本');
            }
            $data = $dispatch->enqueue($tasks, $input['id'], $input['revision']);
        } elseif ($method === 'POST' && $act === 'rebind') {
            if(count($input)!==2||!isset($input['id'],$input['revision']))throw new InvalidArgumentException('接入参数必须为任务 ID 和配置版本');
            $data=$tasks->rebind($input['id'],$input['revision']);
        } else {
            throw new \lib\AdminApp\ApiError('接口不存在',404);
        }
        return $data;
    }
}
