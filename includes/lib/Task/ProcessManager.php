<?php
namespace lib\Task;

final class ProcessManager
{
    public const WATCHDOG_SECONDS=30;
    private $c;private $children=[];private $finished=[];private $socket;private $lock;private $stopping=false;private $stopAt;private $workerState='starting';private $progressAt;private $exit=0;private $lastPersist=0;private $lastResources=0;private $memory=0;private $tasks=0;
    public function __construct(array $config){$this->c=$config;}
    private function log($text){(new ServiceLog($this->c))->append('worker',$text);}
    private function start($kind,$id='',$attempts=[])
    {
        $key=$kind.':'.$id;
        if(isset($this->children[$key]))return;
        if($this->stopping)throw new \RuntimeException('主进程正在停止，拒绝启动新任务');
        $counts=['worker'=>0,'job'=>0,'plugin'=>0];foreach($this->children as $child)$counts[$child['kind']]++;
        $limit=$kind==='worker'?1:($kind==='job'?$this->c['concurrency']:$this->c['service_limit']);
        if($counts[$kind]>=$limit)throw new \RuntimeException('任务进程并发已达上限');
        $fds=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w'],4=>['pipe','r']];$pipes=[];
        $process=proc_open([$this->c['php'],$this->c['root'].'/bin/task-process.php','--child',$kind,$id],$fds,$pipes,$this->c['root'],null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new \RuntimeException('创建受管任务进程失败');
        $stat=proc_get_status($process);foreach([1,2] as $fd)stream_set_blocking($pipes[$fd],false);
        $this->children[$key]=['kind'=>$kind,'id'=>$id,'process'=>$process,'pipes'=>$pipes,'pid'=>$stat['pid'],'identity'=>ProcessRuntime::identity($stat['pid']),
            'inner'=>0,'inner_identity'=>null,'buffer'=>'','attempts'=>$attempts,'stopping'=>false];
        unset($this->finished[$key]);$this->persist();
    }
    private function persist()
    {
        $records=[];foreach($this->children as $key=>$child)$records[$key]=array_intersect_key($child,array_flip(['pid','identity','inner','inner_identity']));
        ProcessRuntime::write($this->c['runtime'].'/children.json',$records);$this->lastPersist=hrtime(true);
    }
    private function stop($key)
    {
        if(!isset($this->children[$key]))return;
        $child=&$this->children[$key];$child['stopping']=true;
        if(ProcessRuntime::alive($child))posix_kill($child['pid'],SIGTERM);
    }
    private function drain($error=false)
    {
        if($error)$this->exit=1;
        if($this->stopping)return;
        $this->stopping=true;$this->stopAt=hrtime(true)+930000000000;
        foreach($this->children as $key=>$child)if($child['kind']!=='job')$this->stop($key);
    }
    private function reap()
    {
        foreach(array_keys($this->children) as $key){
            $child=&$this->children[$key];
            $chunk=stream_get_contents($child['pipes'][1],16384);if($chunk!==false)$child['buffer'].=$chunk;
            if(strlen($child['buffer'])>32768)throw new \RuntimeException('子进程控制消息超出限制');
            while(($end=strpos($child['buffer'],"\n"))!==false){
                $event=json_decode(substr($child['buffer'],0,$end),true,8,JSON_THROW_ON_ERROR);$child['buffer']=substr($child['buffer'],$end+1);
                if(isset($event['pid'])){$child['inner']=(int)$event['pid'];$child['inner_identity']=ProcessRuntime::identity($child['inner']);$this->persist();}
                if(isset($event['progress'])&&$child['kind']==='worker'){$this->workerState=$event['progress'];$this->progressAt=hrtime(true);}
            }
            $error=stream_get_contents($child['pipes'][2],16384);if($error!==false&&$error!=='')$this->log($error);
            $state=proc_get_status($child['process']);
            if($state['running']){unset($child);continue;}
            $exit=$state['signaled']?128+$state['termsig']:$state['exitcode'];
            $inner=['pid'=>$child['inner'],'identity'=>$child['inner_identity']];
            if(ProcessRuntime::alive($inner))posix_kill(-$inner['pid'],SIGKILL);
            foreach($child['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);$closed=proc_close($child['process']);if($exit<0)$exit=$closed;
            $kind=$child['kind'];$id=$child['id'];$stopped=$child['stopping'];$attempts=$child['attempts'];unset($child,$this->children[$key]);
            $this->finished[$key]=['active_state'=>$exit===0?'inactive':'failed','sub_state'=>'dead','result'=>$exit===0?'success':'exit-code','exit_status'=>$exit,'pid'=>0,'restarts'=>count($attempts),'at'=>time()];
            if($kind==='worker')$this->drain(!$this->stopping||$exit!==0);
            $this->persist();
        }
        foreach($this->finished as $key=>$item){
            if(isset($item['retry_at'])&&!$this->stopping&&$item['retry_at']<=time())$this->start($item['kind'],$item['id'],$item['attempts']);
            elseif(!isset($item['retry_at'])&&$item['at']<time()-3600)unset($this->finished[$key]);
        }
    }
    private function status()
    {
        return ['available'=>true,'installed'=>true,'mode'=>'process','managed_externally'=>true,'service'=>$this->c['service'],'active_state'=>$this->stopping?'deactivating':($this->workerState==='starting'?'activating':'active'),
            'sub_state'=>'running','pid'=>getmypid(),'watchdog_seconds'=>self::WATCHDOG_SECONDS,'accepting'=>!$this->stopping,
            'retained_results'=>count($this->finished),'budget'=>['MemoryCurrent'=>(string)$this->memory,'MemoryMax'=>(string)($this->c['memory_max_mb']*1048576),'TasksCurrent'=>(string)$this->tasks,'TasksMax'=>'192'],'message'=>'由进程管理器负责启动、停止、重启和开机自启'];
    }
    private function resources()
    {
        if(hrtime(true)-$this->lastResources<2000000000)return;
        $this->lastResources=hrtime(true);$groups=[];$sizes=[];$counts=[];
        foreach($this->children as $child)if($child['inner']>0)$groups[$child['inner']]=$child['kind'];
        $total=0;$tasks=0;
        foreach(glob('/proc/[0-9]*/stat') as $path){
            $stat=@file_get_contents($path);if($stat===false)continue;$end=strrpos($stat,')');if($end===false)continue;
            $fields=explode(' ',trim(substr($stat,$end+1)));$group=(int)($fields[2]??0);
            if(!isset($groups[$group]))continue;
            $status=@file_get_contents(dirname($path).'/status');$rss=0;
            if($status!==false&&preg_match('/^VmRSS:\s+(\d+)/m',$status,$m))$rss=(int)$m[1]*1024;
            $total+=$rss;$tasks++;$sizes[$group]=($sizes[$group]??0)+$rss;$counts[$group]=($counts[$group]??0)+1;
        }
        $this->memory=$total;$this->tasks=$tasks;
        foreach($sizes as $group=>$bytes){
            $limit=($groups[$group]==='job'?256:192)*1048576;
            if($bytes>$limit||$counts[$group]>32){$this->log('任务进程组超出资源限制，已终止');posix_kill(-$group,SIGKILL);}
        }
        if($total>$this->c['memory_max_mb']*1048576||$tasks>192){$this->log('本站后台进程超出总资源预算，停止接管');$this->drain(true);}
    }
    private function handle(array $message)
    {
        if(($message['action']??'')==='status')return $this->status();
        if(($message['action']??'')==='drain'){$this->drain();return ['accepted'=>true];}
        if(!preg_match('/^(job|plugin)-(start|stop|status|ack)$/D',$message['action']??'',$match))throw new \InvalidArgumentException('不支持的进程控制操作');
        $kind=$match[1];$action=$match[2];$id=$message['id']??null;
        if($kind==='job')Execution::id($id);
        elseif(!is_string($id)||!preg_match('/^[a-f0-9]{32}g[1-9][0-9]{0,8}$/D',$id))throw new \InvalidArgumentException('插件实例编号无效');
        $key=$kind.':'.$id;
        if($action==='ack'){
            if(isset($this->children[$key])||isset($this->finished[$key]['retry_at']))throw new \RuntimeException('实例尚未结束，不能回收结果');
            unset($this->finished[$key]);return ['accepted'=>true];
        }
        if($action==='start'){$this->start($kind,$id);return ['accepted'=>true];}
        if($action==='stop'){
            $this->stop($key);
            if(isset($this->finished[$key]['retry_at'])){$this->finished[$key]['active_state']='inactive';$this->finished[$key]['sub_state']='dead';unset($this->finished[$key]['retry_at']);}
            return ['accepted'=>true];
        }
        if(isset($this->children[$key]))return ['active_state'=>$this->children[$key]['stopping']?'deactivating':'active','sub_state'=>'running','result'=>'success','exit_status'=>0,'pid'=>$this->children[$key]['inner'],'restarts'=>count($this->children[$key]['attempts'])];
        return $this->finished[$key]??['active_state'=>'inactive','sub_state'=>'dead','result'=>'unknown','exit_status'=>-1,'pid'=>0,'restarts'=>0];
    }
    private function connections()
    {
        for($i=0;$i<8;$i++){
            $client=@stream_socket_accept($this->socket,0);if(!$client)break;
            try{
                stream_set_timeout($client,0,100000);$line=fgets($client,4097);
                if($line===false||strlen($line)>4096||substr($line,-1)!=="\n")throw new \InvalidArgumentException('进程控制请求不完整');
                $response=$this->handle(json_decode($line,true,8,JSON_THROW_ON_ERROR));
            }catch(\Throwable $e){$response=['error'=>Controller::redact($e->getMessage())];}
            fwrite($client,json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");fclose($client);
        }
    }
    private function previous()
    {
        $file=$this->c['runtime'].'/children.json';if(!is_file($file))return;
        if(is_link($file))throw new \RuntimeException('旧进程记录不能为符号链接');
        $records=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);$end=hrtime(true)+10000000000;
        do{
            $live=false;foreach($records as $record){
                if(ProcessRuntime::alive($record)){$live=true;posix_kill($record['pid'],SIGTERM);}
                elseif(($record['inner']??0)>0&&ProcessRuntime::alive(['pid'=>$record['inner'],'identity'=>$record['inner_identity']??null])){posix_kill(-$record['inner'],SIGKILL);$live=true;}
            }
            if(!$live)return;usleep(100000);
        }while(hrtime(true)<$end);
        throw new \RuntimeException('上一代任务进程尚未退出，停止重复接管');
    }
    public function run()
    {
        $dir=$this->c['runtime'];$file=$dir.'/manager.lock';if(is_link($file))throw new \RuntimeException('进程锁不能为符号链接');
        $this->lock=fopen($file,'c');if(!$this->lock||!flock($this->lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('本站已有进程管理器主进程运行');
        chmod($file,0600);$this->previous();
        ProcessRuntime::write($dir.'/config.json',$this->c);
        $path=$dir.'/control.sock';if(file_exists($path)||is_link($path))unlink($path);
        $this->socket=stream_socket_server('unix://'.$path,$code,$message,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
        if(!$this->socket)throw new \RuntimeException('无法建立进程控制套接字');
        chmod($path,0600);stream_set_blocking($this->socket,false);
        pcntl_async_signals(true);pcntl_signal(SIGTERM,function(){$this->drain();});pcntl_signal(SIGINT,function(){$this->drain();});
        $this->progressAt=hrtime(true);
        try{
            $this->start('worker');$this->log('进程管理器已接管主进程');
            do{
                $this->reap();$this->connections();$this->resources();
                if(!$this->stopping&&hrtime(true)-$this->progressAt>self::WATCHDOG_SECONDS*1000000000){$this->log('调度进度超时，停止并交由进程管理器恢复');$this->drain(true);}
                if($this->stopping&&hrtime(true)>=$this->stopAt){$this->exit=1;break;}
                usleep(50000);
            }while(!$this->stopping||$this->children);
        }finally{
            foreach($this->children as $child)if(is_resource($child['pipes'][4]))fclose($child['pipes'][4]);
            $end=hrtime(true)+7000000000;
            while($this->children&&hrtime(true)<$end){$this->reap();usleep(50000);}
            fclose($this->socket);unlink($path);flock($this->lock,LOCK_UN);fclose($this->lock);
        }
        return $this->exit;
    }
}
