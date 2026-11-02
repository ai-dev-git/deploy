<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use SodiumException;

final class Keys
{
    /**
     * @return array{public: string, secret: string}
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $encoded = [
            'public' => sodium_bin2base64($public, SODIUM_BASE64_VARIANT_ORIGINAL),
            'secret' => sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_ORIGINAL),
        ];
        sodium_memzero($secret);
        sodium_memzero($pair);

        return $encoded;
    }

    public static function secret(string $encoded): string
    {
        $raw = self::decode($encoded);
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ModuleUpdateException('Private key is unreadable.');
        }

        return $raw;
    }

    public static function public(string $encoded): string
    {
        $raw = self::decode($encoded);
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ModuleUpdateException('Public key is unreadable.');
        }

        return $raw;
    }

    public static function readFile(string $path, bool $secret): string
    {
        if (!is_file($path)) {
            throw new ModuleUpdateException($secret ? 'Private key not found.' : 'Public key not found.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || strlen($contents) > 512) {
            throw new ModuleUpdateException($secret ? 'Private key is unreadable.' : 'Public key is unreadable.');
        }

        return $secret ? self::secret($contents) : self::public($contents);
    }

    private static function decode(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '' || strlen($encoded) > 256 || preg_match('/^[A-Za-z0-9+\\/=]+$/', $encoded) !== 1) {
            throw new ModuleUpdateException('Key is unreadable.');
        }

        try {
            return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new ModuleUpdateException('Key is unreadable.');
        }
    }
}
