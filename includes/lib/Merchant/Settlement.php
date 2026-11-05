<?php
namespace lib\Merchant;

use lib\AdminApp\Store;
use lib\Finance\Ledger;

final class Settlement
{
    private $db;
    private $conf;
    public function __construct(Store $db,array $conf){$this->db=$db;$this->conf=$conf;}
    public function create($uid,$amount=null,$automatic=false)
    {
        if($amount!==null)$amount=Ledger::amount($amount);
        return Ledger::transaction($this->db,function()use($uid,$amount,$automatic){
            $u=$this->db->row('SELECT * FROM pre_user WHERE uid=?'.$this->db->lockClause(),[$uid]);
            if(!$u)throw new \DomainException('商户不存在');
            $c=FinancialPolicy::config($this->db,$this->conf,$u);FinancialPolicy::check($c,$u,'settle');
            $mode=(int)($c['settle_open']??0);
            if(!in_array($mode,$automatic?[1,3]:[2,3],true))throw new \DomainException('未开启此结算方式');
            if(empty($u['account'])||empty($u['username'])||!in_array((int)$u['settle_id'],[1,2,3,4],true))throw new \DomainException('请先完善结算账户');
            if(!empty($c['cert_force'])&&empty($u['cert']))throw new \DomainException('请先完成实名认证');
            $available=FinancialPolicy::available($this->db,$c,$u);
            $money=$amount??$available;
            if(bccomp($money,'0',2)<=0||bccomp($money,(string)($c['settle_money']??'0'),2)<0)throw new \DomainException('未达到最低提现金额');
            if(bccomp($money,$available,2)>0)throw new \DomainException('提现金额大于可提现余额');
            if($automatic&&bccomp($money,(string)($c['auto_settle_money']??'0'),2)<=0)throw new \DomainException('未达到自动转账金额');
            // A later cron run must not create another payout while an earlier one is unresolved.
            if($automatic&&$this->db->value('SELECT COUNT(*) FROM pre_settle WHERE uid=? AND status IN (0,2,3)',[$uid]))throw new \DomainException('已有结算待处理，请先核对原结算单');
            if(!$automatic&&!empty($c['settle_maxlimit'])&&$this->db->value('SELECT COUNT(*) FROM pre_settle WHERE uid=? AND addtime>=?',[$uid,date('Y-m-d').' 00:00:00'])>=(int)$c['settle_maxlimit'])throw new \DomainException('今日申请提现次数已达上限');
            $fee='0.00';$rate=(string)($c['settle_rate']??'0');
            if(bccomp($rate,'0',4)>0){
                $fee=number_format(round((float)$money*(float)$rate/100,2),2,'.','');
                if(!empty($c['settle_fee_min'])&&bccomp($fee,(string)$c['settle_fee_min'],2)<0)$fee=(string)$c['settle_fee_min'];
                if(!empty($c['settle_fee_max'])&&bccomp($fee,(string)$c['settle_fee_max'],2)>0)$fee=(string)$c['settle_fee_max'];
            }
            $net=bcsub($money,$fee,2);Ledger::amount($net);
            $data=['uid'=>$uid,'type'=>$u['settle_id'],'account'=>$u['account'],'username'=>$u['username'],'money'=>$money,'realmoney'=>$net,'addtime'=>date('Y-m-d H:i:s'),'status'=>0];
            $id=$this->db->insert('settle',$data);
            Ledger::change($this->db,$uid,$money,false,$automatic?'自动结算':'手动提现','settle-'.$id,false);
            return $data+['id'=>$id,'config'=>$c];
        });
    }
}
