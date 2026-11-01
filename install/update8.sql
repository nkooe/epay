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

