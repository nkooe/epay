<?php
if(!defined('IN_CRONLITE'))exit();
$paytype = $DB->getAll("SELECT * FROM pre_type WHERE status=1 ORDER BY id ASC");
require_once __DIR__.'/../../../includes/pages/docs.php';
epay_docs_header('paytype');
?>
<h1 id="支付方式列表"><a href="#支付方式列表">支付方式列表</a></h1><table><thead><tr><th align="left">调用值</th><th align="left">描述</th></tr></thead><tbody>
<?php
foreach($paytype as $row){
  echo '<tr><td>'.$row['name'].'</td><td>'.$row['showname'].'</td></tr>';
}
?>
                </tbody></table>
<?php epay_docs_footer('paytype'); ?>
