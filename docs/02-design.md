# Chef Daily Order — Design

| | |
|---|---|
| Version | 1.0 (approved) |
| Status | Approved by Shankar, 2026-09-28 |
| Implements | [Requirements v1.0](01-requirements.md) |
| Stage | 2 of 5 — Requirements → **Design** → Build → Test → Deploy |

Requirement IDs (F1…, N1…) are given in brackets so every design choice can be traced back.

---

## 1. Overview

```
 Chef / Admin phone                      buy.mirchi.cl (cPanel)                    Google
┌──────────────────────┐   HTTPS    ┌──────────────────────────────┐          ┌──────────────┐
│ Web app (HTML/CSS/JS)│ ─────────► │ api/  PHP 8                  │  append  │ Google Sheet │
│  · login, order,     │  JSON +    │  · login, sessions, roles    │ ───────► │  tab Orders  │
│    items, users      │  cookie    │  · items, orders, users      │          └──────────────┘
│  · offline outbox    │ ◄───────── │  · sheet sync, e-mail        │
│  · service worker    │            │           │                  │  e-mail  ┌──────────────┐
└──────────────────────┘            │           ▼                  │ ───────► │ user inbox   │
                                    │        MySQL                 │          └──────────────┘
         GitHub  ── on push to main │                              │  daily   ┌──────────────┐
  shanky8281/chef-daily-order ─FTPS─►  cron: market prices, sync   │ ◄─────── │ ODEPA prices │
                                    └──────────────────────────────┘          └──────────────┘
```

* **One address** for everything: `https://buy.mirchi.cl`. The page and the API share the
  origin, so the login cookie works on every phone browser.
* **No build step, no frameworks.** Plain HTML, CSS and JavaScript in the browser; plain PHP 8
  with PDO on the server. Nothing to install on cPanel. [N7]
* **One vendored library:** SheetJS (`xlsx.full.min.js`) for reading and writing Excel files in
  the item manager. It is stored in the repository, not loaded from a CDN, and only downloaded
  when an admin opens import/export. [F28]

---

## 2. Screens

All screens: black background, white text, red accents and buttons [N2]; minimum touch target
44 px [N1]; item names Spanish first, English below [N3, F9]. Sketches show a phone (≈ 390 px wide).

### 2.1 Login [F1, F5, F7]
```
┌────────────────────────────┐
│        MIRCHI              │  ← red
│     Daily Order            │
│                            │
│  Username  [____________]  │
│  Password  [____________]  │
│                            │
│  [        Log in        ]  │  ← red button
│                            │
│      Forgot password?      │
└────────────────────────────┘
```
* Wrong password → "Wrong username or password" (never says which one was wrong).
* Locked → "Too many attempts. Try again in 15 minutes."
* **Forgot password?** → asks for username or e-mail → always answers "If that account exists,
  we sent a link to its e-mail." The link opens **Set new password** (password twice).

### 2.2 Order page (home) [F8–F17]
```
┌────────────────────────────┐
│ Mirchi · Daily Order  🕘 ✏️ ☰│  ← 🕘 past orders, ✏️ items (admin only), ☰ menu
│ Sunday 28 September 2026   │
│ [🔍 Search… cebolla, onion ]│
│ (✓ My list)(🥕 Verduras)(🌿…)│  ← category tabs, scroll sideways
├────────────────────────────┤
│ 🥕 VEGETABLES              │
│ Cebolla morada   [−][2,5][+]│  ← Spanish, large bold
│ Onion (red) · $950/kg   kg │  ← English small; price; unit under box
│ ────────────────────────── │
│ Tomate           [−][ 0 ][+]│
│ Tomato · $1.118/kg ▲4%  kg │
│ …                          │
├────────────────────────────┤
│ 2 items        [Review &  ]│  ← sticky bottom bar
│ $5.729         [ send ➜   ]│
└────────────────────────────┘
```
* ☰ menu: **Change password**, **Users** (admin), **Settings** (admin), **Log out**. [F3, F4, F6]
* A small grey line appears when orders are waiting to upload: "1 order waiting for signal". [F17]
* "↻ Repeat last order" button shows when the list is empty. [F16]

### 2.3 Review & send [F18, F19]
Bottom sheet with the report text, then:
`[ Send on WhatsApp ]` (green, WhatsApp colour) · `[ Copy ]` `[ Share… ]` · `Clear & start new order`.

Report format:
```
🛒 *MIRCHI — DAILY ORDER*
🧾 Order #260928-RA-1
📅 Sun, 28 Sept 2026   ⏰ 09:12
👨‍🍳 Chef: Ravi
━━━━━━━━━━━━━━━━
🥕 *VERDURAS / VEGETABLES* (2)
• Cebolla morada (Onion red) — 2,5 kg × $950 = $2.375
• Tomate (Tomato) — 3 kg × $1.118 = $3.354
   _Subtotal: $5.729_
━━━━━━━━━━━━━━━━
📦 Items: 2
💰 *TOTAL (reference): $5.729*
📈 Market prices of 27 Sept 2026 (ODEPA)
```

### 2.4 Past orders [F22]
List: date/time, chef (admins only), items, total → **View**, **Send again**, **Reuse**.
Chefs see only their own; admins see everyone's with a chef filter. Loaded from the server
(last 60 days, paged); the last 20 of your own are also kept on the phone for offline viewing.

### 2.5 Item manager (admin) [F23–F31]
```
┌────────────────────────────┐
│ ← Items           [+ Item] │
│ [🔍 Search            ]    │
│ [Categories] [Import] [Export] [Show deleted] │
├────────────────────────────┤
│ 🥕 VERDURAS / VEGETABLES   │
│ ↑↓ Cebolla morada   $950 M │  ← M = market price, ✎ = manual
│     Onion (red) · kg       │
│ ↑↓ Tomate          $1.118 M│
│ …                          │
└────────────────────────────┘
```
Tap an item → **Edit item** sheet:
Spanish name* · English name · Category ▾ · Unit ▾ (kg, g, unit, bunch, l, ml, box, pack, sack, dozen) ·
Price (CLP) · Price source (Market / Manual) · Market keywords (Market only) ·
`[Save]` `[Delete]` · "Last changed by Shankar, 28 Sep 14:02". [F24, F29, F30]

* **Delete** hides the item (restorable from *Show deleted*). [F25]
* **Categories** sheet: add, rename (Spanish + English + icon), drag or ↑↓ to reorder, remove
  (asks first if it still has items; its items must be moved or deleted first). [F26]
* **↑↓** moves an item within its category. [F27]
* **Import** → pick .xlsx or .csv → preview table: *new* (green), *changed* (yellow, old → new),
  *unchanged* (grey), *errors* (red, row not imported) → `[Import N items]`. [F28]
* **Export** → downloads `items-YYYY-MM-DD.xlsx` in the same column layout as import.

### 2.6 Users (admin) [F2, F3]
List: username, role, e-mail, active/locked, last login → tap to edit.
Edit: username, e-mail, role (Chef/Admin), active on/off, `[Send password reset e-mail]`,
`[Set temporary password]`. An admin cannot deactivate or demote themselves (so there is
always at least one admin).

### 2.7 Settings (admin)
Restaurant name, owner WhatsApp number [F19], Google Sheet ID and tab, sheet sync status
("All orders copied" / "3 rows waiting — last error: …" with `[Retry now]`).

---

## 3. Database (MySQL, utf8mb4)

```
users ─┬─< sessions
       ├─< password_resets
       ├─< orders ─< order_items >─ items >─ categories
       └─< item_changes >─ items
settings (key → value)      login_attempts (per username)      schema_version
```

| Table | Columns (key ones) | Notes |
|---|---|---|
| **users** | id, username (unique), email, password_hash, role (`admin`/`chef`), active, failed_logins, locked_until, created_at, last_login_at | bcrypt hash [N6]; lockout fields [F7] |
| **sessions** | id, token_hash (unique), user_id, created_at, expires_at, last_seen_at, user_agent | cookie holds the token; DB stores only its SHA-256 [F6] |
| **password_resets** | id, token_hash, user_id, expires_at, used_at | 1-hour, single use [F5] |
| **categories** | id, name_es, name_en, icon, sort, deleted_at | [F26] |
| **items** | id, category_id, name_es, name_en, unit, price, price_source (`market`/`manual`), market_keywords, market_price, market_prev, market_date, sort, deleted_at, updated_by, updated_at | price shown = market_price if source is market and known, else price [F29] |
| **item_changes** | id, item_id, user_id, action (`add`/`edit`/`delete`/`restore`/`import`/`move`), before_json, after_json, at | audit trail [F30] |
| **orders** | id, uid (unique), order_no, user_id, order_date, order_time, total, item_count, price_note, report_text, created_at | uid comes from the phone → no duplicates [F17, F20] |
| **order_items** | id, order_id, item_id, category_name, name_es, name_en, qty DECIMAL(12,3), unit, price, line_total, synced_at, sync_token, sync_claimed_at | names **copied** at order time so later item edits don't change history; sync fields [F21] |
| **settings** | key, value | restaurant, whatsapp, sheet_id, sheet_tab, market_date |
| **schema_version** | version | database upgrades run automatically (§8) |

---

## 4. Server API

All endpoints are under `https://buy.mirchi.cl/api/`, JSON in and out. Every request except
login/forgot/reset needs a valid session; the role is checked on the server for every call. [N6]

| Method & path | Who | Purpose | Req. |
|---|---|---|---|
| POST `auth/login` | anyone | username + password → sets session cookie | F1, F7 |
| POST `auth/logout` | user | ends session | F6 |
| GET `auth/me` | user | who am I, role, settings needed by the page | F1 |
| POST `auth/password` | user | change own password (old + new) | F4 |
| POST `auth/forgot` | anyone | send reset e-mail | F5 |
| POST `auth/reset` | anyone with link | token + new password | F5 |
| GET `catalog` | user | categories + items + prices (+ version stamp) | F8, F9, F33* |
| POST `orders` | user | save one order (idempotent by uid) | F17, F20 |
| GET `orders?mine=1&page=` | user | own past orders; admins may omit `mine` | F22 |
| GET `orders/{id}` | owner of order or admin | full order + report text | F22 |
| POST/PUT/DELETE `items…` | admin | add / edit / delete / restore / move | F24–F27 |
| POST/PUT/DELETE `categories…` | admin | add / rename / reorder / remove | F26 |
| POST `items/import/preview` | admin | rows → diff (new/changed/unchanged/errors) | F28 |
| POST `items/import/commit` | admin | apply the previewed diff | F28 |
| GET `items/history?item=` | admin | change log | F30 |
| GET/POST/PUT `users…` | admin | list / add / edit / reset | F2, F3 |
| GET/PUT `settings` | admin | restaurant, WhatsApp, sheet | F19, F21 |
| POST `sync/sheet` | admin | copy pending rows now | F21 |

\* "Changes show for everyone on next load" comes from the catalog version stamp.

---

## 5. Key flows

### 5.1 Login and session [F1, F6, F7]
1. Page calls `auth/me`. No session → login screen.
2. `auth/login`: user found, active, not locked, `password_verify` OK →
   new random 32-byte token; cookie `cdo_session` (**HttpOnly, Secure, SameSite=Lax**, 30 days);
   DB stores SHA-256 of the token. `failed_logins` reset.
3. Wrong password → `failed_logins + 1`; at 5 → `locked_until = now + 15 min`.
   Unknown usernames are counted in `login_attempts` too, so the answer is the same either way.
4. Each request refreshes `last_seen_at`; sessions unused for 30 days expire.
5. Deactivating a user or resetting their password deletes all their sessions.

### 5.2 Forgot password [F5]
`auth/forgot` → if the account exists and has an e-mail: random token, SHA-256 stored,
expires in 1 hour → e-mail with `https://buy.mirchi.cl/#reset=<token>` sent via PHP `mail()`
from the cPanel mailbox. `auth/reset` checks hash, expiry and `used_at`, sets the new password,
marks the token used and ends all sessions. Max 3 reset e-mails per account per hour.

### 5.3 Sending an order, online or offline [F17–F21]
1. Chef taps **Send on WhatsApp** → the phone builds the report, gives the order a random
   **uid** and an order number `YYMMDD-<chef code>-<n>` (e.g. `260928-RA-1`; chef code = first
   two letters of the username, n = that chef's orders that day on that phone).
2. The order goes into the phone's **outbox** (localStorage) and WhatsApp opens.
3. The page posts the outbox to `POST orders`, one by one. The server stores the order in one
   transaction; if the uid already exists it just answers "already saved". Only a confirmed
   `success` removes it from the outbox; no signal, a timeout or an expired session keeps it.
4. Retries happen on page open, when the phone comes back online, and when the page becomes
   visible again. If the session expired while offline, the order waits until the chef logs in
   again (the outbox is kept per user).
5. After replying, the server copies unsent rows to the Google Sheet (§5.4).

### 5.4 Google Sheet copy [F21]
Same method as tested earlier: rows are **claimed** with a random token, appended to the sheet
in one call, then marked `synced_at`. A failure releases the claim; a cron job every 30 minutes
retries. Nothing is appended twice. The tab and header row are created if missing. Login to
Google uses the service-account key (RS256-signed JWT) — no Composer packages.

Sheet columns: Date · Time · Order # · Chef · Category · Spanish name · English name · Qty ·
Unit · Price · Line total · Order total.

### 5.5 Market prices [F29]
Daily cron (06:30 Chile time): downloads the ODEPA wholesale bulletin for Santiago markets,
matches each *Market* item by its keywords, converts box/sack prices to the item's unit,
stores `market_price` (keeping yesterday's as `market_prev` for the ▲▼ %). If ODEPA is down
or changes format, yesterday's prices stay. *Manual* items are never touched.

### 5.6 Item import [F28]
Columns (header row required, Excel or CSV):

`Categoría (category) | Nombre (Spanish) | Name (English) | Unidad (unit) | Precio (price) | Fuente (Market/Manual) | Palabras clave (market keywords) | ID`

* Rows with an **ID** update that item; rows without ID are matched by Spanish name within the
  category, otherwise added. Unknown categories are created.
* Nothing is written until the admin confirms the preview. Every change is logged as `import`.

---

## 6. Offline behaviour [F15, F17, N4]

| What | Where it is kept on the phone |
|---|---|
| App files (HTML, CSS, JS, icon) | Service-worker cache, network first |
| Item list and prices | Last `catalog` response |
| Draft order | localStorage, per user |
| Outbox (sent, not yet uploaded) | localStorage, per user |
| Last 20 own orders | localStorage |
| Who is logged in | Last `auth/me` response (the session itself is the cookie) |

Admin screens (items, users, settings) need a connection and say so when offline.

---

## 7. Security [N6]

* HTTPS only; `.htaccess` redirects HTTP → HTTPS and sets HSTS.
* Passwords: `password_hash()` bcrypt, minimum 8 characters.
* Session token random, stored hashed; cookie HttpOnly + Secure + SameSite=Lax.
* **CSRF:** every change must be JSON with header `X-CDO: 1`; forms from other sites can't send
  that, and the API refuses requests without it.
* Every API call checks the role on the server; chefs asking for someone else's order get 404.
* All SQL through prepared statements; all text shown via `textContent` (no HTML injection).
* Secrets (database password, Google key path, mail sender) live in `api/config.php` on the
  server only — never in GitHub, never uploaded by the deploy, blocked from the web.
* The Google key file sits **outside** the web folder.
* Login and reset attempts are rate-limited (§5.1, §5.2).

---

## 8. Folder layout and deployment

```
chef-daily-order/
├── public/                 → uploaded to the buy.mirchi.cl web folder (root)
│   ├── index.html  app.js  styles.css  sw.js  manifest.webmanifest  icon.svg
│   ├── vendor/xlsx.full.min.js
│   ├── .htaccess           HTTPS, caching, security headers
│   └── api/
│       ├── index.php       single entry point, routes §4
│       ├── lib/            db.php auth.php items.php orders.php users.php sheets.php mail.php
│       ├── cron/           sync-sheet.php  market-prices.php   (command line only)
│       ├── migrations/     001_schema.sql  002_seed_items.sql …
│       ├── config.sample.php
│       └── .htaccess       only index.php reachable from the web
├── tests/                  PHP tests + browser tests (Stage 4)
├── docs/                   01-requirements.md  02-design.md  …
└── .github/workflows/
    ├── test.yml            runs the tests on every push
    └── deploy.yml          on main: upload public/ over FTPS (skips config.php)
```

* **Database set-up is automatic:** on the first request after a deploy, `schema_version` is
  compared with `migrations/` and new files run in order. The first migration creates the
  tables, the second loads the **starting item list** — recovered from the previous project:
  **102 items in 11 categories** (Vegetables, Herbs & Greens, Fruits, Dairy & Eggs,
  Meat & Poultry, Seafood, Grocery & Dry, Spices, Oils & Sauces, Beverages, Cleaning & Packing).
* **First admin:** a one-time command `php api/cron/create-admin.php Shankar you@example.com`
  run from cPanel Terminal asks for the password. No default password exists anywhere.
* **Deploy:** GitHub Action on push to `main` → tests must pass → FTPS upload of `public/`.
  Needs repository secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
* **Cron jobs (cPanel):** sheet sync every 30 min; market prices daily 06:30.

---

## 9. Decisions (approved)

| # | Decision | Proposal |
|---|---|---|
| D1 | Order number format | `260928-RA-1` (date – chef code – number) |
| D2 | Category names | Shown as **Spanish / English** like items, e.g. "Verduras / Vegetables" |
| D3 | Units list | kg, g, unit, bunch, l, ml, box, pack, sack, dozen — admins can't add new units without a code change. OK? |
| D4 | Reset e-mail sender | `no-reply@mirchi.cl` (a cPanel mailbox you create) |
| D5 | Minimum password length | 8 characters |
| D6 | Starting item list | The recovered 102 items / 11 categories (requirement F31 said ≈120 / 8 — I'll correct F31 to match) |
| D7 | Chefs' past orders | Server keeps all; page shows last 60 days with "Load more" |

## 10. Change log

| Version | Date | Change |
|---|---|---|
| 0.1 | 2026-09-28 | First draft for review |
| 1.0 | 2026-09-28 | Approved, including decisions D1–D7 |
