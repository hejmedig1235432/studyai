ALTER TABLE users ADD COLUMN lock_reason TEXT;
CREATE TABLE banned_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  device_key TEXT NOT NULL,
  user_id INTEGER,
  reason TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_banned_devices_key ON banned_devices(device_key);
CREATE TABLE user_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  device_key TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_user_devices ON user_devices(user_id, device_key);
CREATE TABLE security_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER,
  kind TEXT NOT NULL,
  route TEXT,
  label TEXT,
  excerpt TEXT,
  device_key TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_security_events_user ON security_events(user_id, kind, created_at);
