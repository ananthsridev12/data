-- Seeds the first admin login. Run once, after 001_schema.sql, via phpMyAdmin.
--
-- Default login:
--   email:    admin@example.com
--   password: ChangeMe123!
--
-- IMPORTANT: log in immediately after import and change this password
-- (or update the hash below before importing) -- do not leave the default
-- password in place on a live site.
--
-- To generate a new hash yourself, run locally:
--   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
-- and paste the result in place of the hash below.

INSERT INTO users (name, email, password_hash, role, is_active)
VALUES (
    'Admin',
    'admin@example.com',
    '$2y$12$u27fLt9gHI2wsqjgf61rjuepRFSVdPiUv0F3a/dCt6kxNEHPqaTwu',
    'admin',
    1
);
