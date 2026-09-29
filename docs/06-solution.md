# Chef Daily Order — Solution Document

| | |
|---|---|
| Version | **1.0.0** (see [CHANGELOG](../CHANGELOG.md)) |
| Status | Live at **https://buy.mirchi.cl** — accepted by Shankar, 2026-09-29 |
| Owner | Mirchi (Shankar) |
| Code | GitHub `shanky8281/chef-daily-order` (private) |

This document describes the whole solution in one place. The detail lives in the stage documents:
[Requirements](01-requirements.md) · [Design](02-design.md) · [Test plan](04-test-plan.md) ·
[Test report](04-test-report.md) · [Deployment guide](05-deploy-guide.md).

---

## 1. What it does

Chefs open **buy.mirchi.cl** on their phones, log in, enter the day's shopping quantities and
send the priced order to the owner on **WhatsApp**. Every order is saved in the restaurant's own
database and copied to the owner's **Google Sheet**. Admins keep the item list, prices and users
up to date from the same app.

| Who | Can |
|---|---|
| **Chef** | Log in · fill in quantities (online or offline) · send on WhatsApp · see and reuse own past orders · change own password |
| **Admin** | Everything a chef can, plus: items and categories (add, edit, delete/restore, reorder, Excel import/export, change history) · users · settings · all orders |

## 2. How it fits together

```
 Chef's phone                        buy.mirchi.cl (cPanel)                         Google
┌─────────────────────┐   HTTPS   ┌─────────────────────────────────┐   append   ┌──────────────┐
│ Web app (installable)│ ────────► │ PHP 8 API  ── MySQL database    │ ─────────► │ Google Sheet │
│ · order page         │ ◄──────── │ · login, roles, items, orders   │            │ "Orders" tab │
│ · offline outbox     │   JSON    │ · sheet copy, reset e-mails     │            └──────────────┘
└──────────┬──────────┘            │ cron: sheet copy (30 min)       │   daily    ┌──────────────┐
           │ WhatsApp link         │ cron: market prices (daily)     │ ◄───────── │ ODEPA prices │
           ▼                       └──────────────▲──────────────────┘            └──────────────┘
      Owner's WhatsApp                            │ FTPS upload after tests pass
                                     GitHub  shanky8281/chef-daily-order
```

| Part | Technology | Where |
|---|---|---|
| Web app | Plain HTML, CSS and JavaScript; no framework, no build step | `public/` → `public_html/buy.mirchi.cl` |
| Server | PHP 8.2, one entry point `api/index.php`, no third-party packages | `public/api/` |
| Database | MySQL on cPanel, 11 tables, created and upgraded automatically | cPanel database `cmi97028_…` |
| Owner's view | Google Sheet, written through a Google service account | the sheet set in ☰ → Settings |
| Market prices | ODEPA open data (Lo Valledor, Vega Central) | daily cron job |
| E-mail | Password-reset links from `no-reply@mirchi.cl` | cPanel mail |
| Code, tests, deploy | GitHub + GitHub Actions | `shanky8281/chef-daily-order` |

**Cost:** nothing beyond the existing cPanel hosting. Google Sheets API and GitHub Actions are used within their free limits.

## 3. Main flows

**Ordering.** The chef fills in quantities (the draft is kept on the phone) → *Review & send* →
*Send on WhatsApp* opens WhatsApp with the report → at the same moment the order goes into the phone's
**outbox** and is uploaded. With no signal it waits and uploads when the phone is back online;
each order has a unique id, so it is saved **exactly once** even if sent twice.

**Records.** The server saves the order in MySQL (with who sent it and the item names as they were),
answers the phone, then copies the lines to the Google Sheet. If Google cannot be reached, the lines
wait; the 30-minute cron job or ☰ → Settings → *Copy now* copies them later. Nothing is copied twice.

**Sheet columns:** Date · Time · Order # · Chef · Category · Spanish name · English name · Qty · Unit ·
Price · Line total · Order total. Order numbers look like `260929-SH-1` (date – chef – number that day).

**Prices.** Items marked *Market* get the daily ODEPA wholesale price for Santiago, converted to the
item's unit (boxes, sacks, dozens, bunches). *Manual* items keep the price an admin sets. If ODEPA is
down, yesterday's prices stay.

**Item changes.** Admins edit items in the app (✏️). Changes reach every phone the next time the page
opens. Every change is logged with who and when; deleted items can be restored.

## 4. Security

| Area | How |
|---|---|
| Passwords | Stored only as bcrypt hashes; at least 8 characters; lock for 15 min after 5 wrong tries |
| Sessions | Random token in an HttpOnly, Secure, SameSite cookie; only its hash in the database; 30 days |
| Password reset | One-time link, valid 1 hour, at most 3 e-mails per hour; the answer never reveals whether an account exists |
| Roles | Checked on the server for every request; chefs cannot reach admin functions or other chefs' orders |
| Web | HTTPS only, HSTS, strict Content-Security-Policy, no frames; item names always shown as text |
| Secrets | Only in `api/config.php` on the server and the Google key in `~/chef-private/` — never in GitHub, never uploaded, not reachable from the web |

## 5. Where things are

| What | Location |
|---|---|
| App files | `public_html/buy.mirchi.cl/` (uploaded automatically) |
| Server settings | `public_html/buy.mirchi.cl/api/config.php` (edit in File Manager; never overwritten) |
| Google key | `/home3/cmi97028/chef-private/service-account.json` |
| Database | cPanel → MySQL Databases / phpMyAdmin |
| Cron jobs | cPanel → Cron Jobs: `sync-sheet.php` every 30 min, `market-prices.php` daily |
| GitHub secrets | Settings → Secrets → Actions: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` |
| Service account | `chef-orders@mirchi-chef-orders.iam.gserviceaccount.com` (Editor on the sheet) |

## 6. Running it

| Task | How |
|---|---|
| Add a chef / reset a password | App → ☰ → Users |
| Add or change items and prices | App → ✏️ (or Export to Excel, edit, Import) |
| Owner's WhatsApp number, sheet, tab | App → ☰ → Settings |
| Orders not in the sheet | ☰ → Settings → *Copy now* — shows Google's error if any |
| Check the server | cPanel Terminal: `php ~/public_html/buy.mirchi.cl/api/cron/check.php` |
| Back-up | cPanel → Backup → download the database now and then (the Google Sheet is a second copy of all orders) |
| See which version is live | App → ☰ → bottom line |

## 7. Changing it

1. Ask for the change (e.g. to Claude Code on this repository).
2. It is made on a branch and opened as a **pull request**; GitHub runs all tests
   (163 API checks + 56 browser checks) on MySQL 8 and Chrome.
3. After approval it is merged into `main`; the **Deploy** workflow runs the tests again and only
   then uploads `public/` to buy.mirchi.cl. Database changes run automatically on the next visit.
4. The version goes up (see [CHANGELOG](../CHANGELOG.md)): PATCH for fixes, MINOR for new features,
   MAJOR when something must be done on the server. Each release is tagged in GitHub (`v1.0.0`, …).

**To go back to an earlier version:** GitHub → Actions → Deploy → *Run workflow* on the tag, or ask
Claude to revert the change (a new pull request).

## 8. Quality

* **219 automated checks**, every requirement traced to its tests ([test report](04-test-report.md)).
* 4G load time 0.4–0.6 s (target < 2 s).
* 9 defects found and fixed before or during go-live, none open.

## 9. Known limits and ideas for later

| Item | Note |
|---|---|
| Market prices lag ODEPA by a day or more (weekends) | Normal for ODEPA's publishing schedule |
| The Google Sheet also holds salary tabs | The service account can read them; a separate spreadsheet just for orders is safer |
| Home-screen icon is a generic cart | Could use the Mirchi chilli |
| Out of scope in v1.0.0 | Item photos, suppliers, stock levels, owner dashboard, several restaurants |
