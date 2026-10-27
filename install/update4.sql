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
