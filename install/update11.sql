CREATE TABLE IF NOT EXISTS pre_complaint_sync (
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
