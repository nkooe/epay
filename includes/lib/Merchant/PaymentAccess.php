<?php
namespace lib\Merchant;

use lib\AdminApp\ApiError;

final class PaymentAccess
{
    /** Applies to payment initiation only; settlement callbacks must remain reachable. */
    public static function assertAllowed(array $order)
    {
        global $DB,$conf;
        if((int)($order['channel_test']??0)===2||!empty($GLOBALS['merchantChannelAdminTest']))return;
        $user=$DB->getRow('SELECT status,pay FROM pre_user WHERE uid=:uid',[':uid'=>(int)$order['uid']]);
        if(!$user||empty($user['status'])||empty($user['pay']))throw new ApiError('商户已停用收款，无法支付',403);
        if((int)$user['pay']===2&&(int)($conf['user_review']??0)===1)throw new ApiError('商户未通过审核，无法支付',403);
    }
}
