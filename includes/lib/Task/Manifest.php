<?php
namespace lib\Task;

/** One explicit protocol, no legacy aliases or inferred entry points. */
final class Manifest
{
    public static function fields(array $data,array $fields)
    {
        if(array_diff(array_keys($data),$fields)||array_diff($fields,array_keys($data)))throw new \RuntimeException('后台能力字段不完整或含未知字段');
    }
    public static function method($name)
    {
        if(!is_string($name)||!preg_match('/^_?[a-zA-Z][a-zA-Z0-9_]{0,63}$/D',$name))throw new \RuntimeException('后台能力方法无效');
        return $name;
    }
    public static function read($directory)
    {
        $file=$directory.'/tasks.json';
        if(!is_file($file))return ['schema_version'=>1,'tasks'=>[],'services'=>[]];
        if(is_link($file)||filesize($file)>32768)throw new \RuntimeException('后台能力声明文件无效');
        return self::parse(file_get_contents($file));
    }
    public static function parse($raw)
    {
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data)||substr(ltrim($raw),0,1)!=='{')throw new \RuntimeException('旧 tasks.json 必须先显式迁移为 schema_version=1');
        self::fields($data,['schema_version','tasks','services']);
        if($data['schema_version']!==1)throw new \RuntimeException('不支持的后台能力协议版本');
        foreach(['tasks','services'] as $kind){
            if(!is_array($data[$kind])||!array_is_list($data[$kind]))throw new \RuntimeException('后台能力必须为数组');
            $result=[];
            foreach($data[$kind] as $item){
                if(!is_array($item))throw new \RuntimeException('后台能力必须为对象');
                $fields=$kind==='tasks'?['key','name','method','interval_seconds','timeout_seconds','idempotency']:
                    ['key','name','on_start','run','on_stop','scope','resource_field','ready_seconds','heartbeat_seconds','stop_seconds','affects_payment'];
                self::fields($item,$fields);
                if(!is_string($item['key'])||!preg_match('/^[a-z][a-z0-9_]{0,47}$/D',$item['key'])||!is_string($item['name'])||trim($item['name'])===''||mb_strlen($item['name'])>60)throw new \RuntimeException('后台能力名称无效');
                if(isset($result[$item['key']]))throw new \RuntimeException('后台能力 key 重复');
                if($kind==='tasks'){
                    self::method($item['method']);
                    $item['interval_seconds']=Definitions::integer($item['interval_seconds'],3,86400,'任务间隔');
                    $item['timeout_seconds']=Definitions::integer($item['timeout_seconds'],5,900,'任务超时');
                    if(!in_array($item['idempotency'],['read_only','gateway_key','manual_review'],true))throw new \RuntimeException('任务必须声明幂等方式');
                }else{
                    foreach(['on_start','run','on_stop'] as $key)self::method($item[$key]);
                    if(!in_array($item['scope'],['channel','shared','plugin'],true)||!is_string($item['resource_field']))throw new \RuntimeException('服务范围无效');
                    if($item['scope']==='shared'&&!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/D',$item['resource_field']))throw new \RuntimeException('共享服务必须声明资源配置字段');
                    if($item['scope']!=='shared'&&$item['resource_field']!=='')throw new \RuntimeException('非共享服务不能声明共享资源字段');
                    foreach(['ready_seconds'=>[5,120],'heartbeat_seconds'=>[5,120],'stop_seconds'=>[1,25]] as $key=>$range)$item[$key]=Definitions::integer($item[$key],$range[0],$range[1],$key);
                    if($item['affects_payment']!==false)throw new \RuntimeException('后台服务不得修改通道支付开关，affects_payment 必须为 false');
                }
                $result[$item['key']]=$item;
            }
            $data[$kind]=$result;
        }
        return $data;
    }
    public static function directory($plugins,$plugin)
    {
        if(!is_string($plugin)||!preg_match('/^[a-zA-Z0-9_]+$/D',$plugin))throw new \RuntimeException('插件名称无效');
        $base=realpath($plugins);$path=$plugins.'/'.$plugin;$dir=realpath($path);
        if(!$base||!$dir||is_link($path)||dirname($dir)!==$base)throw new \RuntimeException('插件目录越界或不存在');
        return $dir;
    }
    public static function digest($directory)
    {
        $files=[];$bytes=0;$deadline=hrtime(true)+5000000000;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){
            if($file->isLink())throw new \RuntimeException('后台插件不允许符号链接');
            if(!$file->isFile())continue;
            $bytes+=$file->getSize();
            if(count($files)>=4096||$bytes>67108864)throw new \RuntimeException('插件版本快照超出限制');
            if(hrtime(true)>=$deadline)throw new \RuntimeException('插件版本快照扫描超时（5秒）');
            $relative=substr($file->getPathname(),strlen($directory)+1);
            $hash=hash_file('sha256',$file->getPathname());
            if(!is_string($hash))throw new \RuntimeException('插件版本快照读取失败');
            // ZIP uploads can contain legacy-encoded filenames on Linux. Encode
            // path bytes losslessly; JSON substitution would merge distinct names.
            $files[bin2hex(str_replace('\\','/',$relative))]=$hash;
            if(hrtime(true)>=$deadline)throw new \RuntimeException('插件版本快照扫描超时（5秒）');
        }
        ksort($files);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR));
    }
}
