-- Invite-based signup: an admin adds a user with just name/email/role (no
-- password), and the app generates a one-time signup link the admin
-- copies and shares themselves (see public/signup.php). password_hash is
-- NULL for a pending invite -- attempt_login() already guards against
-- that, so a pending user simply can't log in until they've set one.
-- Also enables self-service password change (public/change_password.php).

ALTER TABLE users
    MODIFY COLUMN password_hash VARCHAR(255) NULL,
    ADD COLUMN invite_token VARCHAR(64) NULL AFTER password_hash,
    ADD COLUMN invite_expires_at DATETIME NULL AFTER invite_token,
    ADD UNIQUE KEY uq_users_invite_token (invite_token);
