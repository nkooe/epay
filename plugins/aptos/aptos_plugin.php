<?php

require_once __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/client.php';

class aptos_plugin extends aptos_chain_payment_core
{
    /** Lifecycle owned by the platform process manager. */
    public static function monitorStart(array $channels, \lib\Task\ServiceContext $context)
    {
        \lib\Task\PaymentMonitor::start('aptos', $channels, $context, static function($channel){
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
        'name' => 'aptos', 'showname' => 'Aptos USDT', 'author' => 'Coco', 'link' => '',
        'types' => ['usdtaptos'], 'transtypes' => [], 'select' => null,
        'inputs' => [
            'appid' => ['name' => 'Aptos 收款地址', 'type' => 'input', 'note' => '0x 开头的 64 位十六进制地址'],
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
            'plugin' => 'aptos', 'showname' => 'Aptos USDT', 'network' => 'Aptos', 'token' => 'USDT',
            'contract' => aptos_client::ASSET_TYPE, 'address_pattern' => '/^0x[0-9a-fA-F]{64}$/', 'tx_pattern' => '/^0x[0-9a-fA-F]{64}$/',
            'default_rate' => 7.2, 'default_decimals' => 4, 'asset_decimals' => 6,
            'amount_field' => 'usdt_amount', 'requires_api_key' => false, 'icon' => 'usdtaptos.png',
        ];
    }

    protected static function createClient($config) { return new aptos_client(); }
}
