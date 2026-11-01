<?php
if(!defined('IN_CRONLITE'))exit();
require_once __DIR__.'/../../../includes/pages/docs.php';
epay_docs_header('index');
?>
<h1 id="接口说明及规范"><a href="#接口说明及规范">接口说明及规范</a></h1><h2 id="协议规则"><a href="#协议规则">协议规则</a></h2><p>提交数据格式：<code>application/x-www-form-urlencoded</code></p><p>返回数据格式：<code>JSON</code></p><p>字符编码：<code>UTF-8</code></p><p>签名算法：<code>SHA256WithRSA</code></p><h2 id="V2升级说明"><a href="#V2升级说明">V2升级说明</a></h2><p>1、V2接口全面使用 RSA 签名算法；V1接口使用 MD5 签名算法</p><p>2、V2接口改用全新的接口地址，支持退款、代付等功能；V1接口使用submit.php和mapi.php提交订单</p><p>3、V2接口新增timestamp入参和返回值用于校验时间戳</p><h2 id="获取RSA密钥对"><a href="#获取RSA密钥对">获取RSA密钥对</a></h2><p>在 商户后台-&gt;个人资料-&gt;API信息 页面，点击【生成商户RSA密钥对】，生成后注意保存【商户私钥】。对接接口时只需要用到【平台公钥】与【商户私钥】。</p><h2 id="旧版接口文档"><a href="#旧版接口文档">旧版接口文档</a></h2><p><a href="/doc_old.html">查看V1旧版接口文档</a></p>
<?php epay_docs_footer('index'); ?>
