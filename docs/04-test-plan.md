# Chef Daily Order — Test Plan

| | |
|---|---|
| Version | 1.0 (for review) |
| Covers | [Requirements v1.1](01-requirements.md), [Design v1.1](02-design.md) |
| Stage | 4 of 5 — Requirements → Design → Build → **Test** → Deploy |
| Results | [Test report](04-test-report.md) |

## 1. Goal

Show that every requirement (F1–F31, N1–N7) and every design decision (D1–D7) works, before
anything is put on buy.mirchi.cl. Every requirement is covered in one of three ways:

| How | What it is | When it runs |
|---|---|---|
| **Automated — API** | `tests/run.php` + `tests/*_test.php`: calls the server exactly as the page does and checks the answers and the database. | On every push (GitHub Actions) and before every deploy. |
| **Automated — browser** | `tests/browser.cjs`: drives the real page in Chromium at phone size (390 × 844), as a chef and as an admin. | Same. |
| **By hand on the real server** | The checklist in §5. Covers what the build environment cannot reach: real Google, e-mail, ODEPA, WhatsApp, real phones. | Once, in Stage 5, after the first upload. |

A few requirements are met by how the app is built and are checked by review (§4).

## 2. Test environment

| | Automated tests | Real server (§5) |
|---|---|---|
| Server | PHP 8.2 (GitHub) / 8.4 (local), PHP's built-in web server | PHP 8 on cPanel, Apache/LiteSpeed |
| Database | MySQL 8.0 (GitHub) / MariaDB (local), **empty test database, wiped every run** | cPanel MySQL |
| Browser | Chromium, phone size | Chrome on Android, Safari on iPhone |
| Google Sheet | Stand-in that behaves like the Sheets API (incl. "Google is down") | The real sheet |
| E-mail | Written to a log file instead of sent | Real mailbox |
| ODEPA | Sample CSV from the previous project's tests | Real daily download |

Test users: **Shankar** (admin, created by the same command used on the server), **Ravi**,
**Maria**, **Pedro** (chefs, created through the app itself).

## 3. What the automated tests do

Each check is named after the requirement it proves (e.g. `F17 same order uploaded twice is saved once`),
so the report can list results per requirement.

| Area | File | Main scenarios |
|---|---|---|
| Login, users, passwords | `10_auth_test.php` | Wrong password / unknown user give the same answer · requests without the page's header are refused · chefs are refused every admin action (8 cases) · lock after 5 wrong passwords, also with the right one · admin reset unlocks and logs out everywhere · password change logs out other devices · e-mail reset: link works once, expires, max 3 per hour, same answer for unknown accounts · deactivated users are logged out |
| Items | `20_items_test.php` | Starting list 102/11 · add with every kind of bad input refused · Chilean price "1.200" · edit + history with who/what · move up/down · delete/restore · category add/rename/remove (asks first)/restore/reorder · import preview (new/changed/unchanged/error/new category) writes nothing · import commit · re-import changes nothing |
| Orders | `30_orders_test.php` | Order saved with sender · same order twice → one copy · another user can't reuse an order id · server recomputes totals · names kept as sent · 6 kinds of bad order refused · chef sees only own orders (list and single) · admin sees all, filter by chef · paging and 60-day window · owner WhatsApp number reaches chefs |
| Google Sheet | `40_sheet_test.php` | Google down → nothing lost · one append with exactly the 12 agreed columns · tab and header created · never copied twice · half-finished copy retried after 10 min · existing header kept · Google login signature verified |
| Market prices | `50_market_test.php` | Chilean number and date formats · 13 unit conversions (boxes, dozens, bunches…) · Santiago markets and latest date preferred · "cebolla" ≠ "cebollín" · stale quotes ignored · manual items untouched · trend ▲▼ · bad ODEPA file refused |
| Whole app in a browser | `browser.cjs` | Log in · touch targets ≥ 44 px · security headers and cookie · Spanish large / English below · black background · search in Spanish, English, without accents · − / + and "2,5" · totals · My list · draft survives reload · **send with no signal, upload when back, exactly once** · report text · WhatsApp link · past orders and reuse · item manager add/edit/history/delete · **Excel export → re-import changes nothing** · CSV (Windows encoding) import · item name with code shown as text · settings · add user · chef sees no admin buttons · change password · e-mail reset flow · **session ends while an order waits → log in → it uploads** · load time on 4G |

## 4. Checked by review

| ID | How it is met |
|---|---|
| F11 | No photo field exists in the database, the item form or the page. |
| N3 | Every button, heading and message in `index.html`, `app.js`, `admin.js` and the server is English; only item and category names are Spanish-first. |
| N7 | Runs on the existing cPanel hosting (PHP + MySQL). No paid service, no third-party code; Google Sheets API and GitHub Actions are used within their free limits. |

## 5. Acceptance on the real server (Stage 5)

Done once by Shankar with Claude after the first upload to buy.mirchi.cl. Each line must pass
before chefs start using the app.

| # | Check | Covers |
|---|---|---|
| M1 | `https://buy.mirchi.cl` opens the login page; `http://` redirects to `https://`. | F1, N6 |
| M2 | Send a test order → within a minute it appears in the **Orders** tab of the Google Sheet with the 12 columns. Settings shows "All orders are in the Google Sheet". | F21 |
| M3 | "Forgot password?" → the e-mail arrives (check spam) → the link sets a new password. | F5 |
| M4 | On a phone, "Send on WhatsApp" opens WhatsApp with the report, addressed to the owner's number. | F19 |
| M5 | Run the market-price job once from cPanel Terminal → "Updated N market prices"; the order page shows the new date. The daily cron job is listed in cPanel. | F29 |
| M6 | On an Android phone (Chrome) and an iPhone (Safari): log in, fill quantities, send; "Add to Home screen" works and opens full screen. | N1, N4 |
| M7 | Browser developer tools on buy.mirchi.cl: security headers present (HSTS, Content-Security-Policy, X-Frame-Options); `https://buy.mirchi.cl/api/config.php` and `/api/lib/core.php` are refused (403). | N6 |
| M8 | Airplane mode on a phone: fill and send an order → "1 order waiting for signal" → airplane mode off → it uploads and appears in the sheet once. | F17, N5 |

## 6. Pass criteria

* All automated checks pass on GitHub (MySQL 8) — required for every deploy.
* Every requirement has at least one passing automated check, or is covered in §4.
* M1–M8 pass on the real server before the app is handed to the chefs.
