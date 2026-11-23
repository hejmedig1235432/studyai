CREATE TABLE ai_cache (
  cache_key VARCHAR(64) NOT NULL PRIMARY KEY,
  response LONGTEXT NOT NULL,
  created_at BIGINT NOT NULL,
  KEY idx_ai_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
