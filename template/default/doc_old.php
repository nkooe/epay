<?php
if(!defined('IN_CRONLITE'))exit();
require_once __DIR__.'/../../includes/pages/docs.php';
epay_docs_header('legacy');
?>
<h1>V1 旧版接口文档</h1>
<blockquote>V1 接口使用 MD5 签名。接入新版接口请查阅 <a href="/doc/index.html">V2 接口说明</a>。</blockquote>
<?php include __DIR__.'/doc.inc.php'; ?>
<?php epay_docs_footer('legacy'); ?>
