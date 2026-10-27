<?php
namespace lib\AdminApp;

/** Small, best-effort payment event writer used by the legacy payment path. */
final class Notifications
{
    public static function paymentReceived($legacyDb, array $order)
    {
        $trade=(string)($order['trade_no'] ?? '');
        $uid=(int)($order['uid'] ?? 0);
        if($trade===''||$uid<=0)return false;
        // Do not add a write for every payment when no administrator device is subscribed.
        if(!$legacyDb->getColumn('SELECT device_id FROM pre_app_notification_device WHERE enabled=1 AND last_seen>=:cutoff LIMIT 1',[':cutoff'=>time()-30*86400]))return false;
        $details=$legacyDb->getRow('SELECT U.username,T.showname,C.name channel_name FROM pre_user U LEFT JOIN pre_type T ON T.id=:type LEFT JOIN pre_channel C ON C.id=:channel WHERE U.uid=:uid',[':type'=>(int)($order['type'] ?? 0),':channel'=>(int)($order['channel'] ?? 0),':uid'=>$uid]) ?: [];
        $type=trim((string)($details['showname'] ?? '')) ?: '支付';
        $amount=(string)($order['realmoney'] ?? $order['money'] ?? '0');
        if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$amount))$amount='0';
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        $amount=ltrim($whole,'0');
        if($amount==='')$amount='0';
        $amount.='.'.str_pad($fraction,2,'0');
        $body='商户 '.$uid.' · '.$type.' · ¥'.$amount;
        if($body!==''){
            $legacyDb->exec('INSERT IGNORE INTO pre_app_notification (event_key,type,title,body,trade_no,uid,amount,created) VALUES (:event_key,:type,:title,:body,:trade_no,:uid,:amount,:created)',[
                ':event_key'=>hash('sha256','order-paid:'.$trade),':type'=>'payment_received',':title'=>'收款成功',':body'=>$body,
                ':trade_no'=>$trade,':uid'=>$uid,':amount'=>$amount,':created'=>time()
            ]);
            return true;
        }
        return false;
    }
}
