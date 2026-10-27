<?php
namespace lib\Complaint;

use lib\AdminApp\ApiError;
use lib\AdminApp\Store;

final class Service
{
    private $db;
    private $config;
    private $factory;
    public function __construct(Store $db, array $config, $factory = null)
    {
        $this->db=$db; $this->config=$config;
        $this->factory=$factory ?? [Gateway::class,'open'];
    }
    private static function integer($value,$name,$min=1,$max=2147483647)
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$value)||$value<$min||$value>$max)throw new ApiError($name.'不正确');
        return (int)$value;
    }
    private static function text($value,$name,$max,$required=true)
    {
        if(!is_string($value)||mb_strlen($value)>$max||($required&&trim($value)===''))throw new ApiError($name.'不能为空且不能超过 '.$max.' 字');
        return trim($value);
    }
    public static function date($value)
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!($date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value))||$date->format('Y-m-d')!==$value)throw new ApiError('日期格式不正确');
        return $value;
    }
    private function account($id,$enabled=true)
    {
        $row=$this->db->row('SELECT * FROM pre_complaint_account WHERE id=?',[self::integer($id,'接口编号')]);
        if(!$row)throw new ApiError('投诉接口不存在',404);
        if($enabled&&!(int)$row['enabled'])throw new ApiError('该投诉接口已停用');
        return $row;
    }
    private function key()
    {
        $key=$this->config['syskey']??'';
        if(!is_string($key)||strlen($key)<16)throw new ApiError('系统加密密钥未配置',503);
        return hash_hkdf('sha256',$key,32,'epay-complaint-credentials-v1');
    }
    private function seal(array $credentials)
    {
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt(json_encode($credentials,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,'complaint-v1');
        if($cipher===false)throw new ApiError('投诉凭证加密失败',503);
        return base64_encode($iv.$tag.$cipher);
    }
    private function open(array $account)
    {
        $credentials=[];
        if($account['provider']==='alipay'){
            if(($account['credentials']??'')===''){
                $channel=$this->db->row('SELECT c.config,c.plugin,t.name type FROM pre_channel c INNER JOIN pre_type t ON t.id=c.type WHERE c.id=?',[$account['channel']]);
                if(!$channel)throw new ApiError('关联支付通道已删除');
                return ($this->factory)($account,ChannelCredentials::alipay($channel));
            }
            $raw=base64_decode($account['credentials'],true);
            if($raw===false||strlen($raw)<29)throw new ApiError('投诉接口凭证损坏',503);
            $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'complaint-v1');
            if($plain===false)throw new ApiError('投诉接口凭证无法解密',503);
            $credentials=json_decode($plain,true,10,JSON_THROW_ON_ERROR);
        }
        return ($this->factory)($account,$credentials);
    }
    public function accounts()
    {
        $rows=$this->db->all("SELECT a.id,a.name,a.provider,a.channel,a.enabled,a.revision,a.updated_at,c.name channel_name,CASE WHEN a.credentials='' THEN 'channel' ELSE 'legacy' END credential_source FROM pre_complaint_account a LEFT JOIN pre_channel c ON c.id=a.channel ORDER BY a.id DESC");
        $states=new SyncState($this->db);
        foreach($rows as &$row){$task=$this->db->row("SELECT next_run_at,last_error,last_status,enabled FROM pre_task WHERE action='complain' AND channel_id=? AND source=1",[$row['channel']]);$row['sync']=$states->status($row,$task);}unset($row);
        return $rows;
    }
    public function schema()
    {
        $channels=$this->db->all("SELECT c.id,c.name,c.plugin,c.config,t.name type FROM pre_channel c INNER JOIN pre_type t ON t.id=c.type WHERE t.name IN ('alipay','wxpay') ORDER BY c.id");
        foreach($channels as &$channel){
            $channel['bindable']=ChannelCredentials::supported($channel);$channel['config_message']=ChannelCredentials::message($channel);
            if($channel['bindable']&&$channel['config_message']===''){
                try{$this->open(['provider'=>$channel['type']==='wxpay'?'wechat':'alipay','channel'=>$channel['id'],'credentials'=>'']);}
                catch(\Throwable $e){$channel['config_message']=SyncState::message($e);}
            }
            $channel['config_ready']=$channel['bindable']&&$channel['config_message']==='';
            $channel['config_url']='pay_channel.php?complaint_channel='.$channel['id'];unset($channel['config']);
        }unset($channel);
        return ['accounts'=>$this->accounts(),'channels'=>$channels,'feedback_codes'=>['02'=>'通过其他方式退款','03'=>'已发货','04'=>'其他','05'=>'已完成售后服务','06'=>'非我方责任范围']];
    }
    public function saveAccount(array $input,$ip)
    {
        if(array_diff(array_keys($input),['id','revision','name','provider','channel','enabled','credentials']))throw new ApiError('接口配置包含未知字段');
        $id=self::integer($input['id']??0,'接口编号',0);$revision=self::integer($input['revision']??0,'配置版本',0);
        $old=$id?$this->account($id,false):null;
        $channel=self::integer($input['channel']??($old['channel']??null),'支付通道');$enabled=self::integer($input['enabled']??($old['enabled']??1),'启用状态',0,1);
        $payment=$this->db->row('SELECT c.name,c.plugin,t.name type FROM pre_channel c INNER JOIN pre_type t ON t.id=c.type WHERE c.id=?',[$channel]);
        $provider=$input['provider']??($payment&&$payment['type']==='wxpay'?'wechat':'alipay');
        if(!$payment||($provider==='wechat'&&($payment['type']!=='wxpay'||!in_array($payment['plugin'],['wxpayn','wxpaynp'],true)))||($provider==='alipay'&&$payment['type']!=='alipay'))throw new ApiError('支付通道与投诉接口类型不匹配');
        if(!in_array($provider,['wechat','alipay'],true))throw new ApiError('投诉接口类型不正确');
        $name=self::text($input['name']??($old['name']??mb_substr($payment['name'],0,76).'投诉'),'接口名称',80);
        if($old&&($old['provider']!==$provider||(int)$old['channel']!==$channel))throw new ApiError('接口类型和关联通道创建后不能修改，请新增接口');
        if(!$old&&!isset($input['credentials'])&&!ChannelCredentials::supported($payment))throw new ApiError('该通道插件未提供官方投诉接口');
        $sealed=$old['credentials']??'';
        if($provider==='alipay'&&isset($input['credentials'])){
            $c=$input['credentials'];
            if(!is_array($c)||array_diff(array_keys($c),['app_id','app_private_key','alipay_public_key','app_auth_token']))throw new ApiError('支付宝凭证字段不正确');
            foreach(['app_id','app_private_key','alipay_public_key'] as $key)$c[$key]=self::text($c[$key]??null,'支付宝凭证',16000);
            if(!preg_match('/^[0-9]{10,32}$/D',$c['app_id']))throw new ApiError('支付宝应用 AppID 不正确');
            foreach(['app_private_key'=>true,'alipay_public_key'=>false] as $key=>$private){
                $value=$c[$key];
                if(strpos($value,'-----BEGIN')===false){$label=$private?'RSA PRIVATE KEY':'PUBLIC KEY';$value='-----BEGIN '.$label."-----\n".chunk_split(preg_replace('/\s/','',$value),64,"\n").'-----END '.$label.'-----';}
                $resource=$private?@openssl_pkey_get_private($value):@openssl_pkey_get_public($value);$details=$resource?openssl_pkey_get_details($resource):false;
                if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048||(!$private&&strpos($value,'PRIVATE KEY')!==false))throw new ApiError($private?'支付宝应用私钥无效':'支付宝公钥无效');
                // AOP SDK accepts PEM contents for both keys.
                $c[$key]=$value;
            }
            $c['app_auth_token']=self::text($c['app_auth_token']??'','应用授权令牌',1024,false);$sealed=$this->seal($c);
        }
        if($provider==='wechat'&&isset($input['credentials']))throw new ApiError('微信接口使用支付通道中的证书和密钥');
        $this->db->pdo->beginTransaction();
        try{
            // Serialize bindings per channel, including simultaneous web/native requests.
            $this->db->row('SELECT id FROM pre_channel WHERE id=?'.$this->db->lockClause(),[$channel]);
            if(!$id&&$this->db->row('SELECT id FROM pre_complaint_account WHERE channel=? LIMIT 1',[$channel]))throw new ApiError('该通道已绑定投诉管理，请编辑已有接口',409);
            if($id){$locked=$this->db->row('SELECT revision FROM pre_complaint_account WHERE id=?'.$this->db->lockClause(),[$id]);if((int)$locked['revision']!==$revision)throw new ApiError('接口配置已更新，请重新读取',409);}
            elseif($revision!==0)throw new ApiError('新增接口版本不正确');
            $data=['name'=>$name,'provider'=>$provider,'channel'=>$channel,'enabled'=>$enabled,'credentials'=>$sealed,'revision'=>$revision+1,'updated_at'=>date('Y-m-d H:i:s')];
            if($id)$this->db->update('complaint_account',$data,'id',$id);
            else{$data['created_at']=$data['updated_at'];$id=$this->db->insert('complaint_account',$data);}
            $this->db->audit('投诉接口配置',$ip,['account_id'=>(int)$id,'enabled'=>$enabled]);$this->db->pdo->commit();
        }catch(\Throwable $e){$this->db->pdo->rollBack();throw $e;}
        if($enabled&&(!$old||!(int)$old['enabled']||isset($input['credentials'])))(new SyncState($this->db))->request($id);
        return ['id'=>(int)$id,'revision'=>$revision+1,'sync_queued'=>(bool)$enabled];
    }
    /** Only queries the upstream list; never replies, refunds, or changes callbacks. */
    public function checkConnection(array $input)
    {
        if(!empty($input['id']))$account=$this->account($input['id'],false);
        else{
            $channel=self::integer($input['channel']??null,'支付通道');
            $payment=$this->db->row('SELECT c.plugin,t.name type FROM pre_channel c INNER JOIN pre_type t ON t.id=c.type WHERE c.id=?',[$channel]);
            if(!$payment||!ChannelCredentials::supported($payment))throw new ApiError('该通道插件未提供官方投诉接口');
            $account=['provider'=>$payment['type']==='wxpay'?'wechat':'alipay','channel'=>$channel,'credentials'=>''];
        }
        $local=true;
        try{$gateway=$this->open($account);$local=false;$gateway->listing(date('Y-m-d'),date('Y-m-d'),1);$result=['ok'=>true,'message'=>'连接正常，已成功查询投诉接口','kind'=>'success'];}
        catch(\PDOException $e){throw $e;}
        catch(\Throwable $e){$result=['ok'=>false,'message'=>SyncState::message($e),'kind'=>$local?'configuration':($e instanceof TransientFailure?'temporary':'upstream')];}
        if(isset($account['id'])){
            $states=new SyncState($this->db);$row=$states->ensure($account['id']);
            $states->update($account['id'],['checked_at'=>date('Y-m-d H:i:s'),'check_ok'=>$result['ok']?1:0,'check_message'=>$result['message']]);
            if($result['ok']&&in_array($row['state'],['blocked','waiting'],true))$states->request($account['id']);
        }
        return $result;
    }
    public function requestSync($id)
    {
        $account=$this->account($id);(new SyncState($this->db))->request($account['id']);
        $task=$this->db->row("SELECT * FROM pre_task WHERE action='complain' AND channel_id=? AND source=1",[$account['channel']]);
        if($task&&$task['last_status']==='failed'){
            if($this->db->row('SELECT id FROM pre_task_execution WHERE active_task_id=?',[$task['id']]))throw new ApiError('同步进程尚未退出，请稍后重试',409);
            $tasks=new \lib\Task\TaskService($this->db,[\lib\Task\CronHandlers::class,'execute']);
            $tasks->save(['id'=>$task['id'],'revision'=>$task['revision'],'action'=>'complain','params'=>['channel'=>$account['channel'],'source'=>1],
                'enabled'=>1,'schedule_type'=>$task['schedule_type'],'interval_seconds'=>$task['interval_seconds'],'daily_at'=>$task['daily_at'],'acknowledge_failure'=>1]);
        }
        return ['queued'=>true,'message'=>'已加入后台同步队列，将从已保存的进度继续'];
    }
    public function listing(array $filter)
    {
        $page=self::integer($filter['page']??1,'页码',1,100000);$where=[];$params=[];
        foreach(['status'=>['p.status',0,2],'account_id'=>['p.account_id',1,2147483647],'uid'=>['p.uid',0,2147483647]] as $key=>$definition){
            if(isset($filter[$key])&&$filter[$key]!==''){$where[]=$definition[0].'=?';$params[]=self::integer($filter[$key],$key,$definition[1],$definition[2]);}
        }
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op)if(!empty($filter[$key])){$where[]='p.created_at '.$op.' ?';$params[]=self::date($filter[$key]).($key==='from'?' 00:00:00':' 23:59:59');}
        if(isset($filter['q'])&&$filter['q']!==''){$q=self::text($filter['q'],'搜索内容',100);$where[]='(p.thirdid LIKE ? OR p.trade_no LIKE ? OR p.title LIKE ?)';array_push($params,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
        $sql=$where?' WHERE '.implode(' AND ',$where):'';
        $total=(int)$this->db->value('SELECT COUNT(*) FROM pre_complaint p'.$sql,$params);
        $rows=$this->db->all('SELECT p.id,p.account_id,p.provider,p.channel,p.thirdid,p.uid,p.trade_no,p.status,p.remote_status,p.title,p.type,p.money,p.created_at,p.snapshot_at,p.revision,a.name account_name FROM pre_complaint p INNER JOIN pre_complaint_account a ON a.id=p.account_id'.$sql.' ORDER BY p.id DESC LIMIT 20 OFFSET '.(($page-1)*20),$params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/20))];
    }
    public function detail($id)
    {
        $row=$this->row($id);$snapshot=json_decode($row['snapshot'],true,30,JSON_THROW_ON_ERROR);unset($row['snapshot']);
        $account=$this->account($row['account_id'],false);
        return ['record'=>$row,'detail'=>$snapshot,'account_enabled'=>(bool)$account['enabled'],'actions'=>$this->db->all('SELECT id,action,state,message,created_at,ended_at FROM pre_complaint_action WHERE complaint_id=? ORDER BY id DESC LIMIT 50',[$row['id']])];
    }
    private function row($id)
    {
        $row=$this->db->row('SELECT * FROM pre_complaint WHERE id=?',[self::integer($id,'投诉编号')]);
        if(!$row)throw new ApiError('投诉记录不存在',404);return $row;
    }
    private function fetchAndPersist(array $account, $third, $gateway)
    {
        Normalizer::identifier($third);
        // Capture the revision before querying upstream, never while holding a DB lock.
        $before=$this->db->row('SELECT revision FROM pre_complaint WHERE account_id=? AND thirdid=?',[$account['id'],$third]);
        $info=$gateway->detail($third);
        $snapshot=Normalizer::convert($account['provider'],$info);
        if(!hash_equals($third,$snapshot['thirdid']))throw new \RuntimeException('投诉详情编号不匹配');
        return $this->persist($account,$info,$before?(int)$before['revision']:null);
    }
    private function persist(array $account, array $info, $expectedRevision)
    {
        $snapshot=Normalizer::convert($account['provider'],$info);$orders=[];
        foreach($snapshot['orders'] as $reference){
            if($reference['out_trade_no']===''&&$reference['transaction_id']==='')continue;
            $matches=$this->db->all('SELECT trade_no,uid,money,status,subchannel FROM pre_order WHERE channel=? AND ((trade_no<>\'\' AND trade_no=?) OR (api_trade_no<>\'\' AND api_trade_no=?) OR (bill_trade_no<>\'\' AND bill_trade_no=?) OR (bill_mch_trade_no<>\'\' AND bill_mch_trade_no=?)) LIMIT 2',[$account['channel'],$reference['out_trade_no'],$reference['transaction_id'],$reference['transaction_id'],$reference['out_trade_no']]);
            if(count($matches)>1)throw new \RuntimeException('投诉关联订单不唯一，请核实通道订单号');
            foreach($matches as $match)$orders[$match['trade_no']]=$match;
        }
        $snapshot['local_orders']=array_values($orders);$uids=array_unique(array_column($orders,'uid'));
        $first=$orders?reset($orders):null;
        $payload=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $data=['channel'=>$account['channel'],'provider'=>$account['provider'],'thirdmchid'=>$snapshot['thirdmchid'],
            'uid'=>count($uids)===1?(int)reset($uids):0,'trade_no'=>$first?$first['trade_no']:'','status'=>$snapshot['status'],
            'remote_status'=>$snapshot['remote_status'],'type'=>$snapshot['type'],'title'=>$snapshot['title'],'content'=>$snapshot['content'],
            'money'=>$snapshot['money'],'created_at'=>$snapshot['created_at'],'remote_updated_at'=>$snapshot['remote_updated_at'],'snapshot_at'=>date('Y-m-d H:i:s'),'snapshot'=>$payload];
        $this->db->pdo->beginTransaction();
        try{
            // The unique identity handles first-insert races; the revision guards in-flight reads.
            $sql=$this->db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $inserted=$this->db->query($sql.' INTO pre_complaint (account_id,thirdid,channel,provider,status,remote_status,type,title,content,money,created_at,snapshot_at,snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$account['id'],$snapshot['thirdid'],$data['channel'],$data['provider'],$data['status'],$data['remote_status'],$data['type'],$data['title'],$data['content'],$data['money'],$data['created_at'],$data['snapshot_at'],$payload])->rowCount()===1;
            $old=$this->db->row('SELECT id,revision,snapshot,remote_updated_at FROM pre_complaint WHERE account_id=? AND thirdid=?'.$this->db->lockClause(),[$account['id'],$snapshot['thirdid']]);
            if(!$inserted&&($expectedRevision===null||(int)$old['revision']!==$expectedRevision))throw new ApiError('投诉在同步期间已更新，请重新同步',409);
            if($old['remote_updated_at']&&$data['remote_updated_at']&&$data['remote_updated_at']<$old['remote_updated_at'])throw new ApiError('上游返回了较早的投诉状态，请稍后重新同步',409);
            $data['revision']=(int)$old['revision']+($old['snapshot']!==$payload?1:0);$this->db->update('complaint',$data,'id',$old['id']);
            $this->db->pdo->commit();return (int)$old['id'];
        }catch(\Throwable $e){$this->db->pdo->rollBack();throw $e;}
    }
    public function sync(array $input)
    {
        $account=$this->account($input['account_id']??null);$from=self::date($input['from']??null);$to=self::date($input['to']??null);
        if($from>$to||$to>date('Y-m-d')||strtotime($to)-strtotime($from)>30*86400)throw new ApiError('同步范围必须是过去 31 天以内的一段日期');
        $page=self::integer($input['page']??1,'同步页码',1,10000);$gateway=$this->open($account);$batch=$gateway->listing($from,$to,$page);$count=0;
        foreach($batch['ids'] as $third){$this->fetchAndPersist($account,$third,$gateway);$count++;}
        return ['processed'=>$count,'next_page'=>$batch['next_page'],'total'=>$batch['total']];
    }
    public function refresh($id)
    {
        $row=$this->row($id);$account=$this->account($row['account_id']);
        $this->fetchAndPersist($account,$row['thirdid'],$this->open($account));return $this->detail($id);
    }
    public function upload($id,array $file)
    {
        $path=$file['tmp_name']??'';
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($path)||!is_uploaded_file($path))throw new ApiError('请选择不超过 2 MB 的图片');
        return $this->uploadFile($id,$path);
    }
    /** Native JSON upload; never accepts a client-supplied server path. */
    public function uploadBytes($id,$encoded)
    {
        if(!is_string($encoded)||strlen($encoded)>950000)throw new ApiError('图片过大，请压缩到 680 KB 以内');
        $bytes=base64_decode($encoded,true);
        if($bytes===false||$bytes===''||base64_encode($bytes)!==$encoded||strlen($bytes)>696320)throw new ApiError('图片编码不正确或超过 680 KB');
        $path=tempnam(sys_get_temp_dir(),'epay-evidence-');
        if($path===false)throw new ApiError('无法建立上传临时文件',503);
        try{
            if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))throw new ApiError('图片保存失败',503);
            return $this->uploadFile($id,$path);
        }finally{if(is_file($path))unlink($path);}
    }
    private function uploadFile($id,$path)
    {
        $row=$this->row($id);$account=$this->account($row['account_id']);
        if((int)$row['status']===2)throw new ApiError('已结束的投诉不能上传处理凭证');
        if($this->db->row("SELECT id FROM pre_complaint_action WHERE complaint_id=? AND state IN ('sending','unknown') LIMIT 1",[$row['id']]))throw new ApiError('有操作结果待核实，请先查看操作记录',409);
        if(filesize($path)>2097152||filesize($path)===0)throw new ApiError('请选择不超过 2 MB 的图片');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$size=@getimagesize($path);
        if(!in_array($mime,['image/png','image/jpeg'],true)||!$size||$size[0]*$size[1]>20000000)throw new ApiError('仅支持有效的 JPG、PNG 图片');
        $name='evidence-'.bin2hex(random_bytes(8)).($mime==='image/png'?'.png':'.jpg');$remote=$this->open($account)->upload($path,$name);
        if(!is_string($remote)||$remote===''||strlen($remote)>512)throw new \RuntimeException('图片上传响应不正确');
        $token=bin2hex(random_bytes(16));$this->db->insert('complaint_attachment',['id'=>$token,'complaint_id'=>$row['id'],'remote_id'=>$remote,'created_at'=>date('Y-m-d H:i:s')]);
        return ['id'=>$token,'name'=>$name];
    }
    public function action(array $input,$ip)
    {
        if(array_diff(array_keys($input),['id','revision','request_id','action','content','images','code','decision']))throw new ApiError('操作包含未知字段');
        $row=$this->row($input['id']??null);$account=$this->account($row['account_id']);$action=$input['action']??'';
        $allowed=$account['provider']==='wechat'?['reply','complete','refund-progress']:['reply','supplement','feedback'];
        if(!in_array($action,$allowed,true))throw new ApiError('当前投诉类型不支持该操作');
        $input['content']=self::text($input['content']??'','处理内容',1000,$action!=='complete'&&!(($input['decision']??'')==='APPROVE'&&$action==='refund-progress'));
        if($action==='feedback'&&!in_array($input['code']??null,['02','03','04','05','06'],true))throw new ApiError('请选择有效的支付宝处理结果');
        if($action==='refund-progress'){
            $snapshot=json_decode($row['snapshot'],true);
            if(($snapshot['problem_type']??'')!=='REFUND')throw new ApiError('该投诉不是退款申请');
            if(!in_array($input['decision']??null,['APPROVE','REJECT'],true))throw new ApiError('退款审批结果不正确');
        }
        $request=$input['request_id']??'';
        if(!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$request))throw new ApiError('操作请求编号不正确');
        $revision=self::integer($input['revision']??null,'投诉版本');$images=[];
        $tokens=$input['images']??[];
        if(!is_array($tokens)||count($tokens)>4||count(array_unique($tokens))!==count($tokens))throw new ApiError('凭证图片最多 4 张');
        foreach($tokens as $token){
            if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token))throw new ApiError('图片凭证编号不正确');
            $attachment=$this->db->row('SELECT remote_id FROM pre_complaint_attachment WHERE id=? AND complaint_id=? AND created_at>=?',[$token,$row['id'],date('Y-m-d H:i:s',time()-86400)]);
            if(!$attachment)throw new ApiError('图片凭证不属于当前投诉或已过期');$images[]=$attachment['remote_id'];
        }
        $hash=hash('sha256',json_encode([$row['id'],$action,$input['content'],$images,$input['code']??null,$input['decision']??null],JSON_THROW_ON_ERROR));
        // Validate local certificates/credentials before recording an upstream submission.
        $gateway=$this->open($account);
        $this->db->pdo->beginTransaction();
        try{
            $current=$this->db->row('SELECT revision,status FROM pre_complaint WHERE id=?'.$this->db->lockClause(),[$row['id']]);
            $previous=$this->db->row('SELECT * FROM pre_complaint_action WHERE request_id=?',[$request]);
            if($previous){
                if(!hash_equals($previous['payload_hash'],$hash))throw new ApiError('操作请求编号已用于其他内容',409);
                $this->db->pdo->commit();return ['state'=>$previous['state'],'message'=>$previous['message'],'replayed'=>true];
            }
            if((int)$current['revision']!==$revision)throw new ApiError('投诉已更新，请刷新详情后操作',409);
            if((int)$current['status']===2)throw new ApiError('投诉已结束，请先刷新确认最新状态',409);
            if($this->db->row("SELECT id FROM pre_complaint_action WHERE complaint_id=? AND state IN ('sending','unknown') LIMIT 1",[$row['id']]))throw new ApiError('有操作正在提交或结果待核实，请先查看操作记录',409);
            $actionId=$this->db->insert('complaint_action',['complaint_id'=>$row['id'],'request_id'=>$request,'action'=>$action,'state'=>'sending','payload_hash'=>$hash,'message'=>'正在提交','ip'=>$ip,'created_at'=>date('Y-m-d H:i:s')]);
            $this->db->pdo->commit();
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
        $state='success';$message='已提交上游';
        try{$gateway->action($action,$row,$input,$images);}
        catch(Rejected $e){$state='failed';$message=mb_substr($e->getMessage(),0,450);}
        catch(\Throwable $e){$state='unknown';$message='提交结果待核实，请刷新上游状态并核实协商记录后再操作';error_log('Complaint action '.$actionId.': '.get_class($e));}
        $this->db->pdo->beginTransaction();
        try{
            // Retire every pre-submit view even when upstream detail refresh is still pending.
            $this->db->query('UPDATE pre_complaint SET revision=revision+1 WHERE id=?',[$row['id']]);
            $this->db->update('complaint_action',['state'=>$state,'message'=>$message,'ended_at'=>date('Y-m-d H:i:s')],'id',$actionId);
            $this->db->audit('投诉处理',$ip,['complaint_id'=>(int)$row['id'],'action'=>$action,'state'=>$state]);
            $this->db->pdo->commit();
        }catch(\Throwable $e){$this->db->pdo->rollBack();throw $e;}
        if($state==='success')try{$this->refresh($row['id']);}catch(\Throwable $e){$message.='，状态刷新失败，请稍后手动刷新';}
        return ['state'=>$state,'message'=>$message,'replayed'=>false];
    }
    public function resolve(array $input,$ip)
    {
        $id=self::integer($input['id']??null,'投诉编号');$actionId=self::integer($input['action_id']??null,'操作记录编号');
        $this->refresh($id);
        $this->db->pdo->beginTransaction();
        try{
            $row=$this->db->row('SELECT * FROM pre_complaint_action WHERE id=? AND complaint_id=?'.$this->db->lockClause(),[$actionId,$id]);
            if(!$row||!in_array($row['state'],['unknown','sending'],true))throw new ApiError('该操作不需要解除限制',409);
            if($row['state']==='sending'&&strtotime($row['created_at'])>time()-120)throw new ApiError('操作仍可能正在提交，请稍后核实',409);
            $this->db->update('complaint_action',['state'=>'verified','message'=>'管理员核实上游记录后解除重试限制','ended_at'=>date('Y-m-d H:i:s')],'id',$actionId);
            $this->db->audit('投诉结果核实',$ip,['complaint_id'=>$id,'action_id'=>$actionId]);$this->db->pdo->commit();
        }catch(\Throwable $e){$this->db->pdo->rollBack();throw $e;}
        return $this->detail($id);
    }
    public function subscribe($id)
    {
        $account=$this->account($id);if($account['provider']!=='wechat')throw new ApiError('支付宝投诉使用定时同步');
        $base=rtrim($this->config['localurl']??'','/');$parts=parse_url($base);
        if(($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new ApiError('请先将网站接口地址配置为 HTTPS');
        $url=$base.'/complaint-notify.php?account_id='.$account['id'];$this->open($account)->subscribe($url);return ['url'=>$url];
    }
    public function notification($accountId,array $headers,$body)
    {
        $account=$this->account($accountId);if($account['provider']!=='wechat')throw new ApiError('接口不接收微信通知');
        $gateway=$this->open($account);$event=$gateway->notification($headers,$body);$id=Normalizer::identifier($event['complaint_id']??null);
        return $this->fetchAndPersist($account,$id,$gateway);
    }
    public function image($id,$media)
    {
        $row=$this->row($id);if($row['provider']!=='wechat'||!is_string($media))throw new ApiError('图片请求不正确');
        $snapshot=json_decode($row['snapshot'],true,30,JSON_THROW_ON_ERROR);$available=array_column($snapshot['images'],'id');
        foreach($snapshot['history'] as $entry)$available=array_merge($available,array_column($entry['images'],'id'));
        if(!in_array($media,$available,true))throw new ApiError('图片不属于该投诉',404);
        $bytes=$this->open($this->account($row['account_id']))->image($media);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!in_array($mime,['image/jpeg','image/png'],true))throw new ApiError('上游返回的不是可显示的图片',502);
        return ['bytes'=>$bytes,'mime'=>$mime];
    }
    public function syncChannel($channel,$source)
    {
        if((int)$source!==1)throw new \RuntimeException('当前投诉模块仅接入微信、支付宝标准投诉接口，来源请选择 1');
        $accounts=$this->db->all('SELECT * FROM pre_complaint_account WHERE channel=? AND enabled=1',[(int)$channel]);
        if(!$accounts)throw new \RuntimeException('该通道尚未配置已启用的投诉接口');
        $messages=[];
        foreach($accounts as $account)$messages[]=$this->syncAccountBatch($account);
        return implode('；',$messages);
    }
    private function syncAccountBatch(array $account)
    {
        $states=new SyncState($this->db);$state=$states->ensure($account['id']);$now=date('Y-m-d H:i:s');
        if($state['next_at']&&$state['next_at']>$now)return '接口 '.$account['id'].' 等待下次同步';
        $cursor=$state['cursor']?json_decode($state['cursor'],true,20,JSON_THROW_ON_ERROR):null;
        if(!$cursor){$cursor=['phase'=>'list','from'=>date('Y-m-d',time()-7*86400),'to'=>date('Y-m-d'),'page'=>1,'ids'=>null,'index'=>0,'next_page'=>null,'open_after'=>0,'open_max'=>(int)$this->db->value('SELECT MAX(id) FROM pre_complaint WHERE account_id=?',[$account['id']])];$states->update($account['id'],['processed'=>0,'started_at'=>$now]);$state['processed']=0;}
        if($state['state']==='waiting'&&$state['next_at']===null)$states->update($account['id'],['failures'=>0]);
        $states->update($account['id'],['state'=>'running']);$local=true;$count=(int)$state['processed'];
        try{
            $gateway=$this->open($account);$local=false;$deadline=hrtime(true)+5000000000;$steps=0;$done=false;
            do{
                if($cursor['phase']==='list'){
                    if($cursor['ids']===null){
                        $batch=$gateway->listing($cursor['from'],$cursor['to'],$cursor['page']);
                        if(!is_array($batch['ids']??null)||count($batch['ids'])>10||(($batch['next_page']??null)!==null&&(int)$batch['next_page']!==$cursor['page']+1))throw new \RuntimeException('投诉列表分页响应不正确');
                        $cursor['ids']=array_values(array_unique($batch['ids']));$cursor['index']=0;$cursor['next_page']=$batch['next_page'];
                    }elseif($cursor['index']<count($cursor['ids'])){
                        $this->fetchAndPersist($account,$cursor['ids'][$cursor['index']],$gateway);$cursor['index']++;$count++;
                    }elseif($cursor['next_page']!==null){$cursor['page']=(int)$cursor['next_page'];$cursor['ids']=null;}
                    else{$cursor['phase']='open';}
                }else{
                    $open=$this->db->row('SELECT id,thirdid FROM pre_complaint WHERE account_id=? AND id>? AND id<=? AND status<>2 AND created_at<? ORDER BY id LIMIT 1',[$account['id'],$cursor['open_after'],$cursor['open_max'],$cursor['from'].' 00:00:00']);
                    if(!$open){$done=true;break;}
                    $this->fetchAndPersist($account,$open['thirdid'],$gateway);$cursor['open_after']=(int)$open['id'];$count++;
                }
                $states->update($account['id'],['cursor'=>json_encode($cursor,JSON_THROW_ON_ERROR),'processed'=>$count]);
            }while(++$steps<12&&hrtime(true)<$deadline);
            if($done)$states->update($account['id'],['state'=>'success','cursor'=>null,'last_success_at'=>date('Y-m-d H:i:s'),'last_count'=>$count,'processed'=>$count,'failures'=>0,'last_error'=>null,'next_at'=>null]);
            else $states->update($account['id'],['state'=>'pending','failures'=>0,'last_error'=>null,'next_at'=>date('Y-m-d H:i:s',time()+3)]);
            return '接口 '.$account['id'].($done?' 同步完成，共 ':' 已保存进度，累计 ').$count.' 条';
        }catch(\PDOException $e){throw $e;}
        catch(\Throwable $e){$states->failed($account['id'],$e,$local);return '接口 '.$account['id'].' 同步等待：'.SyncState::message($e);}
    }
}
