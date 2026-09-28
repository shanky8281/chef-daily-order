# Chef Daily Order — Test Report

| | |
|---|---|
| Version | 1.0 (for review) |
| Plan | [Test plan](04-test-plan.md) |
| Code tested | `main` at 6a79a15 (Build, stage 3) plus the fixes listed in §3 |
| Date | 2026-09-28 |
| Stage | 4 of 5 — Requirements → Design → Build → **Test** → Deploy |

## 1. Summary

| Suite | Checks | Passed | Failed |
|---|---:|---:|---:|
| API (`php tests/run.php`, MariaDB) | 160 | 160 | 0 |
| Browser (`node tests/browser.cjs`, Chromium 390 × 844) | 56 | 56 | 0 |
| **Total** | **216** | **216** | **0** |

The same suites also run on GitHub (MySQL 8.0) for this pull request and before every deploy.

* **Every requirement passes.** 35 of the 38 (F1–F31, N1–N7) are proven by automated checks; F11, N3 and N7 are confirmed by review.
* **Speed (N5), 4G at 9 Mbps / 100 ms, empty cache:** login page **0.4 s**, log in → item list **0.4 s**, reopen while logged in **0.6 s** (target: under 2 s).
* **Not yet tested for real** — the build environment cannot reach them, so stand-ins were used: Google Sheets, e-mail sending, the ODEPA download, WhatsApp itself, real phones, the `.htaccess` rules on Apache. These are the eight checks M1–M8 of the plan, done on buy.mirchi.cl in Stage 5.

## 2. Results per requirement

| ID | Requirement | API checks | Browser checks | Result | Also on the real server (§5) |
|---|---|---:|---:|---|---|
| F1 | Login page first | 5 | 2 | ✅ Pass |  |
| F2 | Many users, roles | 6 | 1 | ✅ Pass |  |
| F3 | Admin manages users | 6 | — | ✅ Pass |  |
| F4 | Change own password | 4 | 1 | ✅ Pass |  |
| F5 | Reset by e-mail | 8 | 3 | ✅ Pass | M3 real e-mail |
| F6 | Stay logged in 30 days, log out | 2 | 1 | ✅ Pass |  |
| F7 | Lock after 5 wrong passwords | 4 | — | ✅ Pass |  |
| F8 | Items grouped by category | 1 | — | ✅ Pass |  |
| F9 | Spanish large, English below | 1 | 2 | ✅ Pass |  |
| F10 | Search Spanish/English, accents | — | 3 | ✅ Pass |  |
| F11 | Text only, no photos | — | — | 📋 Review | Review: no photo field anywhere (design §2) |
| F12 | − / + and decimals | — | 2 | ✅ Pass |  |
| F13 | Totals always visible | — | 1 | ✅ Pass |  |
| F14 | "My list" filter | — | 1 | ✅ Pass |  |
| F15 | Draft saved on device | — | 1 | ✅ Pass |  |
| F16 | Repeat last order | — | 1 | ✅ Pass |  |
| F17 | Offline outbox, never twice | 4 | 5 | ✅ Pass |  |
| F18 | Report: Spanish (English) | — | 2 | ✅ Pass |  |
| F19 | WhatsApp to owner; copy/share | 1 | 2 | ✅ Pass | M4 real WhatsApp |
| F20 | Saved in MySQL with sender | 11 | — | ✅ Pass |  |
| F21 | Copied to Google Sheet | 15 | — | ✅ Pass | M2 real Google Sheet |
| F22 | Past orders by role | 7 | 3 | ✅ Pass |  |
| F23 | Items button (admin) | — | 1 | ✅ Pass |  |
| F24 | Add / edit items | 9 | 3 | ✅ Pass |  |
| F25 | Delete hides, restore | 4 | 1 | ✅ Pass |  |
| F26 | Categories | 6 | — | ✅ Pass |  |
| F27 | Move items | 2 | — | ✅ Pass |  |
| F28 | Excel/CSV import & export | 10 | 5 | ✅ Pass |  |
| F29 | Market / manual prices | 36 | — | ✅ Pass | M5 real ODEPA download |
| F30 | Change history | 3 | 1 | ✅ Pass |  |
| F31 | Starting list 102 / 11 | 1 | — | ✅ Pass |  |
| N1 | Mobile, big buttons | — | 1 | ✅ Pass | M6 real phones |
| N2 | Black, white & red | — | 1 | ✅ Pass |  |
| N3 | Other text English | — | — | 📋 Review | Review of all screens (§4 of the plan) |
| N4 | Installable | — | 1 | ✅ Pass | M6 install on phones |
| N5 | Loads < 2 s on 4G | — | 3 | ✅ Pass | M8 real 4G |
| N6 | Security | 10 | 5 | ✅ Pass | M7 HTTPS & headers on the server |
| N7 | No extra monthly cost | — | — | 📋 Review | Review: runs on existing cPanel, no paid service |
| D3 | Units list | 1 | — | ✅ Pass |  |
| D5 | Password ≥ 8 | 1 | — | ✅ Pass |  |
| D7 | Past orders 60 days, paged | 2 | — | ✅ Pass |  |

Design decisions D1 (order number `260928-SH-1`), D2 (category "Verduras / Vegetables"), D4 (sender
address, config) and D6 (starting list) are checked inside the F18, F8, F5 and F31 checks.

## 3. Defects found and fixed

Found while writing and running the tests. All are fixed and covered by a check.

| # | Defect | Found by | Fix |
|---|---|---|---|
| 1 | A price typed the Chilean way, "1.200", was saved as **$1**. | API test F24 | Prices are read as whole pesos: "1.200", "$1.300", "1300" all work. |
| 2 | A negative price could slip in through import. | Code review while testing | Prices outside 0–1,000,000,000 are refused. |
| 3 | Time stamps could use the wrong time zone depending on which code ran first, so a reset link could look valid for 3 extra hours. | API test F5 | The Santiago time zone is set before any time is read. |
| 4 | An Excel column called "Unidad" was taken for the ID column (it contains "id"). | Code review while testing | Only a column named exactly "ID" is the ID. |
| 5 | A reset link opened in a tab where the app was already open did nothing. | Browser test F5 | The page also reacts when only the link's `#reset=…` part changes. |
| 6 | Quantity boxes were far too wide on phones and squeezed the item names. | Screenshot during build | Style priority fixed; boxes are 62 px wide. |
| 7 | Category tabs were 40 px tall, below the 44 px touch-target size. | Browser test N1 | Tabs are 44 px. |
| 8 | Report showed "Cebolla morada (Onion (red))". | Screenshot during build | Brackets inside the English name are dropped: "Cebolla morada (Onion red)". |

No defects are open.

## 4. How to repeat these results

```
CDO_TEST_DSN="mysql:host=127.0.0.1;dbname=cdo_test" CDO_TEST_USER=… CDO_TEST_PASSWORD=… php tests/run.php
node tests/browser.cjs      # same variables; npm install && npx playwright install chromium first
```

## 5. Recommendation

Ready for **Stage 5 — Deploy**: set up buy.mirchi.cl, upload, then run the eight real-server
checks M1–M8 from the test plan before the chefs start using the app.
