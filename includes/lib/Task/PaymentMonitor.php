<?php
namespace lib\Task;

/** Cooperative payment polling. Business matching remains inside each plugin. */
final class PaymentMonitor
{
    private static $context;
    private static $plugin;
    private static $targets=[];
    private static $activeTarget;

    public static function start($plugin,array $channels,ServiceContext $context,callable $validate)
    {
        if(self::$context)throw new \LogicException('监控服务已启动');
        self::$context=$context;self::$plugin=$plugin;self::$targets=[];
        foreach($channels as $channel)self::$targets[(int)$channel['id'].':'.(int)($channel['subid']??0)]=true;
        foreach($channels as $channel){
            if(($channel['plugin']??'')!==$plugin)throw new ServiceFault('监控通道归属错误');
            self::activate(['channel_id'=>(int)$channel['id'],'subchannel_id'=>(int)($channel['subid']??0)]);
            $validate($channel);
        }
        if(!self::$targets)throw new ServiceFault('没有可监控的启用通道');
        global $DB;
        if((int)$DB->getColumn('SELECT 1')!==1)throw new \RuntimeException('监控数据库检查失败');
        $context->checkpoint();$context->ready();
        echo $plugin.' 监控就绪，目标='.count(self::$targets).'；空闲时不探测支付接口'.PHP_EOL;
    }
    public static function targets(array $channels)
    {
        return array_map(static function($c){return ['channel_id'=>(int)$c['id'],'subchannel_id'=>(int)($c['subid']??0)];},$channels);
    }
    public static function run(array $channels,ServiceContext $context,$interval,callable $cycle)
    {
        $state=[];
        while(!$context->stopRequested()){
            $started=microtime(true);$context->checkpoint();
            $cycle(self::targets($channels),$state);
            $context->checkpoint();if(!$context->heartbeat())break;
            $until=$started+$interval;
            while(microtime(true)<$until&&!$context->stopRequested())usleep(100000);
        }
    }
    public static function checkpoint()
    {
        if(!self::$context)throw new \LogicException('支付监控必须由进程管理启动');
        self::$context->checkpoint();
    }
    public static function activate(array $target)
    {
        if(!isset(self::$targets[(int)$target['channel_id'].':'.(int)$target['subchannel_id']]))throw new ServiceFault('监控目标越界');
        self::$activeTarget=$target;
    }
    public static function activeFor($plugin){return self::$context!==null&&self::$plugin===$plugin;}
    public static function owns(array $channel){return isset(self::$targets[(int)$channel['id'].':'.(int)($channel['subid']??0)]);}
    public static function saveCredentials($channel,array $updates,array $allowed,$childOverrides=false)
    {
        $id=is_array($channel)?(int)$channel['id']:(int)$channel;
        $target=is_array($channel)?['channel_id'=>$id,'subchannel_id'=>(int)($channel['subid']??0)]:self::$activeTarget;
        if(!$target||(int)$target['channel_id']!==$id)throw new ServiceFault('凭据刷新通道越界');
        self::activate($target);
        return self::$context->saveCredentials($target,$updates,$allowed,$childOverrides);
    }
    public static function progress()
    {
        self::checkpoint();
        if(!self::$context->heartbeat())throw new ServiceStopped('服务正在停止');
    }
    public static function notify(array $order,...$args)
    {
        self::checkpoint();global $DB,$channel;
        $flow=trim((string)($args[0]??''));
        if($flow==='')throw new ServiceFault('到账流水号不能为空');
        $lock='epay.monitor.'.substr(hash('sha256',self::$plugin.':'.$flow),0,48);
        if((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)',[':lock_name'=>$lock])!==1)return;
        try{
            self::checkpoint();
            $fresh=$DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1',[':trade_no'=>(string)$order['trade_no']]);
            if(!$fresh||(int)$fresh['status']!==0)return;
            $key=(int)$fresh['channel'].':'.(int)$fresh['subchannel'];
            if(!isset(self::$targets[$key])||($order['plugin']??self::$plugin)!==self::$plugin||
                (int)$fresh['channel']!==(int)$order['channel']||(int)$fresh['subchannel']!==(int)$order['subchannel']||
                (string)$fresh['realmoney']!==(string)$order['realmoney']||($fresh['ext']??'')!==($order['ext']??'')||
                (int)$fresh['type']!==(int)$order['type']||(string)$fresh['addtime']!==(string)$order['addtime']||
                (string)$fresh['money']!==(string)$order['money']||(int)$fresh['uid']!==(int)$order['uid'])throw new ServiceFault('订单归属、金额或上下文已改变，拒绝结算');
            if($DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:flow AND status=1 LIMIT 1',[':flow'=>$flow]))return;
            $channel=(int)$fresh['subchannel']?\lib\Channel::getSub((int)$fresh['subchannel']):\lib\Channel::get((int)$fresh['channel']);
            if(!$channel||$channel['plugin']!==self::$plugin)throw new ServiceFault('订单插件归属已变化');
            \processNotify($order,...$args);
        }finally{$DB->getColumn('SELECT RELEASE_LOCK(:lock_name)',[':lock_name'=>$lock]);}
    }
    public static function stop(array $channels,ServiceContext $context)
    {
        echo self::$plugin.' 监控已停止'.PHP_EOL;
        self::$context=null;self::$plugin=null;self::$targets=[];self::$activeTarget=null;
    }
    public static function requireFields(array $config,array $fields)
    {
        foreach($fields as $field)if(!isset($config[$field])||trim((string)$config[$field])===''||preg_match('/^\[[^\[\]]+\]$/D',(string)$config[$field]))throw new ServiceFault('请先完成通道配置：'.$field);
    }
}
