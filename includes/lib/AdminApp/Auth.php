<?php
namespace lib\AdminApp;

final class Auth
{
    private $db;
    private $conf;
    private $ip;
    private $clock;
    public function __construct(Store $db, array $conf, $ip, $clock = null) { $this->db=$db; $this->conf=$conf; $this->ip=$ip; $this->clock=$clock ?: function () { return time(); }; }
    private function now() { return (int)call_user_func($this->clock); }
    public function stamp()
    {
        return hash_hmac('sha256', json_encode([$this->conf['admin_user'] ?? '', $this->conf['admin_pwd'] ?? '', $this->conf['totp_open'] ?? '0', $this->conf['totp_secret'] ?? '']), $this->conf['syskey'] ?? '');
    }
    private function randomToken() { return bin2hex(random_bytes(32)); }
    public function rate($scope, $limit, $seconds = 600)
    {
        $bucket = hash('sha256', $scope.':'.(int)floor($this->now()/$seconds));
        try { $this->db->insert('app_limit', ['id'=>$bucket, 'hits'=>0, 'expires'=>$this->now()+$seconds*2]); }
        catch (\PDOException $e) { if (!$this->db->row('SELECT id FROM pre_app_limit WHERE id=?', [$bucket])) throw $e; }
        $this->db->query('UPDATE pre_app_limit SET hits=hits+1 WHERE id=?', [$bucket]);
        if ((int)$this->db->value('SELECT hits FROM pre_app_limit WHERE id=?', [$bucket]) > $limit) throw new ApiError('请求过于频繁，请稍后重试', 429);
    }
    private function challenge($kind, $proof, array $data = [])
    {
        $token=$this->randomToken();
        $this->db->insert('app_challenge', ['id'=>hash('sha256', $token), 'kind'=>$kind, 'proof'=>$proof, 'data'=>json_encode($data), 'expires'=>$this->now()+180, 'attempts'=>0]);
        return $token;
    }
    public function captcha()
    {
        $this->rate('captcha:'.$this->ip, 40);
        $a=random_int(2,19); $b=random_int(1,9);
        return ['token'=>$this->challenge('captcha', hash_hmac('sha256', (string)($a+$b), $this->stamp())), 'question'=>$a.' + '.$b.' = ?', 'expires_in'=>180];
    }
    private function checkCaptcha(array $data)
    {
        if (($this->conf['app_captcha_open'] ?? '1') === '0') return null;
        $id=hash('sha256', (string)($data['captcha_token'] ?? ''));
        $row=$this->db->row('SELECT * FROM pre_app_challenge WHERE id=? AND kind=?', [$id, 'captcha']);
        if (!$row || (int)$row['expires']<$this->now()) return ['needs_captcha'=>true, 'captcha'=>$this->captcha()];
        if ($this->db->query('DELETE FROM pre_app_challenge WHERE id=?', [$id])->rowCount()!==1) return ['needs_captcha'=>true, 'captcha'=>$this->captcha()];
        if (!hash_equals($row['proof'], hash_hmac('sha256', trim((string)($data['captcha_answer'] ?? '')), $this->stamp()))) return ['needs_captcha'=>true, 'captcha'=>$this->captcha(), 'captcha_error'=>'验证码错误，请重新输入'];
        return null;
    }
    public function login(array $data)
    {
        $this->rate('login:'.$this->ip, 30);
        if ($captcha=$this->checkCaptcha($data)) return $captcha;
        $username=(string)($data['username'] ?? ''); $password=(string)($data['password'] ?? '');
        $this->rate('account:'.hash('sha256', $username).':'.$this->ip, 10);
        if (!hash_equals((string)($this->conf['admin_user'] ?? ''), $username) || $password==='' || !hash_equals((string)($this->conf['admin_pwd'] ?? ''), $password)) {
            $this->db->audit('登录失败', $this->ip); throw new ApiError('管理员账号或密码错误', 401);
        }
        $device=mb_substr(trim((string)($data['device'] ?? 'Android')), 0, 80);
        if (($this->conf['totp_open'] ?? '0')==='1' && !empty($this->conf['totp_secret'])) {
            return ['needs_totp'=>true, 'challenge'=>$this->challenge('totp', $this->stamp(), ['username'=>$username, 'device'=>$device]), 'expires_in'=>180];
        }
        return $this->issue($username, $device);
    }
    public function totp(array $data)
    {
        $this->rate('totp:'.$this->ip, 20);
        $id=hash('sha256', (string)($data['challenge'] ?? ''));
        $row=$this->db->row('SELECT * FROM pre_app_challenge WHERE id=? AND kind=?', [$id, 'totp']);
        if (!$row || (int)$row['expires']<$this->now() || !hash_equals($this->stamp(), $row['proof']) || (int)$row['attempts']>=5) throw new ApiError('验证已过期，请重新登录', 401);
        if ($this->db->query('UPDATE pre_app_challenge SET attempts=attempts+1 WHERE id=? AND attempts<5', [$id])->rowCount()!==1) throw new ApiError('验证次数已用完，请重新登录',401);
        require_once dirname(__DIR__).'/TOTP.php';
        $code=(string)($data['code'] ?? '');
        if (!preg_match('/^\d{6}$/D', $code) || !\lib\TOTP::create($this->conf['totp_secret'])->verify($code, $this->now(), 1)) throw new ApiError('动态口令错误', 401);
        // Atomic consumption prevents concurrent requests from reusing a password-verified challenge.
        if ($this->db->query('DELETE FROM pre_app_challenge WHERE id=? AND attempts<=5', [$id])->rowCount()!==1) throw new ApiError('验证已使用，请重新登录', 401);
        $info=json_decode($row['data'], true);
        return $this->issue($info['username'], $info['device']);
    }
    private function issue($username, $device)
    {
        $access=$this->randomToken(); $refresh=$this->randomToken(); $id=bin2hex(random_bytes(16));
        $this->db->insert('app_session', ['id'=>$id, 'username'=>$username, 'access_hash'=>hash('sha256',$access), 'refresh_hash'=>hash('sha256',$refresh), 'stamp'=>$this->stamp(), 'device'=>$device, 'ip'=>$this->ip, 'created'=>$this->now(), 'last_seen'=>$this->now(), 'access_expires'=>$this->now()+1800, 'expires'=>$this->now()+2592000, 'revoked'=>0]);
        $this->db->audit('登录', $this->ip, ['device'=>$device]);
        return ['access_token'=>$access, 'refresh_token'=>$refresh, 'expires_in'=>1800, 'session_id'=>$id, 'platform'=>$this->conf['sitename'] ?? '易支付'];
    }
    public function authenticate($token)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new ApiError('请登录管理员账号', 401);
        $row=$this->db->row('SELECT * FROM pre_app_session WHERE access_hash=?', [hash('sha256',$token)]);
        if (!$row || $row['revoked'] || (int)$row['expires']<=$this->now() || (int)$row['access_expires']<=$this->now() || !hash_equals($this->stamp(),$row['stamp'])) throw new ApiError('登录已失效，请重新登录', 401);
        if ((int)$row['last_seen']<$this->now()-60) $this->db->update('app_session', ['last_seen'=>$this->now()], 'id', $row['id']);
        return $row;
    }
    public function refresh($token)
    {
        $this->rate('refresh:'.$this->ip,60);
        $old=hash('sha256', $token);
        $row=$this->db->row('SELECT * FROM pre_app_session WHERE refresh_hash=?', [$old]);
        if (!$row || $row['revoked'] || (int)$row['expires']<=$this->now() || !hash_equals($this->stamp(),$row['stamp'])) throw new ApiError('登录已失效，请重新登录',401);
        $access=$this->randomToken(); $refresh=$this->randomToken();
        if ($this->db->query('UPDATE pre_app_session SET access_hash=?,refresh_hash=?,access_expires=?,last_seen=? WHERE id=? AND refresh_hash=? AND revoked=0', [hash('sha256',$access),hash('sha256',$refresh),$this->now()+1800,$this->now(),$row['id'],$old])->rowCount()!==1) throw new ApiError('会话已刷新，请重新登录',401);
        return ['access_token'=>$access,'refresh_token'=>$refresh,'expires_in'=>1800,'session_id'=>$row['id'],'platform'=>$this->conf['sitename'] ?? '易支付'];
    }
}
