# Chef Daily Order

Mobile web app for chefs at **https://buy.mirchi.cl**: log in, enter the day's shopping
quantities, send the priced order on WhatsApp. Orders are stored in MySQL (cPanel) and
copied to a Google Sheet. Admins manage users and the item list.

Built stage by stage (waterfall):

| Stage | Document | Status |
|---|---|---|
| 1. Requirements | [docs/01-requirements.md](docs/01-requirements.md) | Approved |
| 2. Design | [docs/02-design.md](docs/02-design.md) | Approved (v1.1: build changes in §11) |
| 3. Build | this repository | In review |
| 4. Test | — | |
| 5. Deploy | — | |

## What's where

| Folder | Contents |
|---|---|
| `public/` | Everything uploaded to buy.mirchi.cl. Plain HTML/CSS/JS, no build step. |
| `public/api/` | The server: PHP 8, one entry point (`index.php`), libraries in `lib/`, database changes in `migrations/` (run automatically), command-line jobs in `cron/`. |
| `tests/` | `run.php` + `*_test.php` (API tests), `browser.cjs` (browser tests), `router.php` (local server). |
| `docs/` | Requirements and design. |

## Run it on your computer

Needs PHP 8.1+ with `pdo_mysql`, and MySQL or MariaDB.

1. Create an empty database, copy `public/api/config.sample.php` to `public/api/config.php`,
   fill in the database details and add `'cookie_secure' => false` (no HTTPS locally).
2. Create the first admin: `php public/api/cron/create-admin.php Shankar you@example.com`
3. Start: `php -S localhost:8000 -t public tests/router.php` and open http://localhost:8000

The tables and the starting item list (102 items, 11 categories) are created on the first visit.

## Tests

Tests use their own **empty** database, whose name must contain "test". They wipe it on every run.

```
CDO_TEST_DSN="mysql:host=127.0.0.1;dbname=cdo_test" CDO_TEST_USER=root CDO_TEST_PASSWORD=... php tests/run.php
npm install && npx playwright install chromium && node tests/browser.cjs     # same variables
```

GitHub runs both on every push (`.github/workflows/test.yml`). On `main`, the deploy workflow
runs them first and uploads `public/` only if they pass.
