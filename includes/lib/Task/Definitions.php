<?php
namespace lib\Task;

final class Definitions
{
    public static function all()
    {
        return [
            'order' => ['name'=>'订单统计与清理', 'resource'=>'settlement', 'channel'=>false],
            'settle' => ['name'=>'生成结算列表', 'resource'=>'settlement', 'channel'=>false],
            'notify' => ['name'=>'订单通知重试', 'resource'=>'notification', 'channel'=>false],
            'notify2' => ['name'=>'重发已失败通知', 'resource'=>'notification', 'channel'=>false],
            'profitsharing' => ['name'=>'分账与延迟结算', 'resource'=>'sharing', 'channel'=>false],
            'check' => ['name'=>'支付风控检测', 'resource'=>'risk', 'channel'=>false],
            'transfer' => ['name'=>'自动结算转账', 'resource'=>'settlement', 'channel'=>false],
            'cleanup_logs' => ['name'=>'清理过期记录', 'resource'=>'maintenance', 'channel'=>false],
            'complain' => ['name'=>'通道投诉同步', 'resource'=>'complaint', 'channel'=>true],
            'plugin' => ['name'=>'通道插件任务', 'resource'=>'plugin', 'channel'=>true],
        ];
    }

    public static function get($action)
    {
        $definitions = self::all();
        if (!is_string($action) || !isset($definitions[$action])) {
            throw new \InvalidArgumentException('未注册的任务类型');
        }
        return $definitions[$action];
    }

    public static function integer($value, $min, $max, $label)
    {
        if ((!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value) ||
            strlen((string)$value) > strlen((string)$max) ||
            (strlen((string)$value) === strlen((string)$max) && strcmp((string)$value, (string)$max) > 0) ||
            $value < $min) {
            throw new \InvalidArgumentException($label.'必须是 '.$min.' 至 '.$max.' 的整数');
        }
        return (int)$value;
    }

    public static function params($action, array $params)
    {
        $definition = self::get($action);
        $keys = $definition['channel'] ? ['channel'] : [];
        if ($action === 'complain') $keys[] = 'source';
        if ($action === 'plugin') $keys[] = 'job';
        if ($action === 'cleanup_logs') $keys = ['success_days', 'failure_days'];
        if (array_diff(array_keys($params), $keys) || array_diff($keys, array_keys($params))) {
            throw new \InvalidArgumentException('任务参数不完整或包含未定义字段');
        }
        $result = [];
        if ($action === 'cleanup_logs') {
            $result['success_days'] = self::integer($params['success_days'], 1, 3650, '成功记录保留天数');
            $result['failure_days'] = self::integer($params['failure_days'], 1, 3650, '失败记录保留天数');
        }
        if ($definition['channel']) $result['channel'] = self::integer($params['channel'], 1, 2147483647, '通道 ID');
        if ($action === 'complain') $result['source'] = self::integer($params['source'], 1, 1, '标准投诉来源');
        if ($action === 'plugin') {
            if (!is_string($params['job']) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $params['job'])) throw new \InvalidArgumentException('插件任务 key 无效');
            $result['job'] = $params['job'];
        }
        return $result;
    }

    public static function storedParams(array $task)
    {
        $definition = self::get($task['action']);
        $params = [];
        if ($task['action'] === 'cleanup_logs') {
            $params['success_days'] = $task['retention_success_days'];
            $params['failure_days'] = $task['retention_failure_days'];
        }
        if ($definition['channel']) $params['channel'] = $task['channel_id'];
        elseif ((int)$task['channel_id'] !== 0) throw new \RuntimeException('任务包含非法通道参数');
        if ($task['action'] === 'plugin') $params['job'] = $task['job_key'];
        if ($task['action'] === 'complain') $params['source'] = $task['source'];
        elseif ((int)$task['source'] !== 0) throw new \RuntimeException('任务包含非法投诉来源');
        return self::params($task['action'], $params);
    }

    public static function resource(array $task)
    {
        $definition = self::get($task['action']);
        return $definition['resource'].($task['action'] === 'plugin' ? ':'.$task['channel_id'] : '');
    }

    public static function unavailable($action)
    {
        self::get($action);
        if ($action === 'complain' && !class_exists('lib\\Complaint\\Service')) return '未安装投诉管理模块';
        return null;
    }
}
