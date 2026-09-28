# Chef Daily Order — Requirements Specification

| | |
|---|---|
| Version | 1.0 (approved) |
| Status | Approved by Shankar, 2026-09-28 |
| Stage | 1 of 5 — Requirements → Design → Build → Test → Deploy |

## 1. Purpose

A mobile web app at **https://buy.mirchi.cl** where chefs log in, enter the day's shopping
quantities, and send the priced order to the owner on WhatsApp. Every order is stored in a
MySQL database and copied to the owner's Google Sheet. Admins manage users and the item list.

## 2. Users and roles

| Role | Can do |
|---|---|
| **Chef** | Log in, create and send orders, see **only their own** past orders, change own password |
| **Admin** | Everything a chef can do, plus manage users, manage items and categories, see all orders |

The first admin user is **Shankar**.

## 3. Functional requirements

### 3.1 Login and users

| ID | Requirement |
|---|---|
| F1 | The first screen is a login page (username and password). Nothing else is visible until the user logs in. |
| F2 | Many users, each with their own username, password, email and role (Admin or Chef). |
| F3 | Admins can add users, edit them, deactivate them and reset their passwords. |
| F4 | Users can change their own password. |
| F5 | **Forgotten password:** "Forgot password?" sends a reset link to the user's email. The link works once and expires after 1 hour. |
| F6 | Users stay logged in for 30 days on their device, with a Log out button. |
| F7 | After 5 wrong passwords in a row, that username is locked for 15 minutes. |

### 3.2 Items (order page)

| ID | Requirement |
|---|---|
| F8 | Items are grouped by category, with category tabs to jump between them. |
| F9 | Each item shows the **Spanish name large and bold**, the **English name smaller underneath**, then unit and reference price in CLP. |
| F10 | Search by Spanish or English name, ignoring accents. Only matching items are shown. |
| F11 | Text only, no photos. |

### 3.3 Ordering

| ID | Requirement |
|---|---|
| F12 | Quantities are entered with − / + buttons or by typing. Decimals are allowed (2,5 or 2.5). |
| F13 | Line totals, item count and order total are always visible. |
| F14 | A "My list" filter shows only the items that have a quantity. |
| F15 | The draft order is saved automatically on the device. |
| F16 | "Repeat last order" loads the user's previous order. |
| F17 | **Offline:** once logged in, a chef can fill in and send an order without signal. It is stored on the phone and uploaded automatically when back online. Nothing is lost and nothing is saved twice. |

### 3.4 Sending and records

| ID | Requirement |
|---|---|
| F18 | "Review & send" shows the report: order number, date and time, chef, items per category with subtotals, total, and where the prices come from. Items are written as **Spanish (English)**. |
| F19 | "Send on WhatsApp" opens WhatsApp with the report filled in, addressed to the owner's number (set by an admin). Copy and share are also available. |
| F20 | Each sent order is saved in MySQL with the user who sent it. |
| F21 | Each order is copied to the **existing Google Sheet** (`1LSLSQgAOYBJdhAkgYRG8xZFo6bi4Gpg-po1u7MKKaP0`), tab "Orders", one row per item. Columns: Date, Time, Order #, Chef, Category, Spanish name, English name, Qty, Unit, Price, Line total, Order total. Failed copies are retried automatically. |
| F22 | **Past orders:** chefs see their own, admins see everyone's. Each can be viewed, resent on WhatsApp, or reused as a new order. |

### 3.5 Item management (admins only)

| ID | Requirement |
|---|---|
| F23 | An **Items** button (pencil icon in the top bar) opens the item manager. Chefs do not see it. |
| F24 | Add, edit and delete items. Fields: Spanish name (required), English name, category, unit, price, price source. |
| F25 | Deleting hides an item without erasing it: past orders keep it, and an admin can restore it. |
| F26 | Add, rename, reorder and remove categories. The app asks for confirmation before removing a category that still has items. |
| F27 | Move items up or down within a category. |
| F28 | **Bulk import** from Excel or CSV, showing a preview of what will be added or changed before saving. Admins can also export the current list to Excel. |
| F29 | Price source per item: **Market**, updated daily from ODEPA (Santiago wholesale markets), or **Manual**, set by an admin. |
| F30 | Every item change records who made it and when. |
| F31 | The starting list is the previous item list (about 120 items in 8 categories), with Spanish and English names. |

## 4. Non-functional requirements

| ID | Requirement |
|---|---|
| N1 | Mobile-first, with large buttons usable with wet hands. Also works on a computer. |
| N2 | Colours: black background, white and red text. |
| N3 | Item names are shown in Spanish (main) with English underneath. All other text is in English. |
| N4 | Can be installed on the phone ("Add to Home screen"). |
| N5 | The page loads in under 2 seconds on 4G. |
| N6 | **Security:** HTTPS only; passwords hashed with bcrypt; no passwords or keys in GitHub; every action is checked on the server, so a chef can't reach admin functions. |
| N7 | No monthly cost beyond the existing cPanel hosting. |

## 5. Architecture and constraints

| Part | Where |
|---|---|
| Code and change history | GitHub, repository `shanky8281/chef-daily-order` |
| Web app, server (PHP 8) and database (MySQL) | cPanel, `buy.mirchi.cl` |
| Deploy | A GitHub Action uploads each approved change on `main` to buy.mirchi.cl over FTPS |
| Owner's view | Google Sheet, written by the server through a Google service account |
| Password-reset emails | Sent from a cPanel mailbox, e.g. `no-reply@mirchi.cl` |
| Market prices | Fetched daily from ODEPA by a cPanel cron job |

## 6. Out of scope for version 1

Item photos, suppliers, stock levels, an owner dashboard (the Google Sheet is used instead),
several restaurants, languages other than English and Spanish names.

## 7. Change log

| Version | Date | Change |
|---|---|---|
| 0.1 | 2026-09-28 | First draft |
| 0.2 | 2026-09-28 | Login and users; item management; Spanish-first item names |
| 0.3 | 2026-09-28 | Everything on buy.mirchi.cl (cPanel); open questions answered |
| 1.0 | 2026-09-28 | Approved |
