<?php
namespace lib\Security;

final class TotpReplay
{
    public static function consume(\lib\AdminApp\Store $db,$stamp,$code,$now)
    {
        // One shared record across web and App sessions; a unique insert also excludes concurrent reuse.
        $id=hash('sha256','totp-used:'.$stamp.':'.$code);
        $db->query('DELETE FROM pre_app_limit WHERE id=? AND expires<=?',[$id,$now]);
        try{$db->insert('app_limit',['id'=>$id,'hits'=>1,'expires'=>$now+120]);}
        catch(\PDOException $e){
            if($db->row('SELECT id FROM pre_app_limit WHERE id=?',[$id]))throw new \lib\AdminApp\ApiError('动态口令已使用，请等待下一枚口令',401);
            throw $e;
        }
    }
}
