<?php
namespace lib;

use Exception;

class Transfer
{

    static public $payee_err_code = [ //收款方原因导致的失败编码
		'PAYEE_NOT_EXIST','PAYEE_ACCOUNT_STATUS_ERROR','CARD_BIN_ERROR','PAYEE_CARD_INFO_ERROR','PERM_AML_NOT_REALNAME_REV','PAYEE_USER_INFO_ERROR','PAYEE_ACC_OCUPIED','PERMIT_NON_BANK_LIMIT_PAYEE','PAYEE_TRUSTEESHIP_ACC_OVER_LIMIT','PAYEE_ACCOUNT_NOT_EXSIT','PAYEE_USERINFO_STATUS_ERROR','TRUSTEESHIP_RECIEVE_QUOTA_LIMIT','EXCEED_LIMIT_UNRN_DM_AMOUNT','INVALID_CARDNO','RELEASE_USER_FORBBIDEN_RECIEVE','PAYEE_USER_TYPE_ERROR','PAYEE_NOT_RELNAME_CERTIFY','PERMIT_LIMIT_PAYEE',

		'OPENID_ERROR','NAME_MISMATCH','V2_ACCOUNT_SIMPLE_BAN','MONEY_LIMIT','EXCEED_PAYEE_ACCOUNT_LIMIT','PAYEE_ACCOUNT_ABNORMAL','APPID_OR_OPENID_ERR',

		'REALNAME_CHECK_ERROR','RE_USER_NAME_CHECK_ERROR','ERR_TJ_BLACK','USER_FROZEN','TRANSFER_FAIL','TRANSFER_FEE_LIMIT_ERROR',

		'ACCOUNT_FROZEN','REAL_NAME_CHECK_FAIL','NAME_NOT_CORRECT','OPENID_INVALID','TRANSFER_QUOTA_EXCEED','DAY_RECEIVED_QUOTA_EXCEED','MONTH_RECEIVED_QUOTA_EXCEED','DAY_RECEIVED_COUNT_EXCEED','ID_CARD_NOT_CORRECT','ACCOUNT_NOT_EXIST','TRANSFER_RISK','REALNAME_ACCOUNT_RECEIVED_QUOTA_EXCEED','RECEIVE_ACCOUNT_NOT_PERMMIT','PAYEE_ACCOUNT_ABNORMAL','BLOCK_B2C_USERLIMITAMOUNT_BSRULE_MONTH','BLOCK_B2C_USERLIMITAMOUNT_MONTH',
	];

    //通用转账
    //type alipay:支付宝,wxpay:微信,qqpay:QQ钱包,bank:银行卡
    public static function submit($type, $channel, $out_biz_no, $payee_account, $payee_real_name, $money, $title = null, $desc = null){
        global $conf;

        $bizParam = [
            'type' => $type,
            'out_biz_no' => $out_biz_no,
            'payee_account' => $payee_account,
            'payee_real_name' => $payee_real_name,
            'money' => $money,
            'transfer_name' => $title?$title:$conf['transfer_name'],
            'transfer_desc' => $desc?$desc:$conf['transfer_desc'],
        ];
        return \lib\Plugin::call('transfer', $channel, $bizParam);
    }

    public static function add($uid,$type,$out_biz_no,$payee_account,$payee_real_name,$money,$title=null,$desc=null,$bookid=null,$channelid=null,$api=false){
        global $DB,$conf;
        try{
            // Optional SATF is a separate upstream account-book module, with its own ledger.
            if($uid>0&&class_exists('\\lib\\AlipaySATF\\AlipaySATF')&&!empty($conf['alipay_satf'])&&($type==='alipay'||($type==='bank'&&$conf['transfer_alipay']==$conf['transfer_bank']))){
                $store=\lib\AdminApp\Runtime::legacyStore($DB);$u=$store->row('SELECT * FROM pre_user WHERE uid=?',[$uid]);
                if(!$u)throw new \DomainException('商户不存在');$c=\lib\Merchant\FinancialPolicy::config($store,$store->config(),$u);\lib\Merchant\FinancialPolicy::check($c,$u,'transfer',$api);\lib\Finance\Ledger::amount($money);
                if(!$bookid)$bookid=$DB->findColumn('satf_account_book','id',['uid'=>$uid,'status'=>1],'money DESC');
                return (new \lib\AlipaySATF\AlipaySATF())->transfer($bookid,$type==='bank'?2:1,['out_biz_no'=>$out_biz_no,'account'=>$payee_account,'name'=>$payee_real_name,'money'=>$money,'remark'=>$desc],$uid);
            }
            return (new \lib\AdminApp\Operations(\lib\AdminApp\Runtime::legacyStore($DB),real_ip()))->merchantTransfer((int)$uid,$type,$out_biz_no,$payee_account,$payee_real_name,$money,$title,$desc,$channelid,false,$api);
        }catch(\lib\AdminApp\ApiError|\DomainException $e){return ['code'=>-1,'msg'=>$e->getMessage()];}
    }


    public static function status($biz_no,$uid=null){
        global $DB;
        try{
            $store=\lib\AdminApp\Runtime::legacyStore($DB);
            $r=(new \lib\AdminApp\Operations($store,real_ip()))->transferLifecycle('transfer-query',$biz_no,$uid);
            $order=$store->row('SELECT * FROM pre_transfer WHERE biz_no=?',[$biz_no]);
            return ['code'=>0,'msg'=>$r['message'],'status'=>(int)$order['status'],'biz_no'=>$biz_no,'orderid'=>$order['pay_order_no'],'paydate'=>$order['paytime'],'errmsg'=>$order['result']];
        }catch(\lib\AdminApp\ApiError $e){return ['code'=>-1,'msg'=>$e->getMessage()];}
    }


    public static function query($type, $channel, $biz_no, $pay_order_no){
        $bizParam = [
            'type' => $type,
            'out_biz_no' => $biz_no,
            'orderid' => $pay_order_no
        ];
        return \lib\Plugin::call('transfer_query', $channel, $bizParam);
    }

    //撤销转账
    public static function cancel($biz_no){
        global $DB;
        try{$r=(new \lib\AdminApp\Operations(\lib\AdminApp\Runtime::legacyStore($DB),real_ip()))->transferLifecycle('transfer-cancel',$biz_no);return ['code'=>0,'msg'=>$r['message']];}
        catch(\lib\AdminApp\ApiError $e){return ['code'=>-1,'msg'=>$e->getMessage()];}
    }


    public static function balance($type, $channel, $user_id = null){
        $bizParam = [
            'type' => $type,
            'user_id' => $user_id
        ];
        return \lib\Plugin::call('balance_query', $channel, $bizParam);
    }

    //转账凭证查询
    public static function proof($biz_no, $uid = null){
        global $DB;
        $where = ['biz_no' => $biz_no];
        if($uid !== null) $where['uid'] = (int)$uid;
        $order = $DB->find('transfer', '*', $where);
        if(!$order) return ['code'=>-1, 'msg'=>'付款记录不存在'];
        
        $channelinfo = null;
        if($order['uid'] > 0){
            $channelinfo = $DB->findColumn('user', 'channelinfo', ['uid'=>$order['uid']]);
        }
        $channel = \lib\Channel::get($order['channel'], $channelinfo);
        if(!$channel) return ['code'=>-1, 'msg'=>'支付通道不存在'];

        $bizParam = [
            'type' => $order['type'],
            'out_biz_no' => $biz_no,
            'orderid' => $order['pay_order_no']
        ];
        return \lib\Plugin::call('transfer_proof', $channel, $bizParam);
    }

    //转账回调处理
    public static function processNotify($biz_no,$status,$errmsg=null){
        global $DB;
        $store=\lib\AdminApp\Runtime::legacyStore($DB);$service=new \lib\AdminApp\Operations($store,'callback');
        if($store->row('SELECT biz_no FROM pre_transfer WHERE biz_no=?',[$biz_no]))$service->transferCallback($biz_no,$status,$errmsg);
        else $service->settlementCallback($biz_no,$status,$errmsg);
    }


    public static function red_add($uid,$type,$out_biz_no,$money,$desc=null,$channelid=null){
        global $DB;
        try{return (new \lib\AdminApp\Operations(\lib\AdminApp\Runtime::legacyStore($DB),real_ip()))->merchantTransfer((int)$uid,$type,$out_biz_no,'','',$money,null,$desc,$channelid,true);}
        catch(\lib\AdminApp\ApiError|\DomainException $e){return ['code'=>-1,'msg'=>$e->getMessage()];}
    }


    public static function red_receive($biz_no,$openid){
        global $DB;
        try{return (new \lib\AdminApp\Operations(\lib\AdminApp\Runtime::legacyStore($DB),real_ip()))->receiveRedpacket($biz_no,$openid);}
        catch(\lib\AdminApp\ApiError $e){return ['code'=>-1,'msg'=>$e->getMessage()];}
    }


    public static function red_url($biz_no){
        global $siteurl;
        $t = time().'';
        $s = md5(SYS_KEY.$biz_no.$t.SYS_KEY);
        return $siteurl.'paypage/red.php?n='.$biz_no.'&t='.$t.'&s='.$s;
    }
}
