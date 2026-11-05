<?php
namespace lib\Merchant;

use lib\AdminApp\Store;

final class FinancialPolicy
{
    public static function config(Store $db,array $base,array $user)
    {
        $g=$db->row('SELECT config FROM pre_group WHERE gid=?',[$user['gid']??0]);
        if(!$g)$g=$db->row('SELECT config FROM pre_group WHERE gid=0');
        foreach((array)json_decode($g['config']??'{}',true) as $k=>$v){
            if($v===null||$v==='')continue;
            if(in_array($k,['settle_open','settle_type','settle_transfer','user_transfer'],true)&&$v=='0')continue;
            $base[$k]=$k==='settle_type'?$v-1:$v;
        }
        return $base;
    }
    public static function check(array $conf,array $user,$action,$api=false)
    {
        if(empty($user['status']))throw new \DomainException('商户已停用');
        if($action==='refund'){
            if(empty($conf['user_refund']))throw new \DomainException('管理员未开启商户自助退款');
            if($api&&empty($user['refund']))throw new \DomainException('商户未开启订单退款API接口');
        }else{
            if(empty($user['settle']))throw new \DomainException('商户结算已停用');
            if($action==='transfer'){
                if(empty($conf['user_transfer']))throw new \DomainException('管理员未开启代付功能');
                if($api&&empty($user['transfer']))throw new \DomainException('商户未开启代付API接口');
            }
        }
    }
    public static function available(Store $db,array $conf,array $user,$transfer=false)
    {
        $amount=(string)$user['money'];
        if((int)($conf['settle_type']??0)===1){
            $today=$db->value('SELECT SUM(realmoney) FROM pre_order WHERE uid=? AND status=1 AND endtime>=?'.($transfer?' AND tid<>2':''),[$user['uid'],date('Y-m-d').' 00:00:00'])?:'0';
            $amount=bcsub($amount,(string)$today,2);
        }
        $reserve=(string)($user['remain_money']??'0');
        if(strpos($reserve,'%')!==false){
            $rate=str_replace('%','',$reserve);
            if(is_numeric($rate)&&bccomp($rate,'0',4)>0&&bccomp($rate,'100',4)<0)$amount=bcsub($amount,bcmul($amount,bcdiv($rate,'100',8),2),2);
        }elseif(is_numeric($reserve)&&bccomp($reserve,'0',2)>0)$amount=bcsub($amount,$reserve,2);
        return bccomp($amount,'0',2)<0?'0.00':$amount;
    }
}
