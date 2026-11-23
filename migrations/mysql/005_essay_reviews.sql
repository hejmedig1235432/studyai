CREATE TABLE essay_reviews (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NULL,
  parent_id BIGINT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  assignment LONGTEXT NULL,
  criteria LONGTEXT NULL,
  scale VARCHAR(16) NOT NULL DEFAULT '7',
  language VARCHAR(16) NULL,
  essay LONGTEXT NOT NULL,
  result LONGTEXT NULL,
  score INT NULL,
  grade VARCHAR(8) NULL,
  created_at BIGINT NOT NULL,
  KEY idx_essay_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
