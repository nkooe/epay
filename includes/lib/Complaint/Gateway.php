<?php
namespace lib\Complaint;

/** Explicit upstream operations. A refund approval never invokes an order refund. */
final class Gateway
{
    private $provider;
    private $client;
    public function __construct($provider, $client) { $this->provider=$provider; $this->client=$client; }
    public static function open(array $account, array $credentials)
    {
        if ($account['provider'] === 'wechat') {
            $channel = \lib\Channel::get($account['channel']);
            if (!$channel || !in_array($channel['plugin'], ['wxpayn','wxpaynp'], true)) throw new \RuntimeException('请配置微信 V3 支付通道');
            if (!preg_match('/^[0-9]{6,20}$/D', (string)($channel['appmchid'] ?? ''))) throw new \RuntimeException('微信商户号配置不正确');
            $config = require PLUGIN_ROOT.$channel['plugin'].'/inc/config.php';
            return new self('wechat', new WechatClient($config));
        }
        if ($account['provider'] !== 'alipay') throw new \RuntimeException('不支持的投诉接口');
        return new self('alipay', new AlipayClient($credentials));
    }
    private function call($method, array $args = [])
    {
        try { return $this->client->$method(...$args); }
        catch (\WeChatPay\V3\WeChatPayException $e) {
            if((int)$e->getHttpCode()>=500||(int)$e->getHttpCode()===429)throw new TransientFailure('微信接口服务异常，结果需要核实',0,$e);
            throw new Rejected('微信接口拒绝：'.$e->getMessage(),$e->getErrCode());
        }
        catch (\Alipay\Aop\AlipayResponseException $e) {
            if((string)$e->getRetCode()==='20000')throw new TransientFailure('支付宝接口服务异常，结果需要核实',0,$e);
            throw new Rejected('支付宝接口拒绝：'.$e->getMessage(),$e->getErrCode());
        }
    }
    public function listing($from, $to, $page)
    {
        if ($this->provider === 'wechat') {
            $data=$this->call('batchQuery', [$from,$to,$page,10]);
            if (!isset($data['data']) || !is_array($data['data']) || !isset($data['total_count'])) throw new \RuntimeException('微信投诉列表响应不完整');
            $ids=array_column($data['data'],'complaint_id'); $total=(int)$data['total_count'];
        } else {
            $data=$this->call('batchQuery', [null,$from.' 00:00:00',$to.' 23:59:59',$page,10]);
            if (!isset($data['total_num'])) throw new \RuntimeException('支付宝投诉列表响应不完整');
            $rows=$data['trade_complain_infos'] ?? [];
            if (!is_array($rows)) throw new \RuntimeException('支付宝投诉列表格式不正确');
            $ids=array_column($rows,'complain_event_id'); $total=(int)$data['total_num'];
        }
        if($total<0||($total>($page-1)*10&&!$ids))throw new \RuntimeException('投诉列表缺少记录，未标记为同步成功');
        return ['ids'=>$ids, 'next_page'=>$page*10 < $total ? $page+1 : null, 'total'=>$total];
    }
    public function detail($id)
    {
        $info=$this->call('query',[$id]);
        if ($this->provider === 'wechat') {
            $info['_history']=[];$offset=0;
            do{
                $history=$this->call('queryHistoryPage',[$id,$offset,50]);
                if(!isset($history['data'],$history['total_count'])||!is_array($history['data']))throw new \RuntimeException('微信协商记录格式不正确');
                $total=(int)$history['total_count'];
                if($total<0||$total>500)throw new \RuntimeException('微信协商记录超过 500 条，请在微信商户平台查看');
                $received=count($history['data']);
                if($received>50||($received===0&&$offset<$total))throw new \RuntimeException('微信协商记录分页不完整');
                $info['_history']=array_merge($info['_history'],$history['data']);$offset+=$received;
            }while($offset<$total);
        }
        return $info;
    }
    public function action($action, array $row, array $input, array $images)
    {
        $id=$row['thirdid']; $content=$input['content'] ?? '';
        if ($this->provider === 'wechat') {
            if ($action==='reply') return $this->call('response',[$id,$row['thirdmchid'],$content,$images]);
            if ($action==='complete') return $this->call('complete',[$id,$row['thirdmchid']]);
            if ($action==='refund-progress') {
                $params=['action'=>$input['decision']];
                if ($input['decision']==='APPROVE') $params['launch_refund_day']=0;
                else $params += ['reject_reason'=>$content,'reject_media_list'=>$images];
                return $this->call('updateRefundProgress',[$id,$params]);
            }
        } else {
            $files=$images ? implode(',',$images) : null;
            if ($action==='reply') return $this->call('replySubmit',[$id,$content,$files]);
            if ($action==='supplement') return $this->call('supplementSubmit',[$id,$content,$files]);
            if ($action==='feedback') return $this->call('feedbackSubmit',[$id,$input['code'],$content,$files]);
        }
        throw new \InvalidArgumentException('当前接口不支持该操作');
    }
    public function upload($path,$name) { return $this->call($this->provider==='wechat'?'uploadImage':'imageUpload',[$path,$name]); }
    public function image($id) { return $this->call('getImage',[$id]); }
    public function notification(array $headers,$body) { return $this->client->notification($headers,$body); }
    public function subscribe($url)
    {
        try { $this->call('queryNotifications'); }
        catch (Rejected $e) {
            if ($e->upstreamCode!=='RESOURCE_NOT_EXISTS') throw $e;
            return $this->call('createNotifications',[$url]);
        }
        return $this->call('updateNotifications',[$url]);
    }
}
