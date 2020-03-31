// Database s53905__authors on toolforge:

create table batch_manager(
  user VARCHAR(100) NOT NULL,
  manager_pid INT UNSIGNED,
  PRIMARY KEY(user)
);

create table batches(
  batch_id VARCHAR(20) NOT NULL,
  owner VARCHAR(200) NOT NULL,
  process_id INT UNSIGNED,
  start TIMESTAMP NOT NULL,
  queued BOOLEAN NOT NULL DEFAULT 0,
  PRIMARY KEY(batch_id)
);

create table commands(
  ordinal SMALLINT UNSIGNED NOT NULL,
  batch_id VARCHAR(20) NOT NULL,
  action VARCHAR(20) NOT NULL,
  data MEDIUMTEXT,
  status ENUM('READY', 'RUNNING', 'DONE', 'ERROR') NOT NULL,
  message VARCHAR(1000),
  run TIMESTAMP NULL,
  PRIMARY KEY(batch_id, ordinal),
  FOREIGN KEY (batch_id)
     REFERENCES batches(batch_id)
     ON DELETE CASCADE
);

create table rate_limit_table(last_time BIGINT);
