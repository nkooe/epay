<?php
namespace lib\Merchant;

use lib\AdminApp\Store;

/** Balance and deposit must be changed under the same account lock and commit. */
final class Deposit
{
    private $db;
    public function __construct(Store $db) { $this->db=$db; }

    private function amount($amount)
    {
        if(!is_scalar($amount)||!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D',(string)$amount)
            ||bccomp((string)$amount,'0',2)<=0)throw new \DomainException('金额不合法，请输入最多两位小数的正数');
        return bcadd((string)$amount,'0',2);
    }
    private function transaction($call)
    {
        if($this->db->pdo->inTransaction())return $call();
        $this->db->pdo->beginTransaction();
        try{$result=$call();$this->db->pdo->commit();return $result;}
        catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    private function account($uid)
    {
        $row=$this->db->row('SELECT money,deposit FROM pre_user WHERE uid=?'.$this->db->lockClause(),[$uid]);
        if(!$row)throw new \DomainException('商户账户不存在');
        $row['deposit']=$row['deposit']??'0.00';
        if(bccomp((string)$row['deposit'],'0',2)<0)throw new \DomainException('保证金账户异常，请联系管理员');
        return $row;
    }
    private function range($money,$deposit)
    {
        if(bccomp($money,'99999999.99',2)>0||bccomp($money,'-99999999.99',2)<0
            ||bccomp($deposit,'99999999.99',2)>0)throw new \DomainException('账户金额超出数据库范围');
    }

    public function move($uid,$amount,$withdraw=false,$days=0)
    {
        $amount=$this->amount($amount);$days=max(0,(int)$days);
        return $this->transaction(function()use($uid,$amount,$withdraw,$days){
            $row=$this->account($uid);
            if($withdraw){
                if(bccomp((string)$row['deposit'],$amount,2)<0)throw new \DomainException('保证金不足');
                if($days>0){
                    $cutoff=date('Y-m-d H:i:s',time()-$days*86400);
                    if($this->db->value('SELECT COUNT(*) FROM pre_order WHERE uid=? AND status=1 AND addtime>?',[$uid,$cutoff]))
                        throw new \DomainException('你在最近'.$days.'天内有订单，无法提取保证金');
                    if($this->db->value('SELECT COUNT(*) FROM pre_complaint WHERE uid=? AND created_at>?',[$uid,$cutoff]))
                        throw new \DomainException('你在最近'.$days.'天内有投诉记录，无法提取保证金');
                }
                $money=bcadd((string)$row['money'],$amount,2);$deposit=bcsub((string)$row['deposit'],$amount,2);
            }else{
                if(bccomp((string)$row['money'],$amount,2)<0)throw new \DomainException('余额不足，请选择其他方式支付');
                $money=bcsub((string)$row['money'],$amount,2);$deposit=bcadd((string)$row['deposit'],$amount,2);
            }
            $this->range($money,$deposit);
            $this->db->update('user',['deposit'=>$deposit],'uid',$uid);
            \lib\Finance\Ledger::change($this->db,$uid,$amount,$withdraw,$withdraw?'提取保证金':'充值保证金');
            return ['money'=>$money,'deposit'=>$deposit];
        });
    }

    /** Payment::processOrder owns the order transaction and rejects duplicate callbacks. */
    public function credit($uid,$amount)
    {
        $amount=$this->amount($amount);
        return $this->transaction(function()use($uid,$amount){
            $row=$this->account($uid);$deposit=bcadd((string)$row['deposit'],$amount,2);
            $this->range((string)$row['money'],$deposit);
            $this->db->update('user',['deposit'=>$deposit],'uid',$uid);
            return $deposit;
        });
    }
}
