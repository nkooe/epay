<?php
namespace lib\Finance;

use lib\AdminApp\Store;

/** Shared balance writer. Business state changes must join this transaction. */
final class Ledger
{
    public static function amount($value,$positive=true)
    {
        if(!is_scalar($value)||is_bool($value)||!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D',(string)$value)
            ||($positive&&bccomp((string)$value,'0',2)<=0))throw new \DomainException('金额必须为正数，最多两位小数');
        return bcadd((string)$value,'0',2);
    }
    public static function transaction(Store $db,$call)
    {
        if($db->pdo->inTransaction())return $call();
        $db->pdo->beginTransaction();
        try{$result=$call();$db->pdo->commit();return $result;}
        catch(\Throwable $e){if($db->pdo->inTransaction())$db->pdo->rollBack();throw $e;}
    }
    public static function change(Store $db,$uid,$amount,$add,$type,$reference=null,$allowNegative=true)
    {
        $amount=self::amount($amount);
        return self::transaction($db,function()use($db,$uid,$amount,$add,$type,$reference,$allowNegative){
            $u=$db->row('SELECT money FROM pre_user WHERE uid=?'.$db->lockClause(),[$uid]);
            if(!$u)throw new \DomainException('商户账户不存在');
            // Compatibility callers also serialize refund deduplication on the account lock.
            if($type==='代付退回'&&$reference&&$db->value("SELECT id FROM pre_record WHERE uid=? AND type='代付退回' AND trade_no=? LIMIT 1",[$uid,$reference]))return 0;
            $next=$add?bcadd((string)$u['money'],$amount,2):bcsub((string)$u['money'],$amount,2);
            if(!$allowNegative&&bccomp($next,'0',2)<0)throw new \DomainException('余额不足');
            if(bccomp($next,'99999999.99',2)>0||bccomp($next,'-99999999.99',2)<0)throw new \DomainException('余额超出数据库金额范围');
            $count=$db->update('user',['money'=>$next],'uid',$uid);
            $db->insert('record',['uid'=>$uid,'action'=>$add?1:2,'money'=>$amount,'oldmoney'=>$u['money'],'newmoney'=>$next,'type'=>$type,'trade_no'=>$reference,'date'=>date('Y-m-d H:i:s')]);
            return $count;
        });
    }
}
