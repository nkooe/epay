<?php
@header('Content-Type: text/html; charset=UTF-8');
if($userrow['status']==0){
	sysmsg('你的商户由于违反相关法律法规与《<a href="/?mod=agreement">'.$conf['sitename'].'用户协议</a>》，已被禁用！');
}
switch($conf['user_style']){
	case 1: $style=['bg-black','bg-black','bg-white']; break;
	case 2: $style=['bg-dark','bg-white','bg-dark']; break;
	case 3: $style=['bg-dark','bg-dark','bg-light']; break;
	case 4: $style=['bg-info','bg-info','bg-black']; break;
	case 5: $style=['bg-info','bg-info','bg-white']; break;
	case 6: $style=['bg-primary','bg-primary','bg-dark']; break;
	case 7: $style=['bg-primary','bg-primary','bg-white']; break;
	default: $style=['bg-black','bg-white','bg-black']; break;
}
$groupconfig = getGroupConfig($userrow['gid']);
$conf = array_merge($conf, $groupconfig);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <title><?php echo $title?> | <?php echo $conf['sitename']?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>twitter-bootstrap/3.4.1/css/bootstrap.min.css" type="text/css" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>animate.css/3.7.2/animate.min.css" type="text/css" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>font-awesome/4.7.0/css/font-awesome.min.css" type="text/css" />
  <link rel="stylesheet" href="./assets/css/font.css" type="text/css" />
  <link rel="stylesheet" href="./assets/css/app.css" type="text/css" />
  <link rel="stylesheet" href="../assets/css/bootstrap-table.css?v=1"/>
  <link rel="stylesheet" href="./assets/css/merchant-wechat.css?v=2" />
</head>
<body class="merchant-portal">
<div class="app app-header-fixed  ">
  <a class="merchant-skip-link" href="#content">跳到主要内容</a>
  <header id="header" class="merchant-topbar" role="banner">
    <button type="button" id="merchantMenuToggle" class="merchant-menu-toggle" aria-label="收起导航菜单" aria-controls="aside" aria-expanded="true"><i class="fa fa-bars" aria-hidden="true"></i></button>
    <a href="./" class="merchant-brand">
      <span class="merchant-brand-mark"><i class="fa fa-credit-card" aria-hidden="true"></i></span>
      <span class="merchant-brand-name"><?php echo htmlspecialchars($conf['sitename'], ENT_QUOTES, 'UTF-8')?></span>
    </a>
    <span class="merchant-portal-label">商户中心</span>
    <div class="merchant-profile dropdown">
      <a href="#" id="merchantProfileToggle" data-toggle="dropdown" class="merchant-profile-toggle dropdown-toggle" aria-haspopup="true" aria-expanded="false" aria-label="商户账户菜单">
        <span class="merchant-profile-icon"><i class="fa fa-user" aria-hidden="true"></i></span>
        <span><?php echo intval($uid)?></span><span class="caret" aria-hidden="true"></span>
      </a>
      <ul class="dropdown-menu" aria-labelledby="merchantProfileToggle">
        <li><a href="index.php">商户总览</a></li>
        <li><a href="editinfo.php">修改资料</a></li>
        <li><a href="userinfo.php?mod=account">修改密码</a></li>
        <li class="divider"></li>
        <li><a href="login.php?logout">退出登录</a></li>
      </ul>
    </div>
  </header>
  <!-- aside -->
  <aside id="aside" class="app-aside" aria-label="商户中心导航">
      <div class="aside-wrap">
        <div class="navi-wrap">

          <!-- nav -->
          <nav class="navi clearfix" aria-label="功能菜单">
            <ul class="nav">
              <li class="hidden-folded padder m-t m-b-sm text-muted text-xs">
                <span>导航</span>
              </li>
              <li class="<?php echo checkIfActive('index,')?>">
                <a href="./">
                  <i class="glyphicon glyphicon-home icon text-primary-dker"></i>
                  <span class="font-bold">商户总览</span>
                </a>
              </li>
              <li class="<?php echo checkIfActive('userinfo,editinfo,certificate,deposit')?>">
                <a href class="auto">      
                  <span class="pull-right text-muted">
                    <i class="fa fa-fw fa-angle-right text"></i>
                    <i class="fa fa-fw fa-angle-down text-active"></i>
                  </span>
                  <i class="glyphicon glyphicon-leaf icon text-success-lter"></i>
                  <span>个人资料</span>
                </a>
                <ul class="nav nav-sub dk">
				  <li>
                    <a href="userinfo.php?mod=api">
                      <span>API信息</span>
                    </a>
                  </li>
                  <li>
                    <a href="editinfo.php">
                      <span>修改资料</span>
                    </a>
                  </li>
				  <li>
                    <a href="userinfo.php?mod=account">
                      <span>修改密码</span>
                    </a>
                  </li>
				  <?php if($conf['cert_open']>0){?>
				  <li>
                    <a href="certificate.php">
                      <span>实名认证</span>
                    </a>
                  </li>
				  <?php }?>
          <?php if($conf['user_deposit']>0){?>
				  <li>
                    <a href="deposit.php">
                      <span>保证金</span>
                    </a>
                  </li>
				  <?php }?>
                </ul>
              </li>
              <li class="line dk"></li>
              <li class="hidden-folded padder m-t m-b-sm text-muted text-xs">
                <span>查询</span>
              </li>
			  <li class="<?php echo checkIfActive('order')?>">
                <a href="order.php">
                  <i class="glyphicon glyphicon-list-alt"></i>
                  <span>订单记录</span>
                </a>
              </li>
			  <li class="<?php echo checkIfActive('settle')?>">
                <a href="settle.php">
                  <i class="glyphicon glyphicon-check"></i>
                  <span>结算记录</span>
                </a>
              </li>
			  <li class="<?php echo checkIfActive('record')?>">
                <a href="record.php">
                  <i class="glyphicon glyphicon-calendar"></i>
                  <span>资金明细</span>
                </a>
              </li>
			  <?php if($conf['settle_open']==2||$conf['settle_open']==3){?>
			  <li class="<?php echo checkIfActive('apply')?>">
                <a href="apply.php">
                  <i class="glyphicon glyphicon-edit"></i>
                  <span>申请提现</span>
                </a>
              </li>
			  <?php }?>
			  <?php if($conf['recharge']==1){?>
			  <li class="<?php echo checkIfActive('recharge')?>">
                <a href="recharge.php">
                  <i class="glyphicon glyphicon-yen"></i>
                  <span>余额充值</span>
                </a>
              </li>
			  <?php }?>
			  <?php if($conf['group_buy']==1){?>
			  <li class="<?php echo checkIfActive('groupbuy')?>">
                <a href="groupbuy.php">
                  <i class="glyphicon glyphicon-shopping-cart"></i>
                  <span>购买会员</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['pay_domain_open']==1){?>
			  <li class="<?php echo checkIfActive('domain')?>">
                <a href="domain.php">
                  <i class="glyphicon glyphicon-globe"></i>
                  <span>授权域名</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['complain_open']==1){?>
              <li class="<?php echo checkIfActive('complain,complain_info')?>">
                <a href="complain.php">
                  <?php $complain_total = $DB->getColumn("SELECT count(*) from pre_complain WHERE uid=$uid AND status=0"); if($complain_total>0){echo '<b class="label bg-danger pull-right">'.$complain_total.'</b>';}?>
                  <i class="fa fa-commenting fa-fw"></i>
                  <span>交易投诉</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['mchrisk_open']==1){?>
              <li class="<?php echo checkIfActive('mchrisk')?>">
                <a href="mchrisk.php">
                  <i class="fa fa-asterisk fa-fw"></i>
                  <span>商户违规记录</span>
                </a>
              </li>
			  <?php }?>
              <li class="line dk hidden-folded"></li>

              <li class="hidden-folded padder m-t m-b-sm text-muted text-xs">          
                <span>其他</span>
              </li>
			  <?php if($conf['user_transfer']==1){?>
              <li class="<?php echo checkIfActive('transfer,transfer_add')?>">
                <a href="transfer.php">
                  <i class="fa fa-send-o fa-fw"></i>
                  <span>代付管理</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['onecode']==1 || $userrow['open_code'] == 1){?>
              <li class="<?php echo checkIfActive('onecode')?>">
                <a href="onecode.php">
                  <i class="fa fa-qrcode fa-fw"></i>
                  <span>聚合收款</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['invite_open']==1){?>
              <li class="<?php echo checkIfActive('invite')?>">
                <a href="invite.php">
                  <i class="fa fa-share-alt fa-fw"></i>
                  <span>邀请返现</span>
                </a>
              </li>
			  <?php }?>
              <li>
                <a href="/doc.html" target="_blank">
                  <i class="fa fa-book"></i>
                  <span>开发文档</span>
                </a>
              </li>
			  <?php if(!empty($conf['qqqun'])){?>
              <li>
                <a href="<?php echo $conf['qqqun']?>" target="blank">
                  <i class="fa fa-qq"></i>
                  <span>产品QQ群</span>
                </a>
              </li>
			  <?php }?>
			  <?php if(!empty($conf['appurl'])){?>
              <li>
                <a href="<?php echo $conf['appurl']?>" target="blank">
                  <i class="fa fa-android"></i>
                  <span>APP下载</span>
                </a>
              </li>
			  <?php }?>
            </ul>
          </nav>
          <!-- nav -->
        </div>
      </div>
  </aside>
  <!-- / aside -->
  <button type="button" id="merchantNavBackdrop" class="merchant-nav-backdrop" aria-label="关闭导航菜单" tabindex="-1"></button>
  <!-- content -->
