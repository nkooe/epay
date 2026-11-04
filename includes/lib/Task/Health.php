<?php
namespace lib\Task;

use lib\AdminApp\Store;

final class Health
{
    public static function request(Store $db,$token)
    {
        $probe=['id'=>bin2hex(random_bytes(16)),'worker'=>$token,'state'=>'pending','created'=>time()];
        $db->query("INSERT INTO pre_config(k,v) VALUES ('task_process_probe',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[json_encode($probe,JSON_THROW_ON_ERROR)]);
        return $probe;
    }
    public static function probe(Store $db)
    {
        $raw=$db->value("SELECT v FROM pre_config WHERE k='task_process_probe'");
        return $raw===false?null:json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    }
    public static function update(Store $db,array $old,array $changes)
    {
        return $db->query("UPDATE pre_config SET v=? WHERE k='task_process_probe' AND v=?",[json_encode(array_merge($old,$changes),JSON_THROW_ON_ERROR),json_encode($old,JSON_THROW_ON_ERROR)])->rowCount()===1;
    }
    public static function execute(Store $db,$id)
    {
        $probe=self::probe($db);
        if(!$probe||($probe['id']??null)!==$id)return false;
        $beat=$db->row('SELECT token,state FROM pre_task_worker WHERE id=1');
        if($probe['state']!=='starting'||time()-$probe['created']>60||!$beat||$beat['state']!=='running'||!hash_equals($beat['token'],$probe['worker']))throw new \RuntimeException('验收探针已失效，请重新检查');
        if($db->row('SELECT id FROM pre_task_execution WHERE id=?',[$id]))throw new \RuntimeException('验收编号冲突，已停止执行');
        if(!self::update($db,$probe,['state'=>'executed','pid'=>getmypid(),'executed'=>time()]))throw new \RuntimeException('验收探针已更新，停止旧实例');
        return true;
    }
    public static function tick(Store $db,Controller $ctl,$token,$free)
    {
        $p=self::probe($db);
        if(!$p||($p['worker']??'')!==$token)return false;
        if(!in_array($p['state'],['pending','starting','executed'],true))return false;
        if(time()-$p['created']>60){$ctl->instance('job','stop',$p['id']);self::update($db,$p,['state'=>'failed']);return false;}
        if($p['state']==='pending'){
            if(!$free)return false;
            if(self::update($db,$p,['state'=>'starting']))$ctl->instance('job','start',$p['id']);
            return true;
        }
        $unit=$ctl->instance('job','status',$p['id']);
        if($p['state']==='executed'&&$unit['active_state']==='inactive'&&$unit['result']==='success'&&$unit['exit_status']===0){self::update($db,$p,['state'=>'passed','finished'=>time()]);$ctl->instance('job','ack',$p['id']);return false;}
        if($unit['active_state']==='failed'){self::update($db,$p,['state'=>'failed']);$ctl->instance('job','ack',$p['id']);return false;}
        return true;
    }
    public static function status(Store $db)
    {
        $beat=$db->row('SELECT token,state,heartbeat_at FROM pre_task_worker WHERE id=1');
        $p=self::probe($db);$faults=[];
        if(!$beat||$beat['state']!=='running'||strtotime($beat['heartbeat_at'])<time()-10)$faults[]=['code'=>'heartbeat','message'=>'调度心跳未就绪','action'=>'检查进程管理器是否启动，以及数据库连接和服务日志。'];
        if(!$beat||!$p||($p['worker']??'')!==$beat['token']||$p['state']!=='passed')$faults[]=['code'=>'child','message'=>'真实子任务尚未验收通过','action'=>'等待当前任务排空；若超过一分钟，检查命令行 PHP、子进程权限和服务日志后重新验收。'];
        if(!$beat||$db->value("SELECT v FROM pre_config WHERE k='task_channel_scan'")!==$beat['token'])$faults[]=['code'=>'catalog','message'=>'通道自动接管尚未完成首轮检查','action'=>'等待首轮通道检查完成；通道较多时请稍后重新验收。'];
        return ['healthy'=>!$faults,'message'=>$faults?$faults[0]['message']:'进程运行正常，调度心跳与子任务验收通过','faults'=>$faults,'checked_at'=>date('Y-m-d H:i:s')];
    }
    public static function verify(Store $db,$seconds=60)
    {
        $beat=$db->row('SELECT token,state,heartbeat_at FROM pre_task_worker WHERE id=1');$p=self::probe($db);
        if($beat&&$beat['state']==='running'&&strtotime($beat['heartbeat_at'])>=time()-10&&(!$p||($p['worker']??'')!==$beat['token']||!in_array($p['state'],['pending','starting','executed'],true)||time()-$p['created']>60))self::request($db,$beat['token']);
        $deadline=hrtime(true)+$seconds*1000000000;
        do{$result=self::status($db);if($result['healthy'])return $result;usleep(200000);}while(hrtime(true)<$deadline);
        throw new \RuntimeException(implode('；',array_map(static function($f){return $f['message'].'：'.$f['action'];},$result['faults'])));
    }
}
