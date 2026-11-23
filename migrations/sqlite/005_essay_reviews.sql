CREATE TABLE essay_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  subject_id INTEGER,
  parent_id INTEGER,
  title TEXT NOT NULL DEFAULT '',
  assignment TEXT,
  criteria TEXT,
  scale TEXT NOT NULL DEFAULT '7',
  language TEXT,
  essay TEXT NOT NULL,
  result TEXT,
  score INTEGER,
  grade TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_essay_user ON essay_reviews(user_id, id);
