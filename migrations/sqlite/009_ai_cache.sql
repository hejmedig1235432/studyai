CREATE TABLE ai_cache (
  cache_key TEXT PRIMARY KEY,
  response TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_ai_cache_created ON ai_cache(created_at);
