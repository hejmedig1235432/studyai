# Putting StudyAI online with Simply.com

This guide is for a Simply.com **webhotel**. It follows Simply.com's documented features: you can choose the PHP version
([PHP versions FAQ](https://www.simply.com/en/support/faq/php/503/)) and schedule URLs to run on a timer ("cronjobs",
[cronjob FAQ](https://www.simply.com/en/support/faq/general/356-cronjobs/)). Menu names in the control panel may differ
slightly from what is written here.

> StudyAI has been tested on PHP 8.5 with SQLite on Windows. It has **not yet been tested on Simply.com or with MySQL**, so
> work through the checklist at the end.

---

## 1. In the Simply.com control panel

1. **PHP version:** choose the newest **PHP 8.x** (at least 8.1).
2. **Database:** create a **MySQL database**. Write down the host name, database name, user and password.
3. **HTTPS:** turn on SSL for your domain (Let's Encrypt), so the site runs on `https://`.

## 2. Build the upload package (on your PC)

From the `studyai` folder:

```bash
powershell -ExecutionPolicy Bypass -File deploy\make-zip.ps1
```

This creates `studyai-upload.zip`. It contains the app **without** your local database, test accounts, uploads or `.env`.

## 3. Upload

Unzip the package on your PC, then upload with FTP/SFTP (e.g. FileZilla) or Simply.com's file manager.

**Option A (most secure, if the host lets you create folders next to `public_html`):**

```
/ (your FTP home)
├── public_html/   ← the CONTENTS of the "public" folder (index.php, api.php, cron.php, assets/, .htaccess, .user.ini)
├── src/
├── bin/
├── migrations/
├── storage/
└── .env           ← you create this in step 4
```

**Option B (if you can only use `public_html`):** upload **everything** (all folders and the `.htaccess` file) into
`public_html`. The root `.htaccess` then serves only the `public` folder and blocks `src/`, `storage/`, `.env` and so on.

Make sure the `storage/` folder is writable (it normally is on webhotels).

## 4. Create `.env` on the server

Copy `.env.example` to `.env` (in the folder that contains `src/`) and fill in:

```ini
APP_ENV=production
APP_URL=https://your-domain.dk
APP_TIMEZONE=Europe/Copenhagen
APP_KEY=           ; paste a random value (see below) and keep a backup of it
DB_DRIVER=mysql
DB_HOST=           ; from the control panel
DB_NAME=
DB_USER=
DB_PASS=
MAIL_DRIVER=smtp   ; use the SMTP details of an e-mail account from your Simply.com control panel
MAIL_FROM="StudyAI <no-reply@your-domain.dk>"
SMTP_HOST=
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=
SMTP_PASS=
CRON_KEY=          ; another random value (see below)
```

To make random values on your PC (run it twice, one for `APP_KEY` and one for `CRON_KEY`):

```bash
php -r "echo bin2hex(random_bytes(20)), PHP_EOL;"
```

Don't add your OpenRouter keys here: add them in the app (step 6), where they are stored encrypted.

## 5. First visit: create the admin account straight away

Open `https://your-domain.dk`. The database tables are created automatically on the first request.
**Register right away.** The first account becomes the admin, the only one who can see and change the AI keys.

## 6. AI settings (as admin)

Settings → **AI provider**: add your OpenRouter key(s), then click **Use all free models**, or set a paid model if you
have credit.

## 7. Background processing (cronjob)

In the control panel's cronjob section (under "Website"), add a URL to run **every minute**, or at the shortest
interval allowed:

```
https://your-domain.dk/cron.php?key=YOUR_CRON_KEY
```

Analysis now continues even when nobody has the site open. Opening the URL in a browser should show `OK: ... step(s)`.

If you have SSH access, you can use the crontab instead:

```
* * * * * cd ~/ && php bin/worker.php --once
```

## 8. Checklist after uploading

| Check | Expected |
|---|---|
| `https://your-domain.dk/.env` | 403 Forbidden or Not Found (**never** your settings) |
| `https://your-domain.dk/src/AI.php` | 403 Forbidden or Not Found |
| `https://your-domain.dk/storage/` | 403 Forbidden or Not Found |
| `https://your-domain.dk/cron.php` (without key) | 403 / "Cron is disabled" |
| Register + log in | Works, you are the admin |
| Settings → AI provider → footer | Shows `database: mysql` and the real upload limit |
| Upload a small PDF, then close the tab | After a few minutes (cron) the study guide is ready |
| Password reset | The e-mail arrives |

If any of the first three show content, stop and use Option A in step 3.

## Good to know

- **Speed on shared hosting:** StudyAI writes several topics in parallel using separate PHP processes. If the host doesn't
  allow that, it automatically does one topic at a time: slower, but it still works.
- **Upload limit:** `public/.user.ini` asks for 64 MB uploads, but the host may allow less. The real limit is shown in
  Settings → AI provider.
- **Updating later:** build a new zip and upload everything except `.env` and `storage/`. Database changes are applied
  automatically.
- **Backups:** back up the MySQL database and the `storage/uploads` folder. Keep `APP_KEY` safe: without it, the stored
  API keys can't be decrypted, and you'd have to add them again.
