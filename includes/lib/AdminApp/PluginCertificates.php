<?php
namespace lib\AdminApp;

/** Fixed slots, PEM validation and atomic bundles outside the document root. */
final class PluginCertificates
{
    public static function slots($plugin)
    {
        if($plugin==='wxpay')return ['apiclient_cert.pem'=>'商户 API 证书','apiclient_key.pem'=>'商户 API 私钥','public_key.pem'=>'付款到银行卡公钥'];
        if(in_array($plugin,['wxpayn','wxpaynp','wxpayng'],true))return ['apiclient_key.pem'=>'商户 API 私钥','pub_key.pem'=>'微信支付平台公钥','cert.pem'=>'微信支付平台证书'];
        return [];
    }
    private static function directory($plugin,$merchant)
    {
        if(!self::slots($plugin)||!preg_match('/^\d{6,20}$/D',$merchant))return null;
        $root=getenv('EPAY_APP_CERT_ROOT') ?: dirname(dirname(__DIR__,3)).'/epay-private/app-certificates';
        $root=str_replace('\\','/',$root);$web=str_replace('\\','/',dirname(__DIR__,3));
        if(!preg_match('~^(?:/|[a-zA-Z]:/)~',$root)||preg_match('~/(?:\.|\.\.)(?:/|$)~',$root)||strpos(strtolower(rtrim($root,'/')).'/',strtolower($web).'/')===0)throw new \RuntimeException('证书根目录必须位于网站目录外');
        // A separate site namespace prevents collisions between installations.
        return rtrim($root,'/\\').'/'.substr(hash('sha256',dirname(__DIR__,3)),0,16).'/'.$plugin.'/'.$merchant;
    }
    private static function safe($path)
    {
        for($p=$path;$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new \RuntimeException('证书目录不允许符号链接');
    }
    public static function active($plugin,$merchant)
    {
        $dir=self::directory($plugin,$merchant);if(!$dir)return [];
        self::safe($dir);$manifest=$dir.'/current.json';if(!is_file($manifest))return [];
        $map=json_decode(file_get_contents($manifest),true);if(!is_array($map))throw new \RuntimeException('证书清单损坏');
        $paths=[];
        foreach($map as $slot=>$version){
            if(!isset(self::slots($plugin)[$slot])||!preg_match('/^[a-f0-9]{32}$/D',(string)$version))throw new \RuntimeException('证书清单无效');
            $path=$dir.'/'.$version.'/'.$slot;self::safe($path);if(!is_file($path))throw new \RuntimeException('证书文件缺失');$paths[$slot]=$path;
        }
        return $paths;
    }
    public static function describe($plugin,$merchant)
    {
        $active=self::active($plugin,$merchant);$rows=[];
        foreach(self::slots($plugin) as $slot=>$label){
            $path=$active[$slot] ?? null;
            // Include legacy files in the revision so a concurrent replacement is detected.
            if(!$path){$base=dirname(__DIR__,3).'/plugins/'.$plugin.'/cert/';$path=is_file($base.$merchant.'/'.$slot)?$base.$merchant.'/'.$slot:$base.$slot;}
            $rows[]=['slot'=>$slot,'label'=>$label,'configured'=>is_file($path),'sha256'=>is_file($path)?hash_file('sha256',$path):''];
        }
        return ['files'=>$rows,'revision'=>hash('sha256',json_encode($rows)),'merchant'=>$merchant];
    }
    public static function validate($slot,$content)
    {
        if(!is_string($content)||strlen($content)>262144||strlen($content)<40||strpos($content,'<?')!==false)throw new ApiError('证书文件大小或内容无效');
        if(!preg_match('/\A\s*-----BEGIN ([A-Z ]+)-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END \1-----\s*\z/D',$content))throw new ApiError('仅支持未加密的 PEM 证书或密钥');
        if($slot==='apiclient_key.pem'){
            $key=@openssl_pkey_get_private($content);$info=$key?openssl_pkey_get_details($key):false;
            if(!$info||$info['type']!==OPENSSL_KEYTYPE_RSA||$info['bits']<2048)throw new ApiError('商户私钥必须是至少 2048 位 RSA 私钥');
        }elseif(in_array($slot,['cert.pem','apiclient_cert.pem'],true)){
            $cert=@openssl_x509_read($content);$info=$cert?openssl_x509_parse($cert):false;
            if(!$info||($info['validTo_time_t'] ?? 0)<=time()||($info['validFrom_time_t'] ?? PHP_INT_MAX)>time())throw new ApiError('证书无效、未生效或已过期');
        }else{
            if(strpos($content,'PRIVATE KEY')!==false||!@openssl_pkey_get_public($content))throw new ApiError('请选择有效的公钥文件');
        }
        return trim($content)."\n";
    }
    public static function save($plugin,$merchant,array $data)
    {
        $dir=self::directory($plugin,$merchant);if(!$dir)throw new ApiError('请先保存有效的商户号');
        $slots=self::slots($plugin);$files=$data['files'] ?? null;
        if(!is_array($files)||!$files||count($files)>count($slots))throw new ApiError('请选择证书文件');
        $validated=[];
        foreach($files as $slot=>$encoded){
            if(!isset($slots[$slot])||!is_string($encoded)||strlen($encoded)>349528)throw new ApiError('证书类型或文件大小无效');
            $bytes=base64_decode($encoded,true);if($bytes===false)throw new ApiError('文件编码无效');$validated[$slot]=self::validate($slot,$bytes);
        }
        self::safe($dir);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new ApiError('证书目录不可写',503);
        $lock=fopen($dir.'/write.lock','c');if(!$lock)throw new ApiError('无法锁定证书目录',503);chmod($dir.'/write.lock',0600);
        $bundle=null;$committed=false;$tmp=null;
        try{
            if(!flock($lock,LOCK_EX))throw new ApiError('证书正在更新',409);
            $before=self::describe($plugin,$merchant);
            if(!hash_equals($before['revision'],(string)($data['revision'] ?? '')))throw new ApiError('证书已变化，请刷新后重试',409);
            $active=self::active($plugin,$merchant);$map=is_file($dir.'/current.json')?json_decode(file_get_contents($dir.'/current.json'),true):[];
            $version=bin2hex(random_bytes(16));$bundle=$dir.'/'.$version;mkdir($bundle,0700);
            // Migrate legacy credentials in the same bundle, so uploaded slots never mix with a different merchant.
            $legacy=dirname(__DIR__,3).'/plugins/'.$plugin.'/cert/';
            foreach($slots as $slot=>$label){
                $path=$active[$slot] ?? (is_file($legacy.$merchant.'/'.$slot)?$legacy.$merchant.'/'.$slot:$legacy.$slot);
                $content=$validated[$slot] ?? (is_file($path)?file_get_contents($path):null);
                if($content!==null){$file=$bundle.'/'.$slot;if(file_put_contents($file,$content)!==strlen($content))throw new ApiError('证书写入失败',503);chmod($file,0600);$map[$slot]=$version;}
            }
            if($plugin==='wxpay'&&isset($map['apiclient_cert.pem'],$map['apiclient_key.pem'])){
                if(!openssl_x509_check_private_key(file_get_contents($bundle.'/apiclient_cert.pem'),file_get_contents($bundle.'/apiclient_key.pem')))throw new ApiError('商户证书与私钥不匹配，请同时选择正确文件');
            }
            $json=json_encode($map,JSON_THROW_ON_ERROR);$tmp=$dir.'/'.$version.'.json';
            if(file_put_contents($tmp,$json)!==strlen($json))throw new ApiError('证书清单写入失败',503);chmod($tmp,0600);
            if(!rename($tmp,$dir.'/current.json'))throw new ApiError('证书启用失败',503);
            $committed=true;
            return ['message'=>'证书已校验并启用','files'=>array_keys($validated)];
        }finally{
            if(!$committed&&$bundle){foreach(array_keys($slots) as $slot)if(is_file($bundle.'/'.$slot))unlink($bundle.'/'.$slot);if(is_dir($bundle))rmdir($bundle);}
            if(!$committed&&$tmp&&is_file($tmp))unlink($tmp);
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
