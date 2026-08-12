<?php

/**
 * Encrypts/decrypts a member's personal SMTP password for storage in
 * users.smtp_password (VARBINARY(512)) -- see SmtpMailer::forUser().
 * Same libsodium secretbox scheme, keyed by the same app/config/config.php
 * `encryption_key`, as SaleshandyKeyCipher -- kept as its own class rather
 * than reusing that one directly since it's named/documented specifically
 * for Saleshandy API keys.
 */
final class EmailAccountCipher
{
    public static function encrypt(string $plainPassword): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plainPassword, $nonce, self::secretBytes());
        return $nonce . $ciphertext;
    }

    /** Returns null on any decryption failure (wrong/rotated key, corrupt data) rather than throwing -- callers treat it the same as "not connected". */
    public static function decrypt(string $encrypted): ?string
    {
        if (strlen($encrypted) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($encrypted, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($encrypted, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, self::secretBytes());
        return $plain === false ? null : $plain;
    }

    private static function secretBytes(): string
    {
        static $bytes = null;
        if ($bytes !== null) {
            return $bytes;
        }

        $config = require __DIR__ . '/../config/config.php';
        $hex = trim((string) ($config['encryption_key'] ?? ''));
        if ($hex === '') {
            throw new RuntimeException(
                'encryption_key is not configured (app/config/config.php) -- required to store per-member '
                . 'SMTP passwords. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $decoded = @hex2bin($hex);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'encryption_key must be a 64-character hex string (32 random bytes) -- generate one with: '
                . 'php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        $bytes = $decoded;
        return $bytes;
    }
}
