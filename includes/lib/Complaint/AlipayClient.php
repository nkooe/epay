<?php
namespace lib\Complaint;

final class AlipayClient extends \Alipay\AlipayComplainService
{
    public function __construct(array $config)
    {
        parent::__construct($config);
        $client = new ComplaintAopClient();
        foreach (get_object_vars($this->client) as $key=>$value) $client->$key = $value;
        $this->client = $client;
    }
    public function imageUpload(string $path, string $name): string
    {
        $result = $this->aopExecute('alipay.merchant.image.upload', null, ['image_type'=>pathinfo($name, PATHINFO_EXTENSION), 'image_content'=>new \CURLFile($path, '', $name)]);
        return $result['image_id'];
    }
}

final class ComplaintAopClient extends \Alipay\Aop\AopClient
{
    protected function verifyResponse(\Alipay\Aop\AlipayResponse $response)
    {
        $data=$response->getSignData();$sign=$response->getSign();
        if(!is_string($data)||$data===''||!is_string($sign)||$sign===''||!$this->rsaPubilcVerify($data,$sign))throw new \RuntimeException('支付宝投诉响应验签失败');
    }
    protected function rsaPrivateSign(string $data, string $signType = 'RSA2'): string
    {
        if($signType!=='RSA2'||!openssl_sign($data,$signature,$this->rsaPrivateKey,OPENSSL_ALGO_SHA256))throw new \RuntimeException('支付宝请求签名失败');
        return base64_encode($signature);
    }
    public function rsaPubilcVerify(string $data, string $sign, string $signType = 'RSA2'): bool
    {
        $bytes=base64_decode($sign,true);
        return $signType==='RSA2'&&$bytes!==false&&openssl_verify($data,$bytes,$this->rsaPublicKey,OPENSSL_ALGO_SHA256)===1;
    }
    protected function curl(string $url, array $postFields = null)
    {
        $multipart = false;
        foreach ($postFields ?? [] as $value) if ($value instanceof \CURLFile) $multipart = true;
        [$status, $headers, $body] = Transport::request('POST', $url, $multipart ? [] : ['Content-Type: application/x-www-form-urlencoded'], $multipart ? $postFields : http_build_query($postFields ?? []));
        if ($status !== 200) throw new \RuntimeException('支付宝投诉接口 HTTP '.$status);
        return $body;
    }
}
