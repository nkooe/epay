DROP TABLE IF EXISTS `pre_config`;
create table `pre_config` (
`k` varchar(32) NOT NULL,
`v` text NULL,
PRIMARY KEY  (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `pre_config` VALUES ('version', '2063');
INSERT INTO `pre_config` VALUES ('admin_user', 'admin');
INSERT INTO `pre_config` VALUES ('admin_pwd', '123456');
INSERT INTO `pre_config` VALUES ('admin_paypwd', '123456');
INSERT INTO `pre_config` VALUES ('homepage', '0');
INSERT INTO `pre_config` VALUES ('sitename', 'DCAI聚合');
INSERT INTO `pre_config` VALUES ('title', 'DCAI聚合支付 - 行业领先的免签约支付平台');
INSERT INTO `pre_config` VALUES ('keywords', 'DCAI聚合支付,支付宝免签约即时到账,财付通免签约,微信免签约支付,QQ钱包免签约,免签约支付');
INSERT INTO `pre_config` VALUES ('description', 'DCAI聚合支付是XX公司旗下的免签约支付产品，完美解决支付难题，一站式接入支付宝，微信，财付通，QQ钱包,微信wap，帮助开发者快速集成到自己相应产品，效率高，见效快，费率低！');
INSERT INTO `pre_config` VALUES ('orgname', 'DCAI公司');
INSERT INTO `pre_config` VALUES ('kfqq', '123456789');
INSERT INTO `pre_config` VALUES ('template', 'index_xarr');
INSERT INTO `pre_config` VALUES ('pre_maxmoney', '1000');
INSERT INTO `pre_config` VALUES ('blockname', '');
INSERT INTO `pre_config` VALUES ('blockalert', '温馨提醒该商品禁止出售，如有疑问请联系网站客服！');
INSERT INTO `pre_config` VALUES ('settle_open', '1');
INSERT INTO `pre_config` VALUES ('settle_type', '1');
INSERT INTO `pre_config` VALUES ('settle_money', '30');
INSERT INTO `pre_config` VALUES ('settle_rate', '0.5');
INSERT INTO `pre_config` VALUES ('settle_fee_min', '0.1');
INSERT INTO `pre_config` VALUES ('settle_fee_max', '20');
INSERT INTO `pre_config` VALUES ('settle_alipay', '1');
INSERT INTO `pre_config` VALUES ('settle_wxpay', '1');
INSERT INTO `pre_config` VALUES ('settle_qqpay', '1');
INSERT INTO `pre_config` VALUES ('settle_bank', '0');
INSERT INTO `pre_config` VALUES ('transfer_alipay', '0');
INSERT INTO `pre_config` VALUES ('transfer_wxpay', '0');
INSERT INTO `pre_config` VALUES ('transfer_qqpay', '0');
INSERT INTO `pre_config` VALUES ('transfer_name', 'DCAI聚合支付');
INSERT INTO `pre_config` VALUES ('transfer_desc', 'DCAI聚合平台自动结算');
INSERT INTO `pre_config` VALUES ('login_qq', '0');
INSERT INTO `pre_config` VALUES ('login_alipay', '0');
INSERT INTO `pre_config` VALUES ('login_alipay_channel', '0');
INSERT INTO `pre_config` VALUES ('login_wx', '0');
INSERT INTO `pre_config` VALUES ('login_wx_channel', '0');
INSERT INTO `pre_config` VALUES ('reg_open', '1');
INSERT INTO `pre_config` VALUES ('reg_pay', '1');
INSERT INTO `pre_config` VALUES ('reg_pre_uid', '1000');
INSERT INTO `pre_config` VALUES ('reg_pre_price', '5');
INSERT INTO `pre_config` VALUES ('verifytype', '1');
INSERT INTO `pre_config` VALUES ('test_open', '1');
INSERT INTO `pre_config` VALUES ('test_pre_uid', '1000');
INSERT INTO `pre_config` VALUES ('mail_cloud', '0');
INSERT INTO `pre_config` VALUES ('mail_smtp', 'smtp.qq.com');
INSERT INTO `pre_config` VALUES ('mail_port', '465');
INSERT INTO `pre_config` VALUES ('mail_name', '');
INSERT INTO `pre_config` VALUES ('mail_pwd', '');
INSERT INTO `pre_config` VALUES ('sms_api', '0');
INSERT INTO `pre_config` VALUES ('captcha_open', '1');
INSERT INTO `pre_config` VALUES ('captcha_id', '');
INSERT INTO `pre_config` VALUES ('captcha_key', '');
INSERT INTO `pre_config` VALUES ('onecode', '1');
INSERT INTO `pre_config` VALUES ('recharge', '1');
INSERT INTO `pre_config` VALUES ('pageordername', '1');
INSERT INTO `pre_config` VALUES ('notifyordername', '1');
INSERT INTO `pre_config` VALUES ('user_refund', '1');
INSERT INTO `pre_config` VALUES ('cdnpublic', '0');
INSERT INTO `pre_config` VALUES ('ip_type', '2');


DROP TABLE IF EXISTS `pre_cache`;
create table `pre_cache` (
  `k` varchar(32) NOT NULL,
  `v` longtext NULL,
  `expire` int(11) NOT NULL DEFAULT '0',
PRIMARY KEY  (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_anounce`;
create table `pre_anounce` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `content` text DEFAULT NULL,
  `color` varchar(10) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT '1',
  `addtime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_type`;
CREATE TABLE `pre_type` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `name` varchar(30) NOT NULL,
  `device` int(1) unsigned NOT NULL DEFAULT '0',
  `showname` varchar(30) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
 PRIMARY KEY (`id`),
 KEY `name` (`name`,`device`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `pre_type` VALUES (1, 'alipay', 0, '支付宝', 1);
INSERT INTO `pre_type` VALUES (2, 'wxpay', 0, '微信支付', 1);
INSERT INTO `pre_type` VALUES (3, 'qqpay', 0, 'QQ钱包', 1);
INSERT INTO `pre_type` VALUES (4, 'bank', 0, '网银支付', 0);
INSERT INTO `pre_type` VALUES (5, 'jdpay', 0, '京东支付', 0);
INSERT INTO `pre_type` VALUES (6, 'paypal', 0, 'PayPal', 0);
INSERT INTO `pre_type` VALUES (7, 'douyinpay', 0, '抖音支付', 0);
INSERT INTO `pre_type` VALUES (8, 'usdttrc20', 0, 'TRC20 USDT', 1);
INSERT INTO `pre_type` VALUES (9, 'trx', 0, 'TRON TRX', 1);
INSERT INTO `pre_type` VALUES (10, 'usdtpolygon', 0, 'Polygon USDT', 1);
INSERT INTO `pre_type` VALUES (11, 'usdtaptos', 0, 'Aptos USDT', 1);

DROP TABLE IF EXISTS `pre_plugin`;
CREATE TABLE `pre_plugin` (
  `name` varchar(30) NOT NULL,
  `showname` varchar(60) DEFAULT NULL,
  `author` varchar(60) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `types` varchar(50) DEFAULT NULL,
  `transtypes` varchar(50) DEFAULT NULL,
 PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_channel`;
CREATE TABLE `pre_channel` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `mode` tinyint(1) DEFAULT 0,
  `type` int(11) unsigned NOT NULL,
  `plugin` varchar(30) NOT NULL,
  `name` varchar(30) NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT '100.00',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `apptype` varchar(50) DEFAULT NULL,
  `daytop` int(10) DEFAULT 0,
  `daystatus` tinyint(1) DEFAULT 0,
  `paymin` varchar(10) DEFAULT NULL,
  `paymax` varchar(10) DEFAULT NULL,
  `appwxmp` int(11) DEFAULT NULL,
  `appwxa` int(11) DEFAULT NULL,
  `costrate` decimal(5,2) DEFAULT NULL,
  `config` text DEFAULT NULL,
  `daymaxorder` int(10) DEFAULT 0,
  `timestart` int(11) DEFAULT NULL,
  `timestop` int(11) DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_roll`;
CREATE TABLE `pre_roll` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` int(11) unsigned NOT NULL,
  `name` varchar(30) NOT NULL,
  `kind` int(1) unsigned NOT NULL DEFAULT '0',
  `info` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `index` int(11) NOT NULL DEFAULT '0',
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=101;

DROP TABLE IF EXISTS `pre_weixin`;
CREATE TABLE `pre_weixin` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` tinyint(4) unsigned NOT NULL DEFAULT '0',
  `name` varchar(30) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `appid` varchar(150) DEFAULT NULL,
  `appsecret` varchar(250) DEFAULT NULL,
  `access_token` varchar(300) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  `expiretime` datetime DEFAULT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_order`;
CREATE TABLE `pre_order` (
  `trade_no` char(19) NOT NULL,
  `out_trade_no` varchar(150) NOT NULL,
  `api_trade_no` varchar(150) DEFAULT NULL,
  `uid` int(11) unsigned NOT NULL,
  `tid` tinyint(11) unsigned NOT NULL DEFAULT '0',
  `type` int(10) unsigned NOT NULL,
  `channel` int(10) unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `money` decimal(10,2) NOT NULL,
  `realmoney` decimal(10,2) DEFAULT NULL,
  `getmoney` decimal(10,2) DEFAULT NULL,
  `profitmoney` decimal(10,2) DEFAULT NULL,
  `refundmoney` decimal(10,2) DEFAULT NULL,
  `notify_url` varchar(255) DEFAULT NULL,
  `return_url` varchar(255) DEFAULT NULL,
  `param` varchar(255) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `endtime` datetime DEFAULT NULL,
  `date` date DEFAULT NULL,
  `domain` varchar(64) DEFAULT NULL,
  `domain2` varchar(64) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `buyer` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `notify` int(5) NOT NULL DEFAULT '0',
  `notifytime` datetime DEFAULT NULL,
  `invite` int(11) unsigned NOT NULL DEFAULT '0',
  `invitemoney` decimal(10,2) DEFAULT NULL,
  `combine` tinyint(1) NOT NULL DEFAULT '0',
  `profits` int(11) NOT NULL DEFAULT '0',
  `profits2` int(11) NOT NULL DEFAULT '0',
  `settle` tinyint(1) NOT NULL DEFAULT '0',
  `subchannel` int(11) NOT NULL DEFAULT '0',
  `payurl` varchar(500) DEFAULT NULL,
  `ext` text DEFAULT NULL,
  `version` tinyint(1) NOT NULL DEFAULT '0',
  `bill_trade_no` varchar(150) DEFAULT NULL,
  `bill_mch_trade_no` varchar(150) DEFAULT NULL,
  `mobile` varchar(100) DEFAULT NULL,
  `cert_info` varchar(300) DEFAULT NULL,
  `province` varchar(2) DEFAULT NULL,
 PRIMARY KEY (`trade_no`),
 KEY `uid` (`uid`),
 KEY `out_trade_no` (`out_trade_no`,`uid`),
 KEY `api_trade_no` (`api_trade_no`),
 KEY `bill_trade_no` (`bill_trade_no`),
 KEY `bill_mch_trade_no` (`bill_mch_trade_no`),
 KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_group`;
CREATE TABLE `pre_group` (
  `gid` int(11) unsigned NOT NULL auto_increment,
  `name` varchar(30) NOT NULL,
  `info` varchar(1024) DEFAULT NULL,
  `isbuy` tinyint(1) NOT NULL DEFAULT 0,
  `price` decimal(10,2) DEFAULT NULL,
  `sort` int(10) NOT NULL DEFAULT 0,
  `expire` int(10) NOT NULL DEFAULT 0,
  `config` text DEFAULT NULL,
  `settings` text DEFAULT NULL,
  `visible` varchar(30) DEFAULT NULL,
  `index` int(11) NOT NULL DEFAULT 0,
 PRIMARY KEY (`gid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `pre_group` (`gid`, `name`, `info`) VALUES
(0, '默认用户组', '{"1":{"type":"","channel":"-1","rate":""},"2":{"type":"","channel":"-1","rate":""},"3":{"type":"","channel":"-1","rate":""}}');
UPDATE `pre_group` SET `gid` = '0' WHERE `gid` = 1;

DROP TABLE IF EXISTS `pre_user`;
CREATE TABLE `pre_user` (
  `uid` int(11) unsigned NOT NULL auto_increment,
  `gid` int(11) unsigned NOT NULL DEFAULT 0,
  `upid` int(11) unsigned NOT NULL DEFAULT 0,
  `key` varchar(32) NOT NULL,
  `pwd` varchar(32) DEFAULT NULL,
  `account` varchar(128) DEFAULT NULL,
  `username` varchar(128) DEFAULT NULL,
  `codename` varchar(32) DEFAULT NULL,
  `settle_id` tinyint(4) NOT NULL DEFAULT '1',
  `alipay_uid` varchar(32) DEFAULT NULL,
  `qq_uid` varchar(32) DEFAULT NULL,
  `wx_uid` varchar(32) DEFAULT NULL,
  `wxa_uid` varchar(32) DEFAULT NULL,
  `money` decimal(10,2) NOT NULL,
  `email` varchar(32) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `qq` varchar(20) DEFAULT NULL,
  `url` varchar(64) DEFAULT NULL,
  `cert` tinyint(4) NOT NULL DEFAULT '0',
  `certtype` tinyint(4) NOT NULL DEFAULT '0',
  `certmethod` tinyint(4) NOT NULL DEFAULT '0',
  `certno` varchar(18) DEFAULT NULL,
  `certname` varchar(32) DEFAULT NULL,
  `certtime` datetime DEFAULT NULL,
  `certtoken` varchar(64) DEFAULT NULL,
  `certcorpno` varchar(30) DEFAULT NULL,
  `certcorpname` varchar(80) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `lasttime` datetime DEFAULT NULL,
  `endtime` datetime DEFAULT NULL,
  `level` tinyint(1) NOT NULL DEFAULT '1',
  `pay` tinyint(1) NOT NULL DEFAULT '1',
  `settle` tinyint(1) NOT NULL DEFAULT '1',
  `keylogin` tinyint(1) NOT NULL DEFAULT '1',
  `apply` tinyint(1) NOT NULL DEFAULT '0',
  `mode` tinyint(4) NOT NULL DEFAULT '0',
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `refund` tinyint(1) NOT NULL DEFAULT '1',
  `transfer` tinyint(1) NOT NULL DEFAULT '0',
  `keytype` tinyint(1) NOT NULL DEFAULT '0',
  `publickey` varchar(500) DEFAULT NULL,
  `channelinfo` text DEFAULT NULL,
  `ordername` varchar(255) DEFAULT NULL,
  `msgconfig` text DEFAULT NULL,
  `remain_money` varchar(20) DEFAULT NULL,
  `open_code` tinyint(1) NOT NULL DEFAULT '0',
  `deposit` decimal(10,2) DEFAULT NULL,
  `voice_order` tinyint(1) NOT NULL DEFAULT '0',
  `voice_devid` varchar(30) DEFAULT NULL,
  `print_order` tinyint(1) NOT NULL DEFAULT '0',
  `print_config` varchar(300) DEFAULT NULL,
  `pay_maxmoney` varchar(10) DEFAULT NULL,
  `pay_minmoney` varchar(10) DEFAULT NULL,
 PRIMARY KEY (`uid`),
 KEY `email` (`email`),
 KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1000;

DROP TABLE IF EXISTS `pre_settle`;
CREATE TABLE `pre_settle` (
  `id` int(11) NOT NULL auto_increment,
  `uid` int(11) NOT NULL,
  `batch` varchar(20) DEFAULT NULL,
  `auto` tinyint(1) NOT NULL DEFAULT '1',
  `type` tinyint(1) NOT NULL DEFAULT '1',
  `account` varchar(128) NOT NULL,
  `username` varchar(128) NOT NULL,
  `money` decimal(10,2) NOT NULL,
  `realmoney` decimal(10,2) NOT NULL,
  `addtime` datetime DEFAULT NULL,
  `endtime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `transfer_no` varchar(64) DEFAULT NULL,
  `transfer_channel` int(10) unsigned DEFAULT NULL,
  `transfer_status` tinyint(1) NOT NULL DEFAULT '0',
  `transfer_result` varchar(64) DEFAULT NULL,
  `transfer_date` datetime DEFAULT NULL,
  `transfer_ext` text DEFAULT NULL,
  `result` varchar(64) DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `uid` (`uid`),
 KEY `batch` (`batch`),
 KEY `transfer_no` (`transfer_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_log`;
CREATE TABLE `pre_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `type` varchar(20) NULL,
  `date` datetime NOT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `city` varchar(20) DEFAULT NULL,
  `data` text NULL,
  PRIMARY KEY (`id`),
  KEY `logincheck` (`ip`,`date`,`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_record`;
CREATE TABLE `pre_record` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `action` tinyint(1) NOT NULL DEFAULT '0',
  `money` decimal(10,2) NOT NULL,
  `oldmoney` decimal(10,2) NOT NULL,
  `newmoney` decimal(10,2) NOT NULL,
  `type` varchar(20) DEFAULT NULL,
  `trade_no` varchar(64) DEFAULT NULL,
  `date` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_batch`;
CREATE TABLE `pre_batch` (
  `batch` varchar(20) NOT NULL,
  `allmoney` decimal(10,2) NOT NULL,
  `count` int(11) NOT NULL DEFAULT '0',
  `time` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
 PRIMARY KEY (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_regcode`;
CREATE TABLE `pre_regcode` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `scene` varchar(20) NOT NULL DEFAULT '',
  `type` tinyint(1) NOT NULL DEFAULT '0',
  `code` varchar(32) NOT NULL,
  `to` varchar(32) DEFAULT NULL,
  `time` int(11) NOT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `errcount` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `code` (`to`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_risk`;
CREATE TABLE `pre_risk` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT '0',
  `type` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(64) DEFAULT NULL,
  `content` varchar(64) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_domain`;
CREATE TABLE `pre_domain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `uid` int(11) NOT NULL DEFAULT '0',
  `domain` varchar(128) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  `endtime` datetime DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `domain` (`domain`,`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_psreceiver`;
CREATE TABLE `pre_psreceiver` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `channel` int(11) NOT NULL,
  `subchannel` int(11) DEFAULT NULL,
  `mode` tinyint(1) NOT NULL DEFAULT '0',
  `uid` int(11) DEFAULT NULL,
  `account` varchar(128) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `rate` varchar(10) DEFAULT NULL,
  `minmoney` varchar(10) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  `info` varchar(1024) DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `channel` (`channel`,`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_psorder`;
CREATE TABLE `pre_psorder` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `rid` int(11) NOT NULL,
  `trade_no` char(19) NOT NULL,
  `api_trade_no` varchar(150) NOT NULL,
  `sub_trade_no` varchar(25) DEFAULT NULL,
  `settle_no` varchar(150) DEFAULT NULL,
  `money` decimal(10,2) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `result` text DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `delay` tinyint(1) NOT NULL DEFAULT '0',
  `retry` tinyint(1) NOT NULL DEFAULT '0',
  `rdata` text DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `trade_no` (`trade_no`),
 KEY `addtime` (`addtime`,`delay`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_subchannel`;
CREATE TABLE `pre_subchannel` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `channel` int(11) NOT NULL,
  `uid` int(11) NOT NULL,
  `name` varchar(30) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `info` text DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `usetime` datetime DEFAULT NULL,
  `apply_id` int(11) DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `channel` (`channel`),
 KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_blacklist`;
CREATE TABLE `pre_blacklist` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` tinyint(1) NOT NULL DEFAULT '0',
  `content` varchar(50) NOT NULL,
  `addtime` datetime NOT NULL,
  `endtime` datetime DEFAULT NULL,
  `remark` varchar(80) DEFAULT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `content`(`content`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_wework`;
CREATE TABLE `pre_wework` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `name` varchar(30) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `appid` varchar(150) DEFAULT NULL,
  `appsecret` varchar(250) DEFAULT NULL,
  `access_token` varchar(300) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  `expiretime` datetime DEFAULT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_wxkfaccount`;
CREATE TABLE `pre_wxkfaccount` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `wid` int(11) unsigned NOT NULL,
  `openkfid` varchar(60) NOT NULL,
  `url` varchar(100) DEFAULT NULL,
  `cursor` varchar(30) DEFAULT NULL,
  `name` varchar(300) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `usetime` datetime DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `wid`(`wid`),
 UNIQUE KEY `openkfid`(`openkfid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_wxkflog`;
CREATE TABLE `pre_wxkflog` (
  `trade_no` char(19) NOT NULL,
  `aid` int(11) unsigned NOT NULL,
  `sid` char(32) NOT NULL,
  `payurl` varchar(500) NOT NULL,
  `addtime` datetime NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
 PRIMARY KEY (`trade_no`),
 KEY `sid`(`sid`),
 KEY `addtime`(`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_transfer`;
CREATE TABLE `pre_transfer` (
  `biz_no` char(19) NOT NULL,
  `out_biz_no` varchar(150) NOT NULL DEFAULT '',
  `pay_order_no` varchar(80) DEFAULT NULL,
  `uid` int(11) NOT NULL,
  `type` varchar(10) NOT NULL,
  `channel` int(10) unsigned NOT NULL,
  `account` varchar(128) NOT NULL,
  `username` varchar(128) DEFAULT NULL,
  `money` decimal(10,2) NOT NULL,
  `costmoney` decimal(10,2) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `paytime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `api` tinyint(1) NOT NULL DEFAULT '0',
  `desc` varchar(80) DEFAULT NULL,
  `result` varchar(80) DEFAULT NULL,
  `ext` text DEFAULT NULL,
 PRIMARY KEY (`biz_no`),
 KEY `uid` (`uid`),
 KEY `out_biz_no` (`out_biz_no`,`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_invitecode`;
CREATE TABLE `pre_invitecode` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `code` varchar(40) NOT NULL,
  `addtime` datetime NOT NULL,
  `usetime` datetime DEFAULT NULL,
  `uid` int(11) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_refundorder`;
CREATE TABLE `pre_refundorder` (
  `refund_no` char(19) NOT NULL,
  `out_refund_no` varchar(150) NOT NULL,
  `trade_no` char(19) NOT NULL,
  `uid` int(11) NOT NULL DEFAULT '0',
  `money` decimal(10,2) NOT NULL,
  `reducemoney` decimal(10,2) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  `endtime` datetime DEFAULT NULL,
 PRIMARY KEY (`refund_no`),
 KEY `out_refund_no` (`out_refund_no`,`uid`),
 KEY `trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `pre_suborder`;
CREATE TABLE `pre_suborder` (
  `sub_trade_no` varchar(25) NOT NULL,
  `trade_no` char(19) NOT NULL,
  `api_trade_no` varchar(150) DEFAULT NULL,
  `money` decimal(10,2) NOT NULL,
  `refundmoney` decimal(10,2) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `settle` tinyint(1) NOT NULL DEFAULT '0',
 PRIMARY KEY (`sub_trade_no`),
 KEY `trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `pre_order_notify_attempt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trade_no` varchar(32) NOT NULL,
  `source` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `http_status` smallint unsigned DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `error_code` varchar(32) DEFAULT NULL,
  `error_summary` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_notify_history` (`trade_no`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pre_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(32) NOT NULL,
  `channel_id` int unsigned NOT NULL DEFAULT 0,
  `source` tinyint unsigned NOT NULL DEFAULT 0,
  job_key varchar(48) NOT NULL DEFAULT '',
  plugin varchar(64) NOT NULL DEFAULT '',
  retention_success_days smallint unsigned NOT NULL DEFAULT 30,
  retention_failure_days smallint unsigned NOT NULL DEFAULT 90,
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `schedule_type` varchar(10) NOT NULL,
  `interval_seconds` int unsigned DEFAULT NULL,
  `daily_at` char(5) DEFAULT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_status` varchar(16) NOT NULL DEFAULT 'idle',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `lock_token` char(32) DEFAULT NULL,
  `lock_expires_at` datetime DEFAULT NULL,
  `last_error` varchar(1000) DEFAULT NULL,
  `last_summary` varchar(2000) DEFAULT NULL,
  `revision` int unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_identity` (`action`,`channel_id`,`source`,`job_key`),
  KEY `task_due` (`enabled`,`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pre_task_run` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `token` char(32) NOT NULL,
  `triggered_by` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `summary` varchar(2000) DEFAULT NULL,
  `error` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_token` (`token`),
  KEY `task_history` (`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pre_task` (`action`,`schedule_type`,`interval_seconds`,`daily_at`) VALUES
('order','daily',NULL,'00:01'),
('settle','daily',NULL,'00:05'),
('notify','interval',60,NULL),
('notify2','interval',3600,NULL),
('profitsharing','interval',60,NULL),
('check','interval',60,NULL),
('transfer','interval',300,NULL),
('cleanup_logs','daily',NULL,'03:30')
ON DUPLICATE KEY UPDATE `action`=VALUES(`action`);

CREATE TABLE `pre_merchant_channel_policy` (
  `uid` int unsigned NOT NULL,
  `mode` varchar(8) NOT NULL,
  `rules` text NOT NULL,
  `revision` int unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pre_complaint_account` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `provider` varchar(16) NOT NULL,
  `channel` int unsigned NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT 1,
  `credentials` mediumtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_channel` (`channel`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `pre_complaint` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `channel` int unsigned NOT NULL,
  `provider` varchar(16) NOT NULL,
  `thirdid` varchar(128) NOT NULL,
  `thirdmchid` varchar(64) NOT NULL DEFAULT '',
  `uid` int unsigned NOT NULL DEFAULT 0,
  `trade_no` varchar(64) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL,
  `remote_status` varchar(64) NOT NULL,
  `type` varchar(128) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `money` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL,
  `remote_updated_at` datetime DEFAULT NULL,
  `snapshot_at` datetime NOT NULL,
  `snapshot` mediumtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_account_complaint` (`account_id`,`thirdid`),
  KEY `idx_status_created` (`status`,`created_at`), KEY `idx_merchant` (`uid`,`id`), KEY `idx_channel` (`channel`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `pre_complaint_action` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `request_id` varchar(64) NOT NULL,
  `action` varchar(32) NOT NULL,
  `state` varchar(16) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `message` varchar(500) NOT NULL DEFAULT '',
  `ip` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_request` (`request_id`), KEY `idx_complaint` (`complaint_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `pre_complaint_attachment` (
  `id` char(32) NOT NULL,
  `complaint_id` int unsigned NOT NULL,
  `remote_id` varchar(512) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_complaint` (`complaint_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_task_worker (
  id tinyint unsigned NOT NULL,
  token char(32) NOT NULL,
  pid int unsigned NOT NULL,
  state varchar(16) NOT NULL,
  heartbeat_at datetime NOT NULL,
  started_at datetime NOT NULL,
  active_tasks text NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_task_request (
  id char(32) NOT NULL,
  task_id int unsigned NOT NULL,
  revision int unsigned NOT NULL,
  state varchar(16) NOT NULL,
  created_at datetime NOT NULL,
  started_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  message varchar(2000) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY request_revision (task_id, revision),
  KEY request_pending (state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_task_execution (
 id char(32) NOT NULL,
 task_id int unsigned NOT NULL,
 active_task_id int unsigned DEFAULT NULL,
 revision int unsigned NOT NULL,
 origin varchar(16) NOT NULL,
 request_id char(32) DEFAULT NULL,
 resource_key varchar(128) NOT NULL,
 plugin_digest char(64) NOT NULL DEFAULT '',
 timeout_seconds int unsigned NOT NULL,
 state varchar(16) NOT NULL,
 created_at datetime NOT NULL,
 claimed_at datetime DEFAULT NULL,
 finished_at datetime DEFAULT NULL,
 message varchar(2000) DEFAULT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY execution_active (active_task_id),
 KEY execution_history (task_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_plugin_service (
 id char(32) NOT NULL,
 channel_id int unsigned NOT NULL,
 plugin varchar(64) NOT NULL,
 service_key varchar(48) NOT NULL,
 name varchar(60) NOT NULL,
 resource_key varchar(128) NOT NULL,
 digest char(64) NOT NULL,
 revision int unsigned NOT NULL DEFAULT 1,
 desired tinyint unsigned NOT NULL DEFAULT 0,
 observed varchar(24) NOT NULL DEFAULT 'stopped',
 generation int unsigned NOT NULL DEFAULT 0,
 token char(32) DEFAULT NULL,
 ready_at datetime DEFAULT NULL,
 heartbeat_at datetime DEFAULT NULL,
 started_at datetime DEFAULT NULL,
 last_error varchar(1000) DEFAULT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY service_identity (plugin,resource_key,service_key),
 KEY service_desired (desired,observed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pre_service_operation (
 id char(32) NOT NULL,
 service_id char(32) NOT NULL,
 revision int unsigned NOT NULL,
 action varchar(16) NOT NULL,
 state varchar(16) NOT NULL DEFAULT 'pending',
 created_at datetime NOT NULL,
 finished_at datetime DEFAULT NULL,
 message varchar(1000) DEFAULT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY service_revision (service_id,revision),
 KEY operation_pending (state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pre_complaint_sync` (
 `account_id` int unsigned NOT NULL,
 `state` varchar(16) NOT NULL DEFAULT 'pending',
 `config_digest` char(64) NOT NULL DEFAULT '',
 `cursor` text DEFAULT NULL,
 `processed` int unsigned NOT NULL DEFAULT 0,
 `failures` int unsigned NOT NULL DEFAULT 0,
 `next_at` datetime DEFAULT NULL,
 `started_at` datetime DEFAULT NULL,
 `last_success_at` datetime DEFAULT NULL,
 `last_count` int unsigned NOT NULL DEFAULT 0,
 `last_error` varchar(1000) DEFAULT NULL,
 `checked_at` datetime DEFAULT NULL,
 `check_ok` tinyint unsigned DEFAULT NULL,
 `check_message` varchar(1000) DEFAULT NULL,
 `updated_at` datetime NOT NULL,
 PRIMARY KEY (`account_id`),
 KEY sync_due (state,next_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
