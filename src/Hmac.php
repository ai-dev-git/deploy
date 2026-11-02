<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

final class Hmac
{
    public const PREFIX = 'MU1';

    public static function sign(
        string $secret,
        string $method,
        string $script,
        string $timestamp,
        string $nonce,
        string $canonicalQuery,
    ): string {
        $payload = implode("\n", [
            self::PREFIX,
            strtoupper($method),
            $script,
            $timestamp,
            $nonce,
            hash('sha256', ''),
            $canonicalQuery,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    public static function token(string $moduleId, string $timestamp, string $nonce, string $signature): string
    {
        return self::PREFIX . '.' . $moduleId . '.' . $timestamp . '.' . $nonce . '.' . $signature;
    }

    /**
     * @return array{module_id: string, timestamp: string, nonce: string, signature: string}|null
     */
    public static function parseToken(string $token): ?array
    {
        if (strlen($token) > 512) {
            return null;
        }

        $pattern = '/^MU1\.([a-z0-9][a-z0-9_-]{0,62}[a-z0-9])\.([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/';
        if (preg_match($pattern, $token, $matches) !== 1) {
            return null;
        }

        if (!ModuleId::isValid($matches[1])) {
            return null;
        }

        return [
            'module_id' => $matches[1],
            'timestamp' => $matches[2],
            'nonce' => $matches[3],
            'signature' => $matches[4],
        ];
    }
}
