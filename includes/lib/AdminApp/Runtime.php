<?php
namespace lib\AdminApp;

/** Load the existing plugin runtime only after authentication and validation.
 * Reuses the request PDO; does not execute common.php's session/key generation side effects. */
final class Runtime
{
    private static $sharingLocks=[];
    public static function legacyStore($legacy)
    {
        $r=new \ReflectionClass($legacy);$values=[];
        foreach(['db','prefix'] as $key){$p=$r->getProperty($key);$p->setAccessible(true);$values[$key]=$p->getValue($legacy);}
        return new Store($values['db'],rtrim($values['prefix'],'_'));
    }
    /** Serializes app, cron and callbacks across the gateway I/O boundary. */
    public static function sharingLock(Store $db,$id,$call)
    {
        if($db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql')return $call();
        $key='epay.ps.'.hash('sha256',$db->value('SELECT DATABASE()').':'.$db->tablePrefix().':'.$id);
        $key=substr($key,0,64);$local=spl_object_id($db->pdo).':'.$key;
        if(isset(self::$sharingLocks[$local]))return $call();
        if((int)$db->value('SELECT GET_LOCK(?,5)',[$key])!==1)throw new ApiError('分账正在处理，请稍后查询',409);
        self::$sharingLocks[$local]=true;
        try{return $call();}finally{unset(self::$sharingLocks[$local]);$db->value('SELECT RELEASE_LOCK(?)',[$key]);}
    }
    public static function prepare(array $channel,$method)
    {
        $name=$channel['plugin'] ?? '';if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new ApiError('插件名称无效');
        $file=PLUGIN_ROOT.$name.'/'.$name.'_plugin.php';if(!is_file($file))throw new ApiError('服务器缺少此支付插件');
        require_once $file;$class='\\'.$name.'_plugin';if(!class_exists($class,false)||!is_callable([$class,$method]))throw new ApiError('此插件不支持操作 '.$method);
        return $class;
    }
    public static function orderChannel(Store $db,array $order)
    {
        $channel=!empty($order['subchannel'])?\lib\Channel::getSub($order['subchannel']):\lib\Channel::get($order['channel'],$db->value('SELECT channelinfo FROM pre_user WHERE uid=?',[$order['uid']]) ?: null);
        if(!$channel)throw new ApiError('订单通道不存在');return $channel;
    }
    public static function orderCall(array $channel,$method,array $order)
    {
        $class=self::prepare($channel,$method);$GLOBALS['channel']=$channel;$GLOBALS['order']=$order;
        foreach(['IN_PLUGIN'=>true,'PAY_ROOT'=>PLUGIN_ROOT.$channel['plugin'].'/','TRADE_NO'=>$order['trade_no']] as $key=>$value)if(!defined($key))define($key,$value);
        return $class::$method($order);
    }
    public static function boot(Store $db,array $config)
    {
        $root=dirname(__DIR__,3).'/';
        foreach(['ROOT'=>$root,'SYSTEM_ROOT'=>$root.'includes/','PLUGIN_ROOT'=>$root.'plugins/','PAYPAGE_ROOT'=>$root.'includes/pages/','TEMPLATE_ROOT'=>$root.'template/','IN_CRONLITE'=>true,'SYS_KEY'=>$config['syskey'] ?? '','VERSION'=>'3097','DB_VERSION'=>'2063','DBQZ'=>rtrim($db->tablePrefix(),'_')] as $k=>$v)if(!defined($k))define($k,$v);
        require_once $root.'includes/autoloader.php';
        if(!class_exists('lib\\PdoHelper',false))\Autoloader::register();
        $r=new \ReflectionClass(\lib\PdoHelper::class);$legacy=$r->newInstanceWithoutConstructor();
        foreach(['db'=>$db->pdo,'prefix'=>$db->tablePrefix()] as $key=>$value){$p=$r->getProperty($key);$p->setAccessible(true);$p->setValue($legacy,$value);}
        $GLOBALS['DB']=$legacy;$GLOBALS['conf']=$config;$GLOBALS['_CACHE']=$config;$GLOBALS['CACHE']=new \lib\Cache();
        $base=$config['localurl'] ?? '';
        if(!preg_match('~^https?://~',$base)){$host=$_SERVER['HTTP_HOST'] ?? 'localhost';$base='https://'.$host.'/';}
        $GLOBALS['siteurl']=rtrim($base,'/').'/';$GLOBALS['conf']['localurl']=$GLOBALS['siteurl'];
        $GLOBALS['date']=date('Y-m-d H:i:s');$GLOBALS['clientip']=$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';$GLOBALS['userrow']=['channelinfo'=>null];
        require_once $root.'includes/functions.php';
        // App authentication is independent of the website cookie/session bootstrap.
        if(!isset($_SERVER['HTTP_PHP_VERSION']))$_SERVER['HTTP_PHP_VERSION']=PHP_VERSION;
        self::composer($root.'includes/vendor');
    }
    private static function composer($vendor)
    {
        static $loaded=false;if($loaded||!is_file($vendor.'/composer/ClassLoader.php'))return;
        // Read Composer's generated maps directly: the website bootstrap also emits a session cookie.
        require_once $vendor.'/composer/ClassLoader.php';
        $loader=new \Composer\Autoload\ClassLoader($vendor);
        foreach(require $vendor.'/composer/autoload_psr4.php' as $prefix=>$paths)$loader->setPsr4($prefix,$paths);
        foreach(require $vendor.'/composer/autoload_namespaces.php' as $prefix=>$paths)$loader->add($prefix,$paths);
        $loader->addClassMap(require $vendor.'/composer/autoload_classmap.php');$loader->register(true);$loaded=true;
        if(is_file($vendor.'/composer/autoload_files.php'))foreach(require $vendor.'/composer/autoload_files.php' as $id=>$file){
            if(empty($GLOBALS['__composer_autoload_files'][$id])){$GLOBALS['__composer_autoload_files'][$id]=true;require $file;}
        }
    }
}
