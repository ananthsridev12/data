# Lead Management Dashboard - Deployment (shared cPanel hosting)

A plain PHP 8 + MySQL app. No Composer and no build step are required.

Steps 1-2 and 4-6 below are one-time, manual setup. File sync for every
change after that is handled by cPanel's **Git Version Control** feature
-- see "Deploying updates via cPanel Git Version Control" at the bottom
-- so there's no File Manager/FTP upload step for routine updates.

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
5. `sql/005_bounce_types.sql`
6. `sql/006_lead_soft_delete.sql`
7. `sql/007_column_preferences.sql`
8. `sql/008_custom_fields.sql`
9. `sql/009_delivery_status.sql`
10. `sql/010_relax_required_fields.sql`
11. `sql/011_saleshandy_integration.sql`
12. `sql/012_user_invites.sql`
13. `sql/013_pull_from_saleshandy.sql`
14. `sql/014_saleshandy_sync_tracking.sql`
15. `sql/015_email_verification.sql`
16. `sql/016_bounce_suppression_settings.sql`
17. `sql/017_saleshandy_prospect_sync.sql`

(If you're setting up a brand-new site, import all seventeen in order. If
you already have a running site from before these were added, just
import whichever numbered files you're missing -- they're additive, so
re-running 001/002 against an existing database will error on already-
existing tables/rows. **A 500 error right after deploying new code is
usually this** -- the code expects tables/columns from a migration that
hasn't been run against your live database yet.)

## 3. Upload the files

This app is split into two folders that must **not** both end up inside
your public web root:

- `app/` -- PHP includes, config, and the vendored SimpleXLSX library.
  Deployed **outside** the docroot, e.g. `/home1/cpaneluser/app/` (a
  sibling of the docroot, not inside it).
- `public/` -- everything web-visible. Its **contents** go directly into
  the docroot (e.g. `/home1/cpaneluser/yourdomain.com/`), not the
  `public/` folder itself.

The initial upload can be done via cPanel's File Manager or FTP, matching
the layout above -- but every deploy after that is handled by cPanel's
**Git Version Control** feature instead (see the bottom of this doc),
which copies files into exactly this layout automatically via
`.cpanel.yml`.

If your hosting plan only gives you one web-facing directory (some
restrictive addon-domain setups), you can instead put `app/` inside the
docroot as `app/` and add a `.htaccess` with `Require all denied` in
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
2. Log in with the seeded admin account and change the password via
   **Change password** in the top nav.
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

## Campaign assignment rules (one campaign per lead, bounce suppression, one pending send per account)

Three rules govern whether a lead can be added to a campaign, enforced
everywhere a new assignment is created (Add leads to campaign, the
CSV/history import's auto-assign-to-campaign option, and the domain
suppression flows below) -- `WaveAssigner::filterEligibleForCampaign()` is
the shared implementation:

1. **A lead's email can only ever belong to one campaign.** Once a lead is
   assigned anywhere, it's excluded from being added to a *different*
   campaign (re-adding it to the campaign it's already in is a harmless
   no-op). This means once a persona has been pushed to Saleshandy for
   Campaign A, it cannot later be added to Campaign B -- but a *different*,
   not-yet-assigned persona at the same account remains free to be added to
   one campaign of its own, as long as the account isn't suppressed (below).
   This doesn't retroactively touch leads that were already in more than
   one campaign before this rule existed -- it only guards new assignments
   going forward. If you need to move a lead to a different campaign, use
   **Remove checked from this campaign** on the Campaign Leads page first
   (see below) -- that frees it up for the new assignment.
2. **If any persona at a domain bounces, the whole domain is blocked from
   every future campaign** -- not just the one that bounced -- via the
   existing `suppressed_domains` global list. Which bounce types actually
   trigger this is configurable under **Bounce Settings** (admin nav): each
   bounce type (Hard Bounce, Soft Bounce, Spam Complaint, Invalid Address,
   Other, and the Saleshandy/delivery-status bounce values) can be toggled
   independently, and all default to "suppresses" so nothing changes until
   you explicitly opt one out (e.g. treating a soft bounce as non-fatal). A
   bounce type left unchecked there still gets recorded on that one lead's
   assignment -- it just doesn't block the rest of the account. See
   `WaveAssigner::bounceTypeSuppresses()`.
3. **An account can only have one unresolved send in flight at a time,
   across all campaigns.** If persona A at an account is already
   assigned/pushed in Campaign 1 and its outcome isn't known yet, persona
   B at the same account can't be added to Campaign 2 until A's send is
   confirmed -- this closes the gap where rule 2's domain suppression
   only kicks in *after* a bounce is confirmed, so two contacts at the
   same account could otherwise go out simultaneously in different
   campaigns and both bounce. "Confirmed" means wave-1 explicitly marked
   **Delivered**, or a Saleshandy-synced Delivery Status of
   Active/Replied/Paused (sent, and not currently flagged as a bounce) --
   at that point B becomes assignable again. A confirmed bounce doesn't
   go through this rule at all -- it's already the stronger, permanent
   block from rule 2. Visible three ways: the Dashboard shows a **Pending
   elsewhere** badge (hover for which campaign) on any lead whose account
   has another persona currently in flight; a skipped selection reports
   exactly which campaign(s) are holding it up (e.g. "2 in
   'DM-DT-ESI-US-01'"); and Add Leads to Campaign has a **Pending
   elsewhere** filter (all/hide blocked leads/show only blocked leads) to
   preview what's actually assignable before you save a selection. See
   `WaveAssigner::pendingElsewhereCampaigns()`.

**Add Leads to Campaign** shows an actual preview table (company, contact,
email, title, seniority, status badges), not just the match counts --
paginated, with the same filters as the Dashboard plus two more specific
to this screen: **Pending elsewhere** (above) and **Account used
elsewhere** (all/yes/no), which is broader -- it flags a lead whose
account has a *different* persona in *any* campaign at all, resolved or
not, for finding backup contacts at companies you're already engaging
with rather than only ones currently blocked from being added.

All six checkbox-dropdown filters (Title, Seniority, Department, Size,
Industry, Country, on both the Dashboard and Add Leads to Campaign) have
a **Select all** link next to Clear, at the bottom of the dropdown --
it selects only the currently visible options, so typing a search term
first narrows what "Select all" applies to.

On the **Campaign Leads** page, the checkbox list at the bottom has two
extra actions alongside Mark Imported/Email Sent/Delivery Status:
**Remove checked from this campaign** (un-assigns the lead, keyed off
`status != 'pushed'` so a lead already pushed to Saleshandy is skipped
rather than silently detached from the campaign it's actually being
emailed from -- it also skips a lead that's still a pending wave-1
leader with a held group under it, to avoid orphaning those held leads)
and, admin-only, **Delete checked leads** (a full, site-wide soft-delete
of the underlying lead -- same as the Dashboard's Delete, undoable from
Deleted Leads -- also skipping anything already pushed).

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

## Direct Saleshandy push/pull (optional)

Beyond the CSV export above, the app can push leads straight into a
Saleshandy sequence step and pull delivery/reply/bounce activity back,
via `app/includes/SaleshandyClient.php`. This is entirely optional --
everything else in the app works without it.

**Setup:**
1. Generate an API key in Saleshandy under **Settings -> API**, and paste
   it into `app/config/config.php` as `saleshandy.api_key`.
2. (Optional, for the scheduled sync) make up a random string (e.g.
   `openssl rand -hex 32`) and set it as `saleshandy.cron_token`.
3. Visit **Saleshandy Field Mapping** (admin nav) and choose which of
   your fields get sent on push, and what Saleshandy field label each
   maps to -- everything is opt-in; nothing is sent unless you enable and
   label it here. Use "Fetch field list from Saleshandy" to see the
   exact label spelling Saleshandy expects -- **always pick from the
   dropdown, never type a label by hand**: Saleshandy's push and sync
   endpoints don't error on a label that doesn't match a real field,
   they silently drop that one field and send everything else, which
   looks like "only some fields are updating" with no error to explain
   why. Re-fetch and check this page any time after renaming/removing a
   field in Saleshandy -- an enabled mapping whose label no longer
   matches is called out in red, right on this page and in the push
   result, instead of failing silently. This is also the place to fix a
   *wrong* label (e.g. a field mapped to a Saleshandy field that turns
   out to hold something else, like company type/industry, when you
   meant a different one, like the company's website URL): fetch the
   list, find the field that's actually right for the value, and re-pick
   it from the dropdown.
4. Visit **Tags** (admin nav) and "Sync from Saleshandy" to pull in your
   existing Saleshandy tags, so leads can be tagged from a known list
   during import or editing (new tags typed in here are created on
   Saleshandy's side automatically the first time they're used in a push).
5. On **Campaigns**, click **Configure** next to a campaign and pick a
   Saleshandy sequence -- the step is picked automatically (the
   sequence's first step, so new leads always start at the beginning);
   change it under "Change step" only if a campaign genuinely needs to
   enter partway through. Refresh/Import work as soon as just the
   sequence is linked; only Push needs a step.
6. On that campaign's **Campaign Leads** page:
   - **Push to Saleshandy** sends only leads currently eligible under the
     existing wave-1 domain-safety gate, and skips bad emails (verified via
     Saleshandy's email verification feature) automatically -- risky ones
     are skipped too unless "Include risky emails" is checked on the push
     form. Verification results are checked once per lead and cached
     (`leads.email_verification_status`), so re-pushing doesn't re-spend
     verification credits on an address already checked. If the
     verification check itself fails, the push proceeds unfiltered rather
     than blocking.
   - **Refresh statuses from Saleshandy** pulls delivery/reply/bounce
     activity back into each already-assigned lead's Delivery Status, and
     cascades a bounce into the same domain-suppression logic Bounce
     Import uses.
   - **Import from Saleshandy** is the reverse direction -- for a
     sequence that already has prospects enrolled in it (added directly
     in Saleshandy, or from before this integration existed), this
     creates the matching lead and campaign assignment here if they
     don't exist yet. A lead created this way only has an email and a
     name (Saleshandy's activity data doesn't include company, title,
     etc.), which is why Company Name is optional.
   - **Sync updated details to Saleshandy** (only shown once a sequence is
     linked) is for a lead you've edited here *after* it was already
     pushed -- typically by re-importing a CSV/Excel file that upserts by
     email (see "Notes on the Saleshandy CSV export" and the Import
     screen; there's no in-app "Edit lead" form for core fields). It
     pushes the checked leads' current field values straight to their
     existing Saleshandy **contact** record via `POST
     /prospects/{id}/attribute` -- contact level, not the sequence-import
     endpoint -- so it can't re-enroll anyone or disturb their step
     position. Only leads already pushed are touched; Saleshandy's
     prospect id is looked up by email once and cached on
     `leads.saleshandy_prospect_id`. This is manual only (no cron
     backstop) -- run it after a re-import whenever a pushed lead's data
     changed.

The **Campaign Leads** page also has a count strip (total/active/held/
suppressed/email sent/imported) right under the page title, each number
a clickable link that filters the table below to just that slice -- plus
a filter form (wave status, email sent, imported to Saleshandy, delivery
status) for narrowing further or combining filters. Filters carry through
pagination and through the bulk-action buttons' redirect.

**Important caveat:** `SaleshandyClient.php`'s endpoint paths were built
from Saleshandy's documented request/response shapes rather than a full
API spec, since the reference used to build this only exposed request/
response shapes, not literal endpoint URLs. Do a small, low-stakes test
(one campaign, a couple of leads) before relying on push/pull broadly,
and check that file's docblock if a call fails outright rather than
returning a normal API error.

**Optional scheduled sync:** in cPanel's **Cron Jobs**, add a job that
hits (e.g. every few hours):
```
wget -q -O /dev/null "https://yoursite.com/cron_saleshandy_sync.php?token=YOUR_CRON_TOKEN"
```
This runs both directions -- status sync (like "Refresh statuses") and
pulling in new prospects (like "Import from Saleshandy") -- for every
campaign that has a Saleshandy sequence linked, as an automatic backstop
alongside the two manual buttons.

**Note on "Import from Saleshandy" coverage:** Saleshandy's API has no
endpoint that lists every prospect enrolled in a sequence -- only
prospects with at least one send/activity event. A prospect still queued
behind an earlier step (not yet emailed) won't show up here yet, so the
count pulled in can legitimately be lower than Saleshandy's own prospect
count for the sequence until those prospects are actually reached.

## Deploying updates via cPanel Git Version Control

This is the actual deploy path for this app -- steps 1, 2, 4, and 5 above
(database, schema, `config.php`, upload folder permissions) are still
one-time manual steps, but every code change after that is deployed
through cPanel's **Git™ Version Control** feature, which clones this repo
server-side and runs `.cpanel.yml` on deploy:

```yaml
deployment:
  tasks:
    - export DOCROOT=/home1/de2shrnx/data.easi7.in
    - export APPDIR=/home1/de2shrnx/app
    - /bin/mkdir -p "$APPDIR"
    - /bin/cp -R public/. "$DOCROOT"/
    - /bin/cp -R app/. "$APPDIR"/
```

`config.php` and `public/uploads/` are gitignored, so they simply aren't
part of the repo checkout `.cpanel.yml` copies from -- they're never
touched or deleted by a deploy.

**One-time setup** (cPanel -> **Git™ Version Control** -> **Create**):
point it at this GitHub repo and the `claude/sales-data-dashboard-php-vlpgvd`
branch, with a repository path outside both the docroot and `app/` (e.g.
`/home1/de2shrnx/repositories/data`) -- this clone is just a staging
copy `.cpanel.yml` deploys *from*, not what's actually served.

**Every time there's a new commit to deploy** (cPanel -> **Git Version
Control** -> **Manage** for this repo -> **Pull or Deploy** tab):
1. **Update from Remote** -- pulls the latest commits from GitHub into
   cPanel's clone. This does *not* touch the live site by itself.
2. **Deploy HEAD Commit** -- runs the `.cpanel.yml` tasks above, copying
   files into the docroot and `app/`. This is the step that actually
   updates the live site.

Both steps are manual and must be repeated after every push -- there's
no webhook wired up for automatic deploy-on-push. If the live site seems
to be running old code after a push, this is almost always why: check
whether both steps were run since the last commit you expect to see live.
