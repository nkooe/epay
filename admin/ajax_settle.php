<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

if(in_array($act,['create_batch', 'complete_batch', 'setSettleStatus', 'opslist', 'settle_save', 'transfer'],true)){
    try{\lib\Security\AdminFinancial::requireVerified(\lib\AdminApp\Runtime::legacyStore($DB),$conf,$clientip,$_POST['paypwd']??null);}
    catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>$e->getCode()===-9?-9:-1,'msg'=>$e->getMessage()]));}
}


switch($act){
case 'settleList':
	$sql=" 1=1";
	$params = [];
	if(isset($_POST['batch']) && !empty($_POST['batch'])) {
		$sql.=" AND `batch`=:batch";
		$params[':batch'] = trim((string)$_POST['batch']);
	}
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$sql.=" AND `uid`=:uid";
		$params[':uid'] = intval($_POST['uid']);
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$sql.=" AND `type`=:type";
		$params[':type'] = intval($_POST['type']);
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$sql.=" AND `status`=:status";
		$params[':status'] = intval($_POST['dstatus']);
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$sql.=" AND (`account` LIKE :value_account OR `username` LIKE :value_username)";
		$params[':value_account'] = '%'.(string)$_POST['value'].'%';
		$params[':value_username'] = '%'.(string)$_POST['value'].'%';
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_settle WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_settle WHERE{$sql} order by id desc limit $offset,$limit", $params);
	$list2 = [];
	foreach($list as $row){
		if($row['type'] == 2 && in_array((int)$row['status'],[0,1],true) && !empty($row['transfer_ext']) && time() - strtotime($row['transfer_date']?:$row['addtime']) <= 86400){
			if(substr($row['ext'], 0, 4) == 'http'){
				$row['jumpurl'] = $row['ext'];
			}else{
				$row['jumpurl'] = $siteurl.'paypage/wxtrans.php?id='.$row['id'].'&type=settle';
			}
		}
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'create_batch':
case 'complete_batch':
    try{
        $ops=new \lib\AdminApp\Operations(\lib\AdminApp\Runtime::legacyStore($DB),$clientip);
        $r=$ops->batchAction($act==='create_batch'?'batch-create':'batch-complete',['batch'=>trim((string)($_POST['batch']??''))]);
        exit(json_encode(['code'=>0,'msg'=>$r['message'],'allmoney'=>$r['amount']??null]+$r));
    }catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));}
break;
case 'setSettleStatus':
case 'opslist':
    $status=(int)($act==='opslist'?($_POST['status']??-1):($_GET['status']??-1));
    // Removing funded history is never a generic bulk delete operation.
    if($act==='opslist'&&$status===4)exit('{"code":-1,"msg":"结算记录包含资金引用，请逐条登记失败并退回余额"}');
    $action=[1=>'settle-complete',3=>'settle-fail',4=>'settle-return'][$status]??null;
    if(!$action)exit('{"code":-1,"msg":"不能直接重置结算状态，请使用结算生命周期操作"}');
    $ids=$act==='opslist'?(array)($_POST['checkbox']??[]):[(int)($_GET['id']??0)];$count=0;
    try{
        $ops=new \lib\AdminApp\Operations(\lib\AdminApp\Runtime::legacyStore($DB),$clientip);
        foreach($ids as $id){$ops->settlementAction($action,['id'=>(int)$id,'reason'=>'后台登记失败']);$count++;}
        exit(json_encode(['code'=>$act==='opslist'?0:200,'msg'=>'已处理'.$count.'条结算记录']));
    }catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>-1,'msg'=>'已处理'.$count.'条；'.$e->getMessage()]));}
break;
case 'settle_result':
	$id=intval($_POST['id']);
	$row=$DB->getRow("select result from pre_settle where id=:id limit 1", [':id'=>$id]);
	if(!$row)
		exit('{"code":-1,"msg":"当前结算记录不存在！"}');
	$result = ['code'=>0,'msg'=>'succ','result'=>$row['result']];
	exit(json_encode($result));
break;
case 'settle_setresult':
	$id=intval($_POST['id']);
	$result=trim($_POST['result']);
	$row=$DB->getRow("select * from pre_settle where id=:id limit 1", [':id'=>$id]);
	if(!$row)
		exit('{"code":-1,"msg":"当前结算记录不存在！"}');
	$sds = $DB->exec("UPDATE pre_settle SET result=:result WHERE id=:id", [':result'=>$result, ':id'=>$id]);
	if($sds!==false)
		exit('{"code":0,"msg":"修改成功！"}');
	else
		exit('{"code":-1,"msg":"修改失败！'.$DB->error().'"}');
break;
case 'settle_info':
	$id=intval($_GET['id']);
	$rows=$DB->getRow("select * from pre_settle where id=:id limit 1", [':id'=>$id]);
	if(!$rows)
		exit('{"code":-1,"msg":"当前结算记录不存在！"}');
	$account = htmlspecialchars((string)$rows['account'], ENT_QUOTES, 'UTF-8');
	$username = htmlspecialchars((string)$rows['username'], ENT_QUOTES, 'UTF-8');
	$data = '<div class="form-group"><div class="input-group"><div class="input-group-addon">结算方式</div><select class="form-control" id="pay_type" default="'.(int)$rows['type'].'">'.($conf['settle_alipay']?'<option value="1">支付宝</option>':null).''.($conf['settle_wxpay']?'<option value="2">微信</option>':null).''.($conf['settle_qqpay']?'<option value="3">QQ钱包</option>':null).''.($conf['settle_bank']?'<option value="4">银行卡</option>':null).'</select></div></div>';
	$data .= '<div class="form-group"><div class="input-group"><div class="input-group-addon">结算账号</div><input type="text" id="pay_account" value="'.$account.'" class="form-control" required/></div></div>';
	$data .= '<div class="form-group"><div class="input-group"><div class="input-group-addon">真实姓名</div><input type="text" id="pay_name" value="'.$username.'" class="form-control" required/></div></div>';
	$data .= '<input type="submit" id="save" onclick="saveInfo('.$id.')" class="btn btn-primary btn-block" value="保存">';
	$result=array("code"=>0,"msg"=>"succ","data"=>$data,"pay_type"=>$rows['type']);
	exit(json_encode($result));
break;
case 'settle_save':
	$id=intval($_POST['id']);
	$pay_type=intval($_POST['pay_type']);
	$pay_account=htmlspecialchars(trim($_POST['pay_account']));
	$pay_name=htmlspecialchars(trim($_POST['pay_name']));
    try{
        $store=\lib\AdminApp\Runtime::legacyStore($DB);
        \lib\Finance\Ledger::transaction($store,function()use($store,$id,$pay_type,$pay_account,$pay_name){
            $r=$store->row('SELECT * FROM pre_settle WHERE id=?'.$store->lockClause(),[$id]);
            if(!$r||!in_array((int)$r['status'],[0,2],true)||$r['transfer_no'])throw new \DomainException('已有付款或结果待核实，不能修改收款账号');
            if(!in_array($pay_type,[1,2,3,4],true)||$pay_account===''||$pay_name==='')throw new \DomainException('收款信息无效');
            $store->update('settle',['type'=>$pay_type,'account'=>$pay_account,'username'=>$pay_name],'id',$id);
        });exit('{"code":0,"msg":"修改成功"}');
    }catch(\DomainException $e){exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));}
break;
case 'paypwd_check':
    try{\lib\Security\AdminFinancial::requireVerified(\lib\AdminApp\Runtime::legacyStore($DB),$conf,$clientip);exit('{"code":0}');}
    catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));}
break;
case 'paypwd_input':
    try{\lib\Security\AdminFinancial::verify(\lib\AdminApp\Runtime::legacyStore($DB),$conf,$clientip,$_POST['paypwd']??null);exit('{"code":0,"msg":"验证成功"}');}
    catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));}
break;
case 'paypwd_reset':
    unset($_SESSION['paypwd'],$_SESSION['financial_stamp'],$_SESSION['financial_until']);exit('{"code":0}');
break;
case 'transfer':
    $id=(int)($_POST['id']??0);$channelId=(int)($_POST['channel']??0);
    try{
        $store=\lib\AdminApp\Runtime::legacyStore($DB);$ops=new \lib\AdminApp\Operations($store,$clientip);
        $r=$store->row('SELECT transfer_no FROM pre_settle WHERE id=?',[$id]);
        if(!$r)throw new \lib\AdminApp\ApiError('记录不存在');
        $out=$ops->settlementAction($r['transfer_no']?'settle-query':'settle-pay',['id'=>$id,'channel'=>$channelId]);
        exit(json_encode(['code'=>0,'ret'=>($out['status']??0)===1?2:1,'result'=>$out['message']]));
    }catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));}
break;

default:
	exit('{"code":-4,"msg":"操作不存在"}');
break;
}
