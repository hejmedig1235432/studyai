ALTER TABLE users ADD COLUMN session_epoch INT NOT NULL DEFAULT 0;
CREATE TABLE rate_limits (
  bucket VARCHAR(96) NOT NULL PRIMARY KEY,
  window_start BIGINT NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
