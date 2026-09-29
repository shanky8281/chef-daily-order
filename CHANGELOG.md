# Changelog

All notable changes to Chef Daily Order. Versions follow **MAJOR.MINOR.PATCH**:

| Part | Goes up when… | Example |
|---|---|---|
| MAJOR | a change needs something done on the server or by users (new setup step, data moved) | 2.0.0 |
| MINOR | a new feature that works without any setup | 1.1.0 |
| PATCH | a fix or small visual change | 1.0.1 |

The number lives in `VERSION` and is shown at the bottom of the app's ☰ menu; a test checks they match.
Each release is tagged in GitHub (`v1.0.0`, …) so any version can be looked at or restored.

## [1.0.0] — 2026-09-29

First release, live at **https://buy.mirchi.cl**. Built in five approved stages:
[requirements](docs/01-requirements.md) → [design](docs/02-design.md) → build →
[test](docs/04-test-report.md) → [deploy](docs/05-deploy-guide.md). Overview: [solution document](docs/06-solution.md).

### Features
- **Login** with personal usernames and passwords; lock after 5 wrong tries; 30-day sessions;
  "Forgot password?" by e-mail; change own password.
- **Two roles**: chefs order and see their own orders; admins also manage users, items and settings.
- **Order page**: Spanish item name first, English below; search in either language; category tabs;
  − / + and decimal quantities; running totals; "My list"; draft kept on the phone; repeat last order.
- **Send on WhatsApp** with a report in *Spanish (English)*; copy and share.
- **Works offline**: orders wait on the phone and upload once when the signal returns.
- **Records**: every order in MySQL (cPanel) and copied to the Google Sheet, one row per item.
- **Item manager**: add, edit, delete/restore, reorder, categories, change history,
  Excel/CSV import with preview, Excel export.
- **Daily market prices** from ODEPA (Santiago wholesale markets).
- **Mirchi branding**: logo on the login page and in the header; black background, white and red text.
- **Automatic deploy**: every change on `main` is tested (219 checks) and then uploaded.

### Built during go-live (included in 1.0.0)
- Mirchi logo added ([#5](https://github.com/shanky8281/chef-daily-order/pull/5)).
- Fixed: first copy to the Google Sheet failed — header row written to a one-cell range
  ([#6](https://github.com/shanky8281/chef-daily-order/pull/6), test report defect 9).
- Deployment guide: correct web folder and cPanel's default FTP folder
  ([#4](https://github.com/shanky8281/chef-daily-order/pull/4)); server self-check `check.php`
  ([#3](https://github.com/shanky8281/chef-daily-order/pull/3)).

### Stages
| Stage | Pull request |
|---|---|
| Build | [#1](https://github.com/shanky8281/chef-daily-order/pull/1) |
| Test | [#2](https://github.com/shanky8281/chef-daily-order/pull/2) |
| Deploy | [#3](https://github.com/shanky8281/chef-daily-order/pull/3), [#4](https://github.com/shanky8281/chef-daily-order/pull/4) |
