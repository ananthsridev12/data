# Lead Management Dashboard - Deployment (shared cPanel hosting)

A plain PHP 8 + MySQL app. No SSH, no Composer, and no build step are
required -- everything can be uploaded via cPanel's File Manager or FTP.

Steps 1-2 and 4-6 below are one-time, manual setup. Once done, file
sync for future changes can be automated -- see "Automated deploys via
GitHub Actions" at the bottom.

## 1. Create the database

In cPanel, under **MySQL Databases**:
1. Create a new database (e.g. `cpaneluser_leads`).
2. Create a new database user with a strong password.
3. Add that user to the database with **All Privileges**.

## 2. Import the schema

Open **phpMyAdmin**, select the new database, go to the **Import** tab,
and import, in order:
1. `sql/001_schema.sql`
2. `sql/002_seed_admin_user.sql` (creates the first admin login --
   `admin@example.com` / `ChangeMe123!`. **Change this password immediately
   after your first login**, or edit the password hash in that file before
   importing -- see the comment at the top of the file for how to generate
   a new one.)
3. `sql/003_verticals_services_email_tracking.sql`
4. `sql/004_wave_suppression.sql`

(If you're setting up a brand-new site, import all four in order. If
you already have a running site from before these were added, just
import whichever of 003/004 you're missing -- they're additive, so
re-running 001/002 against an existing database will error on already-
existing tables/rows.)

## 3. Upload the files

This app is split into two folders that must **not** both end up inside
your public web root:

- `app/` -- PHP includes, config, and the vendored SimpleXLSX library.
  Upload this to a directory **outside** `public_html`, e.g.
  `/home/cpaneluser/app/` (a sibling of `public_html`, not inside it).
- `public/` -- everything web-visible. Upload the **contents** of this
  folder directly into `public_html/` (or a subdirectory/addon domain
  docroot of your choosing).

If your hosting plan only gives you one web-facing directory (some
restrictive addon-domain setups), you can instead upload `app/` inside
`public_html/app/` and add a `.htaccess` with `Require all denied` in
that folder to block direct web access -- but the sibling-directory
layout above is safer and is what this app assumes by default (all PHP
files use `__DIR__ . '/../app/...'` relative paths, so `app/` just needs
to be one level above wherever `public/`'s contents end up).

## 4. Configure the app

Copy `app/config/config.sample.php` to `app/config/config.php` (same
directory) on the server, and fill in:
- Your database host (usually `localhost`), database name, DB username,
  and DB password from step 1.
- `uploads_dir`: the absolute filesystem path to wherever you uploaded
  `public/uploads` (find this in cPanel File Manager -- right-click the
  folder -> "Copy" shows the full path, or check the path shown when
  editing files in that folder).
- `app_url`: your site's URL.

`config.php` is gitignored and must never be committed -- it holds your
real database credentials.

## 5. File permissions

- `public/uploads/` and `public/uploads/cache/` must be writable by PHP.
  `755` is normally sufficient on shared hosting; avoid `777`.
- Everything else can stay at the default `644`/`755`.

## 6. PHP settings

In cPanel's **MultiPHP Manager**, select **PHP 8.1 or newer** for the
domain. In **MultiPHP INI Editor**, raise these as far as your plan
allows (the app is designed to tolerate low shared-hosting defaults via
chunked import processing, but higher limits mean faster imports):
- `upload_max_filesize` / `post_max_size` (at least 25M to match the
  app's per-file upload cap in `public/import.php`)
- `memory_limit`
- `max_execution_time`

Required PHP extensions (all enabled by default on virtually every
cPanel PHP build): `pdo_mysql`, `mbstring`, `zip`, `xml`, `fileinfo`,
`json`.

## 7. HTTPS and security headers

`public/.htaccess` already forces HTTPS redirects and blocks directory
listing. Make sure an SSL certificate is issued for the domain (cPanel's
**AutoSSL** handles this automatically on most hosts) before relying on
that redirect.

## 8. Smoke test

1. Visit your site -- you should land on the login page.
2. Log in with the seeded admin account and change the password
   (via **Users** -> deactivate/recreate, or add a "change password" flow
   later -- there isn't a self-service one yet, so for now recreate the
   admin user with a new password and deactivate the old one).
3. Go to **Import**, upload a small sample file (a handful of rows),
   confirm the column mapping, and watch it process on **Import
   History**.
4. Go to **Dashboard**, filter/search, and confirm the imported rows
   appear.
5. Create a campaign under **Campaigns**, assign a few leads to it from
   the dashboard, and export the CSV to confirm the file downloads
   correctly.

Only once this all works should you import your real provider files.

## 9. Backups

Use cPanel's built-in **Backup Wizard** (or your host's equivalent) to
schedule regular database + file backups, since this setup has no
SSH/cron access for scripted backups.

## Notes on the Saleshandy CSV export

`public/leads_export_csv.php` (via `app/includes/CsvExporter.php` and
`app/config/saleshandy_export_map.php`) exports plain, human-readable
column headers (Email, First Name, Company Name, etc.). Saleshandy's own
"Import Prospects" wizard lets you map each CSV column to a Saleshandy
system field (or a custom field) at import time, so this works without
needing to match Saleshandy's internal field names exactly. If you'd
prefer the export to come in pre-matched to Saleshandy's own column
names, edit `app/config/saleshandy_export_map.php` to match the header
row from Saleshandy's own blank import template (Prospects -> Import ->
download template).

A future phase (not built yet) can push leads directly into a Saleshandy
sequence via its API instead of a CSV round-trip -- the `campaigns`
table already has a `saleshandy_sequence_id` column and
`lead_campaign_assignments.status` already supports a `pushed` value to
support that without a schema change.

## Automated deploys via GitHub Actions

`.github/workflows/deploy.yml` rsyncs this repo to the server over SSH
on every push to `claude/sales-data-dashboard-php-vlpgvd` (or via the
"Run workflow" button for a manual deploy). It handles file sync only --
steps 1, 2, 4, and 5 above (database, schema, `config.php`, upload folder
permissions) are still one-time manual steps; the workflow deliberately
excludes `app/config/config.php` and `public/uploads/` from sync so it
never overwrites your real credentials or deletes real uploaded/import
data.

Before the first automated run, add these under this repo's **Settings
-> Secrets and variables -> Actions -> New repository secret**:

| Secret | Value |
|---|---|
| `DEPLOY_SSH_PRIVATE_KEY` | A private SSH key (generate a dedicated deploy key rather than reusing a personal one). Add the matching **public** key in cPanel under **SSH Access -> Manage SSH Keys -> Import Key**, then click **Authorize**. |
| `DEPLOY_SSH_HOST` | The SSH hostname/IP for the account (from cPanel's SSH Access page, or ask your host). |
| `DEPLOY_SSH_USER` | The cPanel account username (from the deploy path `/home1/de2shrnx/...`, this is `de2shrnx` unless your host differs). |
| `DEPLOY_SSH_PORT` | Optional -- only needed if your host uses a non-standard SSH port. Defaults to `22`. |

If your host doesn't offer SSH access on your plan, use the manual
File Manager/FTP steps above instead and skip this section.
