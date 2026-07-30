<?php
/**
 * Copy this file to config.php (same directory) and fill in real values.
 * config.php is gitignored -- never commit real credentials.
 */

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'your_db_name',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    // Absolute filesystem path to the "uploads" directory that ends up
    // inside your docroot (must be writable by PHP). This must be a real
    // absolute path on the server -- NOT a __DIR__-relative one -- because
    // this app/ folder and your docroot are two separate directories on
    // the server (see README-DEPLOY.md), so there's no fixed relative
    // path between them. Example for a cPanel docroot at
    // /home1/youruser/yourdomain.com:
    //   'uploads_dir' => '/home1/youruser/yourdomain.com/uploads',
    'uploads_dir' => '/absolute/path/to/your/docroot/uploads',

    // Base URL of the site, no trailing slash. Used for redirects/links.
    'app_url' => 'https://example.com',

    'session_name' => 'shdash_sess',

    'saleshandy' => [
        // LEGACY -- no longer read by the app itself. Each member now
        // connects their own personal Saleshandy account on the "Connect
        // Saleshandy" page (users.saleshandy_api_key, encrypted) instead
        // of everyone sharing one key here. This setting only still
        // matters if you're migrating an existing single-key install:
        // set it temporarily, run `php tools/backfill_saleshandy_key.php`
        // once (see that file's header comment) to copy it onto the
        // right member's account, then blank it out again.
        'api_key' => '',

        // A random string you make up (e.g. `openssl rand -hex 32`).
        // Required only if you set up the scheduled sync cron job (see
        // README-DEPLOY.md) -- it's the shared secret cron_saleshandy_sync.php
        // checks in its `token` query param, since a cron hit has no
        // logged-in session to authenticate with.
        'cron_token' => '',
    ],

    // Required: a 64-character hex string (32 random bytes) used to
    // encrypt each member's personal Saleshandy API key at rest
    // (users.saleshandy_api_key -- see app/includes/SaleshandyKeyCipher.php
    // and public/saleshandy_connect.php). Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    // Losing/changing this after members have connected their accounts
    // makes every already-saved key permanently undecryptable -- back it
    // up somewhere safe alongside your other secrets, and never commit it.
    'encryption_key' => '',
];
