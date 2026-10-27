<?php
namespace lib;

class Order
{
    public static function freeze($trade_no){
        global $DB;
        $row = $DB->find('order', 'uid,getmoney,status,channel', ['trade_no'=>$trade_no]);
        if(!$row)
            return ['code'=>-1, 'msg'=>'当前订单不存在！'];
        if($row['status']!=1)
            return ['code'=>-1, 'msg'=>'只支持冻结已支付状态的订单'];
        $channel = \lib\Channel::get($row['channel']);
        if($channel['mode']==1)
            return ['code'=>-1, 'msg'=>'当前支付通道为商户直清，不支持冻结'];
        if($row['getmoney']>0){
            changeUserMoney($row['uid'], $row['getmoney'], false, '订单冻结', $trade_no);
            $DB->update('order', ['status'=>3], ['trade_no'=>$trade_no]);
        }
        return ['code'=>0, 'msg'=>'已成功从UID:'.$row['uid'].'冻结'.$row['getmoney'].'元余额'];
    }

    public static function unfreeze($trade_no){
        global $DB;
        $row = $DB->find('order', 'uid,getmoney,status,channel', ['trade_no'=>$trade_no]);
        if(!$row)
            return ['code'=>-1, 'msg'=>'当前订单不存在！'];
        if($row['status']!=3)
            return ['code'=>-1, 'msg'=>'只支持解冻已冻结状态的订单'];
        $channel = \lib\Channel::get($row['channel']);
        if($channel['mode']==1)
            return ['code'=>-1, 'msg'=>'当前支付通道为商户直清，不支持冻结'];
        if($row['getmoney']>0){
            changeUserMoney($row['uid'], $row['getmoney'], true, '订单解冻', $trade_no);
            $DB->update('order', ['status'=>1], ['trade_no'=>$trade_no]);
        }
        return ['code'=>0, 'msg'=>'已成功为UID:'.$row['uid'].'恢复'.$row['getmoney'].'元余额'];
    }

    public static function refund_info($trade_no, $api = 0, $uid = 0){
        global $DB;
        $where = ['trade_no'=>$trade_no];
        if($uid > 0) $where['uid'] = $uid;
        $order = $DB->find('order', '*', $where);
        if(!$order)
            return ['code'=>-1, 'msg'=>'当前订单不存在！'];
        if(!in_array($order['status'], [1,2,3]))
            return ['code'=>-1, 'msg'=>'该订单状态不支持退款！'];
        if($order['status'] == 2 && empty($order['refundmoney'])) return ['code'=>-1, 'msg'=>'该订单已退款！'];
        if($order['status'] == 2 && $order['refundmoney'] > 0 && $order['refundmoney'] >= $order['realmoney']) return ['code'=>-1, 'msg'=>'该订单已全额退款！'];
        $money = !empty($order['refundmoney']) ? round($order['realmoney'] - $order['refundmoney'], 2) : $order['realmoney'];

        if($api==1){
            if(!$order['api_trade_no']) return ['code'=>-1, 'msg'=>'接口订单号不存在'];
            $channel = \lib\Channel::get($order['channel']);
            if(!$channel) return ['code'=>-1, 'msg'=>'当前支付通道信息不存在'];
            if(\lib\Plugin::isrefund($channel['plugin'])==false){
                return ['code'=>-1, 'msg'=>'当前支付通道不支持API退款'];
            }
        }

        return ['code'=>0, 'money'=>$money];
    }

    public static function refund($refund_no, $trade_no, $money, $api = 0, $uid = 0, $out_refund_no = null){
        global $DB;
        try {
            $store = \lib\AdminApp\Runtime::legacyStore($DB);
            $result = (new \lib\AdminApp\Operations($store, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'))
                ->refundOrder($refund_no, $trade_no, $money, (int)$api === 1, $uid, $out_refund_no);
            return ['code'=>0] + $result;
        } catch (\lib\AdminApp\ApiError $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    public static function close($trade_no, $uid = 0){
        global $DB, $order, $conf;

        $where = ['trade_no'=>$trade_no];
        if($uid > 0) $where['uid'] = $uid;
        $order = $DB->find('order', '*', $where);
        if(!$order)
            return ['code'=>-1, 'msg'=>'当前订单不存在！'];
        if($order['status'] != 0)
            return ['code'=>-1, 'msg'=>'该订单状态不支持关闭！'];

        if(!\lib\Plugin::close($trade_no, $message)){
            return ['code'=>-1, 'msg'=>$message];
        }
        return ['code'=>0];
    }
}
