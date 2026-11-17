<?php

declare(strict_types=1);

namespace lib;

use RuntimeException;

/** Short, per-order admission lock. Unrelated payments never share a lock or wait in PHP workers. */
final class MallPaymentGuard
{
    public static function acquire(array $checkout): void
    {
        global $DB;
        $name = 'mall-pay-' . substr(hash('sha256', MallCheckout::config()['gateway_url'] . ':' . $checkout['trade_no']), 0, 48);
        if ((int) $DB->getColumn('SELECT GET_LOCK(:name,0)', [':name' => $name]) !== 1) {
            throw new RuntimeException('该订单正在发起支付，请稍后查询付款结果，勿重复提交');
        }
        // submit2 and plugin responders may exit; the connection-scoped lock must release on every exit path.
        register_shutdown_function(static function () use ($DB, $name): void {
            $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name' => $name]);
        });
    }
}
