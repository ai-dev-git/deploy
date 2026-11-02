<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use SodiumException;

final class Signer
{
    public function __construct(private string $secretKey)
    {
        if (strlen($this->secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ModuleUpdateException('Private key is unreadable.');
        }
    }

    public static function fromEncoded(string $encoded): self
    {
        return new self(Keys::secret($encoded));
    }

    public static function fromFile(string $path): self
    {
        return new self(Keys::readFile($path, true));
    }

    public function sign(string $payload): string
    {
        $signature = sodium_crypto_sign_detached($payload, $this->secretKey);

        return sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function __destruct()
    {
        try {
            sodium_memzero($this->secretKey);
        } catch (SodiumException) {
            $this->secretKey = '';
        }
    }
}
