<?php
namespace lib\Complaint;

final class WechatClient extends \WeChatPay\V3\ComplainService
{
    public function __construct(array $config)
    {
        parent::__construct($config);
        if(!$this->platformCertificateSerial||!$this->platformPublicKeyInstance)throw new \RuntimeException('请配置有效的微信平台公钥或平台证书');
    }
    protected function curl(string $method, string $url, array $header, $body = null, int $timeout = 10): array
    {
        return Transport::request($method, $url, $header, $body);
    }

    public function queryHistoryPage($id,$offset,$limit)
    {
        return $this->execute('GET','/v3/merchant-service/complaints-v2/'.Normalizer::identifier($id).'/negotiation-historys',['offset'=>$offset,'limit'=>$limit]);
    }

    protected function checkResponseSign(string $body,string $header): bool
    {
        $fields=[];
        foreach(explode("\r\n",$header) as $line){
            if(preg_match('/^Wechatpay-(Timestamp|Nonce|Serial|Signature):\s*(.+)$/iD',$line,$match))$fields[strtolower($match[1])]=trim($match[2]);
        }
        $this->verifySignature($fields,$body);return true;
    }

    private function verifySignature(array $headers,$body)
    {
        foreach (['timestamp','nonce','serial','signature'] as $key) if (empty($headers[$key]) || !is_string($headers[$key])) throw new \RuntimeException('微信签名字段不完整');
        if (!ctype_digit($headers['timestamp']) || abs(time() - (int)$headers['timestamp']) > 300) throw new \RuntimeException('微信签名时间戳已失效');
        if (!hash_equals((string)$this->platformCertificateSerial, $headers['serial']) || !$this->checkSign($headers['timestamp'], $headers['nonce'], $body, $headers['signature'])) throw new \RuntimeException('微信验签失败');
    }

    public function notification(array $headers, $body)
    {
        $this->verifySignature($headers,$body);
        $event = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
        $resource = $event['resource'] ?? [];
        if (($resource['algorithm'] ?? '') !== 'AEAD_AES_256_GCM') throw new \RuntimeException('通知加密算法不正确');
        $plain = $this->decryptToString($resource['ciphertext'] ?? '', $resource['nonce'] ?? '', $resource['associated_data'] ?? '');
        if (!is_string($plain)) throw new \RuntimeException('通知解密失败');
        return json_decode($plain, true, 20, JSON_THROW_ON_ERROR);
    }
}
