<?php
declare(strict_types=1);
namespace lib;
use lib\AdminApp\Store;
use RuntimeException;

final class BridgeSettings
{
    public static function store(): Store { global $DB; return \lib\AdminApp\Runtime::legacyStore($DB); }
    public static function installed(Store $db): bool
    {
        try { return (string)$db->value("SELECT v FROM pre_config WHERE k='mall_bridge_schema'")==='1'; }
        catch (\PDOException $e) {
            // Minimal tools/test fixtures can omit the application configuration table entirely.
            if($e->getCode()==='42S02'||strpos($e->getMessage(),'no such table:')!==false)return false;
            throw $e;
        }
    }
    public static function defaults(): array
    {
        return ['enabled'=>false,'app_id'=>'epay-mall','secret'=>'','gateway_url'=>'','mall_url'=>'','merchant_ids'=>[],'ttl'=>900];
    }
    public static function row(?Store $db=null): array
    {
        $db=$db??self::store();
        if(!self::installed($db))return ['config'=>self::defaults(),'revision'=>0,'installed'=>false];
        $row=$db->row('SELECT payload,revision FROM pre_mall_settings WHERE id=1');
        if(!$row)throw new RuntimeException('桥接设置未初始化，请运行配套升级脚本');
        return ['config'=>array_replace(self::defaults(),json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)),'revision'=>(int)$row['revision'],'installed'=>true];
    }
    public static function read(): array { return self::row()['config']; }
    public static function validate(array $input,array $previous,Store $db): array
    {
        if(array_diff(array_keys($input),array_keys(self::defaults())))throw new RuntimeException('桥接设置包含未知字段');
        if(!is_bool($input['enabled']??null))throw new RuntimeException('请选择桥接开关');
        if(!$input['enabled']&&empty($previous['secret'])&&empty($input['secret']))return self::defaults();
        $app=$input['app_id']??null;
        if(!is_string($app)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$app))throw new RuntimeException('接入标识须为 1～64 位字母、数字、下划线或短横线');
        if($app!==$previous['app_id']&&(int)$db->value('SELECT COUNT(*) FROM pre_mall_checkout'))throw new RuntimeException('已有桥接会话，不能更换接入标识');
        if(!is_string($input['secret']??''))throw new RuntimeException('桥接密钥格式无效');
        $secret=trim($input['secret']??'')?:$previous['secret'];
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new RuntimeException('请填写独立的 64 位十六进制密钥');
        $result=['enabled'=>$input['enabled'],'app_id'=>$app,'secret'=>$secret,
            'enabled_since'=>$input['enabled']&&!$previous['enabled']?time():($previous['enabled_since']??0)];
        foreach(['gateway_url','mall_url'] as $key){
            if(!is_string($input[$key]??null))throw new RuntimeException('请填写 HTTPS 根地址');
            $value=rtrim(trim($input[$key]),'/');$url=parse_url($value);
            if(strlen($value)>255||!filter_var($value,FILTER_VALIDATE_URL)||!$url||($url['scheme']??'')!=='https'||empty($url['host'])
                ||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||($url['path']??'')!=='')
                throw new RuntimeException('请填写 HTTPS 根地址，不含路径、参数或账号密码');
            $result[$key]=$value;
        }
        $ids=$input['merchant_ids']??null;
        if(!is_array($ids)||!$ids||count($ids)>100)throw new RuntimeException('请填写 1～100 个商户 PID');
        foreach($ids as $id){
            if(!is_int($id)||$id<1)throw new RuntimeException('商户 PID 必须为正整数');
        }
        // Configuration may be prepared before merchant onboarding; payment admission checks live eligibility.
        $ttl=$input['ttl']??null;
        if(!is_int($ttl)||$ttl<60||$ttl>3600)throw new RuntimeException('有效期须为 60～3600 秒');
        $result['merchant_ids']=array_values(array_unique($ids));$result['ttl']=$ttl;
        if($previous['secret']!==''&&($result['secret']!==$previous['secret']||$result['gateway_url']!==$previous['gateway_url']||$result['mall_url']!==$previous['mall_url'])){
            if($db->value('SELECT COUNT(*) FROM pre_mall_checkout WHERE state_revision>synced_revision OR (paid_confirmed=0 AND canceled=0 AND expires_at>?)',[time()]))
                throw new RuntimeException('存在未完成或待同步订单，请处理后再更换域名或密钥');
        }
        return $result;
    }
    public static function save(array $input,int $revision): array
    {
        $db=self::store();
        if(!self::installed($db))throw new RuntimeException('请先运行 php bin/upgrade-mall-bridge.php --apply');
        $db->pdo->beginTransaction();
        try{
            $row=$db->row('SELECT * FROM pre_mall_settings WHERE id=1'.$db->lockClause());
            if(!$row||(int)$row['revision']!==$revision)throw new RuntimeException('配置已更新，请刷新后重试');
            $previous=array_replace(self::defaults(),json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR));
            $config=self::validate($input,$previous,$db);
            $db->query('UPDATE pre_mall_settings SET payload=?,revision=revision+1 WHERE id=1',[json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $db->audit('商城桥接设置',$_SERVER['REMOTE_ADDR']??'127.0.0.1',['enabled'=>$config['enabled'],'app_id'=>$config['app_id'],'merchant_count'=>count($config['merchant_ids'])]);
            $db->pdo->commit();
            \lib\Task\MallService::ensure($db);
            if($config['enabled']&&!$previous['enabled'])$db->query('UPDATE pre_plugin_service SET desired=1,revision=revision+1 WHERE id=? AND desired=0',[\lib\Task\MallService::id()]);
            return ['revision'=>$revision+1];
        }catch(\Throwable $e){if($db->pdo->inTransaction())$db->pdo->rollBack();throw $e;}
    }
}
