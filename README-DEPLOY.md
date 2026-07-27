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
18. `sql/018_campaign_vertical_service.sql`
19. `sql/019_saleshandy_pushed_at.sql`
20. `sql/020_campaign_step_notes.sql`
21. `sql/021_role_groups.sql`
22. `sql/022_email_opens.sql`
23. `sql/023_reply_sentiment.sql`
24. `sql/024_send_events.sql`
25. `sql/025_icp_segments.sql`

(If you're setting up a brand-new site, import all twenty-five in order. If
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
   block from rule 2. Visible three ways: the Dashboard shows an
   **Account Delivery Checking (Pending)** badge (hover for which
   campaign) on any lead whose account has another persona currently in
   flight; a skipped selection reports exactly which campaign(s) are
   holding it up (e.g. "2 in 'DM-DT-ESI-US-01'"); and Add Leads to
   Campaign has an **Account Delivery Checking (Pending)** filter
   (all/hide blocked leads/show only blocked leads) to preview what's
   actually assignable before you save a selection. See
   `WaveAssigner::pendingElsewhereCampaigns()`.

**Add Leads to Campaign** shows an actual preview table (company, contact,
email, title, seniority, company country, industry, vertical, service,
status badges), not just the match counts -- paginated, with the same
filters as the Dashboard plus two more specific to this screen:
**Account Delivery Checking (Pending)** (above) and **Account used elsewhere**
(all/yes/no), which is broader -- it flags a lead whose account has a
*different* persona in *any* campaign at all, resolved or not, for
finding backup contacts at companies you're already engaging with
rather than only ones currently blocked from being added.

**The "Used" badge is worded relative to whichever campaign you're
looking at.** It flags a lead that already has an assignment record in
*some* campaign (any campaign, any point in time -- not the same thing
as "Account Delivery Checking (Pending)"/"Account used elsewhere",
which are both about a *different* persona at the same company, not
this lead's own history). On Add Leads to Campaign, where there's a
specific target campaign in view, it reads **"Used in same campaign"**
if that's the campaign it's already in, or **"Used in {campaign
name}"** naming the actual different campaign otherwise -- previously
both cases just said "Used", so it wasn't obvious without hovering
whether a lead was blocked by *this* campaign or a *different* one. On
Dashboard, which has no single target campaign in view, it always
names the campaign(s) directly: "Used in {name}".

This screen requires **at least one filter** before it previews or lets
you save a selection -- an unfiltered click would otherwise scan the
whole leads table (slow, and "every lead in the system" is essentially
never the real candidate pool for one campaign), and in "all matching
leads" mode could assign every eligible lead system-wide from a single
click. Apply any filter (company, domain, title, industry, country,
etc.) to proceed.

**The Dashboard has the same requirement** -- opening it with no filter
applied no longer loads and displays the entire leads table; it shows a
prompt to filter first instead. Every filter narrows it as before, and
a `campaign_id` alone (without checking "Hide leads already used") still
counts as a real filter even though it doesn't currently narrow anything
by itself.

All seven checkbox-dropdown filters (Title, Seniority, Department, Size,
Industry, Country, **Company Country**, on both the Dashboard and Add
Leads to Campaign) have a **Select all** link next to Clear, at the
bottom of the dropdown -- it selects only the currently visible options,
so typing a search term first narrows what "Select all" applies to.
Country and Company Country are two different fields (a contact's own
country vs. their company's) -- both are independently filterable
everywhere the checkbox-dropdown filter set appears (`l.country` /
`l.company_country`).

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

The **Wave 1 groups** card on Campaign Leads shows compact status
buttons (e.g. "23 pending", "2 bounced") instead of one long always-
visible table of every account -- click a status to expand just that
group's table (Bootstrap collapse, `#waveGroup-{status}`). The Release/
Suppress actions per leader are unchanged, just inside the expanded
panel now.

The **contact list itself** (bottom of Campaign Leads) gets the same
treatment whenever no explicit Wave status filter is applied: instead of
one flat table mixing Active/Held/Suppressed, it's three compact status
buttons ("6 active", "38 held", "3 suppressed") that each expand a
capped preview (50 rows) with a "See all N (paginated) &raquo;" link
into the existing single-status filtered/paginated view for the full
list. Picking a Wave status from the filter dropdown (or a count-strip
badge) bypasses this entirely and goes straight to that familiar single
paginated table, unchanged. The "select all" checkbox in each table
header is scoped to just that table (`.selectAllInTable`, see
`assets/js/app.js`) now that more than one can be on screen at once.

A **First Pushed** column is available on Campaign Leads (Manage
columns) showing `saleshandy_pushed_at` -- when a lead was first pushed
to Saleshandy, set once by `campaign_saleshandy_push.php`
(`COALESCE`-guarded so a later re-push/re-sync never overwrites it) and
distinct from **Last Synced** (`saleshandy_synced_at`), which updates on
every push or "Refresh statuses" run and so can't answer "when did this
first go out."

**"Email Date" showing 1970-01-01** was a real bug, now fixed: Saleshandy's
`Email Sent At` field comes back as JavaScript's `Date.toString()` format
with a trailing IANA zone name in parentheses (confirmed against a live
API response, not just the docs) -- e.g.
`"Mon Jul 20 2026 20:20:43 GMT+5:30 (Asia/Kolkata)"`. PHP's `strtotime()`
silently fails on that trailing `(Asia/Kolkata)` suffix and returns
`false`, which `date('Y-m-d', false)` casts to the Unix epoch. Every
place that parsed this field (`SaleshandyClient::syncCampaign()`,
`pullNewProspects()`) now goes through one shared
`parseSaleshandyDate()` that strips the parenthetical before parsing and
returns `null` (not epoch) if parsing still fails -- so a genuinely
unparseable date now shows blank instead of a nonsense date. This also
fixed a latent bug in picking a prospect's *earliest* send time across
multiple sequence steps, which previously compared the raw date
*strings* lexicographically (unreliable for real chronological order)
instead of parsed timestamps.

**But leads already synced before that fix stayed broken** -- the normal
sync's `email_sent_at = COALESCE(email_sent_at, ?)` only fills in a
*NULL* value, so a column already holding the bad 1970-01-01 date (or a
lead pushed before `saleshandy_pushed_at` existed) never self-heals, no
matter how many times "Refresh statuses" runs afterward -- by the time
you re-sync, the narrower [last-synced, now) window has usually already
moved past the real send event anyway, so there's nothing left to
re-trigger a fix from even if the column *were* NULL. **The same
narrow-window problem also affects `delivery_status` itself, not just
dates** -- for a campaign that's been running a while, a lead can show
"Waiting" forever even though it genuinely delivered, if the regular
sync's window raced past that event before it was ever captured.
`releaseResolvedWaveLeaders()`'s unconditional pass only helps once
`delivery_status` is already correct; it has nothing to fall back on if
that value was never set right in the first place -- confirmed in
production: a campaign reported "0 lead(s) updated (0 bounced, 0
replied)" on every Refresh despite emails that had genuinely delivered.

**Backfill dates & status** (next to Refresh statuses on Campaign
Leads, admin-only) exists specifically for both of these: it re-fetches
the sequence's full 2-year history (same lookback `pullNewProspects()`
already uses, not bounded by the last sync) and fixes `email_sent_at`
(only if it's currently NULL or the literal `1970-01-01` marker),
`saleshandy_pushed_at` (only if NULL, using the earliest known send time
as a best-effort proxy -- never overwrites a real value), **and now
also `delivery_status`/`saleshandy_current_step`** -- recomputed from
the complete history using the same bounced > replied > unsubscribed >
sent priority `syncCampaign()` uses, always overwritten (not just
fill-if-empty, since the full lookback is strictly more authoritative
than whatever a narrow window last saw). A freshly-discovered bounce
still triggers the same domain-suppression side effect a regular sync
would, and `releaseResolvedWaveLeaders()` runs at the end too, so any
wave-1 leader newly confirmed delivered by this backfill releases its
held group in the same pass. Heavier than the normal sync, so it's a
separate, deliberately-triggered action -- run it whenever historical
data looks wrong, not routinely. See
`SaleshandyClient::backfillHistoricalDates()`.

**Campaign Leads was redesigned for clarity** -- it had grown to four
separate cards below the contact table (Mark Imported/Email Sent,
Delivery Status, Sync to Saleshandy, Remove/Delete), each with its own
buttons, on top of the Saleshandy sync buttons above the table -- new
users couldn't tell which button did what or why they were split into
so many boxes. Now:

- Every card has an explicit header naming what it's for (e.g.
  "Saleshandy sync -- campaign-wide, no lead selection needed") so it's
  clear at a glance whether an action applies campaign-wide or only to
  ticked rows.
- All bulk actions on checked leads (Mark Imported, Mark Email Sent,
  Delivery Status, Sync to Saleshandy, Remove/Delete) were merged into
  one card directly under the contact table, grouped into rows by
  `<hr>` dividers instead of four separate cards scattered around the
  page.
- Every button that isn't self-explanatory gets a small (&#9432;) info
  icon next to it -- hover or tap for a one-line explanation of exactly
  what that button does (`info_icon()` in `app/includes/helpers.php`,
  a Bootstrap tooltip; initialized globally in `assets/js/app.js` so any
  page can use `data-bs-toggle="tooltip"` without extra wiring).

**Follow-up pass -- collapse the rarely-used, split the rest:**

- **Overview** (click-to-filter count badges) and **Filters** (the
  dropdown form) are now two separate cards instead of one combined
  "Overview & filters" card, since they're two different things people
  reach for at different times.
- **Paste bounced emails** is now collapsed by default (Bootstrap
  collapse, click the card header to expand) -- an occasional bulk
  action, not something that needs to occupy space on every visit. The
  info icon explaining what it does stays visible in the header either
  way.
- **Sync updated details to Saleshandy** was pulled out of the bulk
  actions card into its own small always-visible row just above it
  (still requires ticking leads in the table first) -- important/
  frequently used enough that it shouldn't be hidden behind a click.
- The remaining bulk actions (Mark Imported/Email Sent, Delivery
  Status, Remove/Delete "Danger zone") are now inside a **"More actions
  on checked leads"** card, also collapsed by default and expanded via
  its header -- these are used far less often than Sync, so they're
  tucked away rather than always taking up vertical space.

No form field names, action values, or endpoints changed across either
pass -- this is a pure layout/labeling/visibility change, every button
still posts to the same place it always did.

## Campaign flow (`public/campaign_flow.php`)

A simple T1&rarr;T2&rarr;T3... visual of a campaign's real Saleshandy
sequence steps, for understanding a campaign's structure at a glance
without opening it in Saleshandy -- linked from Campaign Leads
("Campaign flow"), and reachable from Campaigns too (see below). Each
step card shows real, live-fetched data
(`SaleshandyClient::listSequenceSteps()` -- number, type, day offset,
status) plus an editable **Purpose** text box (e.g. "Pain point", "Tool
intro"). Purpose is purely our own annotation -- Saleshandy has no such
field -- stored in the `campaign_step_notes` table (`sql/020`, one row
per campaign+step, upserted on save; a blank box clears that step's note
rather than leaving a stale one). Requires the campaign to already be
linked to a Saleshandy sequence (`campaign_saleshandy_settings.php`);
shows a clear prompt to link one first otherwise, and the usual "could
not reach Saleshandy" alert if the API call itself fails.

**Campaigns** shows the saved Purpose notes inline as a per-row
accordion ("Touches" button, Bootstrap collapse) -- straight from
`campaign_step_notes`, **no Saleshandy API call**, so browsing the
campaign list is never slowed down by per-campaign live lookups. A
campaign with no saved notes yet shows a **Configure flow** link
straight to Campaign Flow instead of an empty accordion; once at least
one step has a Purpose saved there, "Configure flow" is replaced by
"Touches" here. The accordion itself is deliberately minimal (just
`T{n}: {purpose}`, no type/day/status) since those live only in
Saleshandy and this view is intentionally DB-only -- click "Edit"
inside the accordion (or "Configure flow") to see/change the full
live picture on Campaign Flow.

**Action buttons**: each campaign row keeps only its two most-used
actions as buttons (**Manage leads**, **Touches**/**Configure flow**);
**Export CSV**, **Edit**, and **Activate/Deactivate** live in a
kebab (&vellip;) dropdown menu to the right, same underlying links/forms
as before, just relocated so the primary actions aren't lost in a row of
five+ buttons.

**Live sequence status is non-blocking**: the "Sequence active/paused"
badge used to come from a `listSequences()` call made synchronously
before the page rendered, so a slow or unreachable Saleshandy held up
the whole Campaigns page. It now renders a "Checking..." placeholder
badge (`.sequence-status-badge[data-sequence-id]`) immediately, then
`assets/js/app.js` fetches `campaign_saleshandy_status.php` (a thin,
admin-only JSON wrapper around `listSequences()`, fails soft to `[]`)
after page load and patches each badge in place -- "Sequence active",
"Sequence paused", or "Status unknown" if Saleshandy couldn't be
reached. No-op if the page has no linked campaigns.

## Bulk tagging (Dashboard and Add Leads to Campaign)

Both the Dashboard and Add Leads to Campaign screens have a **Tag all N
matching leads** field next to their filters -- type a tag name (existing
tags autocomplete via a datalist) and it's added to every lead currently
matching the filter, via `public/lead_bulk_tag.php` ->
`TagRepository::addTagToLeadIds()`. This only *adds* the tag -- it never
removes a lead's existing tags, so it's safe to run repeatedly with
different filters to build up a lead's tag set incrementally. The flash
message always accounts for the whole filtered set: how many gained the
tag vs. how many already had it.

## Analytics

The **Analytics** nav item (`public/analytics.php`, all logged-in users)
has two parts:

- **Campaign outreach funnel** -- one row per campaign: Vertical, Service
  Pitched (set on the campaign itself, see below), Prospects (everyone
  assigned), Contacted (had at least the first email sent), a **Step N
  sent** column for every sequence step reached by anyone so far
  (cumulative -- "reached step 3" implies steps 1 and 2 also went out,
  since a sequence's steps fire in order; a campaign that's only reached
  step 2 simply has no Step 3 column filled in, and a 3-touch campaign
  never grows a Step 4 column at all), Replies (currently marked
  `Replied`), and First Email Date. Step counts come from
  `lead_campaign_assignments.saleshandy_current_step` (the furthest step
  Saleshandy has reported an actual send for, set by
  `SaleshandyClient::syncCampaign()`) -- local-DB only, not a live
  Saleshandy call. See `AnalyticsRepository::campaignFunnel()`.
- **Four breakdown tables, always shown together** -- By Company Country,
  By Campaign, By Vertical, By Service -- no dropdown to click through.
  Each is a single table, one row per group value, with five
  self-consistent columns: Prospects, Imported to Saleshandy, Not
  Imported, Email Sent, Email Not Sent. "Not Imported" and "Email Not
  Sent" both mean *everyone else in that row* (Imported + Not Imported
  always equals Prospects, same for Email Sent + Email Not Sent), not a
  narrower "still in the pipeline" subset -- deliberately simple so the
  numbers can be sanity-checked at a glance instead of needing to be
  cross-referenced across several tables. **"Imported to Saleshandy"
  means `lead_campaign_assignments.status IN ('exported', 'pushed')`** --
  a lead counts as imported whether it left via a live Saleshandy API
  push (`campaign_saleshandy_push.php`, status `pushed`) or a CSV
  export / the "Mark as Imported to Saleshandy" button (status
  `exported`). This must exactly match `LeadRepository::buildWhere()`'s
  `imported` filter and Campaign Leads' own "Imported" column -- they
  used to disagree (this table and Dashboard's `imported` filter only
  counted `pushed`, so a lead Campaign Leads called "Imported" could
  show as "Not Imported" here), fixed so all three now use the same
  definition. All four sections share one filter bar: campaign,
  vertical, service, industry, and two independent optional date
  ranges -- lead imported-into-app date (`leads.created_at`)
  and email-sent date (`lead_campaign_assignments.email_sent_at`). Blank/
  unknown Company Country groups as literal "NA", matching how the source
  data is usually reported. See `AnalyticsRepository::pivotByDimension()`.
- **Every number is a drill-through link** -- clicking any Prospects/
  Imported/Not Imported/Email Sent/Email Not Sent count (including a
  Grand Total) opens the Dashboard pre-filtered to exactly the leads
  that number counted, so "12 imported in Germany" is one click away
  from the actual list rather than just a count. This needed a few new
  `LeadRepository` filters that only exist to make this work (not shown
  on the Dashboard's own filter form, but valid to type into the URL by
  hand): `company_country`, `imported` (0/1), `email_sent` (0/1, distinct
  from the existing per-assignment bulk-action field of the same name
  elsewhere), and `assigned_campaign_id` (0/1/an id/`none` for "no
  campaign at all" -- a *positive* "show only leads in campaign X"
  filter, unlike the existing `campaign_id` param which only ever
  *excludes*, for the wave-safety candidate-preview screens). All three
  of `imported`/`email_sent`/`assigned_campaign_id` check only a lead's
  *latest* assignment row, matching `pivotByDimension()`'s own dedup, so
  the drill-through count is always exactly what was just clicked --
  verified end to end against the dev DB, including against a lead with
  two historical assignment rows where a naive "any row matches" check
  would have disagreed with Analytics by one. Every drill-through link
  also forces `show_suppressed=1`, since Analytics counts every lead
  including suppressed-domain ones but the Dashboard hides those by
  default -- without it the linked count would come up short.
- **Charts (optional, on top of the same data)** -- two doughnut charts
  under Campaign Summary (Imported vs. Not Imported, Email Sent vs. Not
  Sent, using the current filter's overall totals), plus a **Table /
  Chart** tab on each of the four breakdown cards that switches to a
  stacked horizontal bar chart (Imported vs. Not Imported per group
  value). Pure presentation on top of `pivotByDimension()`'s existing
  output -- no new queries. Uses Chart.js loaded from the same CDN as
  Bootstrap (`cdn.jsdelivr.net`, see `render_header()`/`render_footer()`
  in `helpers.php`) plus `public/assets/js/analytics_charts.js`; each
  section's rows are embedded as a JSON `<script>` block and a chart is
  only built the first time its tab is opened.

Since **a campaign now pitches one particular Vertical/Service** (not
just individual leads), both are set per-campaign on the **Campaigns**
page -- the **Edit** button (was "Rename") on each row, or the new-
campaign form -- via `campaigns.vertical_id` / `campaigns.service_id`,
added by `sql/018_campaign_vertical_service.sql`. **This migration must
be run before Edit will save a Vertical/Service on an existing
campaign** -- without it, the `UPDATE` fails against columns that don't
exist yet on your live database and you'll see a generic "Could not
rename campaign" error (or, if creating a new campaign, "Could not
create campaign"). This is separate from the existing per-*lead*
Vertical/Service fields (set on import or the Dashboard's lead-edit
modal), which the By Vertical/By Service breakdowns above still read
from for their grouping -- the campaign-level fields only feed the
Campaign Summary table.

**Dashboard: hand-pick specific leads and add them to a campaign
directly.** Every row now has a checkbox (header checkbox selects every
row currently on screen -- `.selectAllInTable`/`.lead-checkbox`, same
pattern as Campaign Leads), and a small "Add checked leads to
campaign..." picker sits just above the table's other bulk actions.
This is for the "I already found exactly who I want, right here" case
-- e.g. after clicking a number in Analytics (see below) or narrowing
with Dashboard's own filters -- as opposed to the filter-driven, title-
priority-aware flow on Campaigns → **Add leads to this campaign**,
which is still the better fit for a large, systematic pick.

The checkboxes live inside the results table but submit into a
separate `<form id="bulkAddCampaignForm">` via each checkbox's HTML5
`form="bulkAddCampaignForm"` attribute, rather than wrapping the whole
table in one big form -- avoids nesting them inside each row's own
Edit/Delete forms, which HTML doesn't allow. Posts to the new
`lead_bulk_campaign_add.php`, which calls the exact same
`WaveAssigner::assign()` used by Add Leads to Campaign's own "wave
auto"/"wave manual" modes (no title-priority list, so the wave-1
leader per company falls back to seniority ranking) -- so the same
domain-safety gate applies: two checked leads at the same company still
result in one leader + one held, not two simultaneous unconfirmed
sends. Skips (suppressed domain / already in a different campaign /
blocked by a pending persona elsewhere) are reported the same way as
the main Add Leads to Campaign flow.

Dashboard's filter form also now exposes **Imported to Saleshandy**
(Yes/No/All) directly -- previously this only worked as a hidden URL
param for Analytics drill-through links, not something you could pick
from the form itself.

## Reports (`public/reports.php`)

Replicates a spreadsheet report the user was previously rebuilding by hand
from Saleshandy exports. All logged-in users can view it (nav: **Reports**,
formerly "ABM Visibility" -- renamed since it's grown past just ABM
metrics); only admins see the **Fetch to update from Saleshandy** button.
Two different data sources feed it, at two different granularities:

- **Summary** (headline metrics + a 5-stage funnel: Contacts in database ->
  Contacted at least once -> Delivered (not bounced) -> Opened -> Replied),
  **Replies by outcome**, **Coverage by Vertical** (In database / Contacted
  / Not contacted / Opened / Coverage %), and **Sequence performance** (one
  row per Saleshandy-linked campaign) all read `leads` +
  `lead_campaign_assignments` -- one row per lead per campaign, so "Emails
  sent" and "Contacts" are the same number there (this table only ever
  tracks one lead's *first* send date and *furthest* step reached, not
  individual step-send events).
- **Daily sending activity**, **Weekly rhythm**, and **Steps & drop-off**
  (RAW per-step counts, pooled across every sequence -- explicitly *not*
  cumulative, unlike Analytics' "reached step N" numbers) read the newer
  `saleshandy_send_events` table (`sql/024_send_events.sql`), which *does*
  have true per-day, per-step granularity. See `ReportsRepository.php`.

The original spreadsheet's "Intent Signal Pipeline" / "Signal Skills in
Demand" section remains excluded -- it has no corresponding data anywhere
in this app or in Saleshandy.

**Email opens are now tracked** -- `sql/022_email_opens.sql` adds
`lead_campaign_assignments.open_count`/`last_opened_at`.
`SaleshandyClient::fetchSequenceActivity()` already discarded Saleshandy's
`Open Count`/`Last Opened At` fields on every fetch (confirmed present in
Saleshandy's own documented response for this same
`/analytics/consolidated-stats` endpoint); it now parses them. These are
Saleshandy's own **cumulative snapshot as of the last fetch**, not a
per-day open log -- exact for "opened at all", but Daily/Weekly's
day-attribution of an open (via the snapshot's `Last Opened At`) can be
approximate for a contact who opened earlier and again more recently.

**Replies are now categorized by outcome** -- `sql/023_reply_sentiment.sql`
adds `lead_campaign_assignments.reply_sentiment`, populated from
Saleshandy's own `Current Sentiment` field (Positive / Negative / Neutral
/ Uncategorized) on the same endpoint, only recorded when
`delivery_status = 'Replied'`. A reply that predates this feature (or
whose campaign hasn't been re-synced since) shows as "Not yet categorized"
on the Reports page until the next sync/backfill.

**Per-day, per-step send history** -- `sql/024_send_events.sql` adds
`saleshandy_send_events`, one row per (campaign, recipient, step, date
actually sent), fed by a new `SaleshandyClient::persistSendEvents()`
called from both `syncCampaign()` and `backfillHistoricalDates()` on the
exact raw activity rows `fetchSequenceActivity()` already fetched for
each -- no extra Saleshandy API calls. Idempotent (unique key on
`campaign_id, recipient_email, step_number, sent_date`), so re-fetching an
overlapping date range only ever updates existing rows.

**Because none of open_count/reply_sentiment/send_events existed before
this feature, all of it starts empty/zero regardless of real history** --
the report will under-report (or show entirely empty Daily/Weekly/Steps
sections) until each Saleshandy-linked campaign is synced or backfilled
again after deploying `sql/022`-`024`. Recommended rollout: click **Fetch
to update** on the Reports page once after deploying (it loops the same
incremental `syncCampaign()` cron already runs across every linked
campaign), or run each campaign's own "Backfill dates & status" for a
full 2-year historical pass -- needed at least once to populate
`saleshandy_send_events`, since the incremental sync alone won't reach
back far enough to fill in past history. "Contacts in database"
(Summary/Coverage) counts every non-deleted lead regardless of whether
it's ever been assigned to a campaign -- so Coverage % will read low if
you've imported more leads than you've started outreach to, which is
expected, not a bug.

## Email verification (Bad / Risky / Verified)

Saleshandy verifies each prospect's email deliverability once it's
actually been imported there -- checking before that point returns
nothing useful, so this is a **post-push** signal, not a pre-push one.
`campaign_saleshandy_push.php` already checked it silently at push time
(to skip Bad addresses and, unless "Include risky emails" is checked,
Risky ones too) -- it just was never shown anywhere.

**"Refresh statuses from Saleshandy"** on Campaign Leads now also
re-checks verification for every already-pushed lead in that campaign,
alongside its existing delivery-status pull -- one button, not two,
since both are "pull the latest from Saleshandy for leads already
imported there." See `SaleshandyClient::refreshVerificationStatus()`,
called unconditionally at the end of `syncCampaign()` (same pattern as
`releaseResolvedWaveLeaders()` -- runs even when there's no fresh
activity to sync). The cron sync (`cron_saleshandy_sync.php`) picks
this up automatically too.

**If verification never seems to update, check for an error message.**
Previously, a failed verification-status call was silently swallowed
(`continue`d past) -- indistinguishable from "nothing to check yet,"
since the flash message just never mentioned verification either way.
`refreshVerificationStatus()` now returns the last `SaleshandyApiException`
message it hit (if any) alongside the checked count, surfaced as a
second, separate red flash message on Campaign Leads ("Email
verification check failed: ...") and appended to the cron sync's log
output -- so a genuinely broken call (wrong endpoint, auth, response
shape) is now visible instead of looking identical to "just not
verified yet on Saleshandy's side."

Raw status strings from Saleshandy aren't documented anywhere
accessible, so `SaleshandyClient::classifyVerificationTier()` buckets
them by keyword (`bad`/`invalid`/`undeliverable`/... -> Bad,
`good`/`valid`/`verifi`/... -> Verified, anything else -> Risky) rather
than comparing exact strings. This tier renders as a badge
(`render_verification_badge()` in `app/includes/helpers.php`) --
available as an **Email Verification** column on Dashboard and
Campaign Leads (Manage Columns), and as a filter dropdown
(Verified/Risky/Bad/Not checked yet) on Campaign Leads. The filter's
SQL is a `REGEXP` approximation of the same keyword logic for
convenience only -- the actual push-time skip decision always goes
through the real PHP classifier, so this filter can never affect what
gets sent, only what you can find in the table.

## Role Groups (`public/role_groups.php`)

Consolidates messy free-text `leads.title` values ("VP of Engineering",
"SVP Eng Ops", "Director, Platform Engineering") into a small set of
admin-managed targeting buckets ("Engineering Leadership", "IT Ops",
...), so picking a campaign's audience becomes **Service** (what you're
pitching, already existed) + **Role Group** (who it's for, based on
your ICP) instead of retyping a fragile keyword list every time.

Same structural pattern as Vertical/Service (`sql/003_...`, `public/lists.php`):
a small admin table (`role_groups` -- `code`, `label`, `keywords`,
`is_active`), a nullable `leads.role_group_id` FK, a single-`<select>`
filter, a `ColumnPreferences` entry, and a slot in the Dashboard
lead-edit modal for manual override. Added by `sql/021_role_groups.sql`.

**Classification is keyword-based and deliberately soft.** Each group
has an ordered, comma-separated, case-insensitive keyword list; a
lead's `title` is checked against each *active* group's keywords in
`id` order, first substring match wins (`RoleGroupClassifier::classify()`,
reusing the exact matching style already proven in
`WaveAssigner::pickLeader()`'s wave-1 title-priority list). Unlike
Vertical/Service, an unmatched title never errors the import row --
it just classifies to `NULL` ("Unclassified"), since job titles are far
too varied to pre-enumerate exhaustively the way a fixed vertical/service
list can be. New leads are auto-classified on import
(`LeadImporter.php`); after adding or editing a group's keywords, use
**"Reclassify all leads now"** on the Role Groups page
(`public/lead_reclassify_roles.php`, admin-only) to re-apply the
updated rules to leads already in the system.

**Matching is substring-based, not whole-word** -- a short keyword can
match inside an unrelated title (e.g. "CTO" also matches inside
"Director", since "di**recto**r" contains "cto"). Prefer fuller phrases
("Chief Technology Officer" rather than "CTO") to avoid surprises;
`role_groups.php` shows this caveat directly on the page.

Available as a **Role Group** filter/column on Dashboard, Add Leads to
Campaign (`campaign_select_leads.php` -- the actual "pick Service +
Role Group" moment), and Campaign Leads (Manage Columns).

**"Pick from existing titles" dropdown** (both the Add-a-role-group form
and each group's Edit modal) -- a searchable checkbox list of every
distinct `title` already in the leads table
(`LeadRepository::distinctValues(db(), 'title', 1000)`, the same source
used for Dashboard's own Title filter), rendered via the existing
`render_multiselect_filter()` widget. Checking a title appends it into
that form's Keywords field (comma-separated); unchecking removes it;
titles already present in a group's keywords show pre-checked when
editing (`RoleGroupClassifier::parseKeywords()`). Lets an admin build a
keyword list by browsing real titles in the data instead of guessing
phrases blind. Each instance uses a unique widget name
(`title_picker_new` for the Add form, `title_picker_{id}` per Edit
modal) so checkbox ids never collide across the multiple pickers on one
page -- the sync logic itself lives in `assets/js/app.js`, matching any
checkbox whose name starts with `title_picker` and writing into the
`keywords` field of its own enclosing `<form>`.

## ICP Segments (`public/icp_segments.php`)

Builds on Role Groups: a named, reusable match rule for **one buyer
persona** (company country, industry, seniority, employee count,
vertical, service, and exactly one Role Group) linked to **2 or more
campaigns with a percentage split**, for A/B testing the same persona
across campaigns. A cron job (`public/icp_distribution_cron.php`)
periodically finds newly-matching, never-before-assigned leads and
distributes them across the linked campaigns according to the weights.
`sql/025_icp_segments.sql` adds `icp_segments` + `icp_campaign_links`.

**One ICP = one persona** (at most one Role Group) -- to target multiple
personas, create multiple ICPs, each with its own campaign links. Match
criteria are optional multi-value fields (company country/industry/
seniority/employee count, comma-separated same as `role_groups.keywords`,
parsed with `RoleGroupClassifier::parseKeywords()`) plus optional
single-value Vertical/Service, mapped straight onto
`LeadRepository::matchingIds()`'s existing filter keys
(`IcpRepository::toFilters()`) -- no new filtering logic, just a new way
to save and reuse a filter combination.

**Percentages must sum to exactly 100 to run.** This isn't enforced as a
hard block on activating an ICP (ordering an ICP's own creation before
its links exist would make that awkward) -- instead, an ICP whose links
don't currently sum to 100 is simply skipped by the cron every run until
fixed, shown on the page as a "Not running -- links sum to N%, needs
100%" warning badge rather than treated as an error.

**Split happens per individual lead** (weighted random, via
`IcpRepository::splitLeadIds()` -- shuffle the matching pool, then slice
by cumulative percentage boundaries, last link absorbing any rounding
remainder so every lead lands in exactly one bucket), **not per
company/domain.** This deliberately does *not* need new domain-safety
logic: the existing `WaveAssigner::filterEligibleForCampaign()`
"already elsewhere"/"pending elsewhere" checks (used by every campaign
assignment in this app already) already enforce "don't touch a second
persona at a company until the first one's status is known," *globally
across campaigns* -- so if two personas at one company land in different
buckets during a split, the second is correctly held back automatically,
exactly the same as if they'd been added to two different campaigns by
hand on two different days. Verified end-to-end: a same-company pair
split across two campaigns in one run left only one of them with any
assignment row at all; the other was cleanly skipped as "pending
elsewhere" rather than double-sent.

**Auto-push to Saleshandy is a per-ICP checkbox** (`auto_push_enabled`),
not a global setting. If checked, the cron also pushes newly-assigned
leads to their campaign's Saleshandy sequence immediately after
distributing them; if unchecked (the default), the cron only assigns --
an admin still reviews and clicks each campaign's own "Push to
Saleshandy" button manually, same as every other campaign.

**Push logic is now shared, not duplicated.** The eligibility query,
email-verification filtering, field-mapping staleness check, and
tag-grouped `pushProspects()` calls that used to live only inline in
`campaign_saleshandy_push.php` are now `SaleshandyClient::pushCampaignLeads()`
-- called both by that admin-triggered endpoint (now a thin wrapper) and
by the cron's optional auto-push. Manual push behavior is unchanged;
this was a pure extraction, verified by confirming identical flash-message
output before/after against a fake Saleshandy client.

**Cron setup** -- same shared-secret token convention as the existing
Saleshandy sync cron, reusing `$config['saleshandy']['cron_token']`:
```
wget -q -O /dev/null "https://yoursite.com/icp_distribution_cron.php?token=YOUR_CRON_TOKEN"
```
Add as a separate cPanel Cron Job entry alongside `cron_saleshandy_sync.php`
(a few-hours interval is reasonable -- there's no benefit to running it
more often than leads actually get imported).

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
     Import uses. It also **auto-releases a wave-1 leader's held group**
     the moment its Delivery Status shows Active, Replied, or Paused
     (sent without bouncing) -- equivalent to clicking "Release" on that
     leader by hand (`campaign_wave_update.php`), just automatic. Before
     this, only the bounce outcome auto-cascaded (via the domain-
     suppression path); a leader that sent cleanly left its held group
     stuck until someone manually released it, even though Delivery
     Status alone already looked resolved.
     This release check (`SaleshandyClient::releaseResolvedWaveLeaders()`)
     always re-checks every still-held leader's *already-stored* Delivery
     Status directly, not just leads touched by this run's fresh
     Saleshandy activity -- Saleshandy's activity endpoint is filtered by
     the event's own date, so a leader's send event stops reappearing in
     later, narrower-dated syncs once an earlier sync has already
     consumed it. Without this, a leader resolved by an older sync (e.g.
     from before this auto-release feature existed) would stay stuck
     forever, since there'd be no future activity left to re-trigger a
     release from -- exactly the case a "0 lead(s) updated, but leaders
     already show Active" report turned out to be. Runs from both the
     manual button and the optional cron backstop
     (`cron_saleshandy_sync.php`), every time, regardless of whether any
     new activity was found -- the flash message / cron output line
     reports how many held leads were released, if any.
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
     changed. The result message accounts for every selected lead one way
     or another -- skipped (not pushed yet / no matching Saleshandy
     contact / no enabled mapping had a non-empty value to send),
     partially updated (Saleshandy rejected some but not all fields), or
     fully updated -- so "nothing happened and no error either" shouldn't
     occur; if it does, that's a bug. Saleshandy's per-field attribute
     endpoint has been observed to reject an entire batch at once
     (including fields that succeed fine on their own) when just one
     field's value is invalid -- typically a dropdown/select field (e.g.
     Company Size, Industry, Vertical) whose value isn't one of
     Saleshandy's predefined options for that field. When a batch comes
     back 100% failed, the sync automatically retries each field
     individually so the genuinely valid fields still get saved and the
     error message names the actual offending field(s) by label instead
     of reporting an opaque "N of N failed" for everything. If a specific
     field keeps failing, check its allowed values on the Saleshandy side
     (or leave it unmapped here).

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
