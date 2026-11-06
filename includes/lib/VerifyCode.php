<?php
namespace lib;

use Exception;

class VerifyCode{

    const SMS_PHONE_DAYLY_TIME = 3; //短信验证码每个手机号每天最多发送次数
    const SMS_IP_DAYLY_TIME = 6; //短信验证码每个IP每天最多发送次数
    const EMAIL_ADDRESS_DAYLY_TIME = 7; //邮箱验证码每个邮箱每天最多发送次数
    const EMAIL_IP_DAYLY_TIME = 11; //邮箱验证码每个IP每天最多发送次数

    private static $regcodeid;

    /**
     * 发送验证码
     * @param string $scene 验证码场景
     * @param int $type 验证码类型:0邮件,1手机
     * @param string $sendto 接收人
     * @param int $uid 用户ID
     * @return mixed
     */
    public static function send_code($scene, $type, $sendto, $uid = 0){
        global $DB, $clientip;
        if(!is_string($sendto) || !is_string($scene) || !in_array($scene, ['reg','login','find','edit'], true))return '验证码参数不正确';
        $type = (int)$type === 1 ? 1 : 0;
        $sendto = trim($sendto);
        if(strlen($sendto)>32 || ($type===1 ? !preg_match('/^1[0-9]{10}$/D', $sendto) : !filter_var($sendto, FILTER_VALIDATE_EMAIL)))return $type===1?'手机号码不正确':'邮箱地址不正确或过长';
        $locks = [];
        try{
            $db = \lib\AdminApp\Runtime::legacyStore($DB);
            // A reservation must be committed before any external delivery is attempted.
            if($db->pdo->inTransaction())return '验证码服务繁忙，请稍后重试';
            if($db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'){
                $scope = $db->value('SELECT DATABASE()').':'.$db->tablePrefix();
                $keys = [];
                foreach(['ip:'.$clientip, 'to:'.strtolower($sendto)] as $resource)$keys[] = 'epay.code.'.substr(hash('sha256', $scope.':'.$resource),0,54);
                sort($keys, SORT_STRING);
                foreach($keys as $key){
                    if((int)$db->value('SELECT GET_LOCK(?,2)',[$key])!==1)return '验证码服务繁忙，请稍后重试';
                    $locks[] = $key;
                }
            }
            $now = time();
            $latest = $db->value('SELECT MAX(time) FROM pre_regcode WHERE `to`=?',[$sendto]);
            if($latest!==null && (int)$latest>$now-60)return $type===1?'两次发送短信之间需要相隔60秒！':'两次发送邮件之间需要相隔60秒！';
            $limit = $type===1?self::SMS_PHONE_DAYLY_TIME:self::EMAIL_ADDRESS_DAYLY_TIME;
            if((int)$db->value('SELECT COUNT(*) FROM pre_regcode WHERE `to`=? AND time>?',[$sendto,$now-86400]) >= $limit)return $type===1?'该手机号码发送次数过多，请更换号码！':'该邮箱发送次数过多，请更换邮箱！';
            $limit = $type===1?self::SMS_IP_DAYLY_TIME:self::EMAIL_IP_DAYLY_TIME;
            if((int)$db->value('SELECT COUNT(*) FROM pre_regcode WHERE ip=? AND time>?',[$clientip,$now-86400]) >= $limit)return '你今天发送次数过多，请明天再试！';
            $code = $type===1?random_int(111111,999999):random_int(1111111,9999999);
            // status=2 reserves quota but cannot authorize a reset, even if delivery crashes.
            $id = $db->insert('regcode',['uid'=>$uid,'scene'=>$scene,'type'=>$type,'code'=>$code,'to'=>$sendto,'time'=>$now,'ip'=>$clientip,'status'=>2]);
        }catch(\Throwable $e){
            return '验证码服务暂时不可用，请稍后重试';
        }finally{
            foreach(array_reverse($locks) as $key){try{$db->value('SELECT RELEASE_LOCK(?)',[$key]);}catch(\Throwable $ignored){}}
        }
        try{
            $result = $type===1?send_sms($sendto,$code,$scene):self::send_mail_code($sendto,$code,$scene);
            if($result!==true)return ($type===1?'短信发送失败 ':'邮件发送失败 ').(is_scalar($result)?(string)$result:'发送服务未返回成功');
            if($db->query('UPDATE pre_regcode SET status=0 WHERE id=? AND status=2',[$id])->rowCount()!==1)return '验证码保存失败，请稍后重试';
            return true;
        }catch(\Throwable $e){
            return '验证码发送或保存失败，请稍后重试';
        }
    }

    private static function send_mail_code($email, $code, $scene){
        global $conf;
        $title = $conf['sitename'].' - 验证码获取';
        if($scene == 'reg'){
            $body = '您的验证码是：'.$code.'，您正在注册成为'.$conf['sitename'].'的用户，如非本人操作请忽略。';
        }elseif($scene == 'login'){
            $body = '您的验证码是：'.$code.'，用于'.$conf['sitename'].'登录验证，请勿泄露验证码，如非本人操作请忽略。';
        }elseif($scene == 'find'){
            $body = '您的验证码是：'.$code.'，用于'.$conf['sitename'].'重置密码，请勿泄露验证码，如非本人操作请忽略。';
        }elseif($scene == 'edit'){
            global $situation;
            if($situation=='settle')$body = '您正在修改结算账号信息，验证码是：'.$code;
            elseif($situation=='mibao')$body = '您正在修改密保邮箱，验证码是：'.$code;
            elseif($situation=='bind')$body = '您正在绑定新邮箱，验证码是：'.$code;
            else $body = '您的验证码是：'.$code;
        }
        return send_mail($email, $title, $body);
    }

    /**
     * 验证验证码
     * @param string $scene 验证码场景
     * @param int $type 验证码类型:0邮件,1手机
     * @param string $sendto 接收人
     * @param string $code 验证码
     * @param int $uid 用户ID
     * @return mixed
     */
    public static function verify_code($scene, $type, $sendto, $code, $uid = 0){
        global $DB;
        self::$regcodeid = null;
        $where = ['scene'=>$scene, 'type'=>$type, 'to'=>$sendto];
        if($uid > 0) $where['uid'] = $uid;
        $row = $DB->find('regcode', '*', $where, 'id DESC', 1);
        if (!$row) {
            return '请重新获取验证码！';
        }elseif($row['time']<time()-3600 || $row['status']>0 || $row['errcount']>=5){
            return '验证码已失效，请重新获取';
        }elseif(!is_scalar($code) || is_bool($code) || !hash_equals((string)$row['code'], (string)$code)){
            $DB->exec("update `pre_regcode` set `errcount`=`errcount`+1 where `status`=0 and `errcount`<5 and `id`='{$row['id']}'");
            return '验证码不正确！';
        }
        // Consume with a compare-and-set before a second request can authorize itself.
        $consumed = $DB->exec("UPDATE pre_regcode SET status=1 WHERE id=:id AND status=0 AND errcount<5 AND time>=:cutoff AND code=:code", [':id'=>$row['id'], ':cutoff'=>time()-3600, ':code'=>(string)$code]);
        if($consumed!==1)return '验证码已失效，请重新获取';
        self::$regcodeid = $row['id'];
        return true;
    }

    //作废验证码
    public static function void_code(){
        global $DB;
        if(self::$regcodeid){
            $DB->exec("update `pre_regcode` set `status`='1' where `id`=:id", [':id'=>self::$regcodeid]);
            self::$regcodeid = null;
        }
    }

}