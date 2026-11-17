<?php
declare(strict_types=1);
namespace lib;

use lib\bridge\Money;
use lib\bridge\Protocol;
use RuntimeException;

/** Deferred order creation, signed mall association and gateway-owned payment routing. */
final class MallCheckout
{
    public static function config(): array { return BridgeSettings::read(); }
    public static function mapping(string $trade): ?array
    {
        $db=BridgeSettings::store();
        if(!BridgeSettings::installed($db))return null;
        return $db->row('SELECT * FROM pre_mall_checkout WHERE trade_no=?',[$trade]);
    }
    public static function applies(array $order): bool
    {
        $c=self::config();
        return $c['enabled']&&(!isset($order['addtime'])||strtotime($order['addtime'])>=(int)($c['enabled_since']??0))
            &&empty($order['mall_exempt'])&&in_array((int)($order['tid']??0),[0,3],true)
            &&in_array((int)$order['uid'],$c['merchant_ids'],true);
    }
    public static function prepare(array $request,bool $api=false): void
    {
        try{
            $db=BridgeSettings::store();
            $existing=BridgeSettings::installed($db)?$db->row('SELECT trade_no FROM pre_mall_checkout WHERE merchant_id=? AND out_trade_no=?',[(int)$request['uid'],$request['out_trade_no']]):null;
            if(!$existing){
                if(!self::applies($request))return;
                // Retrying a native order created before activation keeps its original route.
                if($db->row('SELECT trade_no FROM pre_order WHERE uid=? AND out_trade_no=?',[(int)$request['uid'],$request['out_trade_no']]))return;
            }
            self::respond(self::createRequest($request),$api);
        }
        catch(\Throwable $e){
            if($api){echojsonmsg($e->getMessage());exit;}
            sysmsg(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));exit;
        }
    }
    private static function merchant(array $request): array
    {
        global $DB,$conf;
        $merchant=$DB->getRow('SELECT uid,gid,mode,status,pay FROM pre_user WHERE uid=:uid',[':uid'=>$request['uid']]);
        if(!$merchant||!(int)$merchant['status']||!(int)$merchant['pay']||(int)$merchant['mode']!==0)
            throw new RuntimeException('商户不存在、已停用或不是扣费模式');
        $effective=array_replace($conf,getGroupConfig($merchant['gid']));
        if(!empty($effective['pay_payaddstart']))throw new RuntimeException('商城桥接要求关闭随机增减金额');
        \lib\Merchant\PaymentAccess::assertAllowed($request);
        return $merchant;
    }
    public static function createRequest(array $request): array
    {
        $db=BridgeSettings::store();self::merchant($request);
        if(in_array($request['payment_method']??'', ['scan','app','applet'],true)||!empty($request['is_applet']))
            throw new RuntimeException('商城桥接需要浏览器收银，请使用网页或二维码支付');
        $request['money']=Money::decimal(Money::cents((string)$request['money']));
        $request['status']=0;$request['channel_test']=0;
        if(!empty($request['requested_type'])&&!in_array($request['requested_type'],['wxpay','alipay'],true))
            throw new RuntimeException('商城桥接仅支持微信和支付宝，请选择对应支付方式');
        $existing=$db->row('SELECT * FROM pre_mall_checkout WHERE merchant_id=? AND out_trade_no=?',[(int)$request['uid'],$request['out_trade_no']]);
        if(!$existing){
            if($db->row('SELECT trade_no FROM pre_order WHERE uid=? AND out_trade_no=?',[(int)$request['uid'],$request['out_trade_no']]))
                throw new RuntimeException('该商户订单号已使用，请更换订单号');
            $now=time();$request['addtime']=date('Y-m-d H:i:s',$now);
            for($i=0;$i<5;$i++){
                $request['trade_no']=date('YmdHis').random_int(10000,99999);
                $db->query('INSERT IGNORE INTO pre_mall_checkout (trade_no,token,amount_cents,created_at,expires_at,merchant_id,out_trade_no,request_json) VALUES (?,?,?,?,?,?,?,?)',
                    [$request['trade_no'],bin2hex(random_bytes(32)),Money::cents($request['money']),$now,$now+(int)self::config()['ttl'],
                     (int)$request['uid'],$request['out_trade_no'],json_encode($request,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $existing=$db->row('SELECT * FROM pre_mall_checkout WHERE merchant_id=? AND out_trade_no=?',[(int)$request['uid'],$request['out_trade_no']]);
                if($existing)break;
            }
        }
        if(!$existing||$existing['canceled']||(int)$existing['expires_at']<=time())throw new RuntimeException('支付会话已过期或不可用，请更换订单号');
        $saved=json_decode($existing['request_json'],true,32,JSON_THROW_ON_ERROR);
        foreach(['money','name','notify_url','return_url','param','requested_type','channel_id'] as $field)
            if(($saved[$field]??null)!==($request[$field]??null))throw new RuntimeException('订单参数发生变化，请更换订单号');
        $order=$db->row('SELECT status FROM pre_order WHERE trade_no=?',[$existing['trade_no']]);
        if($order&&(int)$order['status']!==0)throw new RuntimeException('订单已完成，请勿重复付款');
        return $existing;
    }
    public static function respond(array $session,bool $api): void
    {
        $url=rtrim(self::config()['gateway_url'],'/').'/mall/entry.php?t='.$session['token'];
        if($api){
            if(!defined('TRADE_NO'))define('TRADE_NO',$session['trade_no']);
            Payment::echoJson(['type'=>'qrcode','page'=>'mall_entry','url'=>$url]);exit;
        }
        header('Location: '.rtrim(self::config()['gateway_url'],'/').'/mall/qrcode.php?t='.$session['token'],true,303);exit;
    }
    public static function token(string $token,bool $allowExpired=false): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('无效结账凭据');
        $db=BridgeSettings::store();
        if(!BridgeSettings::installed($db))throw new RuntimeException('商城桥接未初始化');
        $row=$db->row('SELECT * FROM pre_mall_checkout WHERE token=?',[$token]);
        if(!$row||(!$allowExpired&&(int)$row['expires_at']<=time()))throw new RuntimeException('订单不存在或已过期，请返回重新下单');
        return $row;
    }
    public static function methods(array $request,array $merchant): array
    {
        global $DB;
        if(!empty($request['channel_test'])){
            Channel::merchantPolicy()->assertRoute($request['uid'],$request['type'],$request['channel'],$request['subchannel'],$request['money'],(int)$request['channel_test']);
            $method=$DB->getRow('SELECT * FROM pre_type WHERE id=:id AND status=1',[':id'=>$request['type']]);
            $methods=$method?[(int)$method['id']=>$method]:[];
        }else $methods=Channel::getTypes($request['uid'],$merchant['gid']);
        $list=[];
        foreach($methods as $method){
            if(!in_array($method['name'],['wxpay','alipay'],true))continue;
            if(!empty($request['requested_type'])&&$request['requested_type']!==$method['name'])continue;
            $list[]=['id'=>(int)$method['id'],'name'=>$method['name'],'label'=>$method['showname']];
        }
        if(!$list)throw new RuntimeException('当前商户没有可用的微信或支付宝支付方式');
        return $list;
    }
    public static function open(array $request): array
    {
        $merchant=self::merchant($request);$db=BridgeSettings::store();
        $session=self::mapping((string)$request['trade_no']);
        if(!$session){
            if(!self::applies($request))throw new RuntimeException('当前商户未启用商城桥接');
            $now=time();$amount=Money::cents((string)(($request['realmoney']??null)?:$request['money']));
            if($amount!==Money::cents((string)$request['money']))throw new RuntimeException('桥接订单的成交金额与原订单不一致');
            $db->query('INSERT IGNORE INTO pre_mall_checkout (trade_no,token,amount_cents,created_at,expires_at,merchant_id,out_trade_no,request_json) VALUES (?,?,?,?,?,?,?,?)',
                [$request['trade_no'],bin2hex(random_bytes(32)),$amount,$now,$now+(int)self::config()['ttl'],(int)$request['uid'],$request['out_trade_no'],json_encode($request,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $session=self::mapping((string)$request['trade_no']);
        }
        if(!$session||$session['canceled']||(int)$session['expires_at']<=time())throw new RuntimeException('订单已过期或取消，请重新下单');
        $methods=self::methods($request,$merchant);$config=self::config();
        Protocol::post($config['mall_url'],'/api/bridge/session',[
            'trade_no'=>$session['trade_no'],'merchant_id'=>(int)$request['uid'],'token_hash'=>hash('sha256',$session['token']),
            'amount_cents'=>(int)$session['amount_cents'],'created_at'=>(int)$session['created_at'],'expires_at'=>(int)$session['expires_at'],'methods'=>$methods],$config);
        return $session;
    }
    public static function confirm(string $token): array
    {
        $row=self::token($token);
        return self::open(json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR));
    }
    public static function intercept(string $trade,bool $api=false): void
    {
        $db=BridgeSettings::store();$order=$db->row('SELECT * FROM pre_order WHERE trade_no=?',[$trade]);
        if(!$order||(!self::mapping($trade)&&!self::applies($order)))return;
        self::respond(self::open($order),$api);
    }
    public static function beforePayment(array $order): void
    {
        if((int)($order['status']??0)!==0)return;
        $row=self::mapping((string)$order['trade_no']);
        if(!$row&&!self::applies($order))return;
        if(!$row||!$row['ready'])self::intercept((string)$order['trade_no']);
    }
    public static function reserve(array $data): array { return self::associate($data,false); }
    public static function ready(array $data): array { return self::associate($data,true); }
    private static function associate(array $data,bool $ready): array
    {
        $db=BridgeSettings::store();$db->pdo->beginTransaction();
        try{
            $row=$db->row('SELECT * FROM pre_mall_checkout WHERE trade_no=?'.$db->lockClause(),[(string)($data['trade_no']??'')]);
            if(!$row||$row['canceled']||(int)$row['expires_at']<=time()||(int)$row['amount_cents']!==($data['amount_cents']??0)
                ||!is_string($data['mall_order_id']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$data['mall_order_id']))
                throw new RuntimeException('商城订单或金额校验失败');
            $request=json_decode($row['request_json'],true,32,JSON_THROW_ON_ERROR);
            $merchant=self::merchant($request);$methods=self::methods($request,$merchant);
            $type=(int)($data['type_id']??0);
            if($ready&&!in_array($type,array_column($methods,'id'),true))throw new RuntimeException('当前支付方式不可用');
            if($row['mall_order_id']&&$row['mall_order_id']!==$data['mall_order_id'])throw new RuntimeException('商城订单关联不一致');
            if($ready&&$row['ready']&&(int)$row['type_id']!==$type)throw new RuntimeException('订单已锁定，请使用原支付方式');
            $order=$db->row('SELECT * FROM pre_order WHERE trade_no=?',[$row['trade_no']]);
            if($order&&((int)$order['status']!==0||Money::cents((string)$order['money'])!==(int)$row['amount_cents']))throw new RuntimeException('订单状态或金额已经变化');
            $name=trim((string)($data['product_name']??''));
            if($name==='')throw new RuntimeException('缺少已确认的商品名称');
            if(!$order){
                $fields=['trade_no','out_trade_no','uid','tid','addtime','name','money','notify_url','return_url','param','domain','ip','version','cert_info','buyer','type','channel','subchannel','realmoney','getmoney','status','channel_test'];
                $native=array_replace(['type'=>0,'channel'=>0,'subchannel'=>0],array_intersect_key($request,array_flip($fields)));$native['addtime']=date('Y-m-d H:i:s');
                $db->insert('order',$native);
            }
            $product=is_array($data['product_snapshot']??null)?$data['product_snapshot']:[];
            $snapshot=json_encode(array_replace($product,['name'=>$name]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(strlen($snapshot)>16384)throw new RuntimeException('商品快照超限');
            $db->query('UPDATE pre_mall_checkout SET mall_order_id=?,product_json=?,type_id=?,ready=? WHERE trade_no=?',
                [$data['mall_order_id'],$row['product_json']?:$snapshot,$ready?$type:(int)$row['type_id'],$ready?1:(int)$row['ready'],$row['trade_no']]);
            $db->pdo->commit();
            return $ready?['url'=>self::config()['gateway_url'].'/mall/pay.php?t='.$row['token']]:['trade_no'=>$row['trade_no'],'mall_order_id'=>$data['mall_order_id']];
        }catch(\Throwable $e){if($db->pdo->inTransaction())$db->pdo->rollBack();throw $e;}
    }
    public static function assertPayment(array $order): void
    {
        $row=self::mapping((string)$order['trade_no']);
        if(!$row)return;
        if(!$row['ready']||$row['canceled']||(int)$row['expires_at']<=time()||(int)$row['type_id']!==(int)$order['type']
            ||Money::cents((string)$order['realmoney'])!==(int)$row['amount_cents'])
            throw new RuntimeException('请通过商品收银台确认订单，或检查支付通道配置');
        self::merchant($order);MallLifecycle::start($row['token']);
    }
}
