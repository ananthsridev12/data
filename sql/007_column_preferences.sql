-- Per-user, per-page column visibility + order for listing tables
-- (Dashboard, Campaign leads). One row per user+page; columns_json is an
-- ordered array of {"key": "...", "visible": true|false} covering every
-- column the app currently knows about for that page.

CREATE TABLE user_column_preferences (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    page_key      VARCHAR(50) NOT NULL,
    columns_json  TEXT NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_page (user_id, page_key),
    CONSTRAINT fk_column_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
