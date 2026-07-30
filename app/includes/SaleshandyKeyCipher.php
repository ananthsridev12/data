<?php

/**
 * Encrypts/decrypts a member's personal Saleshandy API key for storage in
 * users.saleshandy_api_key (VARBINARY(512)) -- see SaleshandyClient::forUser().
 * Uses libsodium's secretbox (XSalsa20-Poly1305, built into PHP 7.2+, no
 * extra dependency), keyed by app/config/config.php's `encryption_key`.
 *
 * Format stored: 24-byte random nonce, prefixed directly onto the
 * ciphertext -- a fresh nonce per encryption is required for secretbox's
 * security guarantees, and prefixing it is the standard, simplest way to
 * keep it alongside the ciphertext without a second column.
 */
final class SaleshandyKeyCipher
{
    public static function encrypt(string $plainKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plainKey, $nonce, self::secretBytes());
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
                . 'Saleshandy API keys. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
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
