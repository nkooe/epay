<?php
namespace lib\Merchant;

/** One-use browser-session binding for native merchant login callbacks. */
final class NativeLoginState
{
    public static function issue($provider,$redirect=null)
    {
        $nonce=bin2hex(random_bytes(32));
        $_SESSION['merchant_native_oauth'][$provider]=['nonce'=>$nonce,'created'=>time(),'redirect'=>is_string($redirect)?$redirect:null];
        return $nonce;
    }

    public static function consume($provider,$state)
    {
        $expected=$_SESSION['merchant_native_oauth'][$provider]??null;
        if(!is_string($state)||$state===''||!is_array($expected)||!is_string($expected['nonce']??null)
            ||(int)($expected['created']??0)<time()-600||(int)$expected['created']>time()
            ||!hash_equals($expected['nonce'],$state))throw new \DomainException('登录验证已失效，请重新发起快捷登录');
        unset($_SESSION['merchant_native_oauth'][$provider]);
        return $expected['redirect']??null;
    }
}
