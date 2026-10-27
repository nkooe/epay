<?php
namespace lib\Merchant;

use lib\AdminApp\ApiError;
use lib\AdminApp\Store;

/** Administrator-owned routing. Absence means group routing; a custom policy never falls back. */
final class ChannelPolicy
{
    private $db;
    public function __construct(Store $db) { $this->db = $db; }

    public function load($uid)
    {
        $row = $this->db->row('SELECT mode,rules,revision FROM pre_merchant_channel_policy WHERE uid=?', [(int)$uid]);
        if (!$row) return ['mode'=>'group', 'rules'=>[], 'revision'=>0];
        $rules = json_decode($row['rules'], true);
        try {
            if (!in_array($row['mode'], ['group','custom'], true) || !is_array($rules) || !array_is_list($rules) || substr(ltrim($row['rules']),0,1) !== '[' || count($rules)>100 || (int)$row['revision']<1 || ($row['mode']==='group' && $rules!==[])) throw new ApiError('invalid stored policy');
            $seen=[];
            foreach($rules as $rule) {
                $normalized=self::rule($rule);
                if ($normalized!==$rule || isset($seen[$normalized['type_id']])) throw new ApiError('invalid stored rules');
                $seen[$normalized['type_id']]=true;
            }
        } catch (ApiError $e) { throw new ApiError('商户通道策略损坏，请联系管理员重新配置', 503); }
        return ['mode'=>$row['mode'], 'rules'=>$rules, 'revision'=>(int)$row['revision']];
    }

    public function schema($uid)
    {
        $user = $this->db->row('SELECT uid,username FROM pre_user WHERE uid=?', [(int)$uid]);
        if (!$user) throw new ApiError('商户不存在', 404);
        $channels = $this->db->all('SELECT id,name,type,status,daystatus,paymin,paymax FROM pre_channel ORDER BY type,id');
        $choices = [];
        foreach ($channels as $channel) {
            $choices[] = $this->choice($channel, 0, $channel['name']);
        }
        foreach ($this->db->all('SELECT B.id subid,B.name subname,B.status substatus,A.id,A.name,A.type,A.status,A.daystatus,A.paymin,A.paymax FROM pre_subchannel B INNER JOIN pre_channel A ON A.id=B.channel WHERE B.uid=? ORDER BY B.id', [(int)$uid]) as $channel) {
            $channel['status'] = (int)$channel['status'] === 1 && (int)$channel['substatus'] === 1 ? 1 : 0;
            $choices[] = $this->choice($channel, (int)$channel['subid'], $channel['name'].' / '.$channel['subname']);
        }
        return array_merge($this->load($uid), ['uid'=>(int)$uid, 'username'=>$user['username'],
            'types'=>$this->db->all('SELECT id,name,showname,status FROM pre_type ORDER BY id'), 'channels'=>$choices]);
    }

    private function choice(array $row, $subid, $name)
    {
        return ['channel_id'=>(int)$row['id'], 'subchannel_id'=>$subid, 'type_id'=>(int)$row['type'],
            'name'=>$name, 'enabled'=>(int)$row['status'] === 1 && (int)$row['daystatus'] === 0,
            'min'=>(string)$row['paymin'], 'max'=>(string)$row['paymax']];
    }

    private static function integer($value, $name, $zero=false)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string)$value) || (!$zero && (int)$value === 0)) throw new ApiError($name.'必须为有效整数');
        return (int)$value;
    }

    private static function amount($value)
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $m)) throw new ApiError('限额须为 0 至 99999999.99 的金额，最多两位小数');
        return $m[1].'.'.str_pad($m[2] ?? '', 2, '0');
    }

    private static function cents($value) { return (int)round((float)$value * 100); }

    private static function rule($rule)
    {
        if (!is_array($rule)) throw new ApiError('支付方式配置格式不正确');
        $keys=array_keys($rule);sort($keys);
        if ($keys!==['channel_id','max','min','subchannel_id','type_id']) throw new ApiError('支付方式规则字段不正确');
        $rule=['type_id'=>self::integer($rule['type_id'],'支付方式'),
            'channel_id'=>self::integer($rule['channel_id'],'通道编号'),
            'subchannel_id'=>self::integer($rule['subchannel_id'],'子通道编号',true),
            'min'=>self::amount($rule['min']), 'max'=>self::amount($rule['max'])];
        if (self::cents($rule['max'])>0 && self::cents($rule['min'])>self::cents($rule['max'])) throw new ApiError('单笔最低金额不能大于最高金额');
        return $rule;
    }

    public function save(array $input, $ip)
    {
        $keys = array_keys($input); sort($keys);
        if ($keys !== ['mode','revision','rules','uid']) throw new ApiError('通道策略参数不完整或包含未知字段');
        $uid = self::integer($input['uid'], '商户编号');
        $revision = self::integer($input['revision'], '配置版本', true);
        if (!in_array($input['mode'], ['group','custom'], true)) throw new ApiError('请选择通道策略模式');
        if (!is_array($input['rules']) || !array_is_list($input['rules']) || count($input['rules']) > 100) throw new ApiError('支付方式配置格式不正确');
        if ($input['mode'] === 'group' && $input['rules'] !== []) throw new ApiError('跟随用户组时不能提交独立规则');
        $rules = []; $seen = [];
        foreach ($input['rules'] as $rule) {
            $rule=self::rule($rule);
            $type=$rule['type_id'];
            if (isset($seen[$type])) throw new ApiError('支付方式不能重复配置');
            $seen[$type] = true;
            $channel = $this->channel($uid, $type, $rule, 0, false);
            $lower = max(self::cents($rule['min']), self::cents($channel['paymin']));
            $upper = array_filter([self::cents($rule['max']), self::cents($channel['paymax'])], function($n){return $n > 0;});
            if ($upper && $lower > min($upper)) throw new ApiError('商户限额与通道限额没有交集');
            $rules[] = $rule;
        }
        usort($rules, function($a,$b){return $a['type_id'] <=> $b['type_id'];});
        $pdo = $this->db->pdo;
        if ($pdo->inTransaction()) throw new ApiError('通道策略不能在其他事务中修改', 409);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            if (!$this->db->row('SELECT uid FROM pre_user WHERE uid=?'.$lock, [$uid])) throw new ApiError('商户不存在', 404);
            $current = $this->load($uid);
            if ($current['revision'] !== $revision) throw new ApiError('策略已被修改，请重新打开后再保存', 409);
            $values = ['mode'=>$input['mode'], 'rules'=>json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'revision'=>$revision+1, 'updated_at'=>date('Y-m-d H:i:s')];
            if ($revision === 0) $this->db->insert('merchant_channel_policy', array_merge(['uid'=>$uid], $values));
            else $this->db->update('merchant_channel_policy', $values, 'uid', $uid);
            $this->db->audit('商户通道策略', $ip, ['uid'=>$uid, 'mode'=>$input['mode'], 'revision'=>$revision+1]);
            $pdo->commit();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['uid'=>$uid, 'revision'=>$revision+1, 'message'=>'商户通道策略已保存'];
    }

    /** Reads live channel state on every payment, including reused unpaid orders. */
    private function channel($uid, $type, array $rule, $money, $checkTime=true)
    {
        $paytype = $this->db->row('SELECT id,name,status FROM pre_type WHERE id=?', [$type]);
        if (!$paytype || (int)$paytype['status'] !== 1) throw new ApiError('该支付方式已停用或不存在');
        $channel = $this->db->row('SELECT id,type,plugin,status,daystatus,rate,apptype,mode,paymin,paymax,timestart,timestop FROM pre_channel WHERE id=?', [$rule['channel_id']]);
        if (!$channel) throw new ApiError('商户指定通道已删除，请联系管理员');
        if ((int)$channel['type'] !== (int)$type) throw new ApiError('商户指定通道与支付方式不匹配');
        if ((int)$channel['status'] !== 1 || (int)$channel['daystatus'] !== 0) throw new ApiError('商户指定通道已停用或达到当日限额');
        if ((int)$rule['subchannel_id'] > 0) {
            $sub = $this->db->row('SELECT uid,channel,status,apply_id FROM pre_subchannel WHERE id=?', [$rule['subchannel_id']]);
            if (!$sub || (int)$sub['uid'] !== (int)$uid || (int)$sub['channel'] !== (int)$channel['id'] || (int)$sub['status'] !== 1) throw new ApiError('商户指定子通道不存在、已停用或不属于该商户');
            $channel['apply_id'] = (int)$sub['apply_id'];
        }
        if ($checkTime && ($channel['timestart'] !== null && $channel['timestart'] !== '') && ($channel['timestop'] !== null && $channel['timestop'] !== '') && ((int)$channel['timestart'] > 0 || (int)$channel['timestop'] > 0)) {
            $hour = (int)date('H'); $start = (int)$channel['timestart']; $stop = (int)$channel['timestop'];
            $outside = $start < $stop ? ($hour < $start || $hour > $stop) : ($hour < $start && $hour > $stop);
            if ($outside) throw new ApiError('商户指定通道当前不在开放时段');
        }
        if ($money > 0) {
            $min = max(self::cents($rule['min']), self::cents($channel['paymin']));
            $limits = array_filter([self::cents($rule['max']), self::cents($channel['paymax'])], function($n){return $n > 0;});
            if (self::cents($money) < $min) throw new ApiError('该支付方式单笔最低金额为 '.number_format($min/100,2,'.','').' 元');
            if ($limits && self::cents($money) > min($limits)) throw new ApiError('该支付方式单笔最高金额为 '.number_format(min($limits)/100,2,'.','').' 元');
        }
        $channel['typename'] = $paytype['name'];
        return $channel;
    }

    public function select($uid, $gid, $type, $money=0, $applyId=0)
    {
        $policy = $this->load($uid);
        if ($policy['mode'] === 'group') return null;
        foreach ($policy['rules'] as $rule) {
            if ((int)$rule['type_id'] !== (int)$type) continue;
            $row = $this->channel($uid, $type, $rule, $money);
            if ($applyId > 0 && ((int)$rule['subchannel_id'] === 0 || ($row['apply_id'] ?? 0) !== (int)$applyId)) throw new ApiError('请求的子商户与指定通道不一致');
            $group = $this->db->value('SELECT info FROM pre_group WHERE gid=?', [(int)$gid]);
            if (!$group && (int)$gid !== 0) $group = $this->db->value('SELECT info FROM pre_group WHERE gid=0');
            $rates = json_decode($group ?: '{}', true);
            $rate = $rates[$type]['rate'] ?? null;
            return ['typeid'=>(int)$type, 'typename'=>$row['typename'], 'plugin'=>$row['plugin'],
                'channel'=>(int)$row['id'], 'subchannel'=>(int)$rule['subchannel_id'],
                'rate'=>empty($rate) ? $row['rate'] : $rate, 'apptype'=>$row['apptype'], 'mode'=>$row['mode'],
                'paymin'=>$row['paymin'], 'paymax'=>$row['paymax'], 'timestart'=>$row['timestart'], 'timestop'=>$row['timestop']];
        }
        throw new ApiError('该商户未启用此支付方式，请联系管理员');
    }

    public function assertRoute($uid, $type, $channel, $subchannel, $money)
    {
        $selected = $this->select($uid, 0, $type, $money);
        if ($selected !== null && ($selected['channel'] !== (int)$channel || $selected['subchannel'] !== (int)$subchannel)) throw new ApiError('订单通道与当前商户策略不一致，请重新下单');
    }

    /** null means the declared group mode; [] means independent mode with no usable methods. */
    public function types($uid, $gid, array $types)
    {
        if ($this->load($uid)['mode'] === 'group') return null;
        $available = [];
        foreach ($types as $type) {
            try { $selected = $this->select($uid, $gid, $type['id']); }
            catch (ApiError $e) { if ($e->status >= 500) throw $e; continue; }
            $type['rate'] = $selected['rate']; $available[$type['id']] = $type;
        }
        return $available;
    }
}
