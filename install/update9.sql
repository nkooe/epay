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
