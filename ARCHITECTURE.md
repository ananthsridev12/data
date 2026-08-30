# Architecture & Project Conventions

How this codebase is organized, and the conventions that hold it together.
Written so the same pattern can be reused for a different app — the specifics
(leads, Saleshandy, etc.) are this app's business domain, but the
folder/deploy/coding conventions below are domain-agnostic.

## Philosophy

- **No framework, no build step, no Composer/npm.** PHP 8 + MySQL only.
  Bootstrap 5 and Chart.js load from a CDN in the page `<head>`, nothing is
  bundled.
- **No router.** Each URL *is* a file. `public/campaigns.php` serves
  `/campaigns.php`, `public/campaign_leads.php` serves `/campaign_leads.php`.
  There's no dispatch layer to configure.
- **Server-rendered, POST-redirect-GET.** Every page is plain PHP emitting
  HTML directly (no templating engine). A mutating action is a `<form
  method="post">` back to the same file (or a dedicated `*_update.php`
  file); after handling it, the script does `header('Location: ...'); exit;`
  and never falls through to also render HTML in the same request.
- **Business logic lives in static-method classes, not in the page files.**
  A page file (`public/campaigns.php`) parses input, calls a repository
  class (`CampaignRepository::create(...)`), and renders the result. It
  does not embed SQL inline for anything beyond a page-local one-off query.

## Folder structure

```
app/
  config/
    config.php           -- real secrets (DB creds, API keys). GITIGNORED.
    config.sample.php     -- template committed to git; copy to config.php
                             on every fresh environment/deploy.
    constants.php          -- app-wide constants (roles, enums, etc.)
  includes/
    db.php                 -- db(): PDO singleton
    auth.php                -- session/login/invite-token helpers
    csrf.php                 -- csrf_field()/csrf_verify()
    helpers.php               -- render_header()/render_footer(), flash_*(),
                                  nav_links(), small shared view helpers
    Scope.php                  -- per-request tenant/role context (see below)
    ScopeFilter.php              -- applies Scope to a WHERE clause
    <Noun>Repository.php          -- one class per aggregate/table, all
                                      static methods (LeadRepository,
                                      CampaignRepository-equivalent, etc.)
    <ThirdParty>Client.php          -- one class per external API integration
  vendor/                            -- manually-vendored third-party PHP
                                        libraries (no Composer) -- only add
                                        a library here if it's small,
                                        single-purpose, and license-checked
public/
  bootstrap.php            -- required first by every page; wires up
                              config/db/auth/csrf/helpers, calls auth_boot()
  <feature>.php              -- one file per page/action, flat namespace
                                (no subfolders under public/ -- a long
                                filename beats a nested route)
  assets/
    css/app.css
    js/app.js, <feature>_charts.js, ...
sql/
  001_schema.sql            -- the very first migration: full base schema
  002_....sql
  ...
  0NN_<short_description>.sql  -- every schema change, sequentially
                                   numbered, one migration = one file,
                                   NEVER edited after being committed
tests/
  <thing>_test.php           -- standalone scripts, not a test framework
tools/
  <one_off_script>.php         -- CLI-only maintenance scripts (data
                                   backfills, key rotation, etc.) --
                                   never web-accessible, run by hand via
                                   `php tools/whatever.php`
.cpanel.yml                     -- cPanel Git Version Control deploy hook
.gitignore
README-DEPLOY.md                -- full deploy/ops runbook (migrations to
                                    run, cron setup, troubleshooting)
APP_BRIEF.md                    -- what the app does, for a human/AI
                                    picking up the project cold
ARCHITECTURE.md                 -- this file
```

### Why `app/` and `public/` are split

`app/` is **never** web-accessible. Only `public/` is the web server's
document root. This is enforced at deploy time, not just by convention —
see `.cpanel.yml` below: `app/` gets copied to a directory that sits
*outside* the docroot entirely. So even if `.htaccess`/web-server config
were ever misconfigured, secrets in `app/config/config.php` and all the
business-logic classes still can't be fetched directly by URL.

Every file in `public/` starts with:

```php
require_once __DIR__ . '/bootstrap.php';
```

and `bootstrap.php` itself does:

```php
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/includes/helpers.php';
auth_boot();
```

The `__DIR__ . '/../app/...'` relative path is what makes the two-folder
split work — it assumes `app/` and `public/` are **sibling directories**,
which is exactly what both the git repo layout and the deploy step produce.

## Multi-tenancy & access control (if your app needs it)

Not every app needs this, but if yours has "companies own their own data,
different roles see different slices" — this is the pattern:

- **`Scope`** (`app/includes/Scope.php`) — an immutable value object built
  once per request from the logged-in user: `companyId`, `userId`, `role`,
  `teamId`. Built via `Scope::fromUser($db, $user)`, never constructed by
  hand elsewhere. No "unscoped" default — a code path that doesn't have a
  real `Scope` yet fails loudly (a type error) rather than silently
  running an unfiltered, cross-tenant query.
- **`ScopeFilter::apply()`** — appends `WHERE {alias}.company_id = ?` to a
  clauses/params array. Called at the top of every repository method that
  touches a company-scoped table.
- **`ScopeFilter::applyOwnerScope()`** — a second, optional layer for
  row-level ownership within a company (e.g. Admin sees everything in the
  company, a Team Lead sees their team's rows, a Member sees only their
  own).
- Every repository method that builds a query takes `Scope $scope` as a
  required parameter — not optional, not read from a global. This is what
  makes "did I forget to scope this query" a compile-time-visible problem
  instead of a silent data leak.

## Database & migrations

- Plain `.sql` files in `sql/`, numbered sequentially: `001_schema.sql`,
  `002_...`, `003_...`. No migration framework/tool — they're applied by
  hand (`mysql -u user -p dbname < sql/0NN_....sql`) or via phpMyAdmin, in
  order, once per environment.
- **A migration file is never edited after it's committed and applied
  anywhere.** If you got the column wrong, write a new migration that
  fixes it — never rewrite history that a deployed environment may have
  already run.
- Every migration file opens with a comment explaining *why*, not just
  *what* — since there's no ORM/ActiveRecord layer to infer intent from,
  the migration file itself is the only record of the reasoning.
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` is set on the single shared
  connection (`db()` in `app/includes/db.php`) — a bad query throws
  instead of silently returning `false`.
- All queries go through prepared statements with bound parameters —
  never string-interpolated user input into SQL.

## Config & secrets

- `app/config/config.sample.php` is committed — a template with every
  required key present but no real values.
- `app/config/config.php` is the real file with real credentials/API keys
  — **gitignored**, never committed, recreated by hand on every fresh
  environment (copy the sample, fill in real values).
- `app/config/constants.php` holds app-wide constants that aren't secrets
  (role names, enum-like values) — this one *is* committed, since it's
  code, not config.

## Auth & forms

- Session-based login (`app/includes/auth.php`), not token/JWT — this is
  a server-rendered app, not an API.
- New-user signup and password reset both use the same pattern: an admin
  generates a random token stored on the `users` row with an expiry, gets
  a shareable link containing that token, sends it out of band (there's no
  outbound email sending for auth — copy/paste the link instead). The
  token is single-use, consumed the moment it's redeemed.
- Every mutating `<form>` includes `<?= csrf_field() ?>`, and every POST
  handler calls `csrf_verify()` as its first line, before touching any
  `$_POST` data.
- Flash messages (`flash_set('success'|'danger', $message)` /
  `flash_take()`) carry a one-time message across the redirect after a
  POST — stored in the session, read and cleared on the next page render.

## Testing

No test framework (no PHPUnit) — plain standalone PHP scripts in `tests/`,
run directly: `php tests/some_thing_test.php`. The convention each one
follows:

```php
$db = db();
$db->beginTransaction();
try {
    // ... set up fixture data, call the code under test, assert ...
    // $assert = a small local closure that echoes PASS/FAIL and tracks failures
} finally {
    $db->rollBack();   // every test cleans up after itself, always
}
exit($failures ? 1 : 0);
```

Every test wraps its work in a transaction and rolls it back at the end —
tests never leave fixture data behind, and never depend on a specific
starting DB state (each one creates its own company/users/records from
scratch). Run the whole suite with a simple loop:

```bash
for f in tests/*_test.php; do php "$f"; done
```

## Deployment

**Shared cPanel hosting, deployed via cPanel's "Git Version Control"
feature** — not a CI/CD pipeline, not Docker. `.cpanel.yml` defines what
happens on each deploy:

```yaml
deployment:
  tasks:
    - export DOCROOT=/home1/xxxxx/yourdomain.com
    - export APPDIR=/home1/xxxxx/app
    - /bin/mkdir -p "$APPDIR"
    - /bin/cp -R public/. "$DOCROOT"/
    - /bin/cp -R app/. "$APPDIR"/
```

`public/` is copied into the actual web-served docroot; `app/` is copied
to a **separate directory outside the docroot** — this is what makes the
"never web-accessible" property in the folder-structure section above
actually true in production, not just a convention.

Deploy flow in cPanel: **Git Version Control → your repo → Update from
Remote (pulls the branch) → Deploy HEAD Commit** (runs the tasks above).
`config.php` is *not* part of the repo, so it survives every deploy
untouched — but must be created by hand the very first time a new
environment is set up.

Any new `sql/0NN_*.sql` file needs to be run by hand against the
production DB after deploying the code that depends on it — deploy does
not run migrations automatically.

### Cron jobs

Background/scheduled work runs as plain PHP scripts under `public/` hit by
a cPanel cron job via `wget`:

```
*/20 * * * *  wget -q -O /dev/null "https://yourapp.com/some_cron.php?token=SECRET"
```

- Auth is a shared-secret token compared with `hash_equals()` — not a
  logged-in session (cron has no cookies).
- **Round-robin, not "process everything":** a cron endpoint touching
  many records at once risks the PHP execution time limit on shared
  hosting. So instead of looping every campaign/record in one request,
  each cron hit picks **one** item (whichever has gone longest without
  being processed) and does just that one. Successive cron ticks
  naturally rotate through everything. This is the single most important
  pattern to copy if the new app also runs on shared hosting with an
  execution time limit.

## Git workflow

- Feature work happens on a long-lived feature branch per project (not
  one branch per PR) — commits accumulate there and get deployed directly
  by pulling that branch in cPanel.
- Commit messages explain **why**, not just what changed — the codebase
  has no separate design-doc trail, so a commit message and the
  in-code comments it adds are the only record of a decision later.
- Migration files, `README-DEPLOY.md`'s migration checklist, and the
  actual deployed DB state are kept in lock-step manually — there's no
  automated migration-tracking table, so discipline here matters more
  than in a framework with built-in migration tooling.

## Code-level conventions worth copying

- **One class per table/aggregate, all static methods** (`LeadRepository`,
  `CampaignRepository`, etc.) — no DI container, no service layer, methods
  take `PDO $db` and (when scoped) `Scope $scope` as explicit parameters.
  Easy to trace, easy to test in isolation (see Testing above).
- **Comments explain WHY, not WHAT.** A hidden constraint, a workaround
  for a specific bug, a subtle invariant — not a restatement of the code.
  Identifiers are named clearly enough that "what" rarely needs a comment.
- **A page's own filter/state lives in a single `$filters` array**, built
  once near the top from `$_GET`/`$_POST`, then threaded through to
  whichever repository method needs it — not read ad hoc from
  superglobals scattered through the file.
- **Drill-through consistency:** when the same number is computed two
  different ways in two different places (e.g. a dashboard total and a
  detail-page filter), the query fragments are written to match exactly
  and cross-referenced in comments — so a link from one to the other
  always reproduces the exact same result set, and a future change to one
  doesn't silently drift from the other.
