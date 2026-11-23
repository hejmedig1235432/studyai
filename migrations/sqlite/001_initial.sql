CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user',
  onboarded INTEGER NOT NULL DEFAULT 0,
  prefs TEXT,
  created_at INTEGER NOT NULL,
  last_login_at INTEGER
);

CREATE TABLE password_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  used_at INTEGER,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_resets_token ON password_resets(token_hash);

CREATE TABLE auth_throttle (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_throttle_key ON auth_throttle(key_hash, created_at);

CREATE TABLE settings (
  name TEXT PRIMARY KEY,
  value TEXT,
  updated_at INTEGER
);

CREATE TABLE subjects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  description TEXT,
  color TEXT,
  status TEXT NOT NULL DEFAULT 'empty',
  analysis TEXT,
  exam_date TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  last_studied_at INTEGER
);
CREATE INDEX idx_subjects_user ON subjects(user_id);

CREATE TABLE sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  parent_id INTEGER,
  kind TEXT NOT NULL,
  title TEXT NOT NULL,
  original_name TEXT,
  mime TEXT,
  size_bytes INTEGER NOT NULL DEFAULT 0,
  storage_path TEXT,
  url TEXT,
  status TEXT NOT NULL DEFAULT 'queued',
  error TEXT,
  char_count INTEGER NOT NULL DEFAULT 0,
  meta TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX idx_sources_subject ON sources(subject_id);

CREATE TABLE chunks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  source_id INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
  position INTEGER NOT NULL,
  label TEXT,
  content TEXT NOT NULL,
  terms TEXT NOT NULL,
  term_count INTEGER NOT NULL,
  embedding TEXT
);
CREATE INDEX idx_chunks_subject ON chunks(subject_id);
CREATE INDEX idx_chunks_source ON chunks(source_id);
CREATE INDEX idx_chunks_user ON chunks(user_id);

CREATE TABLE topics (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  summary TEXT,
  subtopics TEXT,
  importance INTEGER NOT NULL DEFAULT 2,
  position INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_topics_subject ON topics(subject_id);

CREATE TABLE guide_sections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  topic_id INTEGER,
  title TEXT NOT NULL,
  content TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  edited INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL
);
CREATE INDEX idx_guide_subject ON guide_sections(subject_id);

CREATE TABLE questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  topic_id INTEGER,
  type TEXT NOT NULL,
  difficulty INTEGER NOT NULL DEFAULT 2,
  prompt TEXT NOT NULL,
  options TEXT,
  answer TEXT NOT NULL,
  accept TEXT,
  explanation TEXT,
  option_notes TEXT,
  refs TEXT,
  hash TEXT NOT NULL,
  times_asked INTEGER NOT NULL DEFAULT 0,
  times_correct INTEGER NOT NULL DEFAULT 0,
  last_asked_at INTEGER,
  srs_due_at INTEGER,
  srs_interval REAL,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_questions_subject ON questions(subject_id);
CREATE INDEX idx_questions_hash ON questions(subject_id, hash);

CREATE TABLE attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  mode TEXT NOT NULL,
  settings TEXT,
  question_ids TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  correct INTEGER NOT NULL DEFAULT 0,
  total INTEGER NOT NULL DEFAULT 0,
  score REAL,
  time_limit_sec INTEGER,
  result TEXT,
  started_at INTEGER NOT NULL,
  finished_at INTEGER
);
CREATE INDEX idx_attempts_user ON attempts(user_id, mode);

CREATE TABLE answers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  attempt_id INTEGER,
  question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
  topic_id INTEGER,
  user_answer TEXT,
  is_correct INTEGER NOT NULL DEFAULT 0,
  score REAL NOT NULL DEFAULT 0,
  feedback TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_answers_user ON answers(user_id, created_at);
CREATE INDEX idx_answers_attempt ON answers(attempt_id);

CREATE TABLE flashcards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  topic_id INTEGER,
  front TEXT NOT NULL,
  back TEXT NOT NULL,
  refs TEXT,
  hash TEXT NOT NULL,
  ease REAL NOT NULL DEFAULT 2.5,
  interval_days REAL NOT NULL DEFAULT 0,
  reps INTEGER NOT NULL DEFAULT 0,
  lapses INTEGER NOT NULL DEFAULT 0,
  due_at INTEGER,
  last_rating TEXT,
  last_reviewed_at INTEGER,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_cards_subject ON flashcards(subject_id);
CREATE INDEX idx_cards_due ON flashcards(user_id, due_at);

CREATE TABLE card_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL,
  card_id INTEGER NOT NULL REFERENCES flashcards(id) ON DELETE CASCADE,
  rating TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_reviews_user ON card_reviews(user_id, created_at);

CREATE TABLE topic_progress (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
  correct INTEGER NOT NULL DEFAULT 0,
  incorrect INTEGER NOT NULL DEFAULT 0,
  seconds INTEGER NOT NULL DEFAULT 0,
  mastery REAL NOT NULL DEFAULT 0,
  understood INTEGER NOT NULL DEFAULT 0,
  last_seen_at INTEGER,
  UNIQUE(user_id, topic_id)
);

CREATE TABLE study_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER,
  activity TEXT NOT NULL,
  day TEXT NOT NULL,
  seconds INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL
);
CREATE INDEX idx_log_user_day ON study_log(user_id, day);

CREATE TABLE tutor_threads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE tutor_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  thread_id INTEGER NOT NULL REFERENCES tutor_threads(id) ON DELETE CASCADE,
  role TEXT NOT NULL,
  content TEXT NOT NULL,
  refs TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_tmsg_thread ON tutor_messages(thread_id);

CREATE TABLE learn_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
  state TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject_id INTEGER,
  type TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  stage TEXT,
  progress INTEGER NOT NULL DEFAULT 0,
  message TEXT,
  payload TEXT,
  error TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  locked_until INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX idx_jobs_status ON jobs(status);
CREATE INDEX idx_jobs_subject ON jobs(subject_id);
