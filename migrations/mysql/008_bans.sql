ALTER TABLE users ADD COLUMN lock_reason TEXT NULL;
CREATE TABLE banned_devices (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_key VARCHAR(80) NOT NULL,
  user_id BIGINT NULL,
  reason TEXT NULL,
  created_at BIGINT NOT NULL,
  KEY idx_banned_devices_key (device_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_devices (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  device_key VARCHAR(80) NOT NULL,
  created_at BIGINT NOT NULL,
  KEY idx_user_devices (user_id, device_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE security_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NULL,
  kind VARCHAR(20) NOT NULL,
  route VARCHAR(80) NULL,
  label TEXT NULL,
  excerpt TEXT NULL,
  device_key VARCHAR(80) NULL,
  created_at BIGINT NOT NULL,
  KEY idx_security_events_user (user_id, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
