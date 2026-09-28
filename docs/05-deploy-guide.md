# Chef Daily Order — Deployment Guide

| | |
|---|---|
| Version | 1.0 |
| Stage | 5 of 5 — Requirements → Design → Build → Test → **Deploy** |
| Goes with | [Test plan §5 (checks M1–M8)](04-test-plan.md) |

Everything happens in **cPanel**, **Google Cloud** and **GitHub**. About 45 minutes. Replace
`CPANELUSER` with your cPanel username (shown top-right in cPanel) wherever it appears.

> **Never** paste passwords, the Google key or FTP details into chats, issues or files in GitHub.
> They go only into cPanel, `config.php` on the server, or GitHub **Secrets**.

---

## Step 1 — Subdomain and HTTPS (cPanel)

1. **Domains → Create A New Domain** → `buy.mirchi.cl`. Untick "Share document root".
   Note the **document root** it proposes, e.g. `/home/CPANELUSER/buy.mirchi.cl`. This is the **web folder**.
2. **SSL/TLS Status** → tick `buy.mirchi.cl` → **Run AutoSSL**. Wait until it shows a valid certificate.
3. **MultiPHP Manager** → tick `buy.mirchi.cl` → PHP **8.2** or newer → Apply.
4. **Select PHP Version → Extensions** (if your host has it): make sure `pdo_mysql`, `curl`, `openssl`,
   `mbstring` and `intl` are ticked.

## Step 2 — Database (cPanel → MySQL Databases)

1. **Create New Database**: `chef` → becomes `CPANELUSER_chef`.
2. **Add New User**: `chef` → `CPANELUSER_chef`, with a long generated password. Keep it for Step 6.
3. **Add User To Database** → that user and database → **ALL PRIVILEGES**.

The tables and the 102 starting items are created automatically on the first visit.

## Step 3 — E-mail sender (cPanel → Email Accounts)

1. **Create** `no-reply@mirchi.cl` (any strong password; nobody needs to read it).
2. **Email Deliverability** → `mirchi.cl` → if SPF or DKIM show a problem, click **Repair**.
   This keeps password-reset e-mails out of spam.

## Step 4 — Google service account (Google Cloud Console)

Skip to 4.5 if you already have a service-account JSON key you want to use.

1. https://console.cloud.google.com → project selector → **New project** → `mirchi-chef-orders` → Create.
2. **APIs & Services → Library** → search **Google Sheets API** → **Enable**.
3. **IAM & Admin → Service Accounts → Create service account** → name `chef-orders` → Done (no roles needed).
4. Open it → **Keys → Add key → Create new key → JSON** → a `.json` file downloads.
5. **cPanel → File Manager** → your home folder (`/home/CPANELUSER`, *not* the web folder) →
   **+ Folder** `chef-private` → open it → **Upload** the JSON → rename it `service-account.json`.
   Then delete the downloaded copy from your computer.
6. Open the JSON in File Manager (View), copy the `client_email` (…@….iam.gserviceaccount.com).
7. Open the Google Sheet → **Share** → paste that e-mail → **Editor** → untick "Notify" → Share.

## Step 5 — Automatic upload from GitHub

1. **cPanel → FTP Accounts → Add FTP Account**: login `deploy`, a long generated password,
   **Directory = the web folder from Step 1** (e.g. `buy.mirchi.cl`). Create.
   Under "Configure FTP Client" note the **FTP server** name (often `ftp.mirchi.cl`) and the full username (`deploy@mirchi.cl`).
2. **GitHub → `shanky8281/chef-daily-order` → Settings → Secrets and variables → Actions → New repository secret**, three times:

   | Name | Value |
   |---|---|
   | `FTP_SERVER` | the FTP server, e.g. `ftp.mirchi.cl` |
   | `FTP_USERNAME` | e.g. `deploy@mirchi.cl` |
   | `FTP_PASSWORD` | the FTP password |

3. **GitHub → Actions → Deploy → Run workflow → main → Run**. It runs all tests, then uploads.
   Green = the files are on the server. (From now on this happens by itself after every merge.)

## Step 6 — Server settings (cPanel → File Manager)

1. In the web folder open `api/` → right-click `config.sample.php` → **Copy** → name `config.php`.
2. Edit `config.php`:

   ```php
   'db_dsn'      => 'mysql:host=localhost;dbname=CPANELUSER_chef;charset=utf8mb4',
   'db_user'     => 'CPANELUSER_chef',
   'db_password' => '…password from Step 2…',
   'base_url'    => 'https://buy.mirchi.cl',
   'mail_from'   => 'no-reply@mirchi.cl',
   'google_key'  => '/home/CPANELUSER/chef-private/service-account.json',
   ```
3. Save. `config.php` is never overwritten by uploads and cannot be opened from the web.

## Step 7 — Check the server, create your login (cPanel → Terminal)

If your cPanel has no Terminal, ask your host to enable "Shell access", or tell Claude.

1. Which PHP: `php -v` must say 8.1 or newer. If not, use the full path, e.g.
   `/opt/cpanel/ea-php82/root/usr/bin/php` instead of `php` below.
2. Self-check: `php ~/buy.mirchi.cl/api/cron/check.php`
   Every line must show ✔ (it explains how to fix any ✘). This also creates the tables.
3. Your admin login: `php ~/buy.mirchi.cl/api/cron/create-admin.php Shankar shan8281@gmail.com`
   It asks for the password twice (at least 8 characters; typing is invisible).
4. First market prices: `php ~/buy.mirchi.cl/api/cron/market-prices.php` → "Updated N market prices".

## Step 8 — Scheduled jobs (cPanel → Cron Jobs)

Add two jobs (use the same `php` as in Step 7):

| When | Command |
|---|---|
| Every 30 minutes (`*/30 * * * *`) | `php /home/CPANELUSER/buy.mirchi.cl/api/cron/sync-sheet.php > /dev/null 2>&1` |
| Once a day (`30 10 * * *`) | `php /home/CPANELUSER/buy.mirchi.cl/api/cron/market-prices.php > /dev/null 2>&1` |

Cron times are in the **server's** time zone, which is often UTC: `30 10 * * *` is 06:30–07:30 in Chile.
ODEPA publishes the previous day's prices, so any early-morning time is fine.

## Step 9 — Go-live checks

Do checks **M1–M8** from the [test plan](04-test-plan.md#5-acceptance-on-the-real-server-stage-5)
and tell Claude the result of each. Then in the app: **☰ → Settings** → owner's WhatsApp number;
**☰ → Users** → add the chefs (with their e-mail so they can reset their own password).

---

## Day-to-day

| Task | Where |
|---|---|
| Add / change items and prices | App → ✏️ (admins) |
| Add a chef, reset a password | App → ☰ → Users |
| See all orders | Google Sheet → Orders tab, or App → 🕘 |
| Something not copied to the sheet | App → ☰ → Settings → "Copy now" (shows Google's error if any) |
| Change the app | Ask Claude → pull request → tests → merge → uploads automatically |
| Back-up | cPanel → Backup → download the `CPANELUSER_chef` database now and then (the sheet is a second copy of all orders) |
