<?php
// Round-trip + tamper-safety check for SaleshandyKeyCipher.
// Usage: php tests/saleshandy_key_cipher_test.php

require_once __DIR__ . '/../app/includes/SaleshandyKeyCipher.php';

$failures = [];
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? "PASS" : "FAIL") . " -- {$label}\n";
    if (!$cond) {
        $failures[] = $label;
    }
};

$plain = 'sh_live_abc123XYZ-secret-key';
$encrypted = SaleshandyKeyCipher::encrypt($plain);
$assert($encrypted !== $plain, 'encrypted value differs from the plaintext');
$assert(SaleshandyKeyCipher::decrypt($encrypted) === $plain, 'decrypt() recovers the original key');

$encrypted2 = SaleshandyKeyCipher::encrypt($plain);
$assert($encrypted2 !== $encrypted, 'encrypting the same key twice produces different ciphertext (fresh nonce)');
$assert(SaleshandyKeyCipher::decrypt($encrypted2) === $plain, 'second encryption still decrypts correctly');

$tampered = $encrypted;
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);
$assert(SaleshandyKeyCipher::decrypt($tampered) === null, 'tampered ciphertext fails to decrypt (returns null, not garbage)');

$assert(SaleshandyKeyCipher::decrypt('') === null, 'empty string decrypts to null rather than throwing');
$assert(SaleshandyKeyCipher::decrypt('short') === null, 'too-short garbage decrypts to null rather than throwing');

if ($failures) {
    echo "\n" . count($failures) . " FAILURE(S):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
} else {
    echo "\nAll cipher checks passed.\n";
}
exit($failures ? 1 : 0);
