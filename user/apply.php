<?php
include("../includes/common.php");
if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$title='申请提现';
include './head.php';
?>
<?php

function display_type($type){
	if($type==1)
		return '支付宝';
	elseif($type==2)
		return '微信';
	elseif($type==3)
		return 'QQ钱包';
	elseif($type==4)
		return '银行卡';
	else
		return 1;
}

function convert_type($type){
	if($type==1)
		return 'alipay';
	elseif($type==2)
		return 'wxpay';
	elseif($type==3)
		return 'qqpay';
	elseif($type==4)
		return 'bank';
	else
		return null;
}

if($conf['settle_open']==0||$conf['settle_open']==1)exit('未开启手动申请提现');

if($conf['settle_type']==1){
	$today=date("Y-m-d").' 00:00:00';
	$order_today=$DB->getColumn("SELECT SUM(realmoney) from pre_order where uid={$uid} and status=1 and endtime>='$today'");
	if(!$order_today) $order_today = 0;
	$enable_money=round($userrow['money']-$order_today,2);
	if($enable_money<0)$enable_money=0;
}else{
	$enable_money=$userrow['money'];
}
if($userrow['remain_money'] > 0 && !strpos($userrow['remain_money'], '%') && $enable_money > 0){
	$enable_money = round($enable_money - $userrow['remain_money'], 2);
	if($enable_money<0)$enable_money=0;
}

if(($_GET['act']??'')==='do'&&($_POST['submit']??'')==='申请提现'){
    if(!checkRefererHost())exit('请求来源无效，请刷新页面后重试');
    try{
        $store=\lib\AdminApp\Runtime::legacyStore($DB);
        $r=(new \lib\Merchant\Settlement($store,$store->config()))->create($uid,$_POST['money']??null);
        $c=$r['config'];$app=convert_type($r['type']);$channelId=$c['transfer_'.$app]??0;
        $message='申请提现成功，请等待结算';
        if(!empty($c['settle_transfer'])&&$channelId>0&&(empty($c['settle_transfermax'])||bccomp($r['money'],(string)$c['settle_transfermax'],2)<=0)){
            try{(new \lib\AdminApp\Operations($store,$clientip))->settlementAction('settle-pay',['id'=>$r['id'],'channel'=>$channelId]);$message='提现转账已提交，请在结算记录中查询或确认收款';}
            catch(\Throwable $e){$message='申请已受理，请在结算记录核对转账结果，勿重复申请';error_log('Settlement '.$r['id'].': '.get_class($e));}
        }
        try{\lib\MsgNotice::send('apply',0,['uid'=>$uid,'money'=>$r['money'],'realmoney'=>$r['realmoney'],'type'=>display_type($r['type']),'account'=>$r['account'],'username'=>$r['username']]);}catch(\Throwable $e){error_log('Settlement notification failed');}
        exit('<script>alert('.json_encode($message,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');location.href="./settle.php";</script>');
    }catch(\DomainException $e){exit('<script>alert('.json_encode($e->getMessage(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).');history.back();</script>');}
}



?>
 <div id="content" class="app-content" role="main">
    <div class="app-content-body ">

<div class="bg-light lter b-b wrapper-md hidden-print">
  <h1 class="m-n font-thin h3">申请提现</h1>
</div>
<div class="wrapper-md control">
<?php if(isset($msg)){?>
<div class="alert alert-info">
	<?php echo $msg?>
</div>
<?php }?>
	<div class="panel panel-default">
		<div class="panel-heading font-bold">
			申请提现
		</div>
		<div class="panel-body">
			<form class="form-horizontal devform" action="./apply.php?act=do" method="post">
				<div class="form-group">
					<label class="col-sm-2 control-label">提现方式</label>
					<div class="col-sm-9">
						<div class="input-group"><input class="form-control" type="text" value="<?php echo display_type($userrow['settle_id'])?>" disabled><a href="./editinfo.php" class="input-group-addon">修改收款账号</a></div>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-2 control-label">提现账号</label>
					<div class="col-sm-9">
						<input class="form-control" type="text" value="<?php echo $userrow['account']?>" disabled>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-2 control-label">你的姓名</label>
					<div class="col-sm-9">
						<input class="form-control" type="text" value="<?php echo $userrow['username']?>" disabled>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-2 control-label">当前余额</label>
					<div class="col-sm-9">
						<input class="form-control" type="text" value="<?php echo $userrow['money']?>" disabled>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-2 control-label">可提现余额</label>
					<div class="col-sm-9">
						<input class="form-control" type="text" name="tmoney" value="<?php echo $enable_money?>" disabled>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-2 control-label">申请提现余额</label>
					<div class="col-sm-9">
						<div class="input-group"><input class="form-control" type="text" name="money" value="" required><a href="javascript:inputMoney()" class="input-group-addon">全部</a></div>
					</div>
				</div>
				<div class="form-group">
				  <div class="col-sm-offset-2 col-sm-4"><input type="submit" name="submit" value="申请提现" class="btn btn-primary form-control"/><br/>
				 </div>
				</div>
			</form>
			<footer class="panel-footer">
				<div class="col-sm-offset-2 col-sm-6"><br/>
				<h4><span class="glyphicon glyphicon-info-sign"></span>注意事项</h4>
					当前最低提现金额为<b><?php echo $conf['settle_money']?></b>元<br/>
					当前手动提现模式是：<?php echo $conf['settle_type']==1?'<b>D+1</b>，可提现余额为截止到前一天你的收入':'<b>D+0</b>，可提现余额为截止到现在你的收入';?><br/>
					<?php echo $conf['settle_transfer']==1?'申请提现后，你的款项将立刻下发到指定账户内。':'申请提现后，你的款项将在1天内下发到指定账户内。';?>
				</div>
			</footer>
		</div>
	</div>
</div>
    </div>
  </div>

<?php include 'foot.php';?>
<script>
function inputMoney(){
	$("input[name='money']").val($("input[name='tmoney']").val());
}
</script>
