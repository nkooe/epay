<?php
declare(strict_types=1);
namespace lib;

require_once __DIR__.'/bridge/Money.php';
use lib\AdminApp\Store;
use lib\bridge\Money;
use lib\bridge\Protocol;

/** The local payment transaction owns the outbox. Network I/O only happens in the child. */
final class MallSync
{
    public static function record(Store $db,string $trade,string $event,array $data=[]): void
    {
        if(!BridgeSettings::installed($db))return;
        if(!$db->pdo->inTransaction())throw new \LogicException('商城状态须与本地业务事务共同提交');
        $row=$db->row('SELECT * FROM pre_mall_checkout WHERE trade_no=?'.$db->lockClause(),[$trade]);
        if(!$row)return;
        if($event==='paid'){
            $amount=Money::cents((string)$data['realmoney']);
            if(!$row['ready']||!$row['mall_order_id']||$amount!==(int)$row['amount_cents'])
                throw new \RuntimeException('商城关联订单或实收金额不一致');
            $row['paid_confirmed']=1;$row['canceled']=0;
            $row['paid_at']=$row['paid_at']?:((int)strtotime($data['endtime']??'')?:time());
            $row['upstream_trade_no']=$data['api_trade_no']??'';
        }elseif($event==='refunded'){
            if(!$row['paid_confirmed'])return;
            $row['refund_cents']=max((int)$row['refund_cents'],Money::cents((string)$data['refundmoney']));
            if($row['refund_cents']>(int)$row['amount_cents'])throw new \RuntimeException('商城退款金额超过实收金额');
        }elseif($event==='closed'){
            if($row['paid_confirmed'])return;
            $row['canceled']=1;
        }else throw new \InvalidArgumentException('未知商城同步事件');
        $payload=['trade_no'=>$trade,'mall_order_id'=>$row['mall_order_id'],'amount_cents'=>(int)$row['amount_cents'],
            'type_id'=>(int)$row['type_id'],'paid_at'=>(int)$row['paid_at'],'upstream_trade_no'=>$row['upstream_trade_no'],
            'state'=>$row['paid_confirmed']?($row['refund_cents']?'refunded':'paid'):'closed','refund_cents'=>(int)$row['refund_cents']];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if($json===$row['state_json'])return;
        $db->query('UPDATE pre_mall_checkout SET paid_confirmed=?,paid_at=?,upstream_trade_no=?,refund_cents=?,canceled=?,state_json=?,state_revision=state_revision+1,synced=0,sync_attempts=0,next_sync_at=0,last_error=? WHERE trade_no=?',
            [(int)$row['paid_confirmed'],(int)$row['paid_at'],$row['upstream_trade_no'],(int)$row['refund_cents'],(int)$row['canceled'],$json,'',$trade]);
    }
    public static function closed(Store $db,string $trade): void
    {
        $own=!$db->pdo->inTransaction();
        if($own)$db->pdo->beginTransaction();
        try{self::record($db,$trade,'closed');if($own)$db->pdo->commit();}
        catch(\Throwable $e){if($own&&$db->pdo->inTransaction())$db->pdo->rollBack();throw $e;}
    }
    public static function batch(Store $db,int $limit=10,?callable $send=null,?callable $checkpoint=null): array
    {
        $result=['sent'=>0,'failed'=>0,'last_error'=>''];
        if(!BridgeSettings::installed($db))return $result;
        $config=BridgeSettings::row($db)['config'];
        if(!$config['secret'])return $result;
        // enabled only admits new orders; existing signed state keeps draining after disable.
        $rows=$db->all('SELECT trade_no,state_json,state_revision,sync_attempts FROM pre_mall_checkout WHERE state_revision>synced_revision AND mall_order_id<>? AND next_sync_at<=? ORDER BY next_sync_at,trade_no LIMIT '.max(1,min(50,$limit)),['',time()]);
        foreach($rows as $row){
            if($checkpoint)$checkpoint();
            try{
                $payload=json_decode($row['state_json'],true,32,JSON_THROW_ON_ERROR);
                if($send)$send($payload,$config);
                else Protocol::post($config['mall_url'],'/api/bridge/paid',$payload,$config);
                $db->query('UPDATE pre_mall_checkout SET synced_revision=?,synced=1,last_error=?,sync_attempts=0,synced_state=? WHERE trade_no=? AND state_revision=?',
                    [(int)$row['state_revision'],'',hash('sha256',$row['state_json']),$row['trade_no'],(int)$row['state_revision']]);
                $result['sent']++;
            }catch(\lib\Task\ServiceStopped $e){throw $e;}
            catch(\Throwable $e){
                $message=mb_substr(\lib\Task\Controller::redact($e->getMessage()),0,250);
                $delay=min(3600,10*(2**min(8,(int)$row['sync_attempts'])))+random_int(0,5);
                $db->query('UPDATE pre_mall_checkout SET sync_attempts=sync_attempts+1,next_sync_at=?,last_error=? WHERE trade_no=? AND state_revision=?',
                    [time()+$delay,$message,$row['trade_no'],(int)$row['state_revision']]);
                $result['failed']++;$result['last_error']=$message;
            }
        }
        $db->query('DELETE FROM pre_mall_nonce WHERE expires_at<?',[time()]);
        return $result;
    }
    public static function summary(Store $db): array
    {
        if(!BridgeSettings::installed($db))return ['pending'=>0,'failed'=>0,'last_error'=>''];
        $row=$db->row('SELECT COUNT(*) pending,COALESCE(SUM(sync_attempts>0),0) failed FROM pre_mall_checkout WHERE state_revision>synced_revision');
        $error=$db->value('SELECT last_error FROM pre_mall_checkout WHERE state_revision>synced_revision AND last_error<>? ORDER BY next_sync_at DESC LIMIT 1',['']);
        return ['pending'=>(int)$row['pending'],'failed'=>(int)$row['failed'],'last_error'=>$error?:''];
    }
}
