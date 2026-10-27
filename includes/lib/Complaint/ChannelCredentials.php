<?php
namespace lib\Complaint;

use lib\AdminApp\ApiError;

/** Resolve credentials at use time so a channel update also updates complaints. */
final class ChannelCredentials
{
    public static function supported(array $channel)
    {
        return ($channel['type']==='wxpay'&&in_array($channel['plugin'],['wxpayn','wxpaynp'],true))
            ||($channel['type']==='alipay'&&$channel['plugin']==='alipaybillautoxd');
    }
    public static function message(array $channel)
    {
        if(!self::supported($channel))return '该通道插件未提供官方投诉接口';
        if($channel['type']==='wxpay')return '';
        try{self::alipay($channel);return '';}
        catch(ApiError $e){return $e->getMessage();}
    }
    public static function alipay(array $channel)
    {
        if(!self::supported($channel)||$channel['type']!=='alipay')throw new ApiError('该通道插件未提供支付宝官方投诉接口');
        $config=json_decode($channel['config']??'{}',true)?:[];
        $c=['app_id'=>$config['app_id']??'', 'app_private_key'=>$config['app_secret']??'',
            'alipay_public_key'=>$config['alipay_public_key']??'', 'app_auth_token'=>$config['app_auth_token']??''];
        if(!is_string($c['app_id'])||!preg_match('/^[0-9]{10,32}$/D',$c['app_id']))throw new ApiError('请在支付通道中完成应用自动配置');
        foreach(['app_private_key'=>true,'alipay_public_key'=>false] as $key=>$private){
            $value=$c[$key];$label=$private?'应用私钥':'支付宝公钥';
            if(!is_string($value)||trim($value)===''||strlen($value)>16000)throw new ApiError('请在支付通道中配置'.$label);
            $value=trim(str_replace(['\\r','\\n'],['',"\n"],$value));
            if(strpos($value,'-----BEGIN')===false){
                $body=preg_replace('/\s/','',$value);$der=base64_decode($body,true);
                $pem=$private?($der!==false&&strpos(substr($der,0,24),"\x02\x01\x00\x02")!==false?'RSA PRIVATE KEY':'PRIVATE KEY'):'PUBLIC KEY';
                $value='-----BEGIN '.$pem."-----\n".chunk_split($body,64,"\n").'-----END '.$pem.'-----';
            }
            $resource=$private?@openssl_pkey_get_private($value):@openssl_pkey_get_public($value);$details=$resource?openssl_pkey_get_details($resource):false;
            if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048||(!$private&&strpos($value,'PRIVATE KEY')!==false))throw new ApiError('支付通道中的'.$label.'无效');
            $c[$key]=$value;
        }
        if(!is_string($c['app_auth_token'])||strlen($c['app_auth_token'])>1024)throw new ApiError('支付通道中的应用授权令牌无效');
        return $c;
    }
}
