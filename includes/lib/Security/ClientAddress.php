<?php
namespace lib\Security;

final class ClientAddress
{
    public static function trusted($ip)
    {
        $ranges=preg_split('/[\s,]+/',(string)getenv('EPAY_TRUSTED_PROXIES'),-1,PREG_SPLIT_NO_EMPTY);
        foreach($ranges as $range){
            $parts=explode('/',$range,2);$net=@inet_pton($parts[0]);$addr=@inet_pton($ip);
            if($net===false||$addr===false||strlen($net)!==strlen($addr))continue;
            $bits=isset($parts[1])?(ctype_digit($parts[1])?(int)$parts[1]:-1):strlen($net)*8;
            if($bits<0||$bits>strlen($net)*8)continue;
            $whole=intdiv($bits,8);$rest=$bits%8;
            if(substr($net,0,$whole)!==substr($addr,0,$whole))continue;
            if(!$rest||((ord($net[$whole])^ord($addr[$whole]))&(255<<(8-$rest)))===0)return true;
        }
        return false;
    }
    public static function resolve(array $server,$type=0)
    {
        $peer=$server['REMOTE_ADDR']??'';
        if(!filter_var($peer,FILTER_VALIDATE_IP))return '';
        if($type>=2||!self::trusted($peer))return $peer;
        if($type<=0&&!empty($server['HTTP_X_FORWARDED_FOR'])){
            $chain=array_map('trim',explode(',',$server['HTTP_X_FORWARDED_FOR']));$chain[]=$peer;
            for($i=count($chain)-1;$i>=0;$i--){
                if(!filter_var($chain[$i],FILTER_VALIDATE_IP))return $peer;
                if(!self::trusted($chain[$i]))return $chain[$i];
            }
            return $chain[0];
        }
        foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP'] as $key)
            if(isset($server[$key])&&filter_var($server[$key],FILTER_VALIDATE_IP))return $server[$key];
        return $peer;
    }
    public static function secure(array $server)
    {
        if((int)($server['SERVER_PORT']??0)===443||in_array(strtolower((string)($server['HTTPS']??'')),['on','1'],true))return true;
        return self::trusted($server['REMOTE_ADDR']??'')&&strtolower((string)($server['HTTP_X_FORWARDED_PROTO']??''))==='https';
    }
}
