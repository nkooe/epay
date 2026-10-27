<?php

require_once __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/client.php';

class trc20_plugin extends trc20_chain_payment_core
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::start('trc20', $channels, $context, static function($channel){
            try{static::validateConfig(static::channelConfig($channel));}
            catch(\Throwable $e){throw new \lib\Task\ServiceFault($e->getMessage(),0,$e);}
        });
    }
    public static function monitorRun(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::run($channels, $context, \lib\Task\Automatic::POLL_SECONDS, static function($targets, &$state){
            $summary=static::monitorOnce($targets);
            if($summary['settled']>0)static::printMonitorSummary($summary);
        });
    }
    public static function monitorStop(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::stop($channels, $context);
    }

    static public $info = [
        'name' => 'trc20', 'showname' => 'TRC20 USDT', 'author' => 'Coco', 'link' => '',
        'types' => ['usdttrc20'], 'transtypes' => [], 'select' => null,
        'inputs' => [
            'appid' => ['name' => 'TRON 收款地址', 'type' => 'input', 'note' => 'T 开头的 34 位地址'],
            'appkey' => ['name' => 'USDT 汇率', 'type' => 'input', 'note' => '1 USDT 等于多少人民币，留空默认 7.2'],
            'xiaoshu' => ['name' => '金额小数位', 'type' => 'input', 'note' => '2-6，留空默认 4'],
            'botid' => ['name' => 'Telegram Chat ID', 'type' => 'input', 'note' => '可留空'],
            'bottoken' => ['name' => 'Telegram Bot Token', 'type' => 'input', 'note' => '可留空'],
        ],
        'bindwxmp' => false, 'bindwxa' => false,
        'note' => '支付有效期固定10分钟，回调匹配宽限15分钟。',
    ];

    protected static function profile()
    {
        return [
            'plugin' => 'trc20', 'showname' => 'TRC20 USDT', 'network' => 'TRC20', 'token' => 'USDT',
            'contract' => trc20_client::USDT_CONTRACT, 'address_pattern' => '/^T[1-9A-HJ-NP-Za-km-z]{33}$/', 'tx_pattern' => '/^[0-9a-fA-F]{64}$/',
            'default_rate' => 7.2, 'default_decimals' => 4, 'asset_decimals' => 6,
            'amount_field' => 'usdt_amount', 'requires_api_key' => false, 'icon' => 'usdttrc20.png',
            'address_checksum' => 'tron', 'address_case_sensitive' => true, 'contract_case_sensitive' => true,
        ];
    }

    protected static function createClient($config) { return new trc20_client(); }
}
