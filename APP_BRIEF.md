# Lead Management & Outreach Dashboard

A PHP 8 + MySQL web app for managing a sales/ABM lead database and running
cold email outreach campaigns through [Saleshandy](https://www.saleshandy.com),
with reporting on top. No framework, no build step — plain PHP pages under
`public/`, shared logic under `app/includes/`.

## What it does

- **Import & manage leads.** Bulk import from Excel/CSV with column
  mapping, admin-defined custom fields, and lookup-table fields (Vertical,
  Service, Industry, Role Group) for consistent categorization.
- **Campaigns.** Group leads into campaigns, each optionally linked to a
  Saleshandy sequence. A "wave" assignment system prevents sending to two
  people at the same company/domain at once, and enforces one campaign per
  lead ever.
- **Domain suppression.** If any contact at a domain bounces, the whole
  domain is blocked from all future campaigns.
- **Saleshandy integration.** Push leads into a sequence, pull
  delivery/bounce/reply/open activity back, refresh email verification
  status (Bad/Risky/Verified), and categorize replies by outcome —
  all via Saleshandy's API (`app/includes/SaleshandyClient.php`).
- **Accounts view.** Company/domain-grouped view of leads with persona
  drill-down.
- **Reports** (`public/reports.php`). Live-queried dashboards: a
  database-to-reply funnel, coverage by vertical, per-sequence
  performance, replies by outcome, and daily/weekly/per-step send
  activity.
- **Analytics** (`public/analytics.php`). Campaign funnel and
  country/vertical/service/campaign breakdowns with drill-through links
  back to the Dashboard.
- **Admin tooling.** User invites, per-user column preferences, tag
  management, bounce-type settings, custom fields, deleted-leads recovery.

## Tech stack

- PHP 8, MySQL/MariaDB, no Composer/build step.
- Bootstrap 5 + Chart.js (via CDN) for UI/charts.
- Deployed to shared cPanel hosting via cPanel's Git Version Control
  feature — see `README-DEPLOY.md` for the full deployment/ops guide.

## Key files

- `public/` — every page (one file per screen), web-facing.
- `app/includes/` — shared classes: `SaleshandyClient` (API integration),
  `LeadRepository`, `WaveAssigner` (assignment/suppression rules),
  `ReportsRepository`, `AnalyticsRepository`, `LeadImporter`, etc.
- `sql/` — numbered migrations, applied in order.
- `README-DEPLOY.md` — the detailed deployment + feature reference doc.
