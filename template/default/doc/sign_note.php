<?php
if(!defined('IN_CRONLITE'))exit();
require_once __DIR__.'/../../../includes/pages/docs.php';
epay_docs_header('sign_note');
?>
<h1 id="签名规则"><a href="#签名规则">签名规则</a></h1><h2 id="签名步骤"><a href="#签名步骤">签名步骤</a></h2><blockquote>对本平台接口发起的请求，需要进行签名。</blockquote><p>1、获取请求报文所有<strong>非空</strong>请求参数，不包括数组、字节类型参数，如文件、字节流，剔除<strong>sign</strong>、<strong>sign_type</strong>字段，并按照第一个字符的键值ASCII码递增排序（字母升序排序），如果遇到相同字符则按照第二个字符的键值ASCII码递增排序，以此类推。</p><p>2、将排序后的参数和对应值，组合成“<strong>参数=参数值</strong>”的格式，并且把这些参数用 <strong>&</strong> 字符连接起来，此时生成的字符串为待签名字符串。</p><p>3、使用<strong>商户私钥</strong>，对待签名字符串计算RSA签名（SHA256WithRSA），得到签名sign。</p><h2 id="验签步骤"><a href="#验签步骤">验签步骤</a></h2><blockquote>针对接口返回的数据，以及异步通知回调的数据，需进行验签。</blockquote><p>1、先根据签名步骤里面的1～2，获取到待签名字符串。</p><p>2、使用<strong>平台公钥</strong>，根据签名字符串sign，对待签名字符串与进行RSA验签（SHA256WithRSA）</p><h2 id="注意事项"><a href="#注意事项">注意事项</a></h2><p>1、商户私钥（private key）需填写到代码中供签名时使用。生成的私钥需妥善保管，避免遗失，不要泄露。</p><p>2、平台公钥（public key）用于接口返回数据、异步通知回调数据的验签。</p><p>3、具体发起支付相关流程的示例代码可下载SDK查看。</p>
<?php epay_docs_footer('sign_note'); ?>
