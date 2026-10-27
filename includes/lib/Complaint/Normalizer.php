<?php
namespace lib\Complaint;

final class Normalizer
{
    public static function identifier($value)
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$value)) throw new \InvalidArgumentException('上游投诉编号不正确');
        return $value;
    }
    private static function text($value, $max = 12000) { return mb_substr(is_scalar($value)?(string)$value:'',0,$max); }
    private static function time($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/D',$value)) throw new \RuntimeException('上游投诉时间格式不正确');
        try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'); }
        catch (\Exception $e) { throw new \RuntimeException('上游投诉时间无法解析'); }
    }
    public static function images($value, $provider)
    {
        $images=[];
        foreach (is_array($value)?$value:[] as $item) {
            if (!is_string($item)) continue;
            if ($provider==='wechat') {
                if (preg_match('/^[a-zA-Z0-9_=-]{1,512}$/D',$item)) $id=$item;
                else { $p=parse_url($item); if (($p['scheme']??'')!=='https'||($p['host']??'')!=='api.mch.weixin.qq.com'||!preg_match('~/v3/merchant-service/images/([a-zA-Z0-9_=-]{1,512})$~D',$p['path']??'',$m)) continue; $id=$m[1]; }
                $images[]=['kind'=>'wechat','id'=>$id];
            } else {
                $p=parse_url($item);$host=$p['host']??'';
                if (($p['scheme']??'')==='https'&&!isset($p['user'])&&!isset($p['pass'])&&preg_match('/(^|\.)(alipay\.com|alipayobjects\.com|alicdn\.com)$/D',$host)) $images[]=['kind'=>'url','url'=>$item];
            }
            if(count($images)>=20)break;
        }
        return $images;
    }
    public static function convert($provider, array $info)
    {
        $wx=$provider==='wechat';
        $third=self::identifier($info[$wx?'complaint_id':'complain_event_id']??null);
        $remote=$info[$wx?'complaint_state':'status']??'';
        $states=$wx?['PENDING'=>0,'PROCESSING'=>1,'PROCESSED'=>2]:['MERCHANT_PROCESSING'=>0,'MERCHANT_FEEDBACKED'=>1,'PLATFORM_PROCESSING'=>1,'FINISHED'=>2,'CANCELLED'=>2,'PLATFORM_FINISH'=>2,'CLOSED'=>2,'REPORT_SUCCEED'=>2];
        if(!isset($states[$remote]))throw new \RuntimeException('上游返回未支持的投诉状态：'.self::text($remote,80));
        $orders=[];$images=[];
        if($wx){
            foreach($info['complaint_order_info']??[] as $order)$orders[]=['out_trade_no'=>self::text($order['out_trade_no']??'',128),'transaction_id'=>self::text($order['transaction_id']??'',128)];
            foreach($info['complaint_media_list']??[] as $media)$images=array_merge($images,self::images($media['media_url']??[],$provider));
            $amount=$info['complaint_order_info'][0]['amount']??0;
            if(!is_numeric($amount)||$amount<0||$amount>999999999999)throw new \RuntimeException('上游投诉金额不正确');
            $money=number_format($amount/100,2,'.','');
        }else{
            $orders[]=['out_trade_no'=>self::text($info['merchant_order_no']??'',128),'transaction_id'=>self::text($info['trade_no']??'',128)];
            $images=self::images($info['images']??[],$provider);
            $amount=(string)($info['trade_amount']??'0');
            if(!preg_match('/^\d{1,10}(\.\d{1,2})?$/D',$amount))throw new \RuntimeException('上游投诉金额不正确');
            $money=number_format((float)$amount,2,'.','');
        }
        $history=[];
        foreach($info[$wx?'_history':'reply_detail_infos']??[] as $entry){
            $historyImages=$wx?self::images($entry['complaint_media_list']['media_url']??[],$provider):self::images($entry['images']??[],$provider);
            $history[]=['actor'=>self::text($entry[$wx?'operator':'replier_name']??'',100),'role'=>self::text($entry['replier_role']??'',40),
                'time'=>self::time($entry[$wx?'operate_time':'gmt_create']??''),'content'=>self::text($entry[$wx?'operate_details':'content']??''),'images'=>$historyImages];
        }
        if(count($history)>500)throw new \RuntimeException('协商记录数量超出单次处理范围');
        usort($history,static function($a,$b){return strcmp($a['time'],$b['time']);});
        return ['thirdid'=>$third,'remote_status'=>$remote,'status'=>$states[$remote],
            'thirdmchid'=>self::text($info['complainted_mchid']??'',64),
            'title'=>self::text($info[$wx?'problem_description':'complain_reason']??'',255),
            'content'=>self::text($info[$wx?'complaint_detail':'content']??''),
            'type'=>self::text($info[$wx?'problem_type':'leaf_category_name']??'',128),
            'money'=>$money,'phone'=>$wx?'':self::text($info['phone_no']??'',64),
            'created_at'=>self::time($info[$wx?'complaint_time':'gmt_create']??''),
            'remote_updated_at'=>$wx?null:self::time($info['gmt_modified']??$info['gmt_create']??''),
            'orders'=>$orders,'images'=>$images,'history'=>$history,
            'full_refunded'=>$wx?($info['complaint_full_refunded']??false):null,
            'problem_type'=>$wx?self::text($info['problem_type']??'',80):'',
            'requested_refund'=>isset($info['apply_refund_amount'])?number_format($info['apply_refund_amount']/100,2,'.',''):null];
    }
}
