<?php
namespace EpayMonitor\yunxingyifuxd;
use \yunxingyifuxd_plugin;
use \RuntimeException;
use \lib\Task\PaymentMonitor;

function monitor($target)
{
    global $DB,$channel;
    $cid=(int)$target['channel_id'];$sid=(int)$target['subchannel_id'];
    $channel=$sid?\lib\Channel::getSub($sid):\lib\Channel::get($cid);
    if(!$channel||$channel['plugin']!=='yunxingyifuxd')throw new RuntimeException('监控通道已变化');
    $expired=$DB->getAll("SELECT * FROM pre_order WHERE channel='{$cid}' AND subchannel='{$sid}' AND status=0 AND addtime<DATE_SUB(NOW(),INTERVAL 5 MINUTE) AND (ext IS NULL OR ext NOT LIKE '%yunxingyifuxd_expired%')");
    foreach($expired as $order){PaymentMonitor::checkpoint();yunxingyifuxd_plugin::markExpired($order);}
    $orders=$DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='{$cid}' AND A.subchannel='{$sid}' AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL 8 MINUTE) ORDER BY A.addtime");
    if(!$orders)return;
    $result=yunxingyifuxd_plugin::getOrderList($channel,time()-yunxingyifuxd_plugin::MATCH_SECONDS);
    if((int)$result['code']!==0)throw new RuntimeException((string)$result['msg']);
    $matched=[];
    foreach($result['items'] as $item){
        $trade=trim((string)($item['renPageName']??''));
        $amount=isset($item['amount'])?round((float)$item['amount']/100,2):0;
        $flow=trim((string)($item['orderId']??''));
        if((int)($item['payState']??0)!==1||$trade===''||$flow===''||$amount<=0||isset($matched[$trade]))continue;
        foreach($orders as $order){
            $created=yunxingyifuxd_plugin::parseOrderTime($order['addtime']);
            $paid=yunxingyifuxd_plugin::parseOrderTime($item['updateTime']??'');
            if($order['trade_no']!==$trade||abs((float)$order['realmoney']-$amount)>0.001||$created<=0||$paid<=$created||$paid>$created+yunxingyifuxd_plugin::MATCH_SECONDS)continue;
            $matched[$trade]=true;$order['plugin']='yunxingyifuxd';
            PaymentMonitor::notify($order,$flow,null,(string)($item['edenOrderId']??''),null,$item['updateTime']??null);
            break;
        }
    }
}
