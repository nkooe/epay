<?php
namespace lib\AdminApp;

/** 收集旧插件写入项，再统一保存到选定的原生管理目标。 */
final class PluginConfig
{
    const KEY='__app_plugin_credentials';
    const FIELDS=[
        'yunwechatxd'=>['sid','sid_login_mode','sid_pay_mode','cloud_ref','aid','account_type','shop_id'],
        'yunalipayxd'=>['cookie','uid','user_id','pid','nickname','avatar','cloud_ref','alipay_uid','alipay_cookie','login_uid','login_mode','login_type','bound_login_mode'],
        'yunkdskxd'=>['sid','book_sid','aid','cloud_ref','sid_login_mode','shop_id','bound_login_mode'],
        'yunskljxd'=>['sid','aid','account_type','shop_id','cloud_ref','sid_login_mode','sid_permission_mode','bound_login_mode'],
        'yunqnmxd'=>['sid','yyb_ref','guanjia_ref','custom_session_key','tally_openid','bound_login_mode'],
        'yundianyxd'=>['personal_sid','personal_payee_openid','personal_payee_name','personal_payee_shop','personal_payee_list','personal_bind_token','personal_yyb_ref','personal_guanjia_ref','personal_bound_login_mode','business_sid','business_aid','business_shop_id','business_account_list','business_bind_token','business_yyb_ref','business_guanjia_ref','business_bound_login_mode'],
        'yunxingyifuxd'=>['appsession','xingyi_dept_id'],
        'jktongypc'=>['pay_mode','sid','payment_link_sid','payment_link_shop_id','receipt_sid','receipt_shop_id','receipt_account_ref'],
        'alipaybillautoxd'=>['app_id','app_secret','pid','app_data_hash','app_data_url'],
    ];
    private static $context;
    public static function activeFor($plugin){return self::$context!==null&&self::$context['plugin']===$plugin;}
    public static function capture($plugin,$id,array $updates)
    {
        if(!self::activeFor($plugin)||(int)$id!==self::$context['id'])throw new ApiError('插件配置对象已变化',409);
        foreach($updates as $key=>$value){
            if(!in_array($key,self::FIELDS[$plugin]??[],true)||!is_scalar($value))throw new ApiError('插件返回未声明的凭据字段',422);
            self::$context['updates'][$key]=(string)$value;
        }
        return true;
    }
    public static function collect(array $channel,callable $call)
    {
        if(self::$context!==null)throw new \LogicException('Nested native plugin invocation');
        self::$context=['plugin'=>$channel['plugin'],'id'=>(int)$channel['id'],'updates'=>[]];
        try{$result=$call();return [$result,self::$context['updates']];}finally{self::$context=null;}
    }
    public static function resolve(array $channel,array $info)
    {
        $saved=$info[self::KEY]??[];
        if(($saved['plugin']??'')!==$channel['plugin']||!is_array($saved['values']??null))return $channel;
        foreach($saved['values'] as $key=>$value)if(in_array($key,self::FIELDS[$channel['plugin']]??[],true)&&is_scalar($value))$channel[$key]=(string)$value;
        return $channel;
    }
    public static function childUpdates($plugin,array $parent,array $child,array $updates)
    {
        $runtime=$child[self::KEY]??[];
        if(!is_array($runtime)||($runtime['plugin']??'')!==$plugin||!is_array($runtime['values']??null))$runtime=['plugin'=>$plugin,'values'=>[]];
        foreach($updates as $key=>$value){
            if(!in_array($key,self::FIELDS[$plugin]??[],true)||!is_scalar($value))throw new ApiError('插件返回未声明的凭据字段',422);
            if(preg_match('/^\[([A-Za-z0-9_]+)\]$/D',(string)($parent[$key]??''),$m)&&$m[1]!==self::KEY){
                $child[$m[1]]=(string)$value;unset($runtime['values'][$key]);
            }else $runtime['values'][$key]=(string)$value;
        }
        $child[self::KEY]=$runtime;return $child;
    }
}
