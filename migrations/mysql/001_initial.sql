CREATE TABLE users (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'user',
  onboarded BIGINT NOT NULL DEFAULT 0,
  prefs TEXT,
  created_at BIGINT NOT NULL,
  last_login_at BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at BIGINT NOT NULL,
  used_at BIGINT,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_resets_token ON password_resets(token_hash);

CREATE TABLE auth_throttle (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash VARCHAR(64) NOT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_throttle_key ON auth_throttle(key_hash, created_at);

CREATE TABLE settings (
  name VARCHAR(100) NOT NULL PRIMARY KEY,
  value TEXT,
  updated_at BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  name TEXT NOT NULL,
  description TEXT,
  color VARCHAR(32),
  status VARCHAR(32) NOT NULL DEFAULT 'empty',
  analysis LONGTEXT,
  exam_date TEXT,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  last_studied_at BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_subjects_user ON subjects(user_id);

CREATE TABLE sources (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  parent_id BIGINT,
  kind VARCHAR(32) NOT NULL,
  title TEXT NOT NULL,
  original_name TEXT,
  mime TEXT,
  size_bytes BIGINT NOT NULL DEFAULT 0,
  storage_path TEXT,
  url TEXT,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  error TEXT,
  char_count BIGINT NOT NULL DEFAULT 0,
  meta LONGTEXT,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_sources_subject ON sources(subject_id);

CREATE TABLE chunks (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  source_id BIGINT NOT NULL,
  position BIGINT NOT NULL,
  label TEXT,
  content LONGTEXT NOT NULL,
  terms LONGTEXT NOT NULL,
  term_count BIGINT NOT NULL,
  embedding LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_chunks_subject ON chunks(subject_id);
CREATE INDEX idx_chunks_source ON chunks(source_id);
CREATE INDEX idx_chunks_user ON chunks(user_id);

CREATE TABLE topics (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  name TEXT NOT NULL,
  summary TEXT,
  subtopics TEXT,
  importance BIGINT NOT NULL DEFAULT 2,
  position BIGINT NOT NULL DEFAULT 0,
  active BIGINT NOT NULL DEFAULT 1,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_topics_subject ON topics(subject_id);

CREATE TABLE guide_sections (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  topic_id BIGINT,
  title TEXT NOT NULL,
  content LONGTEXT NOT NULL,
  position BIGINT NOT NULL DEFAULT 0,
  edited BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_guide_subject ON guide_sections(subject_id);

CREATE TABLE questions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  topic_id BIGINT,
  type VARCHAR(32) NOT NULL,
  difficulty BIGINT NOT NULL DEFAULT 2,
  prompt TEXT NOT NULL,
  options TEXT,
  answer TEXT NOT NULL,
  accept TEXT,
  explanation TEXT,
  option_notes TEXT,
  refs TEXT,
  hash VARCHAR(64) NOT NULL,
  times_asked BIGINT NOT NULL DEFAULT 0,
  times_correct BIGINT NOT NULL DEFAULT 0,
  last_asked_at BIGINT,
  srs_due_at BIGINT,
  srs_interval DOUBLE,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_questions_subject ON questions(subject_id);
CREATE INDEX idx_questions_hash ON questions(subject_id, hash);

CREATE TABLE attempts (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  mode VARCHAR(32) NOT NULL,
  settings TEXT,
  question_ids TEXT,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  correct BIGINT NOT NULL DEFAULT 0,
  total BIGINT NOT NULL DEFAULT 0,
  score DOUBLE,
  time_limit_sec BIGINT,
  result LONGTEXT,
  started_at BIGINT NOT NULL,
  finished_at BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_attempts_user ON attempts(user_id, mode);

CREATE TABLE answers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  attempt_id BIGINT,
  question_id BIGINT NOT NULL,
  topic_id BIGINT,
  user_answer TEXT,
  is_correct BIGINT NOT NULL DEFAULT 0,
  score DOUBLE NOT NULL DEFAULT 0,
  feedback TEXT,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_answers_user ON answers(user_id, created_at);
CREATE INDEX idx_answers_attempt ON answers(attempt_id);

CREATE TABLE flashcards (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  topic_id BIGINT,
  front TEXT NOT NULL,
  back TEXT NOT NULL,
  refs TEXT,
  hash VARCHAR(64) NOT NULL,
  ease DOUBLE NOT NULL DEFAULT 2.5,
  interval_days DOUBLE NOT NULL DEFAULT 0,
  reps BIGINT NOT NULL DEFAULT 0,
  lapses BIGINT NOT NULL DEFAULT 0,
  due_at BIGINT,
  last_rating VARCHAR(32),
  last_reviewed_at BIGINT,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_cards_subject ON flashcards(subject_id);
CREATE INDEX idx_cards_due ON flashcards(user_id, due_at);

CREATE TABLE card_reviews (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  card_id BIGINT NOT NULL,
  rating VARCHAR(32) NOT NULL,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_reviews_user ON card_reviews(user_id, created_at);

CREATE TABLE topic_progress (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  topic_id BIGINT NOT NULL,
  correct BIGINT NOT NULL DEFAULT 0,
  incorrect BIGINT NOT NULL DEFAULT 0,
  seconds BIGINT NOT NULL DEFAULT 0,
  mastery DOUBLE NOT NULL DEFAULT 0,
  understood BIGINT NOT NULL DEFAULT 0,
  last_seen_at BIGINT,
  UNIQUE(user_id, topic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE study_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT,
  activity VARCHAR(32) NOT NULL,
  day VARCHAR(10) NOT NULL,
  seconds BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_log_user_day ON study_log(user_id, day);

CREATE TABLE tutor_threads (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  title TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tutor_messages (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  thread_id BIGINT NOT NULL,
  role VARCHAR(32) NOT NULL,
  content LONGTEXT NOT NULL,
  refs TEXT,
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_tmsg_thread ON tutor_messages(thread_id);

CREATE TABLE learn_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT NOT NULL,
  state LONGTEXT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  subject_id BIGINT,
  type VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  stage VARCHAR(32),
  progress BIGINT NOT NULL DEFAULT 0,
  message TEXT,
  payload LONGTEXT,
  error TEXT,
  attempts BIGINT NOT NULL DEFAULT 0,
  locked_until BIGINT NOT NULL DEFAULT 0,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_jobs_status ON jobs(status);
CREATE INDEX idx_jobs_subject ON jobs(subject_id);
