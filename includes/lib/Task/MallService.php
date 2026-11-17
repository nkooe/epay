<?php
declare(strict_types=1);
namespace lib\Task;
use lib\AdminApp\Store;

/** One built-in child uses the same generation, lease, control and log lifecycle as plugins. */
final class MallService
{
    public const PLUGIN='_mall_bridge';
    public static function id(): string { return substr(hash('sha256','epay:mall-state-sync'),0,32); }
    public static function isInstance(string $id): bool { return preg_match('/^'.self::id().'g[1-9][0-9]{0,8}$/D',$id)===1; }
    public static function configurationDigest(Store $db): string
    {
        return hash('sha256',(string)$db->value('SELECT payload FROM pre_mall_settings WHERE id=1'));
    }
    public static function item(Store $db): ?array
    {
        if(!\lib\BridgeSettings::installed($db))return null;
        $config=\lib\BridgeSettings::row($db)['config'];
        $enabled=$config['secret']!==''&&$config['gateway_url']!==''&&$config['mall_url']!=='';
        $hash=hash_init('sha256');
        foreach(['MallSync.php','BridgeSettings.php','Task/MallService.php','bridge/Protocol.php'] as $file)hash_update_file($hash,dirname(__DIR__).'/'.$file);
        hash_update($hash,self::configurationDigest($db));
        return ['id'=>self::id(),'channel_id'=>0,'plugin'=>self::PLUGIN,'service_key'=>'mall-sync','name'=>'商城状态同步',
            'resource_key'=>'system:mall-sync','channels'=>[],'subchannels'=>[],'targets'=>[],
            'channel_enabled'=>$enabled,'configuration_digest'=>self::configurationDigest($db),'code_digest'=>hash_final($hash),
            'spec'=>['key'=>'mall-sync','name'=>'商城状态同步','scope'=>'system','on_start'=>'onStart','run'=>'run','on_stop'=>'onStop',
                'ready_seconds'=>30,'heartbeat_seconds'=>60,'stop_seconds'=>25]];
    }
    public static function ensure(Store $db): void
    {
        $item=self::item($db);
        if(!$item)return;
        $item['digest']=hash('sha256',$item['code_digest'].json_encode([[],[]],JSON_THROW_ON_ERROR));
        $data=array_intersect_key($item,array_flip(['id','channel_id','plugin','service_key','name','resource_key','digest']));
        $data['desired']=$item['channel_enabled']?1:0;
        if(!$db->row('SELECT id FROM pre_plugin_service WHERE id=?',[self::id()])){
            // The scheduler and an administrator can discover the built-in at the same instant.
            try{$db->insert('plugin_service',$data);}
            catch(\PDOException $e){if(!$db->row('SELECT id FROM pre_plugin_service WHERE id=?',[self::id()]))throw $e;}
        }
    }
    public static function onStart(array $channels,ServiceContext $context): void { $context->ready(); }
    public static function run(array $channels,ServiceContext $context): void
    {
        $db=\lib\BridgeSettings::store();
        while(!$context->stopRequested()){
            $context->checkpoint();
            $result=\lib\MallSync::batch($db,10,null,static function()use($context){$context->checkpoint();$context->heartbeat();});
            if($result['sent']||$result['failed'])error_log('商城状态同步：成功 '.$result['sent'].'，待重试 '.$result['failed']);
            $summary=\lib\MallSync::summary($db);
            if($summary['failed'])$context->recordFailure($summary['last_error']);else $context->clearFailure();
            for($i=0;$i<10&&!$context->stopRequested();$i++){if(!$context->heartbeat())return;usleep(1000000);}
        }
    }
    public static function onStop(array $channels,ServiceContext $context): void {}
}
