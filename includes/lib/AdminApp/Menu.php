<?php
namespace lib\AdminApp;

final class Menu
{
    public static function groups(array $conf)
    {
        $groups=[
            '收款订单'=>[['订单管理','orders'],['退款记录','resource:refunds'],['导出订单','export:orders'],['支付用户统计','resource:buyers'],['黑名单管理','resource:blacklist'],['分账规则','resource:receivers'],['分账记录','resource:sharing']],
            '付款管理'=>[['结算管理','resource:settlements'],['批量结算','resource:batches'],['付款记录','resource:transfers'],['新增付款','action:transfer'],['创建红包','action:redpacket'],['付款统计','stats:transfers'],['导出付款记录','export:transfers']],
            '商户管理'=>[['用户列表','merchants'],['新增商户','merchant-new'],['用户组设置','resource:groups'],['用户组购买','setting:groupbuy'],['资金明细','resource:records'],['支付统计','stats:payments']],
            '支付接口'=>[['支付通道','channels'],['商户子通道','resource:subchannels'],['支付方式','resource:types'],['支付插件','resource:plugins'],['支付通道轮询','resource:rolls'],['公众号小程序','resource:weixin']],
            '系统设置'=>[['网站信息配置','setting:site'],['支付相关配置','setting:pay'],['风控检测配置','setting:risk'],['结算规则配置','setting:settle'],['转账付款配置','setting:transfer'],['快捷登录配置','setting:oauth'],['消息提醒配置','setting:notice'],['实名认证配置','setting:certificate'],['网站公告配置','resource:announcements'],['首页模板配置','setting:template'],['邮箱与短信配置','setting:mail'],['网站Logo上传','tool:logo'],['任务与服务','tasks'],['中转代理配置','setting:proxy'],['H5跳转微信客服支付','setting:wxkf']],
            '其他功能'=>[['投诉管理','complaints'],['App 操作记录','resource:operations'],['风控记录','resource:risks'],['登录日志','resource:logs'],['数据清理','tool:cleanup'],['获取用户标识','tool:identity']]
        ];
        if(($conf['pay_domain_forbid'] ?? '0')==='1'||($conf['pay_domain_open'] ?? '0')==='1')$groups['商户管理'][]=['授权域名','resource:domains'];
        if(($conf['reg_open'] ?? '')==='2')$groups['商户管理'][]=['邀请码管理','resource:invites'];
        $root=dirname(__DIR__,3);
        if(is_file($root.'/includes/lib/Applyments/CommUtil.php')){$groups['支付接口'][]=['进件渠道管理','unavailable'];$groups['支付接口'][]=['进件商户管理','unavailable'];}
        if(is_file($root.'/includes/lib/Complain/CommUtil.php'))$groups['其他功能'][]=['支付交易投诉','unavailable'];
        if(is_file($root.'/includes/lib/WxMchRisk.php'))$groups['其他功能'][]=['渠道商户违规记录','unavailable'];
        if(is_file($root.'/includes/lib/AlipaySATF/AlipaySATF.php'))$groups['付款管理'][]=['安全发转账记录','unavailable'];
        $out=[];foreach($groups as $title=>$items){$rows=[];foreach($items as $item)$rows[]=['title'=>$item[0],'route'=>$item[1],'available'=>$item[1]!=='unavailable','note'=>$item[1]==='unavailable'?'此专用操作当前需在网页后台完成':''];$out[]=['title'=>$title,'items'=>$rows];}return $out;
    }
    public static function resources()
    {
        // These explicit projections must never be replaced by SELECT *.
        return [
            'subchannels'=>['subchannel','id,uid,channel,name,status,addtime','id DESC'],
            'refunds'=>['refundorder','refund_no,trade_no,uid,money,reducemoney,status,addtime,endtime','addtime DESC'],
            'buyers'=>['order','buyer,uid,money,status,addtime','addtime DESC'],
            'blacklist'=>['blacklist','id,type,content,remark,addtime,endtime','id DESC'],
            'receivers'=>['psreceiver','id,channel,subchannel,uid,mode,info,minmoney,status,addtime','id DESC'],
            'sharing'=>['psorder','id,rid,trade_no,api_trade_no,money,settle_no,result,status,addtime','id DESC'],
            'settlements'=>['settle','id,uid,type,account,username,money,realmoney,status,batch,transfer_no,transfer_channel,transfer_status,result,addtime,endtime','id DESC'],
            'transfers'=>['transfer','biz_no,uid,type,channel,account,username,money,costmoney,status,pay_order_no,result,desc,addtime,paytime','addtime DESC'],
            'operations'=>['app_operation','id,reference,state,created','created DESC'],
            'batches'=>['batch','batch,allmoney,count,time,status','batch DESC'],
            'groups'=>['group','gid,name,price,expire,sort','gid DESC'],
            'records'=>['record','id,uid,type,money,oldmoney,newmoney,date','id DESC'],
            'types'=>['type','id,name,showname,device,status','id DESC'],
            'plugins'=>['plugin','name,showname,author,types','name'],
            'rolls'=>['roll','id,type,name,kind,status','id DESC'],
            'weixin'=>['weixin','id,name,type,status,addtime','id DESC'],
            'announcements'=>['anounce','id,content,sort,status,addtime','id DESC'],
            'risks'=>['risk','id,uid,type,content,status,date','id DESC'],
            'logs'=>['log','id,uid,type,ip,date','id DESC'],
            'domains'=>['domain','id,uid,domain,status,addtime,endtime','id DESC'],
            'invites'=>['invitecode','id,code,uid,status,addtime','id DESC']
        ];
    }
    public static function settings()
    {
        return [
            'site'=>['sitename'=>'平台名称','title'=>'网站标题','description'=>'网站介绍','orgname'=>'公司名称','kfqq'=>'客服 QQ'],
            'pay'=>['pre_maxmoney'=>'订单最大金额','user_refund'=>'商户自助退款（0/1）','onecode'=>'一码支付（0/1）'],
            'risk'=>['blockname'=>'拦截商品关键词','blockalert'=>'商品拦截提示'],
            'settle'=>['settle_open'=>'结算开关（0/1）','settle_money'=>'最低结算金额','settle_rate'=>'结算手续费百分比','settle_fee_min'=>'最低手续费','settle_fee_max'=>'最高手续费'],
            'transfer'=>['transfer_name'=>'付款方名称','transfer_desc'=>'付款说明'],
            'oauth'=>['login_qq'=>'QQ 登录（0/1）','login_alipay'=>'支付宝登录（0/1）','login_wx'=>'微信登录（0/1）'],
            'notice'=>['app_admin_email'=>'管理员联系邮箱'],
            'template'=>['template'=>'首页模板目录']
        ];
    }
}
