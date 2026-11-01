<?php
namespace lib\AdminApp;

/** Pending bindings never change the active secret until a fresh code is verified. */
final class ProfileSecurity
{
    private $db;
    private $session;
    private $ip;
    public function __construct(Store $db, array $session, $ip) { $this->db=$db;$this->session=$session;$this->ip=$ip; }
    public function run($action, array $data)
    {
        require_once dirname(__DIR__).'/TOTP.php';
        $this->db->pdo->beginTransaction();
        try {
            $this->db->all("SELECT k FROM pre_config WHERE k IN ('totp_open','totp_secret','admin_pwd')".$this->db->lockClause());
            $conf=$this->db->config();$auth=new Auth($this->db,$conf,$this->ip);
            if(!hash_equals($this->session['stamp'],$auth->stamp()))throw new ApiError('登录已失效，请重新登录',401);
            if($action==='prepare'||$action==='disable') {
                $password=$data['admin_password'] ?? null;
                if(!is_string($password)||$password===''||strlen($password)>800||!hash_equals((string)$conf['admin_pwd'],$password))throw new ApiError('管理员密码错误',403);
            }
            if($action==='prepare') {
                $totp=\lib\TOTP::create();$totp->setIssuer($conf['sitename'] ?? '易支付');$totp->setLabel(str_replace(':','_',$this->session['username']));
                $token=bin2hex(random_bytes(32));
                $this->db->query('DELETE FROM pre_app_challenge WHERE kind=? AND (proof=? OR expires<?)',['totp-setup',$this->session['id'],time()]);
                $this->db->insert('app_challenge',['id'=>hash('sha256',$token),'kind'=>'totp-setup','proof'=>$this->session['id'],'data'=>json_encode(['secret'=>$totp->getSecret(),'stamp'=>$auth->stamp()]),'expires'=>time()+600,'attempts'=>0]);
                $result=['setup_token'=>$token,'secret'=>$totp->getSecret(),'uri'=>$totp->getProvisioningUri(),'expires_in'=>600];
            } elseif($action==='bind') {
                $token=$data['setup_token'] ?? '';$code=$data['code'] ?? '';
                if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!is_string($code)||!preg_match('/^\d{6}$/D',$code))throw new ApiError('请填写六位动态口令');
                $id=hash('sha256',$token);
                $pending=$this->db->row('SELECT * FROM pre_app_challenge WHERE id=? AND kind=?'.$this->db->lockClause(),[$id,'totp-setup']);
                $setup=$pending?json_decode($pending['data'],true):null;
                if(!$pending||$pending['proof']!==$this->session['id']||(int)$pending['expires']<=time()||!hash_equals($auth->stamp(),$setup['stamp'] ?? '')||(int)$pending['attempts']>=5)throw new ApiError('绑定已过期，请重新生成',409);
                $this->db->query('UPDATE pre_app_challenge SET attempts=attempts+1 WHERE id=?',[$id]);
                if(!\lib\TOTP::create($setup['secret'])->verify($code,time(),1)) {
                    // Commit only the attempt counter; keep the old binding untouched.
                    $this->db->pdo->commit();throw new ApiError('动态口令错误，请核对认证软件中的新口令');
                }
                $this->db->setting('totp_secret',$setup['secret']);$this->db->setting('totp_open','1');
                $this->revoke();$result=['reauthenticate'=>true,'message'=>'动态口令已绑定，请重新登录'];
                $this->db->audit('绑定动态口令',$this->ip);
            } elseif($action==='disable') {
                $this->db->setting('totp_open','0');$this->db->setting('totp_secret','');
                $this->revoke();$result=['reauthenticate'=>true,'message'=>'动态口令已关闭，请重新登录'];
                $this->db->audit('关闭动态口令',$this->ip);
            } else throw new ApiError('接口不存在',404);
            $this->db->pdo->commit();return $result;
        } catch(\Throwable $e) { if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e; }
    }
    private function revoke()
    {
        $this->db->query('DELETE FROM pre_app_challenge WHERE kind IN (?,?)',['totp-setup','totp']);
        $this->db->query('UPDATE pre_app_session SET revoked=1 WHERE username=?',[$this->session['username']]);
    }
}
