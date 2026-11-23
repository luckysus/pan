CREATE TABLE IF NOT EXISTS pre_storage_instance (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(80) NOT NULL,
  type varchar(20) NOT NULL,
  config mediumtext NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  min_tier tinyint(1) NOT NULL DEFAULT 0,
  used_at datetime DEFAULT NULL,
  addtime datetime NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
