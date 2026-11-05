<?php
namespace lib\Security;

final class PublicUrl
{
    private static function inRange($ip,$network,$bits)
    {
        $a=@inet_pton($ip);$b=@inet_pton($network);
        if($a===false||$b===false||strlen($a)!==strlen($b))return false;
        $whole=intdiv($bits,8);$rest=$bits%8;
        return substr($a,0,$whole)===substr($b,0,$whole)&&(!$rest||((ord($a[$whole])^ord($b[$whole]))&(255<<(8-$rest)))===0);
    }
    public static function publicAddress($ip)
    {
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
        if(strpos($ip,':')!==false){
            // Only globally routed IPv6 unicast; exclude transition, documentation and special-use ranges.
            if(!self::inRange($ip,'2000::',3))return false;
            foreach([['2001::',23],['2001:db8::',32],['2002::',16],['3fff::',20]] as $r)if(self::inRange($ip,$r[0],$r[1]))return false;
        }else{
            foreach([['0.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',3]] as $r)if(self::inRange($ip,$r[0],$r[1]))return false;
        }
        return true;
    }
    public static function resolve($url,$lookup=null)
    {
        if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL))throw new \InvalidArgumentException('通知地址格式错误');
        $p=parse_url($url);$scheme=strtolower($p['scheme']??'');$host=trim(strtolower($p['host']??''),'[]');
        if(!in_array($scheme,['http','https'],true)||$host===''||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))throw new \InvalidArgumentException('通知地址必须是有效的 HTTP/HTTPS 地址');
        $port=$p['port']??($scheme==='https'?443:80);
        if($port<1||$port>65535)throw new \InvalidArgumentException('通知端口无效');
        if(filter_var($host,FILTER_VALIDATE_IP))$ips=[$host];
        else{
            // Reject ambiguous numeric hosts which libcurl may interpret as non-canonical IPv4.
            if(!preg_match('/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/D',$host))throw new \InvalidArgumentException('通知域名无效');
            if($lookup)$ips=$lookup($host);
            else{$records=@dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];foreach($records?:[] as $record){if(isset($record['ip']))$ips[]=$record['ip'];if(isset($record['ipv6']))$ips[]=$record['ipv6'];}}
        }
        if(!is_array($ips)||!$ips)throw new \InvalidArgumentException('通知域名解析失败');
        foreach($ips as $ip)if(!self::publicAddress($ip))throw new \InvalidArgumentException('通知地址必须指向公网服务');
        return ['host'=>$host,'port'=>$port,'ip'=>reset($ips)];
    }
}
