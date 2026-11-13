<?php

declare(strict_types=1);

namespace FancySign\Seal;

use InvalidArgumentException;

/**
 * The SES-phase seal: an Ed25519 detached signature from ext-sodium (bundled
 * with PHP). Standard library only. Deterministic, so a shared vector can pin
 * the seal bytes in a Node port (`crypto.sign(null, ...)`).
 *
 * Keys are base64. The key id is the first 16 hex characters of the SHA-256
 * of the raw public key, so a verifier picks the right published key.
 */
final class Ed25519
{
    /** @return array{secret: string, public: string, key_id: string} */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        return ['secret' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => $public, 'key_id' => self::keyId($public)];
    }

    public static function keyId(string $publicB64): string
    {
        return substr(hash('sha256', self::decode($publicB64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public')), 0, 16);
    }

    public static function publicFromSecret(string $secretB64): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::decode($secretB64, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret')));
    }

    public static function sign(string $message, string $secretB64): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, self::decode($secretB64, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret')));
    }

    public static function verify(string $message, string $signatureB64, string $publicB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $pk = base64_decode($publicB64, true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $pk);
    }

    private static function decode(string $b64, int $length, string $what): string
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== $length) {
            throw new InvalidArgumentException("The seal {$what} key must be {$length} bytes, base64.");
        }

        return $raw;
    }
}
