CREATE TABLE IF NOT EXISTS `pre_app_session` (
 `id` varchar(32) NOT NULL PRIMARY KEY, `username` varchar(80) NOT NULL,
 `access_hash` varchar(64) NOT NULL UNIQUE, `refresh_hash` varchar(64) NOT NULL UNIQUE,
 `stamp` varchar(64) NOT NULL, `device` varchar(80) NOT NULL, `ip` varchar(50) NOT NULL,
 `created` bigint NOT NULL, `last_seen` bigint NOT NULL, `access_expires` bigint NOT NULL,
 `expires` bigint NOT NULL, `revoked` tinyint NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `pre_app_operation` (
 `id` varchar(128) NOT NULL PRIMARY KEY, `request_id` varchar(64) NOT NULL,
 `reference` varchar(64) NOT NULL, `state` varchar(16) NOT NULL,
 `created` bigint NOT NULL, `detail` text NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `pre_app_challenge` (
 `id` varchar(64) NOT NULL PRIMARY KEY, `kind` varchar(16) NOT NULL, `proof` varchar(64) NOT NULL,
 `data` mediumtext NOT NULL, `expires` bigint NOT NULL, `attempts` int NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `pre_app_limit` (
 `id` varchar(64) NOT NULL PRIMARY KEY, `hits` int NOT NULL DEFAULT 0, `expires` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `pre_app_request` (
 `id` varchar(64) NOT NULL PRIMARY KEY, `fingerprint` varchar(64) NOT NULL,
 `response` mediumtext NULL, `created` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `pre_app_notification_device` (
 `device_id` char(36) NOT NULL PRIMARY KEY, `username` varchar(80) NOT NULL,
 `enabled` tinyint NOT NULL DEFAULT 0, `created` bigint NOT NULL, `last_seen` bigint NOT NULL,
 KEY `enabled_seen` (`enabled`,`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `pre_app_notification` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `event_key` char(64) NOT NULL UNIQUE,
 `type` varchar(32) NOT NULL, `title` varchar(128) NOT NULL, `body` varchar(255) NOT NULL,
 `trade_no` varchar(32) NOT NULL, `uid` bigint unsigned NOT NULL DEFAULT 0,
 `amount` decimal(12,2) NOT NULL DEFAULT 0, `created` bigint NOT NULL,
 KEY `created_id` (`created`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
