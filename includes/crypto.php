<?php
/**
 * DCW Engage - Field-level encryption
 *
 * Bank account numbers are the one new field this reimbursement feature
 * introduces that's a genuinely different risk class from the rest of the
 * applicant data already in this app (real financial PII, not just contact
 * details). This encrypts that single field at rest so a DB dump or a
 * misconfigured backup doesn't hand over account numbers in plaintext.
 *
 * IFSC and account holder name are NOT encrypted — neither is sensitive on
 * its own, and both need to be searchable/displayable without a decrypt
 * round-trip. Only the account number goes through this.
 *
 * SETUP REQUIRED before this works:
 *   1. Generate a key once:  php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"
 *   2. Put it in config.php as $config['security']['field_encryption_key']
 *      (base64-encoded 32-byte key) — NOT in the database, NOT in version
 *      control. Treat it like any other production secret.
 *   3. If that key is ever rotated, every previously-encrypted value becomes
 *      undecryptable — there is no key versioning here. For a first version
 *      that's an acceptable tradeoff; flag it if you need rotation support.
 */

class Crypto {
    /** @return string raw bytes: nonce (24) + ciphertext, ready for VARBINARY storage */
    public static function encrypt(string $plaintext): string {
        $key = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return $nonce . $ciphertext;
    }

    /** @param string $stored raw bytes as produced by encrypt() */
    public static function decrypt(string $stored): string {
        $key = self::key();
        $nonce = substr($stored, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($stored, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            // Wrong key, corrupted data, or truncated storage. Fail loudly —
            // silently returning garbage here means an admin could pay the
            // wrong account number without ever knowing the decrypt failed.
            throw new \RuntimeException('Failed to decrypt field: key mismatch or corrupted ciphertext.');
        }

        return $plaintext;
    }

    private static function key(): string {
        static $key = null;

        if ($key === null) {
            $config = require __DIR__ . '/config.php';
            $encoded = $config['security']['field_encryption_key'] ?? null;

            if (!$encoded) {
                throw new \RuntimeException(
                    "Missing config: security.field_encryption_key. Generate one with " .
                    "php -r \"echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;\" " .
                    "and add it to config.php before storing or reading any bank details."
                );
            }

            $key = base64_decode($encoded, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException(
                    "Invalid security.field_encryption_key: must be a base64-encoded " .
                    SODIUM_CRYPTO_SECRETBOX_KEYBYTES . "-byte key."
                );
            }
        }

        return $key;
    }
}
