<?php
namespace lib\Security;

use lib\AdminApp\ApiError;
use lib\AdminApp\Auth;
use lib\AdminApp\Runtime;

final class WebLogin
{
    private $auth;
    private $db;
    private $conf;
    private $ip;
    public function __construct($legacy, array $conf, $ip)
    {
        $this->db=Runtime::legacyStore($legacy);
        $this->auth=new Auth($this->db,$conf,$ip);
        $this->conf=$conf;$this->ip=$ip;
    }
    public function ratePassword($username)
    {
        unset($_SESSION['admin_login_challenge']);
        $this->auth->rate('web-login:'.$this->ip,30);
        $this->auth->rate('web-account:'.hash('sha256',(string)$username),30);
    }
    public function passwordVerified()
    {
        session_regenerate_id(true);
        $_SESSION['admin_login_challenge']=['stamp'=>$this->auth->stamp(),'ip'=>$this->ip,'expires'=>time()+180,'attempts'=>0];
    }
    public function verifyTotp($code)
    {
        $this->auth->rate('web-totp:'.$this->ip,20);
        $c=$_SESSION['admin_login_challenge'] ?? [];
        if(empty($c)||($c['expires']??0)<=time()||($c['ip']??null)!==$this->ip
            ||!hash_equals($this->auth->stamp(),(string)($c['stamp']??''))||(int)($c['attempts']??0)>=5){
            unset($_SESSION['admin_login_challenge']);
            throw new ApiError('请先验证账号密码，或重新登录',401);
        }
        $_SESSION['admin_login_challenge']['attempts']++;
        if(!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code)
            ||!\lib\TOTP::create($this->conf['totp_secret'])->verify($code))throw new ApiError('动态口令错误',401);
        TotpReplay::consume($this->db,$this->auth->stamp(),$code,time());
        unset($_SESSION['admin_login_challenge']);
    }
    public function complete()
    {
        unset($_SESSION['admin_login_challenge'],$_SESSION['vc_code']);
        session_regenerate_id(true);
        $expire=time()+2592000;
        $token=authcode($this->conf['admin_user']."\t".webAdminSessionStamp($this->conf)."\t".$expire,'ENCODE',SYS_KEY);
        setAuthCookie('admin_token',$token,$expire);
    }
}
