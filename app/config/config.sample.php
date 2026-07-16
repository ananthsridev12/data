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
        // Generate under Saleshandy -> Settings -> API. Required for
        // "Push to Saleshandy" / "Refresh statuses" on Campaign Leads,
        // and for the Saleshandy Field Mapping and Tags admin pages.
        // Leave blank to leave those features disabled (everything else
        // in the app works fine without it).
        'api_key' => '',

        // A random string you make up (e.g. `openssl rand -hex 32`).
        // Required only if you set up the scheduled sync cron job (see
        // README-DEPLOY.md) -- it's the shared secret cron_saleshandy_sync.php
        // checks in its `token` query param, since a cron hit has no
        // logged-in session to authenticate with.
        'cron_token' => '',
    ],
];
