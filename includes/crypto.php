<?php
/**
 * DCW Engage - Field-level encryption
 *
 * Bank account numbers are the one field in this app that is genuinely
 * sensitive financial PII, so they're encrypted at rest: a database dump or
 * a misconfigured backup alone doesn't expose account numbers.
 *
 * Uses OpenSSL AES-256-GCM (authenticated encryption) rather than libsodium,
 * because the sodium extension isn't enabled on every shared host, while
 * openssl effectively always is. Same 32-byte key as before, so a key made
 * with sodium_crypto_secretbox_keygen() works unchanged.
 *
 * IFSC and account holder name are NOT encrypted — neither is sensitive on
 * its own, and both need to be displayable without a decrypt round-trip.
 *
 * SETUP:
 *   1. Generate a key once (any PHP, or: openssl rand -base64 32):
 *        php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
 *   2. Put it in config.php as $config['security']['field_encryption_key']
 *      (base64 of exactly 32 bytes). Never commit it, never store it in the DB.
 *   3. Changing the key makes every previously encrypted value unreadable.
 *      There is no key versioning here.
 *
 * Stored format (raw bytes, fits the VARBINARY(512) column):
 *   1 byte version (0x01) | 12 byte IV | 16 byte GCM tag | ciphertext
 * The version byte leaves room to change algorithm later without guessing
 * which format an old row is in.
 *
 * DO NOT mix this with the earlier libsodium version on the same data: the
 * formats differ. If any rows were ever written with the sodium version,
 * they can't be read by this one (and vice versa).
 */

class Crypto {
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = "\x01";
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @return string raw bytes, ready for VARBINARY storage */
    public static function encrypt(string $plaintext): string {
        $key = self::key();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::VERSION . $iv . $tag . $ciphertext;
    }

    /** @param string $stored raw bytes as produced by encrypt() */
    public static function decrypt(string $stored): string {
        $minLength = 1 + self::IV_BYTES + self::TAG_BYTES;

        if (strlen($stored) < $minLength || $stored[0] !== self::VERSION) {
            throw new \RuntimeException('Failed to decrypt field: unrecognised or truncated data.');
        }

        $iv = substr($stored, 1, self::IV_BYTES);
        $tag = substr($stored, 1 + self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($stored, $minLength);

        $plaintext = openssl_decrypt(
            $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag
        );

        if ($plaintext === false) {
            // Wrong key, corrupted data, or tampering (GCM authenticates the
            // ciphertext). Fail loudly: silently returning garbage here could
            // mean paying the wrong account number.
            throw new \RuntimeException('Failed to decrypt field: key mismatch or corrupted ciphertext.');
        }

        return $plaintext;
    }

    private static function key(): string {
        static $key = null;

        if ($key === null) {
            if (!function_exists('openssl_encrypt')) {
                throw new \RuntimeException('The PHP openssl extension is required for field encryption.');
            }

            $config = require __DIR__ . '/config.php';
            $encoded = $config['security']['field_encryption_key'] ?? null;

            if (!$encoded) {
                throw new \RuntimeException(
                    "Missing config: security.field_encryption_key. Generate one with " .
                    "php -r \"echo base64_encode(random_bytes(32)), PHP_EOL;\" " .
                    "and add it to config.php before storing or reading any bank details."
                );
            }

            $decoded = base64_decode($encoded, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException(
                    "Invalid security.field_encryption_key: must be base64 of exactly 32 bytes."
                );
            }

            $key = $decoded;
        }

        return $key;
    }
}
