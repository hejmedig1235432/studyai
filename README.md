# Studiepilot

An AI study platform in plain PHP: upload your learning material, and StudyAI builds a study workspace
from it, with an analysis dashboard, a study guide, adaptive quizzes, flashcards with spaced repetition,
practice exams, an AI tutor and a learn mode. Everything is grounded in your own sources, with
clickable references back to the page, slide or section each fact came from.

- **Backend:** PHP 8.1+ (no Composer dependencies), PDO with SQLite (default) or MySQL/MariaDB
- **Frontend:** vanilla JavaScript modules + hand-written CSS (no build step); `marked`, `DOMPurify` and `pdf.js` load from a CDN
- **AI:** OpenRouter (or any OpenAI-compatible API), with **multiple API keys** that rotate and fail over

---

## Quick start (localhost, Windows / macOS / Linux)

1. **Install PHP 8.1 or newer.** In `php.ini`, enable these extensions:

   ```ini
   extension=curl
   extension=fileinfo
   extension=gd          ; optional: shrinks large images before OCR
   extension=intl        ; optional: better accent handling when grading
   extension=mbstring
   extension=openssl
   extension=pdo_sqlite  ; or pdo_mysql
   extension=zip
   ```

   Then raise the limits so large documents can be uploaded:

   ```ini
   upload_max_filesize = 64M
   post_max_size = 80M
   max_execution_time = 300
   memory_limit = 512M
   ```

2. **Configure.** Copy `.env.example` to `.env`. The defaults work without changes; optionally put your
   OpenRouter key(s) in `OPENROUTER_API_KEYS` (comma separated). You can also add keys in the app.

3. **Run** from the `studyai` folder (needs Node.js, no npm packages). This starts several PHP processes
   plus the background worker, so slow AI requests never block the rest of the app:

   ```bash
   node dev-server.js
   ```

   Without Node you can use `php -S localhost:8088 -t public public/router.php`, but PHP's built-in server
   handles one request at a time, so the app waits whenever the AI is working.

   Open http://localhost:8088. The database is created and migrated automatically on the first request.
   If port 8088 is taken, use any free port and update `APP_URL` in `.env`.

4. **Create your account.** The **first account registered becomes the admin.** Onboarding asks the
   admin for an OpenRouter API key (get one at https://openrouter.ai/keys).

5. **Background worker (recommended on Windows).** PHP's built-in server handles one request at a time,
   so slow AI analysis would make the app wait. Run the worker in a second terminal. Browser tabs then
   just show its progress, and processing also continues when no tab is open:

   ```bash
   php bin/worker.php
   ```

## Multiple OpenRouter keys

Add as many keys as you like, in either place:

- **In the app:** Settings → AI provider (admin only). Keys are tested when added, stored encrypted
  (AES-256-GCM with `APP_KEY`), and are never sent to the browser. You can disable, enable, remove and
  test them there, and see each key's last error and status.
- **In `.env`:** `OPENROUTER_API_KEYS=sk-or-v1-aaa,sk-or-v1-bbb`

Requests go round-robin across the enabled keys. If a key is invalid (401/403), out of credits (402)
or rate limited (429), StudyAI moves to the next key and gives the failing one a cooldown.

Models are set in Settings → AI provider (or in `.env`): a main model, a vision model for OCR, and an
optional embedding model for semantic search. The defaults use `google/gemini-2.5-flash`, which is fast,
cheap and handles both text and images. Any OpenRouter model id works.

**Free OpenRouter accounts (no credits):** click **Use all free models** in Settings → AI provider. StudyAI
tests every free model in parallel, keeps the ones that work from your region (fastest first) and uses them
as a fallback chain: a busy, rate-limited or region-blocked model is skipped automatically. Free models are
slower and have daily limits, so adding more keys helps. With a little credit, paid models like
`google/gemini-2.5-flash` are much faster.

**Only the admin** sees and manages keys and models. The admin's keys are shared by every user. Regular
users can't see, add or change keys, models or the provider, and only learn whether AI is available.
Use `php bin/make-admin.php <email>` to promote another account.

## Language (e.g. Danish classes)

All AI output (analysis, study guide, quizzes, flashcards, grading feedback, tutor, learn mode) follows
the **AI language**, resolved in this order:

1. **Tutor switch:** "Answer in …" next to the chat, per conversation
2. **Subject language:** subject → Edit details (e.g. *Dansk* for a Danish class, even when some material is in English)
3. **Your default:** Settings → Language
4. **Same as my material** (the default)

Answers written in another language than the material are accepted when grading. Existing content keeps
its language: use **Re-analyse everything**, or the study guide's **AI tools → Translate…**, to convert it.
OCR always transcribes in the original language.

**Assignments:** the tutor has shortcuts to explain an assignment, give feedback on your own text
(structure, argumentation, language), check grammar and spelling, and help you plan. It helps you
improve your work but doesn't write the whole assignment for you.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `APP_NAME` | StudyAI | Product name shown in the UI |
| `APP_URL` | http://localhost:8088 | Public URL, used in password-reset links |
| `APP_ENV` | local | `local` shows reset links on screen when mail is not configured; use `production` when hosted |
| `APP_TIMEZONE` | UTC | Used for streaks and daily review days |
| `APP_KEY` | *(auto)* | Encryption key for stored API keys. If empty, one is generated in `storage/app.key`. **Back it up.** If you lose it, re-enter your API keys. |
| `OPENROUTER_API_KEYS` | — | Comma-separated keys (optional if you add keys in the app) |
| `AI_BASE_URL` | https://openrouter.ai/api/v1 | Any OpenAI-compatible endpoint |
| `AI_MODEL` | google/gemini-2.5-flash | Main model |
| `AI_VISION_MODEL` | = AI_MODEL | OCR for images and scanned PDFs |
| `AI_EMBEDDING_MODEL` | — | e.g. `openai/text-embedding-3-small`. Empty = keyword (BM25) retrieval only |
| `DB_DRIVER` | sqlite | `sqlite` or `mysql` |
| `DB_DATABASE` | storage/database.sqlite | SQLite file |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | — | MySQL settings |
| `MAX_UPLOAD_MB` | 50 | Max size per file (php.ini limits also apply) |
| `MAX_ZIP_UNCOMPRESSED_MB` | 500 | Zip-bomb protection |
| `MAX_STORAGE_MB_PER_USER` | 2000 | Per-user storage quota |
| `ALLOW_PRIVATE_URLS` | false | Allow importing URLs on local/private networks (keep `false` when hosted) |
| `MAIL_DRIVER` | log | `log` (writes `storage/logs/mail.log`), `mail` (PHP mail()) or `smtp` |
| `MAIL_FROM`, `SMTP_*` | — | Sender and SMTP settings for password-reset emails |
| `CURL_CA_BUNDLE` | — | Path to a CA bundle if HTTPS calls fail with certificate errors |

## Supported material

| Source | How it's read |
|---|---|
| PDF | Text per page in the browser (pdf.js). Pages without a text layer (scans) are rendered and read with OCR by the vision model. PDFs inside ZIPs are parsed on the server. |
| Word `.docx`, PowerPoint `.pptx` (incl. speaker notes), Excel `.xlsx` | Parsed on the server (ZipArchive + XML) |
| ZIP | Expanded on the server, with each supported file becoming its own source. Unsupported files, hidden files and `__MACOSX` are skipped, with limits on file count and uncompressed size |
| Images (PNG, JPG, WEBP, GIF) | OCR with the vision model; diagrams get a short factual description |
| TXT, Markdown, CSV, HTML, JSON | Read directly, with Markdown headings used as section labels |
| YouTube links | Uses the video's published subtitles/transcript. Videos without one get a clear error, and you can paste a transcript instead |
| Web pages | Readable article text is extracted. robots.txt is respected; logins and paywalls are **not** bypassed |
| Pasted text | Stored as a source |

Old formats (`.doc`, `.ppt`, `.xls`) get a message asking you to save them as `.docx`/`.pptx`/`.xlsx`/PDF.

## How the AI stays grounded

1. Every source is split into labelled chunks (`p. 4`, `Slide 7`, `Sheet "Data"`, `12:30`, or a section heading).
2. **Analysis (map/reduce):** chunk batches are analysed for topics, definitions, facts, dates,
   people, relationships, likely test questions and *unclear/contradictory points*. Every item must
   cite chunk ids, and items without a valid citation are discarded. The results are merged into one topic structure.
3. **Per topic:** the most relevant chunks (BM25, plus embeddings if configured) are used to write
   the study-guide section and flashcards.
4. **Quizzes and exams** are generated from a shuffled set of topic chunks, so each run covers different
   passages. Previous questions are passed as "do not repeat", recent mistakes steer new questions, and
   difficulty adapts per topic to your mastery. Questions without a valid citation are dropped.
5. **Tutor** answers use retrieved chunks. If your material doesn't cover something, the tutor says so
   and labels any general explanation as *not from your sources*.
6. Citations like `[C123]` render as source chips. Click one to see the original passage.

## Learning features

**Read first, then practise:** once a workspace is built, flashcards, quizzes, the practice exam and memory
review stay locked until every study-guide section is marked "I have read this section" (this is checked on
the server too). The tutor and learn mode stay available to help with reading. When a section is regenerated
or re-analysed, it has to be read again.

- **Analysis dashboard:** overview, topics with importance and mastery, likely test points, definitions, key facts, timeline of dates, people, concept relationships, unclear spots
- **Study guide:** editable Markdown per topic, AI rewrite (simpler / shorter / more detail / highlight key info), your own notes, a one-page cheat sheet, and PDF (print) and Markdown export
- **AI quiz:** multiple choice, true/false, fill-in-the-blank, short answer and exam questions, with instant feedback, explanations, notes on why each wrong option is wrong, and adaptive difficulty
- **Flashcards:** flip cards rated Easy/Medium/Hard (SM-2 style scheduling), with hard cards repeated in-session, a mastery state per card, manual cards, and CSV/Anki/JSON export
- **Practice exam:** custom size, question types, difficulty and topics, an optional timer, a navigator with flags, autosave, AI grading of open answers, and a per-topic breakdown with weak topics
- **Learn mode:** explain → question → evaluate → explain the mistake and give a new example → move on after 2 correct in a row
- **Memory training:** daily review of due flashcards and past mistakes, memory strength per topic, fading items, and a 14-day schedule
- **Dashboard:** study time, streak, quizzes, average score, cards mastered, overall progress, weakest topics, recommendations, upcoming reviews and continue learning
- **Search** across every document, topic and flashcard

## Deployment (when you host it)

**Simply.com:** follow the step-by-step guide in [docs/deploy-simply.md](docs/deploy-simply.md).

1. Upload the whole `studyai` folder. Point the web server's **document root at `studyai/public`**, so
   `src/`, `storage/` and `.env` are never web-accessible.
2. Make `storage/` writable by the web server user.
3. In `.env`: set `APP_ENV=production`, `APP_URL=https://your-domain`, set a fixed `APP_KEY`, and configure
   `MAIL_DRIVER=smtp` with `SMTP_*` so password resets reach users. Serve over **HTTPS**; session cookies
   become `Secure` automatically.
4. **MySQL** (recommended for many users): create an empty database (utf8mb4), set `DB_DRIVER=mysql` and the `DB_*`
   values, then run `php bin/migrate.php` (or just open the site).
5. Run the worker with cron (every minute) or as a service:

   ```bash
   * * * * * cd /path/to/studyai && php bin/worker.php --once >> storage/logs/worker.log 2>&1
   ```

6. Apache works out of the box with `public/.htaccess`. For Nginx:

   ```nginx
   root /path/to/studyai/public;
   index index.php;
   client_max_body_size 80m;
   location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; fastcgi_read_timeout 300; }
   location ~ /router\.php$ { deny all; }
   ```

## Maintenance commands

```bash
php bin/migrate.php                      # apply database migrations
php bin/worker.php                       # process uploads/analysis in the background
php bin/make-admin.php you@example.com   # give an account the admin role
```

## Security notes

- Passwords are hashed with Argon2id (or bcrypt where Argon2 is unavailable). Login, registration and reset
  are rate limited, and reset tokens are single-use, hashed and expire after 1 hour.
- Every query is scoped to the signed-in user, so users can only access their own subjects, sources and results.
- All state-changing requests require a CSRF token. Sessions use HttpOnly + SameSite=Lax cookies.
- Uploads are checked by extension **and** file signature, with size and quota limits, and stored outside the web root under random names.
- URL imports block private/reserved IP ranges (SSRF protection), re-check every redirect, and respect robots.txt.
- API keys are only ever used on the server, are encrypted at rest, and the UI only ever shows them masked.
- AI output is rendered as sanitised Markdown (DOMPurify).

## Project structure

```
public/            web root: index.php (app shell), api.php (JSON API), print.php (PDF export), assets/
src/               PHP application (namespace App)
  AI.php           OpenRouter client: key rotation/failover, JSON mode, OCR, embeddings
  Pipeline.php     background job runner: extraction → analysis → study guide/flashcards
  Analyzer.php     map/reduce analysis, topics, guide sections, flashcard generation
  Retriever.php    chunking, BM25 + embedding retrieval, citations
  Quiz.php         quizzes & exams: generation, grading, results
  Cards.php        flashcards, spaced repetition, memory training
  Tutor.php, Learn.php, Guide.php, Progress.php, Subjects.php, Sources.php, Auth.php, Admin.php
  Extract/         Pdf.php, Office.php (docx/pptx/xlsx), Web.php, YouTube.php
migrations/        sqlite/ and mysql/ schema migrations
bin/               worker.php, migrate.php, make-admin.php
storage/           database, uploads, logs, sessions (not web-accessible)
```

## Troubleshooting

- **"SSL certificate problem: unable to get local issuer certificate"**: PHP has no CA bundle. On Windows StudyAI
  falls back to the Windows certificate store automatically. Otherwise download `cacert.pem` from
  https://curl.se/docs/caextract.html and set `CURL_CA_BUNDLE` (or `curl.cainfo` in php.ini).
- **"Failed to listen on localhost:8000"**: Windows reserves some ports. Use another port (`-S localhost:8088`).
- **Upload says the file is too large**: raise `upload_max_filesize` and `post_max_size` in php.ini and restart PHP.
- **Analysis stops with an AI error**: check Settings → AI provider for the key status and last error, and that the
  model id exists on OpenRouter. Details are in `storage/logs/app.log`.

## Releasing an update (update log)

Users see an update log under **Nyheder**. When you release a new version, add an entry at the **top** of
`src/Changelog.php` (higher version number, date, title and changes marked `new`, `improved` or `fixed`).
Every user then gets a one-time "Ny opdatering" notice and a **Ny** badge on Nyheder until they open it.

## OpenRouter free tier limits

Free OpenRouter accounts get **50 free-model requests per day**, shared by every key on the same account,
resetting at 00:00 UTC. Adding $10 of credit raises this to 1000/day. When the daily limit is reached,
Studiepilot shows a clear message with the reset time, and background jobs pause and continue automatically
after the reset. On the free tier, model "racing" is switched off so each answer costs only one request.
