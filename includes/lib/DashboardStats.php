<?php
namespace lib;

/** Web income statistics use the payment date and paid/frozen orders, as cron.php does. */
class DashboardStats
{
    public static function lastSevenDays($db, \DateTimeImmutable $today = null)
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $start = $today->modify('-6 days');
        $end = $today->modify('+1 day');
        $rows = $db->getAll(
            'SELECT date day,type,channel,COALESCE(SUM(realmoney),0) amount,COALESCE(SUM(profitmoney),0) profit FROM pre_order WHERE status IN (1,3) AND date>=? AND date<? GROUP BY date,type,channel ORDER BY date',
            [$start->format('Y-m-d'), $end->format('Y-m-d')]
        );
        if ($rows === false) {
            throw new \RuntimeException('Dashboard order aggregation failed');
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $key = $start->modify('+'.$i.' days')->format('Ymd');
            $days[$key] = ['all' => 0, 'profit_all' => 0, 'paytype' => [], 'channel' => [], 'profit_paytype' => []];
        }
        foreach ($rows as $row) {
            $key = str_replace('-', '', $row['day']);
            $day = &$days[$key];
            $amount = (float)$row['amount'];
            $profit = (float)$row['profit'];
            $day['all'] += $amount;
            $day['profit_all'] += $profit;
            $day['paytype'][$row['type']] = ($day['paytype'][$row['type']] ?? 0) + $amount;
            $day['channel'][$row['channel']] = ($day['channel'][$row['channel']] ?? 0) + $amount;
            $day['profit_paytype'][$row['type']] = ($day['profit_paytype'][$row['type']] ?? 0) + $profit;
            unset($day);
        }
        foreach ($days as &$day) {
            $day['all'] = round($day['all'], 2);
            $day['profit_all'] = round($day['profit_all'], 2);
            foreach (['paytype', 'channel', 'profit_paytype'] as $field) {
                foreach ($day[$field] as &$value) {
                    $value = round($value, 2);
                }
                unset($value);
            }
        }
        unset($day);
        return $days;
    }
}
