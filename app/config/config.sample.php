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

    // Absolute filesystem path to the public/uploads directory (must be
    // writable by PHP). On cPanel this is typically under public_html.
    'uploads_dir' => __DIR__ . '/../../public/uploads',

    // Base URL of the site, no trailing slash. Used for redirects/links.
    'app_url' => 'https://example.com',

    'session_name' => 'shdash_sess',
];
