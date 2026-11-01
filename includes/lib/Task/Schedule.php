<?php
namespace lib\Task;

final class Schedule
{
    public static function validate($type, $interval, $dailyAt)
    {
        if ($type === 'interval') {
            if ($dailyAt !== null) throw new \InvalidArgumentException('间隔任务不能设置每日时间');
            return ['schedule_type'=>'interval', 'interval_seconds'=>Definitions::integer($interval, 3, 86400, '间隔秒数'), 'daily_at'=>null];
        }
        if ($type === 'daily') {
            if ($interval !== null) throw new \InvalidArgumentException('每日任务不能设置间隔秒数');
            if (!is_string($dailyAt) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $dailyAt)) {
                throw new \InvalidArgumentException('每日时间必须为 HH:mm');
            }
            return ['schedule_type'=>'daily', 'interval_seconds'=>null, 'daily_at'=>$dailyAt];
        }
        throw new \InvalidArgumentException('未定义的执行周期');
    }

    public static function next(array $schedule, \DateTimeImmutable $after)
    {
        $validated = self::validate($schedule['schedule_type'], $schedule['interval_seconds'], $schedule['daily_at']);
        if ($validated['schedule_type'] === 'interval') {
            return $after->modify('+'.$validated['interval_seconds'].' seconds')->format('Y-m-d H:i:s');
        }
        list($hour, $minute) = explode(':', $validated['daily_at']);
        $next = $after->setTime((int)$hour, (int)$minute, 0);
        if ($next <= $after) $next = $next->modify('+1 day');
        return $next->format('Y-m-d H:i:s');
    }
}
