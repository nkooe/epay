<?php
namespace lib\AdminApp;

final class ChannelTest
{
    public static function create(Store $db, array $conf, array $data, $ip)
    {
        $id=$data['id'] ?? ''; $amount=$data['money'] ?? '';
        if(!is_scalar($id)||!preg_match('/^[1-9][0-9]{0,10}$/D',(string)$id))throw new ApiError('通道编号无效');
        if(!is_string($amount)||!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',$amount)||bccomp($amount,'0',2)<=0)throw new ApiError('请输入大于零、最多两位小数的金额');
        $amount=bcadd($amount,'0',2);
        $channel=$db->row('SELECT * FROM pre_channel WHERE id=?',[(int)$id]);
        if(!$channel)throw new ApiError('通道不存在',404);
        if(empty($channel['config']))throw new ApiError('请先配置通道密钥');
        $type=$db->row('SELECT id FROM pre_type WHERE id=?',[$channel['type']]);
        if(!$type)throw new ApiError('支付方式不存在');
        $uid=(int)($conf['test_pay_uid'] ?? 0);
        if(!$uid||!$db->row('SELECT uid FROM pre_user WHERE uid=?',[$uid]))throw new ApiError('请先在系统设置中配置有效的测试支付收款商户 ID');
        foreach([['pay_minmoney','pay_maxmoney',$conf],['paymin','paymax',$channel]] as $limits) {
            list($min,$max,$source)=$limits;
            if(is_numeric($source[$min] ?? null)&&bccomp((string)$source[$min],'0',2)>0&&bccomp($amount,(string)$source[$min],2)<0)throw new ApiError('支付金额低于最低限额 '.$source[$min].' 元');
            if(is_numeric($source[$max] ?? null)&&bccomp((string)$source[$max],'0',2)>0&&bccomp($amount,(string)$source[$max],2)>0)throw new ApiError('支付金额超过最高限额 '.$source[$max].' 元');
        }
        $base=rtrim((string)($conf['localurl'] ?? ''),'/');
        if(!filter_var($base,FILTER_VALIDATE_URL)||!in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true)) {
            $host=$_SERVER['HTTP_HOST'] ?? '';
            if(!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/D',$host))throw new ApiError('平台地址未配置，请先设置 localurl');
            $base='https://'.$host.rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/appapi.php')),'/');
        }
        $trade=date('YmdHis').random_int(10000,99999);
        $return=$base.'/user/test.php?ok=1&trade_no='.$trade;
        // Match the website test order, preselecting this exact channel for public submit2.php.
        $db->insert('order',['trade_no'=>$trade,'out_trade_no'=>$trade,'uid'=>$uid,'tid'=>3,'addtime'=>date('Y-m-d H:i:s'),'name'=>'支付测试','money'=>$amount,'type'=>$channel['type'],'channel'=>(int)$id,'subchannel'=>0,'realmoney'=>$amount,'getmoney'=>$amount,'notify_url'=>$return,'return_url'=>$return,'domain'=>parse_url($base,PHP_URL_HOST),'ip'=>$ip,'status'=>0]);
        $db->audit('通道测试支付',$ip,['trade_no'=>$trade,'channel'=>(int)$id,'money'=>$amount]);
        // This public payment entry needs no administrator cookie or bearer in the browser.
        return ['trade_no'=>$trade,'money'=>$amount,'url'=>'submit2.php?typeid='.$channel['type'].'&trade_no='.$trade];
    }
}
