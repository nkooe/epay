<?php

declare(strict_types=1);

namespace lib;

use RuntimeException;
use Throwable;

final class MallLifecycle
{
    public static function start(string $token): array
    {
        global $DB;
        $checkout = MallCheckout::token($token);
        $DB->exec(
            'UPDATE pre_mall_checkout SET started_at=:now WHERE trade_no=:trade AND ready=1 AND canceled=0 AND started_at=0 AND expires_at>:time',
            [':now' => time(), ':trade' => $checkout['trade_no'], ':time' => time()]
        );
        $checkout = MallCheckout::token($token);
        if (!$checkout['ready'] || $checkout['canceled'] || !$checkout['started_at']) {
            throw new RuntimeException('订单已取消或尚未确认');
        }
        return $checkout;
    }

}
