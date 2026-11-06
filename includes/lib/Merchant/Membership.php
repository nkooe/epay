<?php
namespace lib\Merchant;

use lib\AdminApp\Store;
use lib\Finance\Ledger;

final class Membership
{
    private $db;
    private $config;
    public function __construct(Store $db,array $config){$this->db=$db;$this->config=$config;}

    public function purchase($uid,$gid,$quantity)
    {
        if(!is_int($quantity)||$quantity<1||$quantity>300)throw new \DomainException('数量不正确');
        return Ledger::transaction($this->db,function()use($uid,$gid,$quantity){
            $user=$this->db->row('SELECT * FROM pre_user WHERE uid=?'.$this->db->lockClause(),[$uid]);
            if(!$user||empty($user['status']))throw new \DomainException('商户已停用或不存在');
            $group=$this->db->row('SELECT * FROM pre_group WHERE gid=?',[$gid]);
            if(!$group||empty($group['isbuy']))throw new \DomainException('当前会员等级无法购买');
            if((int)$user['gid']===(int)$gid&&$user['endtime']===null)throw new \DomainException('你已购买此会员等级，请勿重复购买');
            $money=Ledger::amount(bcmul(Ledger::amount($group['price']), (string)$quantity,2));
            $end=self::endtime($user,$gid,(int)$group['expire']*$quantity);
            Ledger::change($this->db,$uid,$money,false,'购买会员',null,false);
            $this->db->update('user',['gid'=>$gid,'endtime'=>$end],'uid',$uid);
            $this->commission($user,$money);
            return ['money'=>$money,'endtime'=>$end];
        });
    }

    public static function endtime(array $user,$gid,$months)
    {
        if($months<=0)return null;
        $start=time();
        if((int)$user['gid']===(int)$gid&&!empty($user['endtime']))$start=max($start,strtotime($user['endtime'])?:0);
        return date('Y-m-d',strtotime('+ '.$months.' month',$start));
    }

    /** Called inside the payment transaction, once per confirmed order. */
    public function fulfill(array $param,$money)
    {
        return Ledger::transaction($this->db,function()use($param,$money){
            $uid=(int)$param['uid'];$gid=(int)$param['gid'];
            $user=$this->db->row('SELECT * FROM pre_user WHERE uid=?'.$this->db->lockClause(),[$uid]);
            if(!$user)throw new \DomainException('会员收款对象不存在');
            if(array_key_exists('months',$param)){
                if(!is_int($param['months'])||$param['months']<0||$param['months']>120000)throw new \DomainException('会员有效期无效');
                $end=self::endtime($user,$gid,$param['months']);
            }else{
                // Already-issued legacy orders have an absolute entitlement date.
                $end=$param['endtime']??null;
            }
            $this->db->update('user',['gid'=>$gid,'endtime'=>$end],'uid',$uid);
            $this->commission($user,$money);
        });
    }

    private function commission(array $user,$money)
    {
        if(empty($user['upid']))return;
        $parent=$this->db->row('SELECT * FROM pre_user WHERE uid=?'.$this->db->lockClause(),[$user['upid']]);
        if(!$parent)throw new \DomainException('邀请商户不存在');
        $config=FinancialPolicy::config($this->db,$this->config,$parent);
        $rate=$config['invite_groupbuy_rate']??0;
        if(empty($config['invite_open'])||!is_numeric($rate)||$rate<=0)return;
        $reward=number_format(round((float)$money*(float)$rate/100,2),2,'.','');
        if(bccomp($reward,'0',2)>0)Ledger::change($this->db,$parent['uid'],$reward,true,'邀请购买会员');
    }
}
