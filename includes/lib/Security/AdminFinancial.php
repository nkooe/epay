<?php
namespace lib\Security;

use lib\AdminApp\ApiError;
use lib\AdminApp\Auth;
use lib\AdminApp\Store;

final class AdminFinancial
{
    public static function verify(Store $db,array $conf,$ip,$password)
    {
        if(empty($conf['admin_paypwd']))throw new ApiError('请先设置管理员支付密码',403);
        if(!is_string($password)||!hash_equals((string)$conf['admin_paypwd'],$password)){
            (new Auth($db,$conf,$ip))->rate('web-payment-password-failed',10,60);
            throw new ApiError('支付密码错误',403);
        }
        $_SESSION['financial_stamp']=hash('sha256',(string)$conf['admin_paypwd']);
        $_SESSION['financial_until']=time()+900;
    }
    public static function requireVerified(Store $db,array $conf,$ip,$password=null)
    {
        if($password!==null)self::verify($db,$conf,$ip,$password);
        if(empty($conf['admin_paypwd'])||($_SESSION['financial_until']??0)<time()
            ||!hash_equals(hash('sha256',(string)$conf['admin_paypwd']),(string)($_SESSION['financial_stamp']??'')))
            throw new ApiError('请验证支付密码后操作',403,-9);
    }
}
