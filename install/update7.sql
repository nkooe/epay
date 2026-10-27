CREATE TABLE IF NOT EXISTS `pre_complaint_account` (
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
CREATE TABLE IF NOT EXISTS `pre_complaint` (
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
CREATE TABLE IF NOT EXISTS `pre_complaint_action` (
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
CREATE TABLE IF NOT EXISTS `pre_complaint_attachment` (
  `id` char(32) NOT NULL,
  `complaint_id` int unsigned NOT NULL,
  `remote_id` varchar(512) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`), KEY `idx_complaint` (`complaint_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
