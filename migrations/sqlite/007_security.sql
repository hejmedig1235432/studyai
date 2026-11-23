ALTER TABLE users ADD COLUMN session_epoch INTEGER NOT NULL DEFAULT 0;
CREATE TABLE rate_limits (
  bucket TEXT PRIMARY KEY,
  window_start INTEGER NOT NULL,
  hits INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_rate_limits_window ON rate_limits(window_start);
