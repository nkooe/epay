INSERT INTO `pre_task` (`action`,`schedule_type`,`interval_seconds`,`daily_at`) VALUES
('cleanup_logs','daily',NULL,'03:30')
ON DUPLICATE KEY UPDATE `action`=VALUES(`action`);
