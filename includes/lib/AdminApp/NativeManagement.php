<?php
namespace lib\AdminApp;

/** Transport adapter only: native and web clients share the business services. */
final class NativeManagement
{
    const READS=['v1/tasks'=>'list','v1/tasks/editor'=>'editor','v1/tasks/history'=>'history',
        'v1/tasks/logs'=>'logs','v1/tasks/plugin'=>'plugin_service_detail',
        'v1/complaints'=>'list','v1/complaints/detail'=>'detail','v1/complaints/schema'=>'schema','v1/complaints/image'=>'image'];
    const WRITES=['v1/tasks/service'=>'service','v1/tasks/save'=>'save','v1/tasks/run'=>'run',
        'v1/tasks/rebind'=>'rebind','v1/tasks/plugin'=>'plugin_service','v1/tasks/sync'=>'sync_plugins',
        'v1/tasks/log-delete'=>'service_log_delete','v1/complaints/account'=>'save-account',
        'v1/complaints/sync'=>'sync','v1/complaints/refresh'=>'refresh','v1/complaints/action'=>'action',
        'v1/complaints/resolve'=>'resolve','v1/complaints/subscribe'=>'subscribe','v1/complaints/upload'=>'upload',
        'v1/complaints/check'=>'check-connection','v1/complaints/queue'=>'queue-sync'];
    private $tasks;
    private $complaints;
    private $db;
    private $ip;
    public function __construct(Store $db,array $config,$ip,\lib\Task\Management $tasks=null,\lib\Complaint\Service $complaints=null)
    {
        $this->db=$db;$this->ip=$ip;
        $this->tasks=$tasks??new \lib\Task\Management($db,$ip);
        $this->complaints=$complaints??new \lib\Complaint\Service($db,$config);
    }
    public function handle($method,$route,array $input=[],$requestKey='')
    {
        $map=$method==='GET'?self::READS:($method==='POST'?self::WRITES:[]);
        if(!isset($map[$route]))throw new ApiError('接口不存在',404);
        try{
            if(strpos($route,'v1/tasks')===0){
                if($map[$route]==='editor'){
                    $data=$this->tasks->handle('GET','list');$id=$input['id']??'0';
                    \lib\Task\Definitions::integer($id,0,2147483647,'任务 ID');$record=null;
                    foreach($data['rows'] as $row)if((string)$row['id']===(string)$id)$record=$row;
                    if((int)$id>0&&!$record)throw new ApiError('任务不存在',404);
                    return $this->safe(['record'=>$record,'definitions'=>$data['definitions'],'plugin_jobs'=>$data['plugin_jobs'],
                        'channels'=>$this->db->all('SELECT id,name FROM pre_channel ORDER BY id')]);
                }
                if($map[$route]==='logs'){
                    $data=$this->tasks->handle('GET','service_log_days');
                    $date=$input['date']??$data['today'];
                    if($date===$data['today']&&!in_array($date,$data['days'],true))return $data+['date'=>$date,'text'=>''];
                    return array_merge($data,$this->tasks->handle('GET','service_log',['date'=>$date]));
                }
                if($map[$route]==='plugin_service')$input['operation_id']=substr($requestKey,0,32);
                $data=$this->tasks->handle($method,$map[$route],$input);
                if($map[$route]==='history'){
                    $rows=$data['rows'];$data['has_more']=count($rows)===30;$data['next_cursor']=$rows?(int)end($rows)['id']:0;
                }
                return $this->safe($data);
            }
            $service=$this->complaints;
            switch($route){
                case 'v1/complaints':
                    $data=$service->listing($input);$data['has_more']=$data['page']<$data['pages'];
                    return array_merge($data,$service->schema());
                case 'v1/complaints/detail':return array_merge($service->detail($input['id']??null),['feedback_codes'=>$service->schema()['feedback_codes']]);
                case 'v1/complaints/schema':return $service->schema();
                case 'v1/complaints/image':
                    $image=$service->image($input['id']??null,$input['media']??null);
                    if(strlen($image['bytes'])>2097152)throw new ApiError('图片超过 2 MB，请在网页查看',413);
                    return ['mime'=>$image['mime'],'base64'=>base64_encode($image['bytes'])];
                case 'v1/complaints/account':return $service->saveAccount($input,$this->ip);
                case 'v1/complaints/check':return $service->checkConnection($input);
                case 'v1/complaints/queue':return $service->requestSync($input['id']??null);
                case 'v1/complaints/sync':return $service->sync($input);
                case 'v1/complaints/refresh':return $service->refresh($input['id']??null);
                case 'v1/complaints/action':$input['request_id']=$requestKey;return $service->action($input,$this->ip);
                case 'v1/complaints/resolve':return $service->resolve($input,$this->ip);
                case 'v1/complaints/subscribe':return $service->subscribe($input['id']??null);
                case 'v1/complaints/upload':return $service->uploadBytes($input['id']??null,$input['base64']??null);
            }
        }catch(\InvalidArgumentException $e){throw new ApiError($e->getMessage());}
        catch(\lib\Complaint\Rejected $e){throw new ApiError($e->getMessage(),502);}
        throw new ApiError('接口不存在',404);
    }
    private function safe(array $data)
    {
        if(array_key_exists('lock_token',$data))$data['locked']=$data['lock_token']!==null;
        foreach($data as $key=>$value){
            if(in_array($key,['token','lock_token','lock_owner','payload_hash'],true)){unset($data[$key]);continue;}
            if(is_array($value))$data[$key]=$this->safe($value);
        }
        return $data;
    }
}
