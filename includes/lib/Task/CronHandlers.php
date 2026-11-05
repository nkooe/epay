<?php
namespace lib\Task;


final class CronHandlers
{
    public static function execute($action, array $params)
    {
        $params = Definitions::params($action, $params);
        $method = $action;
        return self::$method($params);
    }

    private static function cleanup_logs(array $params)
    {
        global $DB;
        $store=\lib\AdminApp\Runtime::legacyStore($DB);
        $result=(new LogRetention($store,$params['success_days'],$params['failure_days'],$GLOBALS['epay_task_context']??null))->execute();
        return $result.' 已清理应用过期记录 '.$store->cleanup().' 条。';
    }

    private static function settle(array $params)
    {
        global $DB, $conf, $date;
        $settle_time=getSetting('settle_time', true);
        if(strtotime($settle_time)>=strtotime(date("Y-m-d").' 00:00:00'))return ('自动生成结算列表今日已完成');
        $store=\lib\AdminApp\Runtime::legacyStore($DB);
        $settlementDay=date('Ymd');
        $rs=$DB->query("SELECT * from pre_user where money>={$conf['settle_money']} and settle=1 and status=1 and account is not null and username is not null");
        $i=0;
        $allmoney=0;
        while($row = $rs->fetch())
        {
            $store->pdo->beginTransaction();
            try {
                $row=$store->row('SELECT * FROM pre_user WHERE uid=?'.$store->lockClause(),[$row['uid']]);
                if(!$row||!(int)$row['settle']||!(int)$row['status']||$row['account']===null||$row['username']===null)continue;
                $reference='settle-'.$settlementDay.'-'.$row['uid'];
                // 同一天已提交的商户不会因其他商户失败而再次结算。
                if($store->value("SELECT id FROM pre_record WHERE uid=? AND type='自动结算' AND trade_no=? LIMIT 1",[$row['uid'],$reference]))continue;
                if($conf['cert_force']==1 && $row['cert']==0){
                    continue;
                }
                $settle_rate = $conf['settle_rate'];
                $group = getGroupConfig($row['gid']);
                if(isset($group['settle_open']) && $group['settle_open'] > 0){
                    if($group['settle_open'] == 2) continue;
                }elseif($conf['settle_open']!=1 && $conf['settle_open']!=3) continue;
                if(isset($group['settle_rate'])) $settle_rate = $group['settle_rate'];
                if($row['remain_money'] > 0){
                    if(strpos($row['remain_money'], '%') !== false){
                        $remain_rate = floatval(str_replace('%', '', $row['remain_money']));
                        if($remain_rate > 0 && $remain_rate < 100){
                            $row['money'] = round($row['money'] * (1 - $remain_rate / 100), 2);
                        }else{
                            $row['money'] = round($row['money'] - $row['remain_money'], 2);
                        }
                    }else{
                        $row['money'] = round($row['money'] - $row['remain_money'], 2);
                    }
                }
                if($row['money']<$conf['settle_money']) continue;
                if($settle_rate>0){
                    $fee=round($row['money']*$settle_rate/100,2);
                    if(!empty($conf['settle_fee_min']) && $fee<$conf['settle_fee_min'])$fee=$conf['settle_fee_min'];
                    if(!empty($conf['settle_fee_max']) && $fee>$conf['settle_fee_max'])$fee=$conf['settle_fee_max'];
                    $realmoney=$row['money']-$fee;
                }else{
                    $realmoney=$row['money'];
                }
                $data = ['uid'=>$row['uid'], 'type'=>$row['settle_id'], 'account'=>$row['account'], 'username'=>$row['username'], 'money'=>$row['money'], 'realmoney'=>$realmoney, 'addtime'=>date('Y-m-d H:i:s'), 'status'=>0];
                if($store->insert('settle', $data)){
                    if(changeUserMoney($row['uid'], $row['money'], false, '自动结算', $reference) !== 1) {
                        throw new \RuntimeException('结算余额扣减失败，商户'.$row['uid'].'本轮结算已回滚');
                    }
                    $store->pdo->commit();
                    $i++;
                    $allmoney+=$realmoney;
                }else{
                    throw new \RuntimeException('生成结算记录失败，商户'.$row['uid']);
                }
            }finally{
                if($store->pdo->inTransaction())$store->pdo->rollBack();
            }
        }
        $store->setting('settle_time', $date);
        return ('自动生成结算列表成功 allmony='.$allmoney.' num='.$i);
    }

    private static function order(array $params)
    {
        global $DB, $conf, $CACHE, $date;
        $order_time=getSetting('order_time', true);
        if(strtotime($order_time)>=strtotime(date("Y-m-d").' 00:00:00')){
            echo '订单统计与清理任务今日已完成';
            if($conf['wxnotice_tpl_balance'] || $conf['msgconfig_balance']){
                $rs=$DB->query("SELECT * from pre_user where status=1");
                $i=0;
                while($row = $rs->fetch())
                {
                    $row['msgconfig'] = unserialize($row['msgconfig']);
                    if($row['msgconfig']['balance'] > 0 && $row['msgconfig']['balance_money'] > 0 && $row['money'] < $row['msgconfig']['balance_money']){
                        $day = $CACHE->read('balance_notice_'.$row['uid']);
                        if($day && $day == date('Ymd')) continue;
                        \lib\MsgNotice::send('balance', $row['uid'], ['user'=>$row['uid'], 'time'=>date('Y-m-d H:i:s'), 'money'=>$row['money']]);
                        $CACHE->save('balance_notice_'.$row['uid'], date('Ymd'), 86400);
                        $i++;
                    }
                }
                if($i > 0) echo '，余额不足提醒已发送给'.$i.'位商户';
            }
            return;
        }

        $store=\lib\AdminApp\Runtime::legacyStore($DB);
        $notices=[];
        $store->pdo->beginTransaction();
        try {
            $completed=$store->value("SELECT v FROM pre_config WHERE k='order_time'".$store->lockClause());
            if(strtotime($completed)>=strtotime(date('Y-m-d').' 00:00:00')){
                $store->pdo->commit();return '订单统计与清理任务今日已完成';
            }
            $order_paytype=[];$profit_paytype=[];$order_channel=[];
            $thtime=date("Y-m-d H:i:s",time()-3600*48);

            $CACHE->clean();
            $DB->exec("delete from pre_order where status=0 and addtime<'{$thtime}'");
            $DB->exec("delete from pre_regcode where `time`<'".(time()-3600*24)."'");
            $DB->exec("delete from pre_blacklist where endtime is not null and endtime<NOW()");
            $DB->exec("delete from pre_wxkflog where addtime<'".date("Y-m-d H:i:s", strtotime('-48 hours'))."'");

            $day = date("Ymd", strtotime("-1 day"));

            $paytype = [];
            $rs = $DB->getAll("SELECT id,name,showname FROM pre_type WHERE status=1");
            foreach($rs as $row){
                $paytype[$row['id']] = $row['showname'];
            }
            unset($rs);

            $channel = [];
            $rs = $DB->getAll("SELECT id,name FROM pre_channel WHERE status=1");
            foreach($rs as $row){
                $channel[$row['id']] = $row['name'];
            }
            unset($rs);

            $lastday=date("Y-m-d",strtotime("-1 day"));
            $today=date("Y-m-d");

            $rs=$DB->query("SELECT type,channel,realmoney,profitmoney from pre_order where (status=1 OR status=3) and date>='$lastday' and date<'$today'");
            foreach($paytype as $id=>$type){
                $order_paytype[$id]=0;
                $profit_paytype[$id]=0;
            }
            foreach($channel as $id=>$type){
                $order_channel[$id]=0;
            }
            while($row = $rs->fetch())
            {
                $order_paytype[$row['type']]+=$row['realmoney'];
                $order_channel[$row['channel']]+=$row['realmoney'];
                if(!empty($row['profitmoney'])){
                    $profit_paytype[$row['type']]+=$row['profitmoney'];
                }
            }
            foreach($order_paytype as $k=>$v){
                $order_paytype[$k] = round($v,2);
            }
            foreach($order_channel as $k=>$v){
                $order_channel[$k] = round($v,2);
            }
            foreach($profit_paytype as $k=>$v){
                $profit_paytype[$k] = round($v,2);
            }
            $allmoney=0;
            foreach($order_paytype as $money){
                $allmoney+=$money;
            }
            $allprofit=0;
            foreach($profit_paytype as $money){
                $allprofit+=$money;
            }

            $order_lastday['all']=round($allmoney,2);
            $order_lastday['profit_all']=round($allprofit,2);
            $order_lastday['paytype']=$order_paytype;
            $order_lastday['channel']=$order_channel;
            $order_lastday['profit_paytype']=$profit_paytype;

            $CACHE->save('order_'.$day, serialize($order_lastday), 604830);

            $DB->exec("update pre_channel set daystatus=0");

            if($conf['invite_mode'] == 1){
                $moneylist = $DB->getAll("SELECT uid,SUM(realmoney) money FROM pre_order WHERE (status=1 OR status=3) AND `date`='$lastday' GROUP BY uid");
                foreach($moneylist as $row){
                    $upid = $DB->findColumn('user', 'upid', ['uid'=>$row['uid']]);
                    if($upid > 0){
                        $upgid = $DB->findColumn('user', 'gid', ['uid'=>$upid]);
                        $groupconfig = getGroupConfig($upgid);
                        $conf_n = array_merge($conf, $groupconfig);
                        if($conf_n['invite_open'] == 1 && !empty($conf_n['invite_rate'])){
                            $invite_money = round($row['money'] * $conf_n['invite_rate'] / 100, 2);
                            if($invite_money > 0){
                                if(changeUserMoney($upid, $invite_money, true, '邀请返现', $row['uid'])!==1)throw new \RuntimeException('邀请返现入账失败，商户'.$upid);
                            }
                        }
                    }
                }
            }

            $expire_users = $DB->getAll("SELECT uid,gid,status,endtime FROM pre_user WHERE gid>0 AND endtime>0 AND endtime<NOW()");
            foreach($expire_users as $row){
                $group = $DB->getRow("SELECT * FROM pre_group WHERE gid='{$row['gid']}'");
                $gid = 0;
                $DB->exec("UPDATE pre_user SET gid={$gid},endtime=NULL WHERE uid='{$row['uid']}'");
                if($row['status'] == 1){
                    $notices[]=['uid'=>$row['uid'],'group'=>$group['name'],'endtime'=>$row['endtime']];
                }
            }
            $store->setting('order_time', $date);
            $store->pdo->commit();
        }catch(\Throwable $e){
            if($store->pdo->inTransaction())$store->pdo->rollBack();
            throw $e;
        }
        foreach($notices as $notice){
            try{\lib\MsgNotice::send('group',$notice['uid'],$notice);}
            catch(\Throwable $e){error_log('会员到期提醒发送失败，商户'.$notice['uid'].'：'.Controller::redact($e->getMessage()));}
        }
        return ($day.'订单统计与清理任务执行成功');
    }

    private static function notify(array $params)
    {
        global $DB, $conf;
        $limit = 20; //每次重试的订单数量
        $delivery = \lib\Notification\Delivery::legacy();
        for($i=0;$i<$limit;$i++){
            $srow=$DB->getRow("SELECT * FROM pre_order WHERE (TO_DAYS(NOW()) - TO_DAYS(endtime) <= 1) AND notify>0 AND notifytime<NOW() LIMIT 1");
            if(!$srow)break;

            try { $result = $delivery->send($srow['trade_no'], 'cron', 'retry'); }
            catch (\lib\AdminApp\ApiError $e) { if ($e->status === 409) break; throw $e; }
            if (!empty($result['skipped'])) continue;
            $notify = (int)$srow['notify'] + 1;
            if($result['delivered']){
                echo $srow['trade_no'].' 重新通知成功<br/>';
            }else{
                echo $srow['trade_no'].' 重新通知失败（第'.$notify.'次）<br/>';
                if($conf['auto_check_notify'] == 1){
                    $count = intval($conf['check_notify_count']);
                    if($count > 0){
                        $userrow = $DB->find('user', 'uid,email,pay', ['uid'=>$srow['uid']]);
                        if($userrow['pay'] == 1){
                            $orders = $DB->getAll("SELECT trade_no,notify FROM pre_order WHERE uid='{$srow['uid']}' and status>0 order by trade_no desc limit {$count}");
                            $failcount = 0;
                            foreach($orders as $order){
                                if($order['notify'] > 0) $failcount++;
                            }
                            if($failcount >= $count){
                                $DB->exec("UPDATE pre_user SET pay=0 WHERE uid='{$srow['uid']}'");
                                echo 'UID:'.$srow['uid'].' 连续'.$failcount.'个订单通知失败，已关闭支付权限<br/>';
                                $DB->exec("INSERT INTO `pre_risk` (`uid`, `type`, `content`, `date`) VALUES (:uid, 2, :content, NOW())", [':uid'=>$srow['uid'],':content'=>'连续'.$failcount.'个订单']);
                                if($conf['check_notify_notice'] == 1){
                                    send_mail($userrow['email'],$conf['sitename'].' - 商户支付权限关闭提醒','尊敬的用户：你的商户ID '.$userrow['uid'].' 因连续'.$failcount.'个订单回调通知失败，已被系统自动关闭支付权限！请自行检查你的网站是否有防CC防火墙、WAF等，导致支付回调拦截。如有疑问请联系网站客服。<br/>----------<br/>'.$conf['sitename'].'<br/>'.date('Y-m-d H:i:s'));
                                }
                            }
                        }
                    }
                }
            }
        }
        echo 'ok!';
    }

    private static function notify2(array $params)
    {
        global $DB;
        $limit = 20; //每次重试的订单数量
        $delivery = \lib\Notification\Delivery::legacy();
        $rows=$DB->getAll("SELECT o.* FROM pre_order o WHERE (TO_DAYS(NOW()) - TO_DAYS(o.endtime) <= 1) AND o.notify=-1
            ORDER BY COALESCE((SELECT a.id FROM pre_order_notify_attempt a WHERE a.trade_no=o.trade_no ORDER BY a.id DESC LIMIT 1),0),o.trade_no LIMIT {$limit}");
        foreach($rows as $srow){

            try { $result = $delivery->send($srow['trade_no'], 'cron_exhausted', 'exhausted'); }
            catch (\lib\AdminApp\ApiError $e) { if ($e->status === 409) continue; throw $e; }
            if (!empty($result['skipped'])) continue;
            if($result['delivered']){
                echo $srow['trade_no'].' 重新通知成功<br/>';
            }else{
                echo $srow['trade_no'].' 重新通知失败<br/>';
            }
        }
        echo 'ok!';
    }

    private static function profitsharing(array $params)
    {
        \lib\ProfitSharing\CommUtil::task();

        \lib\Payment::settle_task();
        echo 'ok!';
    }

    private static function check(array $params)
    {
        \lib\RiskCheck::execute();
    }

    private static function complain(array $params)
    {
        global $DB, $conf;
        $service = new \lib\Complaint\Service(\lib\AdminApp\Runtime::legacyStore($DB), $conf);
        return $service->syncChannel($params['channel'], $params['source']);
    }

    private static function plugin(array $params)
    {
        global $DB;
        $registry = new PluginRegistry(\lib\AdminApp\Runtime::legacyStore($DB));
        return $registry->execute($params['channel'], $params['job'], $GLOBALS['epay_task_context']['plugin'] ?? null);
    }

    private static function transfer(array $params)
    {
        global $DB, $conf;
        if(!$conf['auto_settle_money']) throw new \RuntimeException('未开启自动结算转账功能');
        if(!$conf['transfer_alipay']) throw new \RuntimeException('未设置支付宝转账接口通道');

        $store=\lib\AdminApp\Runtime::legacyStore($DB);$base=$store->config();$success=0;
        $list=$store->all('SELECT uid FROM pre_user WHERE status=1 AND settle=1 AND settle_id=1 AND money>? ORDER BY uid DESC LIMIT 5',[(string)$conf['auto_settle_money']]);
        foreach($list as $row){
            try{$r=(new \lib\Merchant\Settlement($store,$base))->create($row['uid'],null,true);}
            catch(\DomainException $e){echo '商户'.(int)$row['uid'].'跳过：'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'<br/>';continue;}
            // Failed or unknown submissions remain visible and reserved for reconciliation.
            (new \lib\AdminApp\Operations($store,'task'))->settlementAction('settle-pay',['id'=>$r['id'],'channel'=>(int)$base['transfer_alipay']]);
            $success++;echo '商户'.(int)$row['uid'].'已提交结算，结算单号'.(int)$r['id'].'<br/>';
        }
        echo '已提交结算'.$success.'个商户<br/>';
    }

}

