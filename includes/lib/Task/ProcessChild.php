<?php
namespace lib\Task;

final class ProcessChild
{
    public static function run(array $c,$kind,$id)
    {
        if(!in_array($kind,['worker','job','plugin'],true))throw new \InvalidArgumentException('任务进程类型无效');
        if($kind==='job')Execution::id($id);
        if($kind==='plugin'&&!preg_match('/^[a-f0-9]{32}g[1-9][0-9]{0,8}$/D',$id))throw new \InvalidArgumentException('插件实例编号无效');
        $life=fopen('php://fd/4','rb');if(!$life)throw new \RuntimeException('进程生命管道缺失');
        stream_set_blocking($life,false);stream_set_blocking(STDOUT,false);
        $logs=new ServiceLog($c);$source=$kind.($kind==='worker'?'':':'.$id);
        $pipes=[];$fds=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']];
        if($kind==='worker')$fds[3]=['pipe','w'];
        $process=proc_open([$c['php'],$c['root'].'/bin/task-process.php','--exec',$kind,$id],$fds,$pipes,$c['root'],null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new \RuntimeException('无法创建任务子进程');
        $status=proc_get_status($process);$pid=$status['pid'];
        foreach($pipes as $pipe)stream_set_blocking($pipe,false);
        fwrite(STDOUT,json_encode(['pid'=>$pid])."\n");
        $stop=false;$termSent=false;$stopAt=null;$exit=null;$last=hrtime(true);$started=$last;$buffers=[1=>'',2=>'',3=>''];$discard=[1=>false,2=>false];
        pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$stop){$stop=true;});pcntl_signal(SIGINT,static function()use(&$stop){$stop=true;});
        try{
            $logs->append($source,'进程启动');
            while(true){
                $status=proc_get_status($process);
                if(!$status['running']){$exit=$status['signaled']?128+$status['termsig']:$status['exitcode'];break;}
                $read=array_values($pipes);if(is_resource($life))$read[]=$life;$write=null;$except=null;
                $ready=$read?@stream_select($read,$write,$except,0,100000):false;
                if(!$read)usleep(100000);
                if(is_resource($life)&&$ready!==false&&in_array($life,$read,true)){
                    fread($life,1);
                    if(feof($life)){
                        $stop=true;
                        $stopAt=min($stopAt??PHP_INT_MAX,hrtime(true)+5000000000);
                        fclose($life);$life=null;
                    }
                }
                if($stop&&$stopAt===null)$stopAt=hrtime(true)+($kind==='worker'?930:($kind==='plugin'?30:5))*1000000000;
                if($stop&&!$termSent){$termSent=@posix_kill(-$pid,SIGTERM);}
                if($kind==='worker'&&hrtime(true)-$last>=Watchdog::SECONDS*1000000000)throw new \RuntimeException('调度主循环进度超时（30秒）');
                if($kind==='job'&&hrtime(true)-$started>910000000000)throw new \RuntimeException('任务进程运行超时');
                if($stopAt!==null&&hrtime(true)>=$stopAt){@posix_kill(-$pid,SIGKILL);proc_terminate($process,9);}
                foreach($pipes as $fd=>$pipe){
                    if($ready!==false&&in_array($pipe,$read,true)){
                        $chunk=fread($pipe,8192);if($chunk===false)throw new \RuntimeException('读取任务日志失败');
                        if($fd!==3&&$discard[$fd]){
                            $end=strpos($chunk,"\n");
                            if($end===false)$chunk='';else{$chunk=substr($chunk,$end+1);$discard[$fd]=false;}
                        }
                        $buffers[$fd].=$chunk;
                        if($fd===3){
                            if(strlen($buffers[3])>4096)throw new \RuntimeException('调度进度协议超出限制');
                            while(($end=strpos($buffers[3],"\n"))!==false){
                                $state=substr($buffers[3],0,$end);$buffers[3]=substr($buffers[3],$end+1);
                                if(!in_array($state,['running','draining','stopped'],true))throw new \RuntimeException('调度进度状态无效');
                                $last=hrtime(true);fwrite(STDOUT,json_encode(['progress'=>$state])."\n");
                            }
                        }else{
                            if(($end=strrpos($buffers[$fd],"\n"))!==false){$logs->append($source,substr($buffers[$fd],0,$end+1));$buffers[$fd]=substr($buffers[$fd],$end+1);}
                            if(strlen($buffers[$fd])>16384){$logs->append($source,substr($buffers[$fd],0,16384).' [日志行过长，剩余内容已截断]');$buffers[$fd]='';$discard[$fd]=true;}
                        }
                    }
                    if(feof($pipe)){if($fd!==3&&$buffers[$fd]!==''){$logs->append($source,$buffers[$fd]);$buffers[$fd]='';}fclose($pipe);unset($pipes[$fd]);}
                }
            }
        }catch(\Throwable $e){
            $logs->append($source,$e->getMessage());$exit=1;
        }finally{
            @posix_kill(-$pid,SIGKILL);
            $state=proc_get_status($process);if($state['running'])proc_terminate($process,9);
            foreach($pipes as $fd=>$pipe){
                if($fd!==3){$tail=$buffers[$fd].stream_get_contents($pipe,16384);if($tail!=='')$logs->append($source,$tail);}
                fclose($pipe);
            }
            $closed=proc_close($process);if(is_resource($life))fclose($life);
        }
        $exit=$exit!==null&&$exit>=0?$exit:$closed;
        if($exit<0)$exit=1;
        $logs->append($source,'进程结束，退出码 '.$exit);
        return $exit;
    }
}
