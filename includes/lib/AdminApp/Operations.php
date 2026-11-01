<?php
namespace lib\AdminApp;

/** Explicit business actions. Local money/state/ledger changes share one transaction. */
final class Operations
{
    private $db;private $conf;private $ip;private $request;private $activeObject=null;
    public function __construct(Store $db,$ip,$request=''){$this->db=$db;$this->conf=$db->config();$this->ip=$ip;$this->request=$request;}
    public static function definitions()
    {
        $f=[Management::class,'field'];$pwd=$f('paypwd','支付密码','text','',['secret'=>true,'required'=>true,'max'=>800]);
        $trade=$f('trade_no','平台订单号','text','',['required'=>true,'max'=>19,'pattern'=>'/^\d{19}$/D']);
        $uid=$f('uid','商户编号','integer','',['required'=>true,'reference'=>'merchants']);
        $id=$f('id','结算记录编号','integer','',['required'=>true]);
        $amount=$f('money','金额（元）','decimal','',['required'=>true]);
        $type=$f('type','付款方式','select','alipay',['options'=>['alipay'=>'支付宝','wxpay'=>'微信','qqpay'=>'QQ 钱包','bank'=>'银行卡']]);
        $channel=$f('channel','付款通道','select','',['reference'=>'channels','required'=>true]);
        $biz=$f('biz_no','付款单号','text','',['required'=>true,'max'=>19,'pattern'=>'/^\d{19}$/D']);
        $defs=[
            'balance'=>['title'=>'调整商户余额','note'=>'真实变更商户余额并写入资金明细。扣款可能使余额为负，请核对商户与金额。','fields'=>[$uid,$f('direction','操作','select','add',['options'=>['add'=>'后台加款','subtract'=>'后台扣款']]),$amount,$f('reason','操作原因','text','',['required'=>true,'max'=>80]),$pwd]],
            'freeze'=>['title'=>'冻结订单','note'=>'从商户余额扣除订单入账金额，订单变为已冻结。','fields'=>[$trade,$pwd]],
            'unfreeze'=>['title'=>'解冻订单','note'=>'恢复订单入账金额至商户余额。','fields'=>[$trade,$pwd]],
            'refund'=>['title'=>'订单退款','note'=>'接口退款会向支付渠道真实发起退款；手工退款仅扣账，请先在线下完成退款。冻结订单只支持全额退款，部分退款请先解冻。','fields'=>[$trade,$amount,$f('mode','退款方式','select','api',['options'=>['api'=>'接口退款 · 原路退回','manual'=>'手工退款 · 仅登记扣账']]),$pwd]],
            'close'=>['title'=>'关闭未付订单','note'=>'调用支付插件关闭交易，成功后记录渠道已关单，保留平台未支付状态。','fields'=>[$trade,$pwd]],
            'order-delete'=>['title'=>'删除订单','note'=>'删除未支付且没有资金或关联记录的订单，删除后不可恢复。','fields'=>[$trade]],
            'settle-complete'=>['title'=>'登记结算完成','note'=>'仅登记已线下完成的付款，不会调用支付渠道转账。','fields'=>[$id,$pwd]],
            'settle-fail'=>['title'=>'登记结算失败','note'=>'记录失败原因，保留结算记录及商户已扣余额。','fields'=>[$id,$f('reason','失败原因','text','',['required'=>true,'max'=>64]),$pwd]],
            'settle-return'=>['title'=>'结算失败退回余额','note'=>'仅允许明确失败且无渠道转账的结算。退回原结算金额，删除结算记录，保留资金明细。','fields'=>[$id,$pwd]],
            'settle-pay'=>['title'=>'结算转账','note'=>'向此结算记录的收款人真实付款。返回处理中时，请先核对渠道结果。','fields'=>[$id,$channel,$pwd]],
            'batch-create'=>['title'=>'创建结算批次','note'=>'将当前所有待结算记录加入批次。创建批次不会付款。','fields'=>[$pwd]],
            'batch-complete'=>['title'=>'登记批次完成','note'=>'仅在批次内所有结算均已线下付款后操作；不会发起渠道转账。','fields'=>[$f('batch','结算批次号','text','',['required'=>true,'max'=>20]),$pwd]],
            'transfer'=>['title'=>'新增付款','note'=>'平台账户将向指定收款人真实付款。请核对通道、账号、姓名及金额。','fields'=>[$type,$channel,$f('account','收款账号 / OpenID','text','',['required'=>true,'max'=>128]),$f('name','收款人姓名','text','',['max'=>128]),$amount,$f('desc','付款说明','text','',['max'=>32]),$pwd]],
            'redpacket'=>['title'=>'创建红包','note'=>'生成真实可领取红包，领取时从付款通道出款。','fields'=>[$f('type','红包方式','select','alipay',['options'=>['alipay'=>'支付宝','wxpay'=>'微信']]),$channel,$amount,$f('desc','红包说明','text','',['max'=>32]),$pwd]],
            'transfer-query'=>['title'=>'刷新付款状态','note'=>'向支付渠道查询最新结果，不会重新付款。','fields'=>[$biz]],
            'transfer-cancel'=>['title'=>'撤销付款','note'=>'向渠道申请撤销；是否可撤销以渠道结果为准。','fields'=>[$biz,$pwd]],
            'merchant-password'=>['title'=>'重设商户密码','note'=>'保存后商户使用新密码登录。','fields'=>[$uid,$f('password','新登录密码','text','',['secret'=>true,'required'=>true,'max'=>128]),$pwd]],
            'merchant-key'=>['title'=>'重置商户 API 密钥','note'=>'旧密钥立即失效。新密钥仅显示一次，请及时安全交给商户更新对接。','fields'=>[$uid,$pwd]],
            'payment-password'=>['title'=>'设置支付密码','note'=>'用于 App 内付款、退款及余额调整。','fields'=>[$f('admin_password','当前管理员登录密码','text','',['secret'=>true,'required'=>true,'max'=>800]),$f('password','新支付密码（至少 8 位）','text','',['secret'=>true,'required'=>true,'max'=>128])]]
        ];
        $defs['settle-query']=['title'=>'查询结算转账','note'=>'查询已有付款编号，不会重新出款。','fields'=>[$id]];
        $defs['order-reconcile']=['title'=>'核实未知渠道结果','note'=>'处理 App 超时留下的待核实操作。先核对渠道及平台入账流水，再登记结果；不会再次调用渠道或重新补单。补单或预授权扣款成功须先完成平台入账核实。','fields'=>[$trade,$f('reference','待核实业务编号','text','',['required'=>true,'max'=>19]),$f('outcome','渠道最终结果','select','success',['options'=>['success'=>'已成功','failed'=>'明确未成功']]),$f('evidence','渠道核实凭据 / 说明','text','',['required'=>true,'max'=>200]),$pwd]];
        $defs['transfer-proof']=['title'=>'获取转账凭证','note'=>'向渠道查询转账凭证。','fields'=>[$biz]];
        $defs['transfer-balance']=['title'=>'查询通道余额','note'=>'查询指定付款通道可用余额。','fields'=>[$type,$channel]];
        foreach(['submit'=>'提交分账','query'=>'查询分账','cancel'=>'取消分账','return'=>'回退分账','amount'=>'查询可分账金额','edit-money'=>'修改分账金额'] as $key=>$title){$fields=[$f('id','分账记录编号','integer','',['required'=>true])];if($key==='edit-money')$fields[]=$amount;if(!in_array($key,['query','amount'],true))$fields[]=$pwd;$defs['sharing-'.$key]=['title'=>$title,'note'=>'按渠道结果处理分账，处理中或结果不明时请先查询。','fields'=>$fields];}
        $defs['operation-reconcile']=['title'=>'登记渠道核实结果','note'=>'用于未知的分账取消、回退及接收人同步结果。核实所有接收方的最终状态后登记，不会再次请求渠道。','fields'=>[$f('object','操作对象','text','',['required'=>true,'max'=>128,'pattern'=>'/^(?:sharing|receiver):\d+$/D']),$f('reference','业务编号','text','',['required'=>true,'max'=>64]),$f('outcome','渠道最终结果','select','success',['options'=>['success'=>'已全部成功','failed'=>'明确全部未执行']]),$f('evidence','核实凭据 / 说明','text','',['required'=>true,'max'=>200]),$pwd]];
        foreach(['enable'=>'启用分账规则','disable'=>'停用分账规则'] as $key=>$title)$defs['receiver-'.$key]=['title'=>$title,'note'=>'将向通道同步分账接收方，全部成功后保存开关。','fields'=>[$f('id','分账规则编号','integer','',['required'=>true]),$pwd]];
        $evidence=$f('evidence','渠道凭据 / 核实说明','text','',['required'=>true,'max'=>200]);
        foreach(['manual-fill'=>['手工补单','仅在渠道已收款并核实金额后操作。执行平台原有入账、业务订单和通知逻辑。'],
            'order-settle'=>['确认订单结算','适用于支付宝直付通、微信平台收付通。会向渠道提交结算。'],
            'preauth-pay'=>['预授权扣款','将真实扣除预授权冻结资金并执行订单入账。'],
            'preauth-release'=>['预授权解冻','将冻结资金退回付款人，订单恢复未支付。'],
            'redpacket-retry'=>['红包转账重试','仅在上次出款已明确失败后重试；请先核对渠道，防止重复付款。']] as $a=>$meta)
            $defs[$a]=['title'=>$meta[0],'note'=>$meta[1],'fields'=>array_merge([$trade],$a==='redpacket-retry'?[$evidence]:[],[$pwd])];
        foreach(['transfer-approve'=>['审核并付款','向渠道真实提交待审核付款，沿用原付款单号。'],
            'transfer-complete'=>['登记付款完成','仅登记已在渠道或线下确认成功的付款。'],
            'transfer-fail'=>['登记失败并退回','仅在明确未出款时操作；商户原扣款将退回一次。']] as $a=>$meta)
            $defs[$a]=['title'=>$meta[0],'note'=>$meta[1],'fields'=>array_merge([$biz],$a==='transfer-approve'?[]:[$evidence],[$pwd])];
        $defs['merchant-rsa']=['title'=>'生成商户 RSA 密钥','note'=>'生成 2048 位密钥并切换为 RSA 签名。旧商户公钥立即替换；私钥只显示一次。','fields'=>[$uid,$pwd]];
        $defs['merchant-delete']=['title'=>'删除无业务商户','note'=>'仅允许删除余额、保证金为零且没有业务引用的商户。删除后不可恢复。','fields'=>[$uid,$pwd]];
        foreach(['order-mark-unpaid'=>['改未完成',0], 'order-mark-paid'=>['改已完成',1]] as $key=>$meta)
            $defs[$key]=['title'=>$meta[0],'note'=>'仅修改订单状态，不调整余额、不触发入账或通知。已处理订单改为未完成后保留防重复入账标记；实际退款和结算状态不会撤销。','fields'=>[$trade,$f('expected_status','当前订单状态','select','',['required'=>true,'options'=>['0'=>'未完成','1'=>'已完成','2'=>'已退款']])]];
        return $defs;
    }
    public function schema(array $data)
    {
        if(($data['action'] ?? '')==='order-notify')return ['action'=>'order-notify','name'=>'通知商户','fields'=>[],'note'=>'服务器立即向商户发送通知并记录接收结果，失败的近期订单按计划重试。','context'=>(object)[]];
        $action=(string)($data['action'] ?? '');$s=self::definitions()[$action] ?? null;if(!$s)throw new ApiError('操作不存在',404);
        $management=new Management($this->db,$this->ip);$fields=[];
        foreach($s['fields'] as $f){if(isset($f['reference'])&&$f['type']==='select')$f['options']=$management->options($f['reference']);$f['value']=!empty($f['secret'])?'':(string)($data[$f['key']] ?? $f['default']);$fields[]=$f;}
        // Always show current financial context before confirmation.
        $context=[];
        if(!empty($data['trade_no']))$context=$this->db->row('SELECT trade_no,uid,name,realmoney,getmoney,refundmoney,status FROM pre_order WHERE trade_no=?',[$data['trade_no']]) ?: [];
        if(!empty($data['id'])&&strpos($action,'settle-')===0)$context=$this->db->row('SELECT id,uid,account,username,money,realmoney,status FROM pre_settle WHERE id=?',[$data['id']]) ?: [];
        if(!empty($data['uid']))$context=$this->db->row('SELECT uid,username,money FROM pre_user WHERE uid=?',[$data['uid']]) ?: [];
        if(!empty($data['biz_no']))$context=$this->db->row('SELECT biz_no,uid,type,channel,account,username,money,status FROM pre_transfer WHERE biz_no=?',[$data['biz_no']]) ?: [];
        if(!empty($data['id'])&&strpos($action,'sharing-')===0)$context=$this->db->row('SELECT id,rid,trade_no,money,settle_no,status FROM pre_psorder WHERE id=?',[$data['id']]) ?: [];
        if(!empty($data['id'])&&strpos($action,'receiver-')===0)$context=$this->db->row('SELECT id,channel,uid,info,status FROM pre_psreceiver WHERE id=?',[$data['id']]) ?: [];
        return ['action'=>$action,'name'=>$s['title'],'fields'=>$fields,'note'=>$s['note'],'context'=>(object)$context];
    }
    private function transaction($call)
    {
        if($this->db->pdo->inTransaction())return $call();
        $this->db->pdo->beginTransaction();try{$r=$call();$this->db->pdo->commit();return $r;}catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    private function row($table,$pk,$id){$r=$this->db->row('SELECT * FROM pre_'.$table.' WHERE `'.$pk.'`=?'.$this->db->lockClause(),[$id]);if(!$r)throw new ApiError('业务记录不存在',404);return $r;}
    private function ledger($uid,$amount,$add,$type,$reference)
    {
        if(bccomp((string)$amount,'0',2)<=0)return;
        $u=$this->row('user','uid',$uid);$old=(string)$u['money'];$next=$add?bcadd($old,$amount,2):bcsub($old,$amount,2);
        if(bccomp($next,'99999999.99',2)>0||bccomp($next,'-99999999.99',2)<0)throw new ApiError('余额超出数据库金额范围');
        $this->db->update('user',['money'=>$next],'uid',$uid);
        $this->db->insert('record',['uid'=>$uid,'action'=>$add?1:2,'money'=>$amount,'oldmoney'=>$old,'newmoney'=>$next,'type'=>$type,'trade_no'=>$reference,'date'=>date('Y-m-d H:i:s')]);
    }
    private function noPending($object)
    {
        if($this->activeObject===$object)return;
        if($this->db->row("SELECT id FROM pre_app_operation WHERE id=? AND state='pending'",[$object]))throw new ApiError('此业务有渠道操作结果待核实，请先核对渠道与操作记录，勿重复提交',409);
    }
    /** A durable object guard survives unknown gateway responses and different request keys. */
    private function external($object,$reference,$call,array $detail=[])
    {
        Runtime::boot($this->db,$this->conf);
        $this->noPending($object);
        try{
            $existing=$this->db->row('SELECT id FROM pre_app_operation WHERE id=?',[$object]);
            $detail=json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(!$existing)$this->db->insert('app_operation',['id'=>$object,'request_id'=>$this->request,'reference'=>$reference,'state'=>'pending','created'=>time(),'detail'=>$detail]);
            else if(!$this->db->query("UPDATE pre_app_operation SET state='pending',request_id=?,reference=?,created=?,detail=? WHERE id=? AND state<>'pending'",[$this->request,$reference,time(),$detail,$object])->rowCount())throw new ApiError('操作正在处理',409);
        }catch(\PDOException $e){throw new ApiError('操作正在处理，请查询业务状态',409);}
        $sent=false;$this->activeObject=$object;
        try{$r=$call($sent);$this->db->update('app_operation',['state'=>'done'],'id',$object);return $r;}
        catch(\Throwable $e){if(!$sent)$this->db->update('app_operation',['state'=>'rejected'],'id',$object);if($sent)throw new ApiError('渠道操作结果待核实，业务编号 '.$reference.'。请核对渠道后处理，勿重复发起。',409);throw $e;}
        finally{$this->activeObject=null;}
    }
    private function closed($trade)
    {
        return (bool)$this->db->value("SELECT COUNT(*) FROM pre_app_operation WHERE id=? AND state='done'",['closed:'.$trade]);
    }
    private function markClosed($trade)
    {
        if(!$this->closed($trade))$this->db->insert('app_operation',['id'=>'closed:'.$trade,'request_id'=>$this->request,'reference'=>$trade,'state'=>'done','created'=>time(),'detail'=>'{"action":"close"}']);
    }
    private function channel($id,$type=null)
    {
        $c=$this->db->row('SELECT id,type,status,plugin FROM pre_channel WHERE id=?',[$id]);if(!$c||(int)$c['status']!==1)throw new ApiError('请选择已启用的付款通道');
        if($type&&!in_array($type,explode(',',(string)$this->db->value('SELECT transtypes FROM pre_plugin WHERE name=?',[$c['plugin']])),true))throw new ApiError('插件不支持此付款方式');return $c;
    }
    public function run(array $data)
    {
        $action=(string)($data['action'] ?? '');$s=self::definitions()[$action] ?? null;if(!$s)throw new ApiError('操作不存在',404);
        if(!is_array($data['values'] ?? null))throw new ApiError('操作字段无效');$v=(new Management($this->db,$this->ip))->values($s['fields'],$data['values']);
        foreach($s['fields'] as $f)if(!empty($f['required'])&&(!isset($v[$f['key']])||$v[$f['key']]===''))throw new ApiError('请填写'.$f['label']);
        if(isset($v['money'])&&bccomp($v['money'],'0',2)<=0)throw new ApiError('金额必须大于 0');
        if($action==='payment-password'){
            if(!hash_equals((string)$this->conf['admin_pwd'],$v['admin_password']))throw new ApiError('管理员登录密码错误',403);
            if(mb_strlen($v['password'])<8)throw new ApiError('支付密码至少 8 位');$this->db->setting('admin_paypwd',$v['password']);$this->db->audit('设置支付密码',$this->ip);return ['message'=>'支付密码已设置'];
        }
        if(!in_array($action,['transfer-query','settle-query','sharing-query','sharing-amount','transfer-proof','transfer-balance','order-mark-unpaid','order-mark-paid','order-delete'],true)){
            if(empty($this->conf['admin_paypwd']))throw new ApiError('请先在管理员资料中设置支付密码');
            if(!hash_equals((string)$this->conf['admin_paypwd'],(string)($v['paypwd'] ?? '')))throw new ApiError('支付密码错误',403);
        }
        if($action==='balance')return $this->transaction(function()use($v){$this->ledger($v['uid'],$v['money'],$v['direction']==='add',$v['direction']==='add'?'后台加款':'后台扣款','');$this->db->audit('余额调整',$this->ip,['uid'=>$v['uid'],'direction'=>$v['direction'],'money'=>$v['money'],'reason'=>$v['reason']]);return ['message'=>'余额已调整并记录资金明细'];});
        if(in_array($action,['merchant-password','merchant-key'],true))return $this->transaction(function()use($action,$v){$this->row('user','uid',$v['uid']);$result=['message'=>'商户密码已更新'];if($action==='merchant-key'){$key=bin2hex(random_bytes(16));$update=['key'=>$key];$result=['message'=>'密钥已重置，仅显示一次','secret'=>$key];}else{if(mb_strlen($v['password'])<8)throw new ApiError('商户密码至少 8 位');$update=['pwd'=>md5(md5($v['password']).md5('1277180438'.$v['uid']))];}$this->db->update('user',$update,'uid',$v['uid']);$this->db->audit($action,$this->ip,['uid'=>$v['uid']]);return $result;});
        if(in_array($action,['order-mark-unpaid','order-mark-paid'],true))return $this->transaction(function()use($v,$action){
            $o=$this->row('order','trade_no',$v['trade_no']);$this->noPending('order:'.$v['trade_no']);
            $from=(int)$o['status'];$to=$action==='order-mark-paid'?1:0;
            if(!in_array($from,[0,1,2],true))throw new ApiError('冻结或预授权订单请先完成对应处理',409);
            if((string)$from!==(string)$v['expected_status'])throw new ApiError('订单状态已变化，请刷新后重新确认',409);
            if($to===0&&$from!==0&&!$this->db->row('SELECT id FROM pre_app_operation WHERE id=?',['status-only:'.$v['trade_no']]))
                $this->db->insert('app_operation',['id'=>'status-only:'.$v['trade_no'],'request_id'=>$this->request,'reference'=>$v['trade_no'],'state'=>'done','created'=>time(),'detail'=>'{"prevent_recredit":true}']);
            if($from!==$to)$this->db->update('order',['status'=>$to],'trade_no',$v['trade_no']);
            $this->db->audit($action,$this->ip,['trade_no'=>$v['trade_no'],'from'=>$from,'to'=>$to]);
            return ['message'=>'订单已'.($to?'改已完成':'改未完成').'，余额和资金明细未变更'];
        });
        if($action==='order-delete')return $this->transaction(function()use($v){
            $o=$this->row('order','trade_no',$v['trade_no']);
            $this->noPending('order:'.$v['trade_no']);
            if((int)$o['status']!==0)throw new ApiError('仅支持删除未支付订单');
            if(!empty($o['endtime'])||bccomp((string)($o['refundmoney'] ?: '0'),'0',2)>0||$this->db->row('SELECT id FROM pre_app_operation WHERE id=?',['status-only:'.$v['trade_no']]))throw new ApiError('订单已有支付或退款历史，不能删除');
            foreach(['record','refundorder','psorder','suborder'] as $table)
                if($this->db->value('SELECT COUNT(*) FROM pre_'.$table.' WHERE trade_no=?',[$v['trade_no']]))throw new ApiError('订单存在资金或关联记录，不能删除');
            if($this->db->query('DELETE FROM pre_order WHERE trade_no=? AND status=0',[$v['trade_no']])->rowCount()!==1)throw new ApiError('订单已变化，请刷新后重试',409);
            $this->db->audit('删除订单',$this->ip,['trade_no'=>$v['trade_no'],'uid'=>$o['uid'],'money'=>$o['money']]);
            return ['message'=>'订单已删除','deleted'=>true];
        });
        if(in_array($action,['freeze','unfreeze'],true))return $this->transaction(function()use($v,$action){$o=$this->row('order','trade_no',$v['trade_no']);$this->noPending('order:'.$v['trade_no']);if((int)$o['status']!==($action==='freeze'?1:3))throw new ApiError('订单状态不支持此操作');if((int)$this->db->value('SELECT mode FROM pre_channel WHERE id=?',[$o['channel']])===1)throw new ApiError('商户直清通道不支持冻结');$this->ledger($o['uid'],(string)$o['getmoney'],$action==='unfreeze',$action==='freeze'?'订单冻结':'订单解冻',$v['trade_no']);$this->db->update('order',['status'=>$action==='freeze'?3:1],'trade_no',$v['trade_no']);$this->db->audit($action,$this->ip,['trade_no'=>$v['trade_no']]);return ['message'=>'订单已'.($action==='freeze'?'冻结':'解冻')];});
        if(in_array($action,['manual-fill','order-settle','preauth-pay','preauth-release','redpacket-retry'],true))return $this->orderAction($action,$v);
        if(in_array($action,['merchant-rsa','merchant-delete'],true))return $this->merchantAction($action,$v);
        if(in_array($action,['transfer-approve','transfer-complete','transfer-fail'],true))return $this->reviewTransfer($action,$v);
        if($action==='refund')return $this->refund($v);
        if($action==='order-reconcile')return $this->reconcile($v);
        if($action==='operation-reconcile')return $this->reconcileOperation($v);
        if(strpos($action,'sharing-')===0||strpos($action,'receiver-')===0)return $this->sharing($action,$v);
        if($action==='transfer-balance'){Runtime::boot($this->db,$this->conf);$this->channel($v['channel'],$v['type']);$c=\lib\Channel::get($v['channel']);Runtime::prepare($c,'balance_query');return $this->gatewayResult(\lib\Transfer::balance($v['type'],$c));}
        if($action==='close'&&$this->closed($v['trade_no']))throw new ApiError('此订单已登记渠道关单，请勿重复提交');
        if($action==='close')return $this->external('order:'.$v['trade_no'],$v['trade_no'],function(&$sent)use($v){return $this->transaction(function()use($v,&$sent){$o=$this->row('order','trade_no',$v['trade_no']);if((int)$o['status']!==0)throw new ApiError('只能关闭未付订单');$c=Runtime::orderChannel($this->db,$o);$method=empty($o['combine'])?'close':'close_combine';Runtime::prepare($c,$method);$sent=true;$r=Runtime::orderCall($c,$method,$o);if((int)($r['code'] ?? -1)!==0)throw new \RuntimeException('close unconfirmed');// status=4 belongs to preauthorization; channel closure is recorded in the durable operation guard.
$this->markClosed($v['trade_no']);$this->db->audit('关闭订单',$this->ip,['trade_no'=>$v['trade_no']]);return ['message'=>'订单已关闭'];});},['action'=>'close']);
        if(strpos($action,'settle-')===0)return $this->settlement($action,$v);
        if($action==='batch-create')return $this->transaction(function(){
            $rows=$this->db->all('SELECT id,realmoney FROM pre_settle WHERE status=0 AND transfer_no IS NULL ORDER BY id'.$this->db->lockClause());if(!$rows)throw new ApiError('没有待结算记录');$batch=date('YmdHis').random_int(10000,99999);$total='0.00';foreach($rows as $r){$total=bcadd($total,(string)$r['realmoney'],2);$this->db->update('settle',['batch'=>$batch,'status'=>2],'id',$r['id']);}$this->db->insert('batch',['batch'=>$batch,'allmoney'=>$total,'count'=>count($rows),'time'=>date('Y-m-d H:i:s'),'status'=>0]);$this->db->audit('创建结算批次',$this->ip,['batch'=>$batch]);return ['message'=>'批次已创建，未发起付款','batch'=>$batch,'count'=>count($rows),'amount'=>$total];});
        if($action==='batch-complete')return $this->transaction(function()use($v){$b=$this->row('batch','batch',$v['batch']);if((int)$b['status']===1)throw new ApiError('批次已完成');$rows=$this->db->all('SELECT id,status,transfer_no FROM pre_settle WHERE batch=? ORDER BY id'.$this->db->lockClause(),[$v['batch']]);if(!$rows)throw new ApiError('批次没有结算记录');foreach($rows as $r){if((int)$r['status']!==2||$r['transfer_no'])throw new ApiError('批次包含状态异常或渠道转账记录，请逐条核对');$this->db->update('settle',['status'=>1,'endtime'=>date('Y-m-d H:i:s')],'id',$r['id']);}$this->db->update('batch',['status'=>1],'batch',$v['batch']);$this->db->audit('登记结算批次完成',$this->ip,['batch'=>$v['batch']]);return ['message'=>'批次已登记完成'];});
        if(in_array($action,['transfer','redpacket'],true))return $this->createTransfer($action,$v);
        if(in_array($action,['transfer-query','transfer-cancel','transfer-proof'],true))return $this->transferAction($action,$v);
        throw new ApiError('操作未实现',422);
    }
    /** Website/API callers share the same guarded state transition as the native administrator. */
    public function refundOrder($reference, $trade, $amount, $api, $uid, $outReference)
    {
        $owner=$this->db->row('SELECT uid FROM pre_order WHERE trade_no=?',[$trade]);
        if(!$owner || ($uid>0 && (int)$owner['uid']!==(int)$uid)) throw new ApiError('订单不存在',404);
        $run=function()use($reference,$trade,$amount,$api,$uid,$outReference){
            return $this->refund(['trade_no'=>$trade, 'money'=>$amount, 'mode'=>$api?'api':'manual',
                'expected_uid'=>(int)$uid, 'out_refund_no'=>$outReference ?: $reference], $reference);
        };
        if(!$outReference) return $run();
        $key='epay.refund.'.substr(hash('sha256',$this->db->value('SELECT DATABASE()').':'.$this->db->tablePrefix().':'.$owner['uid'].':'.$outReference),0,51);
        if((int)$this->db->value('SELECT GET_LOCK(?,0)',[$key])!==1)throw new ApiError('该退款单号正在处理，请稍后查询',409);
        try{return $run();}finally{$this->db->value('SELECT RELEASE_LOCK(?)',[$key]);}
    }

    private function refund(array $v,$knownRef=null,$reconciled=false)
    {
        $ref=$knownRef ?: date('YmdHis').random_int(10000,99999);
        $call=function(&$sent)use($v,$ref,$reconciled){return $this->transaction(function()use($v,$ref,$reconciled,&$sent){
            $o=$this->row('order','trade_no',$v['trade_no']);$this->noPending('order:'.$v['trade_no']);$paid=(string)($o['realmoney'] ?? $o['money']);$prev=(string)($o['refundmoney'] ?: '0.00');
            if(!empty($v['expected_uid']) && (int)$o['uid'] !== $v['expected_uid']) throw new ApiError('订单不存在',404);
            if($v['money'] === null || $v['money'] === '') $v['money']=$paid;
            if(!is_scalar($v['money']) || is_bool($v['money']) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',(string)$v['money']) || bccomp((string)$v['money'],'0',2)<=0) throw new ApiError('退款金额必须为大于 0 的金额，最多两位小数');
            $outRef=$v['out_refund_no'] ?? ($v['mode']==='manual'?'manual-':'').$ref;
            $prior=$this->db->row('SELECT * FROM pre_refundorder WHERE uid=? AND out_refund_no=? AND status=1',[$o['uid'],$outRef]);
            if($prior){
                if($prior['trade_no']!==$v['trade_no'] || bccomp((string)$prior['money'],(string)$v['money'],2)!==0) throw new ApiError('退款单号已用于不同订单或金额',409);
                return ['message'=>'该退款已完成','refund_no'=>$prior['refund_no'],'out_refund_no'=>$outRef,'trade_no'=>$v['trade_no'],'out_trade_no'=>$o['out_trade_no'],'uid'=>$o['uid'],'money'=>$prior['money'],'reducemoney'=>$prior['reducemoney']];
            }
            if(!in_array((int)$o['status'],[1,2,3],true)||((int)$o['status']===2&&bccomp($prev,'0',2)===0))throw new ApiError('订单状态不支持退款');
            $next=bcadd($prev,$v['money'],2);if(bccomp($next,$paid,2)>0)throw new ApiError('退款金额超过剩余可退金额');
            if((int)$o['status']===3&&bccomp($v['money'],$paid,2)!==0)throw new ApiError('冻结订单的部分退款请先解冻');
            $direct=(int)$this->db->value('SELECT mode FROM pre_channel WHERE id=?',[$o['channel']])===1;$debit='0.00';
            if(!$direct&&(int)$o['status']!==3){$cap=($this->conf['refund_fee_type'] ?? '0')==='1'?$paid:(string)$o['getmoney'];$before=bccomp($prev,$cap,2)>0?$cap:$prev;$after=bccomp($next,$cap,2)>0?$cap:$next;$debit=bcsub($after,$before,2);}
            $uid=$o['uid'];if((int)$o['tid']===2){$param=json_decode($o['param'] ?? '',true);if(!empty($param['uid']))$uid=$param['uid'];}
            $account=$this->row('user','uid',$uid);
            if(!empty($v['expected_uid']) && bccomp($debit,(string)$account['money'],2)>0) throw new ApiError('商户余额不足，请先充值');
            if($v['mode']==='api'&&!$reconciled){
                if(empty($o['api_trade_no']))throw new ApiError('缺少渠道订单号，无法原路退款');$c=Runtime::orderChannel($this->db,$o);$method=empty($o['combine'])?'refund':'refund_combine';Runtime::prepare($c,$method);$gateway=$o;$gateway['refund_no']=$ref;$gateway['refundmoney']=$v['money'];$sent=true;
                $r=Runtime::orderCall($c,$method,$gateway);if((int)($r['code'] ?? -1)!==0)throw new \RuntimeException('refund unconfirmed');
            }
            $this->ledger($uid,$debit,false,'订单退款',$v['trade_no']);
            if($direct&&($this->conf['refund_fee_type'] ?? '0')!=='1'&&bccomp($next,$paid,2)===0){$fee=$this->db->row("SELECT money FROM pre_record WHERE trade_no=? AND type IN ('订单服务费','在线收款服务费') ORDER BY id LIMIT 1",[$v['trade_no']]);if($fee)$this->ledger($uid,(string)$fee['money'],true,'服务费退款',$v['trade_no']);}
            $this->db->update('order',['status'=>2,'refundmoney'=>$next],'trade_no',$v['trade_no']);
            $this->db->insert('refundorder',['refund_no'=>$ref,'out_refund_no'=>$outRef,'trade_no'=>$v['trade_no'],'uid'=>$o['uid'],'money'=>$v['money'],'reducemoney'=>$debit,'status'=>1,'addtime'=>date('Y-m-d H:i:s'),'endtime'=>date('Y-m-d H:i:s')]);
            $this->db->audit('订单退款',$this->ip,['trade_no'=>$v['trade_no'],'refund_no'=>$ref,'money'=>$v['money'],'mode'=>$v['mode']]);return ['message'=>$v['mode']==='api'?'退款成功':'手工退款已登记（未调用渠道）','refund_no'=>$ref,'out_refund_no'=>$outRef,'trade_no'=>$v['trade_no'],'out_trade_no'=>$o['out_trade_no'],'uid'=>$uid,'money'=>$v['money'],'reducemoney'=>$debit];
        });};
        if($reconciled){$sent=false;return $call($sent);}
        if($v['mode']==='api')return $this->external('order:'.$v['trade_no'],$ref,$call,['action'=>'refund','money'=>$v['money'],'mode'=>'api','out_refund_no'=>$v['out_refund_no'] ?? $ref]);
        $sent=false;return $call($sent);
    }
    public function refreshSharing($id){return $this->sharing('sharing-query',['id'=>$id]);}
    private function sharing($action,array $v)
    {
        return Runtime::sharingLock($this->db,($action[0]==='r'?'receiver:':'').$v['id'],function()use($action,$v){return $this->sharingLocked($action,$v);});
    }
    private function sharingLocked($action,array $v)
    {
        Runtime::boot($this->db,$this->conf);$receiver=strpos($action,'receiver-')===0;$object=($receiver?'receiver:':'sharing:').$v['id'];
        if($action==='sharing-submit')return $this->submitSharing($v,$object);
        $call=function(&$sent)use($action,$v,$receiver,$object){return $this->transaction(function()use($action,$v,$receiver,$object,&$sent){
            if($receiver){$rule=$this->row('psreceiver','id',$v['id']);$o=['channel'=>$rule['channel'],'subchannel'=>$rule['subchannel'],'uid'=>$rule['uid']];}
            else{$r=$this->row('psorder','id',$v['id']);$rule=$this->row('psreceiver','id',$r['rid']);$o=$this->row('order','trade_no',$r['trade_no']);$o['channel']=$rule['channel'];}
            $c=Runtime::orderChannel($this->db,$o);$model=\lib\ProfitSharing\CommUtil::getModel($c);if(!$model)throw new ApiError('此通道不支持分账');
            $info=json_decode($rule['info'] ?? '',true) ?: [['account'=>$rule['account'],'name'=>$rule['name'],'rate'=>$rule['rate']]];
            if($receiver){$next=$action==='receiver-enable'?1:0;if((int)$rule['status']===$next)throw new ApiError('规则已处于此状态');foreach($info as $item){$sent=true;$out=$next?$model->addReceiver($item['account'],$item['name']):$model->deleteReceiver($item['account'],$item['name']);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('receiver sync unconfirmed');}$this->db->update('psreceiver',['status'=>$next],'id',$v['id']);}
            else{
                $trade=$r['sub_trade_no'] ?: $r['trade_no'];$status=(int)$r['status'];
                if($action==='sharing-edit-money'){$this->noPending($object);if($status!==0)throw new ApiError('只有待分账记录可修改金额');if(bccomp($v['money'],(string)$o['realmoney'],2)>0)throw new ApiError('分账金额超过订单实付');$this->db->update('psorder',['money'=>$v['money']],'id',$v['id']);}
                elseif($action==='sharing-amount')return $this->gatewayResult($model->amount($trade,$r['api_trade_no']));
                elseif($action==='sharing-query'){
                    $guard=$this->db->row('SELECT state,detail FROM pre_app_operation WHERE id=?',[$object]);$detail=json_decode($guard['detail'] ?? '',true);
                    if(($guard['state'] ?? '')==='pending'&&($detail['action'] ?? '')!=='sharing-submit')throw new ApiError('请先登记取消或回退操作的渠道核实结果',409);
                    if($status!==1)return ['message'=>$status===0?'分账尚未提交':'分账已结束','status'=>$status];
                    $out=$model->query($trade,$r['api_trade_no'],$r['settle_no']);$this->gatewayResult($out);$u=[];
                    if((int)($out['status'] ?? 0)===1){$u=['status'=>2];if($rule['uid']&&(int)$c['mode']===0)$this->ledger($rule['uid'],(string)$r['money'],false,'订单分账',$r['trade_no']);}
                    elseif((int)($out['status'] ?? 0)===2)$u=['status'=>3,'result'=>mb_substr($out['reason'] ?? '渠道分账失败',0,255)];
                    if($u){$this->db->update('psorder',$u,'id',$v['id']);if(($detail['action'] ?? '')==='sharing-submit')$this->db->update('app_operation',['state'=>'done'],'id',$object);}return ['message'=>'分账结果已刷新','status'=>$u['status'] ?? $status];
                }else{
                    if($action==='sharing-cancel'){
                        if(!in_array($status,[0,3],true))throw new ApiError('请先查询已提交分账的最终结果');$sent=true;$out=$model->unfreeeze($trade,$r['api_trade_no']);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('sharing cancel unconfirmed');$u=['status'=>4];
                    }else{if($status!==2)throw new ApiError('只有成功分账可回退');$sent=true;$out=$model->return($trade,$r['api_trade_no'],$r['settle_no'],json_decode($r['rdata'] ?? '',true) ?: []);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('sharing return unconfirmed');$u=['status'=>4];}
                    $this->db->update('psorder',$u,'id',$v['id']);
                }
            }
            $this->db->audit($action,$this->ip,['id'=>$v['id']]);return ['message'=>'分账操作已完成'];
        });};
        if(in_array($action,['sharing-query','sharing-amount','sharing-edit-money'],true)){$sent=false;return $call($sent);}
        return $this->external($object,(string)$v['id'],$call,['action'=>$action]);
    }
    private function submitSharing(array $v,$object)
    {
        return $this->external($object,(string)$v['id'],function(&$sent)use($v){
            $prepared=$this->transaction(function()use($v){
                $r=$this->row('psorder','id',$v['id']);if(!in_array((int)$r['status'],[0,3],true))throw new ApiError('只有待分账或失败记录可提交');
                $rule=$this->row('psreceiver','id',$r['rid']);if((int)$rule['status']!==1)throw new ApiError('分账规则未启用');$o=$this->row('order','trade_no',$r['trade_no']);$o['channel']=$rule['channel'];
                $c=Runtime::orderChannel($this->db,$o);$model=\lib\ProfitSharing\CommUtil::getModel($c);if(!$model)throw new ApiError('此通道不支持分账');
                $trade=$r['sub_trade_no'] ?: $r['trade_no'];$amount=$r['sub_trade_no']?$this->db->value('SELECT money FROM pre_suborder WHERE sub_trade_no=?',[$trade]):$o['realmoney'];if(!$amount||bccomp((string)$r['money'],'0',2)<=0)throw new ApiError('订单或分账金额无效');
                $info=json_decode($rule['info'] ?? '',true) ?: [['account'=>$rule['account'],'name'=>$rule['name'],'rate'=>$rule['rate']]];
                // This claim must commit before I/O. A timeout must never restore status=0.
                $this->db->update('psorder',['status'=>1,'result'=>'渠道结果待查询'],'id',$r['id']);return [$r,$rule,$c,$model,$trade,$amount,$info];
            });
            [$r,$rule,$c,$model,$trade,$amount,$info]=$prepared;$sent=true;$out=$model->submit($trade,$r['api_trade_no'],$amount,$info);
            if(!in_array((int)($out['code'] ?? -1),[0,1],true))throw new \RuntimeException('sharing unconfirmed');
            return $this->transaction(function()use($v,$out,$rule,$c,$r){
                $current=$this->row('psorder','id',$v['id']);$u=['settle_no'=>$out['settle_no'] ?? null];
                if(isset($out['rdata'])){$u['money']=$out['money'];$u['rdata']=json_encode($out['rdata'],JSON_UNESCAPED_UNICODE);}
                if((int)$current['status']!==2){$u['status']=(int)$out['code']===1?2:1;if($u['status']===2&&$rule['uid']&&(int)$c['mode']===0)$this->ledger($rule['uid'],(string)($u['money'] ?? $r['money']),false,'订单分账',$r['trade_no']);}
                $this->db->update('psorder',$u,'id',$v['id']);$this->db->audit('提交分账',$this->ip,['id'=>$v['id']]);return ['message'=>'分账已提交，请查询最终结果','status'=>$u['status'] ?? 2];
            });
        },['action'=>'sharing-submit']);
    }
    private function reconcileOperation(array $v)
    {
        return $this->transaction(function()use($v){
            [$kind,$id]=explode(':',$v['object']);$row=$this->row($kind==='sharing'?'psorder':'psreceiver','id',$id);
            $guard=$this->db->row('SELECT * FROM pre_app_operation WHERE id=?'.$this->db->lockClause(),[$v['object']]);
            if(!$guard||$guard['state']!=='pending'||!hash_equals((string)$guard['reference'],$v['reference']))throw new ApiError('未找到对应待核实操作');
            if(time()-(int)$guard['created']<120)throw new ApiError('至少 2 分钟后才能登记核实结果');$detail=json_decode($guard['detail'] ?? '',true);$action=$detail['action'] ?? '';
            if(!in_array($action,['sharing-cancel','sharing-return','receiver-enable','receiver-disable'],true))throw new ApiError('此业务应通过查询功能刷新');
            if($v['outcome']==='success')$this->db->update($kind==='sharing'?'psorder':'psreceiver',['status'=>$kind==='sharing'?4:($action==='receiver-enable'?1:0)],'id',$id);
            $this->db->update('app_operation',['state'=>$v['outcome']==='success'?'done':'rejected'],'id',$guard['id']);
            $this->db->audit('渠道操作核实',$this->ip,['object'=>$v['object'],'reference'=>$v['reference'],'outcome'=>$v['outcome'],'evidence'=>$v['evidence']]);return ['message'=>'核实结果已登记'];
        });
    }
    private function gatewayResult($r)
    {
        if(!is_array($r)||(int)($r['code'] ?? -1)!==0)throw new ApiError(is_array($r)?($r['msg'] ?? '渠道查询失败'):'渠道返回异常');
        $out=['message'=>$r['msg'] ?? '查询完成'];foreach(['balance','money','amount','url','proof_url','download_url','status','orderid','paydate'] as $k)if(isset($r[$k])&&is_scalar($r[$k]))$out[$k]=$r[$k];return $out;
    }
    private function settlement($action,array $v)
    {
        Runtime::boot($this->db,$this->conf);
        if($action==='settle-query'){
            $r=$this->db->row('SELECT * FROM pre_settle WHERE id=?',[$v['id']]);if(!$r||!$r['transfer_no'])throw new ApiError('没有可查询的转账编号');$c=\lib\Channel::get($r['transfer_channel']);if(!$c)throw new ApiError('付款通道不存在');Runtime::prepare($c,'transfer_query');$type=[1=>'alipay',2=>'wxpay',3=>'qqpay',4=>'bank'][(int)$r['type']] ?? '';
            $out=\lib\Transfer::query($type,$c,$r['transfer_no'],$r['transfer_result']);$this->gatewayResult($out);
            return $this->transaction(function()use($v,$out){$r=$this->row('settle','id',$v['id']);$this->settleResult($r,$out);if(in_array((int)($out['status'] ?? -1),[1,2],true))$this->db->update('app_operation',['state'=>'done'],'id','settle:'.$v['id']);return ['message'=>'渠道结果已刷新','status'=>$out['status'] ?? 0];});
        }
        if($action!=='settle-pay')return $this->transaction(function()use($action,$v){$r=$this->row('settle','id',$v['id']);$this->noPending('settle:'.$v['id']);if((int)$r['status']===1)throw new ApiError('已完成结算不能重复操作');if($r['transfer_no']&&((int)$r['transfer_status']!==2||$action!=='settle-return'))throw new ApiError('已有渠道转账，请先查询结果');
            if($action==='settle-return'){if((int)$r['status']!==3)throw new ApiError('请先明确登记结算失败');$this->ledger($r['uid'],(string)$r['money'],true,'结算失败退回','settle-'.$r['id']);$this->db->query('DELETE FROM pre_settle WHERE id=?',[$r['id']]);}
            elseif($action==='settle-complete')$this->db->update('settle',['status'=>1,'endtime'=>date('Y-m-d H:i:s'),'result'=>null],'id',$r['id']);
            elseif($action==='settle-fail')$this->db->update('settle',['status'=>3,'result'=>$v['reason']],'id',$r['id']);
            $this->db->audit($action,$this->ip,['id'=>$r['id']]);return ['message'=>'结算已处理'];});
        $ref=date('YmdHis').random_int(10000,99999);
        return $this->external('settle:'.$v['id'],$ref,function(&$sent)use($v,$ref){
            $prepared=$this->transaction(function()use($v,$ref){$r=$this->row('settle','id',$v['id']);if((int)$r['status']===1||$r['transfer_no'])throw new ApiError('已有付款记录，不能重复出款');$type=[1=>'alipay',2=>'wxpay',3=>'qqpay',4=>'bank'][(int)$r['type']] ?? '';if(!$type)throw new ApiError('结算方式不支持');$this->channel($v['channel'],$type);$c=\lib\Channel::get($v['channel']);Runtime::prepare($c,'transfer');$this->db->update('settle',['transfer_no'=>$ref,'transfer_channel'=>$v['channel'],'transfer_status'=>0,'result'=>'渠道结果待查询'],'id',$r['id']);return [$r,$type,$c];});
            [$r,$type,$c]=$prepared;$sent=true;$out=\lib\Transfer::submit($type,$c,$ref,$r['account'],$r['username'],$r['realmoney'],null,null);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('settlement unconfirmed');
            return $this->transaction(function()use($v,$out){$r=$this->row('settle','id',$v['id']);$this->settleResult($r,$out);$this->db->audit('结算转账',$this->ip,['id'=>$r['id'],'reference'=>$r['transfer_no']]);return ['message'=>'转账已提交，请查询渠道最终结果','status'=>$out['status'] ?? 0,'biz_no'=>$r['transfer_no']];});
        },['action'=>'settle-pay']);
    }
    private function settleResult(array $r,array $out)
    {
        if((int)$r['status']===1||((int)$r['transfer_status']===2&&(int)$r['status']===3))return;
        $status=(int)($out['status'] ?? 0);if(!in_array($status,[0,1,2],true))throw new ApiError('渠道状态未知');$u=['transfer_status'=>$status===2?2:1];
        if(!empty($out['orderid']))$u['transfer_result']=$out['orderid'];if(isset($out['wxpackage']))$u['transfer_ext']=$out['wxpackage'];
        if($status===1)$u+=['status'=>1,'endtime'=>date('Y-m-d H:i:s'),'transfer_date'=>$out['paydate'] ?? date('Y-m-d H:i:s'),'result'=>null];
        elseif($status===2)$u+=['status'=>3,'result'=>mb_substr($out['errmsg'] ?? '渠道明确失败',0,64)];else $u['result']='渠道处理中，请查询';$this->db->update('settle',$u,'id',$r['id']);
    }
    private function createTransfer($action,array $v)
    {
        Runtime::boot($this->db,$this->conf);$this->channel($v['channel'],$v['type']);$c=\lib\Channel::get($v['channel']);Runtime::prepare($c,'transfer');$biz=date('YmdHis').random_int(10000,99999);
        return $this->external('transfer:'.$biz,$biz,function(&$sent)use($action,$v,$c,$biz){
            $this->transaction(function()use($action,$v,$biz){$this->db->insert('transfer',['biz_no'=>$biz,'out_biz_no'=>$biz,'uid'=>0,'type'=>$v['type'],'channel'=>$v['channel'],'account'=>$v['account'] ?? '','username'=>$v['name'] ?? '','money'=>$v['money'],'costmoney'=>$v['money'],'addtime'=>date('Y-m-d H:i:s'),'status'=>$action==='redpacket'?4:0,'desc'=>$v['desc']]);});
            if($action==='redpacket')return ['message'=>'红包已创建','biz_no'=>$biz,'url'=>\lib\Transfer::red_url($biz),'status'=>4];
            $sent=true;$out=\lib\Transfer::submit($v['type'],$c,$biz,$v['account'],$v['name'],$v['money'],null,$v['desc']);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('transfer unconfirmed');
            $this->transaction(function()use($biz,$out){$r=$this->row('transfer','biz_no',$biz);$this->transferResult($r,$out);$this->db->audit('新增付款',$this->ip,['biz_no'=>$biz]);});return ['message'=>'付款已提交，请查询最终结果','biz_no'=>$biz,'status'=>$out['status'] ?? 0];
        },['action'=>$action]);
    }
    public function transferCallback($biz,$status,$errmsg=null)
    {
        if(!in_array((int)$status,[1,2],true))return;
        return $this->transaction(function()use($biz,$status,$errmsg){$r=$this->row('transfer','biz_no',$biz);$this->transferResult($r,['status'=>(int)$status,'errmsg'=>$errmsg ?? '渠道通知失败']);$this->db->update('app_operation',['state'=>'done'],'id','transfer:'.$biz);});
    }
    private function transferResult(array $r,array $out)
    {
        if(in_array((int)$r['status'],[1,2],true))return;
        $status=(int)($out['status'] ?? -1);if(!in_array($status,[0,1,2],true))throw new ApiError('渠道状态未知');$u=['status'=>$status];
        if(!empty($out['orderid']))$u['pay_order_no']=$out['orderid'];if(isset($out['wxpackage']))$u['ext']=$out['wxpackage'];
        if($status===1)$u+=['paytime'=>$out['paydate'] ?? date('Y-m-d H:i:s'),'result'=>''];
        if($status===2){$u['result']=mb_substr($out['errmsg'] ?? '转账已撤销或失败',0,255);if((int)$r['uid']>0)$this->ledger($r['uid'],(string)$r['costmoney'],true,'代付退回',$r['biz_no']);}
        $this->db->update('transfer',$u,'biz_no',$r['biz_no']);
    }
    private function transferAction($action,array $v)
    {
        Runtime::boot($this->db,$this->conf);
        if($action==='transfer-proof'){$r=\lib\Transfer::proof($v['biz_no']);return $this->gatewayResult($r);}
        if($action==='transfer-query'){
            $r=$this->db->row('SELECT * FROM pre_transfer WHERE biz_no=?',[$v['biz_no']]);if(!$r)throw new ApiError('付款记录不存在');if((int)$r['status']===3)return ['message'=>'付款尚未审核提交','status'=>3];if(in_array((int)$r['status'],[1,2],true))return ['message'=>'付款已结束','status'=>(int)$r['status']];if((int)$r['status']===4)return ['message'=>'红包尚未领取','url'=>\lib\Transfer::red_url($r['biz_no'])];
            $c=\lib\Channel::get($r['channel'],$r['uid']?$this->db->value('SELECT channelinfo FROM pre_user WHERE uid=?',[$r['uid']]):null);if(!$c)throw new ApiError('付款通道不存在');Runtime::prepare($c,'transfer_query');$out=\lib\Transfer::query($r['type'],$c,$r['biz_no'],$r['pay_order_no']);$this->gatewayResult($out);
            return $this->transaction(function()use($v,$out){$r=$this->row('transfer','biz_no',$v['biz_no']);$this->transferResult($r,$out);if(in_array((int)($out['status'] ?? -1),[1,2],true))$this->db->update('app_operation',['state'=>'done'],'id','transfer:'.$v['biz_no']);return ['message'=>'付款结果已刷新','status'=>$out['status'] ?? 0];});
        }
        return $this->external('transfer:'.$v['biz_no'],$v['biz_no'],function(&$sent)use($v){return $this->transaction(function()use($v,&$sent){$r=$this->row('transfer','biz_no',$v['biz_no']);if(in_array((int)$r['status'],[1,2],true))throw new ApiError('已结束付款不能撤销');
            if((int)$r['status']===4)$out=['code'=>0,'status'=>2];else{$c=\lib\Channel::get($r['channel'],$r['uid']?$this->db->value('SELECT channelinfo FROM pre_user WHERE uid=?',[$r['uid']]):null);if(!$c)throw new ApiError('通道不存在');Runtime::prepare($c,'transfer_cancel');$sent=true;$out=\lib\Plugin::call('transfer_cancel',$c,['type'=>$r['type'],'out_biz_no'=>$r['biz_no'],'orderid'=>$r['pay_order_no']]);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('cancel unconfirmed');$out['status']=2;}
            $this->transferResult($r,$out);$this->db->audit('撤销付款',$this->ip,['biz_no'=>$r['biz_no']]);return ['message'=>'付款已撤销'];});},['action'=>'transfer-cancel']);
    }
    private function reconcile(array $v)
    {
        return $this->transaction(function()use($v){
            $o=$this->row('order','trade_no',$v['trade_no']);
            $guard=$this->db->row('SELECT * FROM pre_app_operation WHERE id=?'.$this->db->lockClause(),['order:'.$v['trade_no']]);
            if(!$guard||$guard['state']!=='pending'||!hash_equals((string)$guard['reference'],$v['reference']))throw new ApiError('未找到对应待核实操作');
            if(time()-(int)$guard['created']<120)throw new ApiError('请等待渠道请求结束，至少 2 分钟后核实');
            $detail=json_decode($guard['detail'] ?? '',true);$action=$detail['action'] ?? '';
            if(!in_array($action,['refund','close','manual-fill','order-settle','preauth-pay','preauth-release','redpacket-retry'],true))throw new ApiError('此操作不支持手工核实');
            $success=$v['outcome']==='success';
            if(in_array($action,['manual-fill','preauth-pay'],true)){
                if($success&&(int)$o['status']!==1)throw new ApiError('尚未确认平台入账完成。请先由后台核对渠道、商户余额及业务流水并修复，再登记成功；此入口不会重复入账');
                if(!$success&&(int)$o['status']!==($action==='manual-fill'?0:4))throw new ApiError('订单状态已改变，不能登记未成功');
            }
            // Guard transition and ledger changes commit together. No gateway call is replayed.
            $this->db->update('app_operation',['state'=>$success?'done':'rejected'],'id',$guard['id']);
            if($success){
                if($action==='close'){if((int)$o['status']!==0)throw new ApiError('订单状态已改变，请先核对');$this->markClosed($v['trade_no']);}
                elseif($action==='refund')$this->refund(['trade_no'=>$v['trade_no'],'money'=>$detail['money'],'mode'=>'api','out_refund_no'=>$detail['out_refund_no'] ?? $v['reference']],$v['reference'],true);
                elseif($action==='preauth-release'){if(!in_array((int)$o['status'],[0,4],true))throw new ApiError('订单状态已改变');$this->db->query('UPDATE pre_order SET status=0 WHERE trade_no=? AND status=4',[$v['trade_no']]);}
                elseif(in_array($action,['order-settle','redpacket-retry'],true)){if((int)$o['status']!==1)throw new ApiError('订单状态已改变');$this->db->update('order',['settle'=>2],'trade_no',$v['trade_no']);}
            }
            $this->db->audit('核实渠道结果',$this->ip,['trade_no'=>$v['trade_no'],'reference'=>$v['reference'],'action'=>$action,'outcome'=>$v['outcome'],'evidence'=>$v['evidence']]);
            return ['message'=>'核实结果已登记，未重发渠道请求'];
        });
    }
    private function merchantAction($action,array $v)
    {
        return $this->transaction(function()use($action,$v){
            $u=$this->row('user','uid',$v['uid']);
            if($action==='merchant-rsa'){
                $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
                if(!$key||!openssl_pkey_export($key,$private))throw new ApiError('服务器无法生成 RSA 密钥',503);
                $public=openssl_pkey_get_details($key)['key'];$this->db->update('user',['keytype'=>1,'publickey'=>$public],'uid',$v['uid']);
                $this->db->audit('生成商户 RSA 密钥',$this->ip,['uid'=>$v['uid']]);
                return ['message'=>'已切换 RSA 签名，请保存私钥并交给商户更新对接','publickey'=>$public,'secret'=>$private];
            }
            if(bccomp((string)$u['money'],'0',2)!==0||bccomp((string)($u['deposit'] ?? 0),'0',2)!==0)throw new ApiError('商户有余额或保证金，不能删除');
            foreach(['order','refundorder','transfer','settle','record','subchannel','psreceiver','domain','invitecode','regcode','risk','log'] as $table)
                if($this->db->value('SELECT COUNT(*) FROM pre_'.$table.' WHERE uid=?',[$v['uid']]))throw new ApiError('商户有业务记录，请停用商户代替删除');
            if($this->db->value('SELECT COUNT(*) FROM pre_user WHERE upid=?',[$v['uid']]))throw new ApiError('商户仍有下级商户');
            $this->db->query('DELETE FROM pre_merchant_channel_policy WHERE uid=?',[$v['uid']]);
            $this->db->query('DELETE FROM pre_user WHERE uid=?',[$v['uid']]);$this->db->audit('删除无业务商户',$this->ip,['uid'=>$v['uid']]);return ['message'=>'商户已删除'];
        });
    }
    private function orderAction($action,array $v)
    {
        // The persistent guard spans legacy business logic, which owns its own transactions.
        $ref=date('YmdHis').random_int(10000,99999);
        return $this->external('order:'.$v['trade_no'],$ref,function(&$sent)use($action,$v,$ref){
            $o=$this->db->row('SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=?',[$v['trade_no']]);
            if(!$o)throw new ApiError('订单不存在',404);$c=Runtime::orderChannel($this->db,$o);
            if($action==='manual-fill'){
                if($this->db->row('SELECT id FROM pre_app_operation WHERE id=?',['status-only:'.$v['trade_no']]))throw new ApiError('此订单仅重置显示状态，禁止重复补单入账');
                if($this->closed($v['trade_no']))throw new ApiError('已登记渠道关单的订单不能直接补单，请先核实渠道最终状态');
                if((int)$o['status']!==0)throw new ApiError('只有未支付订单可补单');
                $GLOBALS['channel']=$c;$sent=true;
                \lib\Payment::processOrder(true,$o,$o['api_trade_no']);
                if((int)$this->db->value('SELECT status FROM pre_order WHERE trade_no=?',[$v['trade_no']])!==1)throw new \RuntimeException('completion not confirmed');
            }elseif($action==='order-settle'){
                if((int)$o['status']!==1||!in_array((int)$o['settle'],[1,3],true)||!in_array($c['plugin'],['alipayd','wxpaynp'],true))throw new ApiError('此订单不支持确认结算');
                $sent=true;if($c['plugin']==='alipayd')\lib\Payment::alipaydSettle($c,$o);else \lib\Payment::wxpaynpSettle($c,$o);
                $this->db->update('order',['settle'=>2],'trade_no',$v['trade_no']);
            }elseif($action==='redpacket-retry'){
                if($c['plugin']!=='alipayrp'||(int)$o['status']!==1||!in_array((int)$o['settle'],[1,3],true))throw new ApiError('此订单不支持红包转账重试');
                $payee=$c['appmchid'] ?? $this->db->value('SELECT alipay_uid FROM pre_user WHERE uid=?',[$o['uid']]);if(!$payee)throw new ApiError('商户未绑定支付宝账号');
                $sent=true;\lib\Payment::alipayRedPacketTransfer($c,$payee,$o['money'],$o['api_trade_no'],$ref);$this->db->update('order',['settle'=>2],'trade_no',$v['trade_no']);
            }else{
                if((int)$o['status']!==4||strpos($c['plugin'],'alipay')!==0)throw new ApiError('只有支付宝预授权订单可执行此操作');
                $sent=true;
                if($action==='preauth-release'){
                    \lib\Payment::alipayUnfreeze($c,$o,$ref);
                    $this->db->query('UPDATE pre_order SET status=0 WHERE trade_no=? AND status=4',[$v['trade_no']]);
                }else{
                    $out=\lib\Payment::alipayPreAuthPay($c,$o,$o['realmoney']);
                    if(empty($out['trade_no'])||!isset($out['total_amount'])||bccomp((string)$out['total_amount'],(string)$o['realmoney'],2)!==0)throw new \RuntimeException('preauth amount mismatch');
                    $GLOBALS['channel']=$c;\lib\Payment::processOrder(true,$o,$out['trade_no'],$out['buyer_user_id'] ?? null);
                }
            }
            $audit=['trade_no'=>$v['trade_no'],'reference'=>$ref];if(isset($v['evidence']))$audit['evidence']=$v['evidence'];
            $this->db->audit($action,$this->ip,$audit);return ['message'=>'操作已完成，请刷新订单核对','reference'=>$ref];
        },['action'=>$action]);
    }
    private function reviewTransfer($action,array $v)
    {
        if($action!=='transfer-approve')return $this->transaction(function()use($action,$v){
            $r=$this->row('transfer','biz_no',$v['biz_no']);$this->noPending('transfer:'.$v['biz_no']);
            if(!in_array((int)$r['status'],[0,3],true))throw new ApiError('仅处理中或待审核付款可以登记结果');
            $this->transferResult($r,['status'=>$action==='transfer-complete'?1:2,'errmsg'=>$v['evidence']]);
            $this->db->audit($action,$this->ip,['biz_no'=>$v['biz_no'],'evidence'=>$v['evidence']]);return ['message'=>'付款结果已登记'];
        });
        return $this->external('transfer:'.$v['biz_no'],$v['biz_no'],function(&$sent)use($v){
            $r=$this->transaction(function()use($v){$r=$this->row('transfer','biz_no',$v['biz_no']);if((int)$r['status']!==3)throw new ApiError('只有待审核付款可提交');$this->channel($r['channel'],$r['type']);$c=\lib\Channel::get($r['channel'],$r['uid']?$this->db->value('SELECT channelinfo FROM pre_user WHERE uid=?',[$r['uid']]):null);Runtime::prepare($c,'transfer');$this->db->update('transfer',['status'=>0],'biz_no',$r['biz_no']);return [$r,$c];});
            [$row,$c]=$r;$sent=true;$out=\lib\Transfer::submit($row['type'],$c,$row['biz_no'],$row['account'],$row['username'],$row['money'],null,$row['desc']);if((int)($out['code'] ?? -1)!==0)throw new \RuntimeException('transfer review unconfirmed');
            $this->transaction(function()use($row,$out){$r=$this->row('transfer','biz_no',$row['biz_no']);$this->transferResult($r,$out);});$this->db->audit('审核付款',$this->ip,['biz_no'=>$row['biz_no']]);return ['message'=>'审核付款已提交，请查询最终结果','biz_no'=>$row['biz_no']];
        },['action'=>'transfer-approve']);
    }

}
