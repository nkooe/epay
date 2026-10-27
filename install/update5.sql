CREATE TABLE IF NOT EXISTS `pre_order_notify_attempt` (
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
