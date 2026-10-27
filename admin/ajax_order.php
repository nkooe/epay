<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

function adminApplyOrderStatus($trade_no, $status){
	global $DB;
	$trade_no=trim((string)$trade_no);
	$status=(int)$status;
	if($trade_no==='' || !in_array($status,[0,1],true))return ['code'=>400,'msg'=>'订单编号或状态参数错误'];
	if(!$DB->beginTransaction())return ['code'=>500,'msg'=>'无法开始订单状态更新'];
	try{
		$row=$DB->getRow("SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1 FOR UPDATE",[':trade_no'=>$trade_no]);
		if(!$row){$DB->rollBack();return ['code'=>404,'msg'=>'订单不存在'];}
		$current=(int)$row['status'];
		if($current===$status){
			if(!$DB->commit())return ['code'=>500,'msg'=>'订单状态确认失败'];
			return ['code'=>200,'msg'=>'订单已处于目标状态'];
		}
		if(!in_array($current,[0,1,2],true)){
			$DB->rollBack();
			return ['code'=>409,'msg'=>'冻结或预授权订单请先完成对应处理'];
		}
		if($status===0 && $current!==0){
			$markerId='status-only:'.$trade_no;
			$marker=$DB->getRow("SELECT id FROM pre_app_operation WHERE id=:id",[':id'=>$markerId]);
			if(!$marker){
				$inserted=$DB->exec("INSERT INTO pre_app_operation (id,request_id,reference,state,created,detail) VALUES (:id,:request_id,:reference,'done',:created,:detail)",[
					':id'=>$markerId, ':request_id'=>'web-admin-status', ':reference'=>$trade_no,
					':created'=>time(), ':detail'=>'{"prevent_recredit":true}'
				]);
				if($inserted===false){
					$DB->rollBack();
					return ['code'=>500,'msg'=>'订单状态未修改，防重复入账保护未能写入'];
				}
			}
		}
		$updated=$DB->exec("UPDATE pre_order SET status=:status WHERE trade_no=:trade_no AND status=:current",[
			':status'=>$status, ':trade_no'=>$trade_no, ':current'=>$current
		]);
		if($updated===false || (int)$updated<1){
			$DB->rollBack();
			return ['code'=>409,'msg'=>'订单状态未更新，请刷新后重试'];
		}
		if(!$DB->commit())return ['code'=>500,'msg'=>'订单状态提交失败'];
		return ['code'=>200,'msg'=>'订单状态已更新'];
	}catch(\Throwable $e){
		$DB->rollBack();
		return ['code'=>500,'msg'=>'订单状态更新失败'];
	}
}

switch($act){
case 'orderList':
	$paytype = [];
	$paytypes = [];
	$rs = $DB->getAll("SELECT * FROM pre_type");
	foreach($rs as $row){
		$paytype[$row['id']] = $row['showname'];
		$paytypes[$row['id']] = $row['name'];
	}
	unset($rs);

	$sql=" 1=1";
	$params = [];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$sql.=" AND A.`uid`=:uid";
		$params[':uid'] = intval($_POST['uid']);
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$sql.=" AND A.`type`=:type";
		$params[':type'] = intval($_POST['type']);
	}elseif(isset($_POST['channel']) && !empty($_POST['channel'])) {
		$sql.=" AND A.`channel`=:channel";
		$params[':channel'] = intval($_POST['channel']);
	}elseif(isset($_POST['subchannel']) && !empty($_POST['subchannel'])) {
		$sql.=" AND A.`subchannel`=:subchannel";
		$params[':subchannel'] = intval($_POST['subchannel']);
	}elseif(isset($_POST['applyid']) && !empty($_POST['applyid'])) {
		$sql.=" AND A.`subchannel` IN (SELECT id FROM pre_subchannel WHERE apply_id=:applyid)";
		$params[':applyid'] = intval($_POST['applyid']);
	}
	if(isset($_POST['dstatus']) && !isNullOrEmpty($_POST['dstatus'])) {
		$dstatus = (string)$_POST['dstatus'];
		if(preg_match('/^settle_([1-3])$/', $dstatus, $matches)){
			$sql.=" AND A.settle=:dstatus";
			$params[':dstatus'] = (int)$matches[1];
		}else{
			if(!preg_match('/^[0-4]$/', $dstatus))exit(json_encode(['code'=>400,'msg'=>'状态筛选参数错误']));
			$sql.=" AND A.status=:dstatus";
			$params[':dstatus'] = (int)$dstatus;
		}
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = trim((string)$_POST['starttime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starttime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND A.addtime>=:starttime";
			$params[':starttime'] = $starttime.' 00:00:00';
		}
		if(!empty($_POST['endtime'])){
			$endtime = trim((string)$_POST['endtime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endtime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND A.addtime<=:endtime";
			$params[':endtime'] = $endtime.' 23:59:59';
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = (string)($_POST['column'] ?? '');
		$allowedColumns = ['trade_no', 'out_trade_no', 'api_trade_no', 'bill_mch_trade_no', 'bill_trade_no', 'name', 'money', 'realmoney', 'getmoney', 'domain', 'buyer', 'ip', 'mobile', 'param'];
		if(!in_array($column, $allowedColumns, true))exit(json_encode(['code'=>400,'msg'=>'搜索字段无效']));
		if($column=='name'){
			$sql.=" AND A.`{$column}` LIKE :value";
			$params[':value'] = '%'.(string)$_POST['value'].'%';
		}else{
			if(in_array($column, ['money', 'realmoney', 'getmoney'], true) && strpos((string)$_POST['value'],'-')!==false){
				$money = explode('-', (string)$_POST['value'], 2);
				if(count($money)!==2 || !is_numeric($money[0]) || !is_numeric($money[1]))exit(json_encode(['code'=>400,'msg'=>'金额范围格式错误']));
				$sql.=" AND A.`{$column}`>=:min_value AND A.`{$column}`<=:max_value";
				$params[':min_value'] = $money[0];
				$params[':max_value'] = $money[1];
			}else{
				$sql.=" AND A.`{$column}`=:value";
				$params[':value'] = (string)$_POST['value'];
			}
		}
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_order A WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT A.*,B.plugin,B.name channelname FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE{$sql} order by trade_no desc limit $offset,$limit", $params);
	$list2 = [];
	foreach($list as $row){
		$row['typename'] = $paytypes[$row['type']];
		$row['typeshowname'] = $paytype[$row['type']];
		$list2[] = $row;
	}

	exit(json_encode(['total'=>$total, 'rows'=>$list2]));
break;

case 'refundOrderList':
	$sql=" 1=1";
	$params = [];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$sql.=" AND `uid`=:uid";
		$params[':uid'] = intval($_POST['uid']);
	}
	if(isset($_POST['status']) && !isNullOrEmpty($_POST['status'])) {
		$sql.=" AND `status`=:status";
		$params[':status'] = intval($_POST['status']);
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = trim((string)$_POST['starttime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starttime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND addtime>=:starttime";
			$params[':starttime'] = $starttime.' 00:00:00';
		}
		if(!empty($_POST['endtime'])){
			$endtime = trim((string)$_POST['endtime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endtime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND addtime<=:endtime";
			$params[':endtime'] = $endtime.' 23:59:59';
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = isset($_POST['column']) ? $_POST['column'] : '';
		$allow_columns = ['refund_no', 'out_refund_no', 'trade_no'];
		if(in_array($column, $allow_columns)){
			$sql.=" AND `{$column}`=:value";
			$params[':value'] = (string)$_POST['value'];
		}
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_refundorder WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_refundorder WHERE{$sql} order by refund_no desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'refundOrderStats':
	$sql=" 1=1";
	$params = [];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$sql.=" AND `uid`=:uid";
		$params[':uid'] = intval($_POST['uid']);
	}
	if(isset($_POST['status']) && !isNullOrEmpty($_POST['status'])) {
		$sql.=" AND `status`=:status";
		$params[':status'] = intval($_POST['status']);
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = trim((string)$_POST['starttime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starttime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND addtime>=:starttime";
			$params[':starttime'] = $starttime.' 00:00:00';
		}
		if(!empty($_POST['endtime'])){
			$endtime = trim((string)$_POST['endtime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endtime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND addtime<=:endtime";
			$params[':endtime'] = $endtime.' 23:59:59';
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = isset($_POST['column']) ? $_POST['column'] : '';
		$allow_columns = ['refund_no', 'out_refund_no', 'trade_no'];
		if(in_array($column, $allow_columns)){
			$sql.=" AND `{$column}`=:value";
			$params[':value'] = (string)$_POST['value'];
		}
	}
	$row = $DB->getRow("SELECT 
		COUNT(*) AS totalCount,
		SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS processingCount,
		SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS successCount,
		SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS failCount,
		SUM(money) AS totalMoney,
		SUM(CASE WHEN status = 0 THEN money ELSE 0 END) AS processingMoney,
		SUM(CASE WHEN status = 1 THEN money ELSE 0 END) AS successMoney,
		SUM(CASE WHEN status = 2 THEN money ELSE 0 END) AS failMoney
		FROM pre_refundorder WHERE{$sql}", $params);
	$result = [
		'totalCount' => $row['totalCount'] ?? '0',
		'processingCount' => $row['processingCount'] ?? '0',
		'successCount' => $row['successCount'] ?? '0',
		'failCount' => $row['failCount'] ?? '0',
		'totalMoney' => number_format(floatval($row['totalMoney']), 2, '.', ''),
		'processingMoney' => number_format(floatval($row['processingMoney']), 2, '.', ''),
		'successMoney' => number_format(floatval($row['successMoney']), 2, '.', ''),
		'failMoney' => number_format(floatval($row['failMoney']), 2, '.', ''),
	];
	exit(json_encode(['code'=>0, 'data'=>$result]));
break;

case 'statistics':
    $sql=" 1=1";
	$params = [];
	if(isset($_POST['uid']) && !empty($_POST['uid'])) {
		$sql.=" AND A.`uid`=:uid";
		$params[':uid'] = intval($_POST['uid']);
	}
	if(isset($_POST['type']) && !empty($_POST['type'])) {
		$sql.=" AND A.`type`=:type";
		$params[':type'] = intval($_POST['type']);
	}elseif(isset($_POST['channel']) && !empty($_POST['channel'])) {
		$sql.=" AND A.`channel`=:channel";
		$params[':channel'] = intval($_POST['channel']);
	}elseif(isset($_POST['subchannel']) && !empty($_POST['subchannel'])) {
		$sql.=" AND A.`subchannel`=:subchannel";
		$params[':subchannel'] = intval($_POST['subchannel']);
	}elseif(isset($_POST['applyid']) && !empty($_POST['applyid'])) {
		$sql.=" AND A.`subchannel` IN (SELECT id FROM pre_subchannel WHERE apply_id=:applyid)";
		$params[':applyid'] = intval($_POST['applyid']);
	}
	if(isset($_POST['dstatus']) && $_POST['dstatus']>-1) {
		$dstatus = (string)$_POST['dstatus'];
		if(preg_match('/^settle_([1-3])$/', $dstatus, $matches)){
			$sql.=" AND A.settle=:dstatus";
			$params[':dstatus'] = (int)$matches[1];
		}else{
			if(!preg_match('/^[0-4]$/', $dstatus))exit(json_encode(['code'=>400,'msg'=>'状态筛选参数错误']));
			$sql.=" AND A.status=:dstatus";
			$params[':dstatus'] = (int)$dstatus;
		}
	}
	if(!empty($_POST['starttime']) || !empty($_POST['endtime'])){
		if(!empty($_POST['starttime'])){
			$starttime = trim((string)$_POST['starttime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starttime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND A.addtime>=:starttime";
			$params[':starttime'] = $starttime.' 00:00:00';
		}
		if(!empty($_POST['endtime'])){
			$endtime = trim((string)$_POST['endtime']);
			if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endtime))exit(json_encode(['code'=>400,'msg'=>'日期格式错误']));
			$sql.=" AND A.addtime<=:endtime";
			$params[':endtime'] = $endtime.' 23:59:59';
		}
	}
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = (string)($_POST['column'] ?? '');
		$allowedColumns = ['trade_no', 'out_trade_no', 'api_trade_no', 'bill_mch_trade_no', 'bill_trade_no', 'name', 'money', 'realmoney', 'getmoney', 'domain', 'buyer', 'ip', 'mobile', 'param'];
		if(!in_array($column, $allowedColumns, true))exit(json_encode(['code'=>400,'msg'=>'搜索字段无效']));
		if($column=='name'){
			$sql.=" AND A.`{$column}` LIKE :value";
			$params[':value'] = '%'.(string)$_POST['value'].'%';
		}else{
			if(in_array($column, ['money', 'realmoney', 'getmoney'], true) && strpos((string)$_POST['value'],'-')!==false){
				$money = explode('-', (string)$_POST['value'], 2);
				if(count($money)!==2 || !is_numeric($money[0]) || !is_numeric($money[1]))exit(json_encode(['code'=>400,'msg'=>'金额范围格式错误']));
				$sql.=" AND A.`{$column}`>=:min_value AND A.`{$column}`<=:max_value";
				$params[':min_value'] = $money[0];
				$params[':max_value'] = $money[1];
			}else{
				$sql.=" AND A.`{$column}`=:value";
				$params[':value'] = (string)$_POST['value'];
			}
		}
	}
    // 统计数据
    $resultMoneyData = $DB->getRow("SELECT 
    SUM(money) AS totalMoney,
    SUM(CASE WHEN A.status = 1 THEN money ELSE 0 END) AS successMoney,
    SUM(CASE WHEN A.status = 0 THEN money ELSE 0 END) AS unpaidMoney,
    SUM(CASE WHEN A.status = 2 THEN refundmoney ELSE 0 END) AS refundMoney
    FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE {$sql}", $params);

    $resultCount = $DB->getRow("SELECT 
    COUNT(*) AS totalCount,
    SUM(CASE WHEN A.status = 1 THEN 1 ELSE 0 END) AS successCount,
    SUM(CASE WHEN A.status = 0 THEN 1 ELSE 0 END) AS unpaidCount,
    SUM(CASE WHEN A.status = 2 THEN 1 ELSE 0 END) AS refundCount
    FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE {$sql}", $params);

    // 获取平台总收入利润
    $platformProfit = $DB->getColumn("SELECT SUM(A.profitmoney) FROM pre_order A LEFT JOIN pre_channel B ON A.channel=B.id WHERE {$sql} AND A.status = 1", $params);

	$result = [
        'totalMoney' => number_format($resultMoneyData['totalMoney'], 2, '.', '') ?? 0.00,
        'successMoney' => number_format($resultMoneyData['successMoney'], 2, '.', '') ?? 0.00,
        'unpaidMoney' => number_format($resultMoneyData['unpaidMoney'], 2, '.', '') ?? 0.00,
        'refundMoney' => number_format($resultMoneyData['refundMoney'], 2, '.', '') ?? 0.00,
        'totalCount' => $resultCount['totalCount'] ?? '0',
        'successCount' => $resultCount['successCount'] ?? '0',
        'unpaidCount' => $resultCount['unpaidCount'] ?? '0',
        'refundCount' => $resultCount['refundCount'] ?? '0',
        'platformProfit' => number_format($platformProfit, 2, '.', '') ?? 0.00
    ];
	$result['successRate'] = $result['totalCount'] > 0 ? round(($result['totalCount']-$result['unpaidCount']) / $result['totalCount'] * 100, 2) : 0;
	exit(json_encode(['code'=>0, 'data'=>$result]));
break;

case 'riskList':
	$sql=" 1=1";
	$params = [];
	if(isset($_POST['value']) && !empty($_POST['value'])) {
		$column = (string)($_POST['column'] ?? '');
		if(!in_array($column, ['uid', 'url', 'content'], true))exit(json_encode(['code'=>400,'msg'=>'搜索字段无效']));
		$sql.=" AND `{$column}`=:value";
		$params[':value'] = (string)$_POST['value'];
	}
	if(isset($_POST['type']) && $_POST['type']>-1) {
		$type = filter_var($_POST['type'], FILTER_VALIDATE_INT);
		if($type===false || $type<0 || $type>3)exit(json_encode(['code'=>400,'msg'=>'风控类型参数错误']));
		$sql.=" AND `type`=:type";
		$params[':type'] = $type;
	}
	$offset = intval($_POST['offset']);
	$limit = intval($_POST['limit']);
	$total = $DB->getColumn("SELECT count(*) from pre_risk WHERE{$sql}", $params);
	$list = $DB->getAll("SELECT * FROM pre_risk WHERE{$sql} order by id desc limit $offset,$limit", $params);

	exit(json_encode(['total'=>$total, 'rows'=>$list]));
break;

case 'setStatus': //改变订单状态
	$trade_no=trim((string)($_GET['trade_no'] ?? ''));
	$status=filter_var($_GET['status'] ?? null,FILTER_VALIDATE_INT);
	if($trade_no==='' || $status===false)exit(json_encode(['code'=>400,'msg'=>'订单编号或状态参数错误']));
	if($status==5){
		$deleted=$DB->exec("DELETE FROM pre_order WHERE trade_no=:trade_no",[':trade_no'=>$trade_no]);
		if($deleted===false)exit(json_encode(['code'=>500,'msg'=>'删除订单失败']));
		if((int)$deleted<1)exit(json_encode(['code'=>404,'msg'=>'订单不存在']));
		exit(json_encode(['code'=>200,'msg'=>'订单已删除']));
	}
	exit(json_encode(adminApplyOrderStatus($trade_no,$status)));
break;
case 'order': //订单详情
	$trade_no=trim($_GET['trade_no']);
	$row=$DB->getRow("select A.*,B.showname typename,C.name channelname from pre_order A,pre_type B,pre_channel C where A.trade_no=:trade_no and A.type=B.id and A.channel=C.id limit 1", [':trade_no'=>$trade_no]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在或未成功选择支付通道！"}');
	$row['subchannelname'] = $row['subchannel'] > 0 ? $DB->findColumn('subchannel', 'name', ['id'=>$row['subchannel']]) : '';
	if($row['status']==2){
		$row['refundtime'] = $DB->findColumn('refundorder', 'addtime', ['trade_no'=>$trade_no], 'refund_no DESC');
	}
	$row['notification']=\lib\Notification\Delivery::legacy()->detail($row);
	$result=array("code"=>0,"msg"=>"succ","data"=>$row);
	exit(json_encode($result));
break;
case 'subOrders':
	$trade_no=trim($_GET['trade_no']);
	$list = \lib\Payment::getSubOrders($trade_no);
	exit(json_encode(['code'=>0, 'data'=>$list, 'settle'=>$DB->findColumn('order', 'settle', ['trade_no'=>$trade_no])]));
break;
case 'operation': //批量操作订单
	$status=filter_var($_POST['status'] ?? null,FILTER_VALIDATE_INT);
	if($status===false || !in_array($status,[0,1,2,3,4],true))exit(json_encode(['code'=>-1,'msg'=>'请选择有效操作']));
	$checkbox=isset($_POST['checkbox']) && is_array($_POST['checkbox']) ? $_POST['checkbox'] : [];
	if(empty($checkbox))exit(json_encode(['code'=>-1,'msg'=>'请选择订单']));
	$i=0;$failures=[];
	foreach($checkbox as $trade_no){
		$trade_no=trim((string)$trade_no);
		if($trade_no===''){$failures[]='空订单编号';continue;}
		if($status===0 || $status===1){
			$result=adminApplyOrderStatus($trade_no,$status);
			if($result['code']===200)$i++;
			else $failures[]=$trade_no.'：'.$result['msg'];
		}elseif($status===4){
			$deleted=$DB->exec("DELETE FROM pre_order WHERE trade_no=:trade_no",[':trade_no'=>$trade_no]);
			if($deleted!==false && (int)$deleted>0)$i++;
			else $failures[]=$trade_no.'：删除失败';
		}else{
			$result=$status===3 ? \lib\Order::unfreeze($trade_no) : \lib\Order::freeze($trade_no);
			$expectedStatus=$status===3?1:3;
			$actualStatus=$DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no",[':trade_no'=>$trade_no]);
			if(isset($result['code']) && (int)$result['code']===0 && (int)$actualStatus===$expectedStatus)$i++;
			else $failures[]=$trade_no.'：'.($result['msg'] ?? '操作失败');
		}
	}
	$msg='成功处理'.$i.'条订单';
	if($failures)$msg.='，失败'.count($failures).'条：'.implode('；',array_slice($failures,0,5));
	exit(json_encode(['code'=>0,'msg'=>$msg,'failed'=>count($failures)]));
break;
case 'getmoney': //退款查询
	if(!$conf['admin_paypwd'])exit('{"code":-1,"msg":"你还未设置支付密码"}');
	$trade_no=trim($_POST['trade_no']);
	$api=isset($_POST['api'])?intval($_POST['api']):0;
	$result = \lib\Order::refund_info($trade_no, $api);
	exit(json_encode($result));
break;
case 'refund': //退款操作
	$trade_no=trim($_POST['trade_no']);
	$money = trim($_POST['money']);
	if(!is_numeric($money) || !preg_match('/^[0-9.]+$/', $money))exit('{"code":-1,"msg":"金额输入错误"}');

	$refund_no = date("YmdHis").rand(11111,99999);
	$result = \lib\Order::refund($refund_no, $trade_no, $money);
	if($result['code'] == 0){
		$result['msg'] = '已成功从UID:'.$result['uid'].'扣除'.$result['reducemoney'].'元余额';
	}
	exit(json_encode($result));
break;
case 'apirefund': //API退款操作
	$trade_no=trim($_POST['trade_no']);
	$paypwd=trim($_POST['paypwd']);
	$money = trim($_POST['money']);
	if(!is_numeric($money) || !preg_match('/^[0-9.]+$/', $money))exit('{"code":-1,"msg":"金额输入错误"}');
	if($paypwd!=$conf['admin_paypwd'])
		exit('{"code":-1,"msg":"支付密码输入错误！"}');
	
	$refund_no = date("YmdHis").rand(11111,99999);
	$result = \lib\Order::refund($refund_no, $trade_no, $money, 1);
	if($result['code'] == 0){
		$result['msg'] = '退款成功！退款金额¥'.$result['money'];
		if($result['reducemoney']>0){
			$result['msg'] .= '，并成功从UID:'.$result['uid'].'扣除'.$result['reducemoney'].'元余额';
		}
	}
	exit(json_encode($result));
break;
case 'freeze': //冻结订单
	$trade_no=trim($_POST['trade_no']);
	$result = \lib\Order::freeze($trade_no);
	exit(json_encode($result));
break;
case 'unfreeze': //解冻订单
	$trade_no=trim($_POST['trade_no']);
	$result = \lib\Order::unfreeze($trade_no);
	exit(json_encode($result));
break;
case 'notifyHistory':
	$row=$DB->find('order','trade_no,notify,notifytime',['trade_no'=>trim((string)($_GET['trade_no'] ?? ''))]);
	if(!$row)exit(json_encode(['code'=>-1,'msg'=>'订单不存在']));
	exit(json_encode(['code'=>0,'data'=>\lib\Notification\Delivery::legacy()->detail($row,max(0,(int)($_GET['before'] ?? 0)))]));
break;
case 'notify':
	if($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['order_notify_csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['order_notify_csrf'],$_POST['csrf']))exit(json_encode(['code'=>-1,'msg'=>'页面凭证已失效，请刷新后重试']));
	$trade_no=trim($_POST['trade_no']);
	$row=$DB->find('order','*',['trade_no'=>$trade_no]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	if(!in_array((int)$row['status'],[1,2,3],true))exit(json_encode(['code'=>-1,'msg'=>'未支付订单不能发送通知']));
	if(($_POST['isreturn'] ?? 0)==1){$url=creat_callback($row);exit(json_encode(['code'=>0,'url'=>$url['return']]));}
	session_write_close();
	try{$result=\lib\Notification\Delivery::legacy()->send($trade_no,'admin');exit(json_encode(['code'=>$result['delivered']?0:-1,'msg'=>$result['message'],'data'=>$result]));}
	catch(\lib\AdminApp\ApiError $e){exit(json_encode(['code'=>-1,'msg'=>$e->getMessage()]));}
	catch(Throwable $e){error_log('Order notify failed: '.get_class($e));exit(json_encode(['code'=>-1,'msg'=>'通知处理异常，请查看发送记录后再重试']));}
break;
case 'fillorder': //手动补单
	$trade_no=trim((string)($_POST['trade_no'] ?? ''));
	if($trade_no==='')exit(json_encode(['code'=>-1,'msg'=>'订单编号不能为空']));
	$row=$DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A left join pre_type B on A.type=B.id WHERE trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
	if(!$row)
		exit(json_encode(['code'=>-1,'msg'=>'当前订单不存在！']));
	if((int)$row['status']!==0)exit(json_encode(['code'=>-1,'msg'=>'当前订单不是未完成状态！']));
	$pending=$DB->getColumn("SELECT COUNT(*) FROM pre_app_operation WHERE id=:id AND state='pending'",[':id'=>'order:'.$trade_no]);
	if($pending===false || (int)$pending>0)exit(json_encode(['code'=>-1,'msg'=>'订单存在待核实操作，请先核对渠道结果']));
	$closed=$DB->getColumn("SELECT COUNT(*) FROM pre_app_operation WHERE id=:id AND state='done'",[':id'=>'closed:'.$trade_no]);
	if($closed===false || (int)$closed>0)exit(json_encode(['code'=>-1,'msg'=>'该订单已关单或保护数据不可用，不能直接补单']));
	$reset=$DB->getColumn("SELECT COUNT(*) FROM pre_app_operation WHERE id=:id AND state='done'",[':id'=>'status-only:'.$trade_no]);
	if($reset===false)exit(json_encode(['code'=>-1,'msg'=>'订单保护数据不可用，暂不能手工补单']));
	if((int)$reset>0)exit(json_encode(['code'=>-1,'msg'=>'该订单已重置状态，禁止重复入账']));
	$channel=!empty($row['subchannel']) ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'],$DB->findColumn('user','channelinfo',['uid'=>$row['uid']]));
	if(!$channel)exit(json_encode(['code'=>-1,'msg'=>'订单支付通道不存在']));
	$GLOBALS['channel']=$channel;
	try{
		\lib\Payment::processOrder(true,$row,$row['api_trade_no']);
	}catch(\Throwable $e){
		exit(json_encode(['code'=>-1,'msg'=>'补单处理失败，请核对订单状态后重试']));
	}
	$currentStatus=$DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no",[':trade_no'=>$trade_no]);
	if((int)$currentStatus!==1)exit(json_encode(['code'=>-1,'msg'=>'订单未完成入账，请刷新并核实状态']));
	exit(json_encode(['code'=>0,'msg'=>'补单成功']));
break;
case 'alipaydSettle': //支付宝直付通确认结算
	$trade_no=trim($_POST['trade_no']);
	$row=$DB->getRow("select * from pre_order where trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
	if(!$row)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	if($row['status']==0)exit('{"code":-1,"msg":"当前订单状态是未支付"}');
	$channel = $row['subchannel'] > 0 ? \lib\Channel::getSub($row['subchannel']) : \lib\Channel::get($row['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	try{
		if($channel['plugin'] == 'alipayd'){
			\lib\Payment::alipaydSettle($channel, $row);
		}elseif($channel['plugin'] == 'wxpaynp'){
			\lib\Payment::wxpaynpSettle($channel, $row);
		}else{
			exit('{"code":-1,"msg":"支付插件不支持该操作"}');
		}
		$DB->exec("update `pre_order` set `settle`=2 where `trade_no`=:trade_no", [':trade_no'=>$trade_no]);
		exit('{"code":0,"msg":"结算成功！"}');
	}catch(Exception $e){
		$DB->exec("update `pre_order` set `settle`=3 where `trade_no`=:trade_no", [':trade_no'=>$trade_no]);
		exit('{"code":-1,"msg":"结算失败,'.$e->getMessage().'"}');
	}
break;
case 'alipayPreAuthPay': //支付宝授权资金支付
	$trade_no=trim($_POST['trade_no']);
	$order=$DB->getRow("select * from pre_order where trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
	if(!$order)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$channel = $order['subchannel'] > 0 ? \lib\Channel::getSub($order['subchannel']) : \lib\Channel::get($order['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	try{
		$result = \lib\Payment::alipayPreAuthPay($channel, $order);

		$api_trade_no = $result['trade_no'];
		$buyer_id = $result['buyer_user_id'];
		$total_amount = $result['total_amount'];
		processNotify($order, $api_trade_no, $buyer_id);

		exit('{"code":0,"msg":"授权资金支付成功！"}');
	}catch(Exception $e){
		$errmsg = $e->getMessage();
		exit('{"code":-1,"msg":"授权资金支付失败,'.$errmsg.'"}');
	}
break;
case 'alipayUnfreeze': //支付宝授权资金解冻
	$trade_no=trim($_POST['trade_no']);
	$order=$DB->getRow("select * from pre_order where trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
	if(!$order)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$channel = $order['subchannel'] > 0 ? \lib\Channel::getSub($order['subchannel']) : \lib\Channel::get($order['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	try{
		\lib\Payment::alipayUnfreeze($channel, $order);
		$DB->exec("update `pre_order` set `status`=0 where `trade_no`=:trade_no", [':trade_no'=>$trade_no]);
		exit('{"code":0,"msg":"授权资金解冻成功！"}');
	}catch(Exception $e){
		$errmsg = $e->getMessage();
		exit('{"code":-1,"msg":"授权资金解冻失败,'.$errmsg.'"}');
	}
break;
case 'alipayRedPacketTansfer': //支付宝红包转账重试
	$trade_no=trim($_POST['trade_no']);
	$order=$DB->getRow("select * from pre_order where trade_no=:trade_no limit 1", [':trade_no'=>$trade_no]);
	if(!$order)
		exit('{"code":-1,"msg":"当前订单不存在！"}');
	$channel = $order['subchannel'] > 0 ? \lib\Channel::getSub($order['subchannel']) : \lib\Channel::get($order['channel'], $DB->findColumn('user', 'channelinfo', ['uid'=>$row['uid']]));
	if(!$channel){
		exit('{"code":-1,"msg":"当前支付通道信息不存在"}');
	}
	if(!empty($channel['appmchid'])) $payee_user_id = $channel['appmchid'];
	else $payee_user_id = $DB->findColumn('user', 'alipay_uid', ['uid'=>$order['uid']]);
	if(!$payee_user_id) exit('{"code":-1,"msg":"当前商户未绑定支付宝账号"}');
	try{
		\lib\Payment::alipayRedPacketTransfer($channel, $payee_user_id, $order['money'], $order['api_trade_no']);
		$DB->exec("update `pre_order` set `settle`=2 where `trade_no`=:trade_no", [':trade_no'=>$trade_no]);
		exit('{"code":0,"msg":"红包打款成功！"}');
	}catch(Exception $e){
		$errmsg = $e->getMessage();
		exit('{"code":-1,"msg":"红包打款失败,'.$errmsg.'"}');
	}
break;
default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}
