<?php

require_once __DIR__ . '/db.php';

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = require __DIR__ . '/../config/config.php';
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name($config['session_name'] ?? 'shdash_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    auth_boot();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cached = null;
    if ($cached !== null && $cached['id'] === $_SESSION['user_id']) {
        return $cached;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        return null;
    }

    $cached = $user;
    return $user;
}

function attempt_login(string $email, string $password): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim(strtolower($email))]);
    $user = $stmt->fetch();

    // password_hash is NULL for a pending invite (see signup.php) -- such
    // a user simply can't log in yet, same as any other wrong-credentials case.
    if (!$user || !$user['is_active'] || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    session_login($user['id']);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

    unset($user['password_hash']);
    return $user;
}

function session_login(int $userId): void
{
    auth_boot();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

/**
 * @return ?array{id:int,name:string,email:string} the pending user, or null if the token is unknown/expired/already used
 */
function find_user_by_invite_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id, name, email FROM users
          WHERE invite_token = ? AND invite_expires_at > NOW() AND password_hash IS NULL LIMIT 1'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Sets a pending invitee's password, consumes the invite token, and logs
 * them in.
 */
function complete_signup(int $userId, string $password): void
{
    db()->prepare('UPDATE users SET password_hash = ?, invite_token = NULL, invite_expires_at = NULL WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    session_login($userId);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
}

function logout(): void
{
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== ROLE_ADMIN) {
        http_response_code(403);
        echo '403 Forbidden - admin access required.';
        exit;
    }
    return $user;
}
