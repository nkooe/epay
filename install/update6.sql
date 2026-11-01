CREATE TABLE IF NOT EXISTS `pre_merchant_channel_policy` (
  `uid` int unsigned NOT NULL,
  `mode` varchar(8) NOT NULL,
  `rules` text NOT NULL,
  `revision` int unsigned NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
