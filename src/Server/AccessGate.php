<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

use AiDev\ModuleUpdate\Hmac;

final class AccessGate
{
    public function __construct(
        private readonly SecretStore $secrets,
        private readonly NonceStore $nonces,
        private readonly int $tolerance,
    ) {
    }

    /**
     * @param array{module_id: string, timestamp: string, nonce: string, signature: string} $token
     */
    public function assert(array $token, string $script, string $query): void
    {
        $timestamp = (int) $token['timestamp'];
        if ($token['timestamp'] !== (string) $timestamp || abs(time() - $timestamp) > $this->tolerance) {
            $this->compareAgainstDummy($token, $script, $query);
            throw new HttpException(401, 'unauthorized', 'timestamp');
        }

        $secret = $this->secrets->raw($token['module_id']);
        $key = $secret ?? $this->secrets->dummy();
        $expected = Hmac::sign($key, 'GET', $script, $token['timestamp'], $token['nonce'], $query);
        $matches = strlen($token['signature']) === strlen($expected) && hash_equals($expected, $token['signature']);
        if (!$matches || $secret === null) {
            throw new HttpException(401, 'unauthorized', $secret === null ? 'unknown-module' : 'hmac');
        }

        if (!$this->nonces->consume($token['module_id'], $token['nonce'], $timestamp)) {
            throw new HttpException(401, 'unauthorized', 'nonce');
        }
    }

    /**
     * @param array{module_id: string, timestamp: string, nonce: string, signature: string} $token
     */
    private function compareAgainstDummy(array $token, string $script, string $query): void
    {
        $expected = Hmac::sign($this->secrets->dummy(), 'GET', $script, $token['timestamp'], $token['nonce'], $query);
        if (strlen($token['signature']) === strlen($expected)) {
            hash_equals($expected, $token['signature']);
        }
    }
}
