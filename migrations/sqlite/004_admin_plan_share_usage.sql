ALTER TABLE users ADD COLUMN locked_at INTEGER;
ALTER TABLE subjects ADD COLUMN plan TEXT;
ALTER TABLE subjects ADD COLUMN share_token TEXT;
ALTER TABLE subjects ADD COLUMN copied_from INTEGER;
CREATE INDEX idx_subjects_share ON subjects(share_token);
CREATE TABLE ai_usage (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL DEFAULT 0,
  day TEXT NOT NULL,
  requests INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL,
  UNIQUE(user_id, day)
);
CREATE INDEX idx_ai_usage_day ON ai_usage(day);
