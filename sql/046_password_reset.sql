-- Admin-triggered password reset for an EXISTING user (one who already
-- has a password set) -- distinct from invite_token/invite_expires_at
-- (sql/012_user_invites.sql), which is deliberately restricted to
-- brand-new signups (find_user_by_invite_token() requires password_hash
-- IS NULL). Same "generate a token, admin copies the link and shares it
-- out of band" pattern as signup, since this app has no outbound email
-- infrastructure for auth flows -- see public/password_reset.php.

ALTER TABLE users
    ADD COLUMN reset_token VARCHAR(64) NULL AFTER invite_expires_at,
    ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token,
    ADD UNIQUE KEY uq_users_reset_token (reset_token);
