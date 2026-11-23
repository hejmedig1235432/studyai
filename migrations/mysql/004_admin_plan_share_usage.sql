ALTER TABLE users ADD COLUMN locked_at BIGINT NULL;
ALTER TABLE subjects ADD COLUMN plan LONGTEXT NULL;
ALTER TABLE subjects ADD COLUMN share_token VARCHAR(64) NULL;
ALTER TABLE subjects ADD COLUMN copied_from BIGINT NULL;
CREATE INDEX idx_subjects_share ON subjects(share_token);
CREATE TABLE ai_usage (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL DEFAULT 0,
  day VARCHAR(10) NOT NULL,
  requests BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL,
  UNIQUE KEY uq_ai_usage (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ai_usage_day ON ai_usage(day);
