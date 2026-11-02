<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Client;

use AiDev\ModuleUpdate\Hmac;
use AiDev\ModuleUpdate\Manifest;
use AiDev\ModuleUpdate\ModuleId;
use AiDev\ModuleUpdate\ModuleUpdateException;
use AiDev\ModuleUpdate\Version;
use JsonException;
use SodiumException;

final class UpdateClient
{
    private readonly string $baseUrl;

    private readonly string $moduleId;

    private readonly string $secret;

    private readonly string $publicKey;

    public function __construct(
        string $baseUrl,
        string $moduleId,
        string $moduleSecret,
        string $publicKey,
        private readonly CurlTransport $transport,
        private readonly int $timeout,
        private readonly int $downloadTimeout,
        private readonly int $maxPackageBytes,
    ) {
        module_update_require('sodium');
        $baseUrl = rtrim($baseUrl, '/');
        $this->assertBaseUrl($baseUrl);
        $this->baseUrl = $baseUrl;
        $this->moduleId = ModuleId::assert($moduleId);
        $this->secret = self::decodeSecret($moduleSecret);
        $publicKey = trim($publicKey);
        if ($publicKey === '' || str_contains($publicKey, 'REMPLACER')) {
            throw new ModuleUpdateException(AdminTranslator::line('Update public key is missing.'));
        }
        $this->publicKey = $publicKey;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        foreach (['base_url', 'module_id', 'module_secret', 'public_key'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '' || str_contains($config[$key], 'REMPLACER')) {
                throw new ModuleUpdateException(AdminTranslator::line('Update configuration is incomplete.'));
            }
        }

        $timeout = isset($config['timeout']) ? (int) $config['timeout'] : 30;
        $downloadTimeout = isset($config['download_timeout']) ? (int) $config['download_timeout'] : 120;
        $maxBytes = isset($config['max_package_bytes']) ? (int) $config['max_package_bytes'] : 52428800;

        return new self(
            $config['base_url'],
            $config['module_id'],
            $config['module_secret'],
            $config['public_key'],
            new CurlTransport((bool) ($config['allow_insecure_http'] ?? false)),
            max(5, min(120, $timeout)),
            max(10, min(600, $downloadTimeout)),
            max(1048576, min(209715200, $maxBytes)),
        );
    }

    public function check(): Manifest
    {
        $response = $this->transport->get(
            $this->baseUrl . '/manifest.php',
            $this->headers('manifest.php', ''),
            1048576,
            $this->timeout,
        );

        return $this->manifestFromResponse($response['status'], $response['body']);
    }

    public function download(Manifest $manifest): string
    {
        $this->assertTrusted($manifest);
        $query = 'version=' . $manifest->version;
        $url = $this->baseUrl . '/package.php?' . $query;
        $this->assertPackageUrl($url, $manifest->version);
        $temporary = tempnam(sys_get_temp_dir(), 'mupkg');
        if ($temporary === false) {
            throw new ModuleUpdateException(AdminTranslator::line('Download failed.'));
        }

        try {
            $status = $this->transport->download(
                $url,
                $this->headers('package.php', $query),
                $temporary,
                $this->maxPackageBytes,
                $this->downloadTimeout,
            );
            if ($status !== 200) {
                throw new ModuleUpdateException($this->statusMessage($status));
            }
            $this->assertPackageHash($temporary, $manifest);
        } catch (ModuleUpdateException $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        }

        return $temporary;
    }

    public function inspect(string $json): Manifest
    {
        if (strlen($json) > 1500000) {
            throw new ModuleUpdateException(AdminTranslator::line('Manifest is too large.'));
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ModuleUpdateException(AdminTranslator::line('Manifest is unreadable.'));
        }
        if (!is_array($data)) {
            throw new ModuleUpdateException(AdminTranslator::line('Manifest is unreadable.'));
        }

        $manifest = Manifest::fromArray($data);
        $this->assertTrusted($manifest);

        return $manifest;
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * @return list<string>
     */
    private function headers(string $script, string $query): array
    {
        $timestamp = (string) time();
        if (strlen($timestamp) !== 10) {
            throw new ModuleUpdateException(AdminTranslator::line('Server clock is unreadable.'));
        }
        $nonce = bin2hex(random_bytes(16));
        $signature = Hmac::sign($this->secret, 'GET', $script, $timestamp, $nonce, $query);
        $token = Hmac::token($this->moduleId, $timestamp, $nonce, $signature);

        return [
            'Authorization: ModuleUpdate ' . $token,
            'X-Module-Token: ' . $token,
            'Accept: ' . ($script === 'package.php' ? 'application/octet-stream' : 'application/json'),
        ];
    }

    private function manifestFromResponse(int $status, string $body): Manifest
    {
        if ($status !== 200) {
            throw new ModuleUpdateException($this->statusMessage($status));
        }

        return $this->inspect($body);
    }

    private function statusMessage(int $status): string
    {
        if ($status === 401) {
            return AdminTranslator::line('The update server refused access.');
        }
        if ($status === 404) {
            return AdminTranslator::line('No release for this module.');
        }
        if ($status === 429) {
            return AdminTranslator::line('Too many checks. Try again in a few minutes.');
        }

        return AdminTranslator::line('The remote release is unavailable.');
    }

    private function assertTrusted(Manifest $manifest): void
    {
        if ($manifest->moduleId !== $this->moduleId) {
            throw new ModuleUpdateException(AdminTranslator::line('The manifest does not match this module.'));
        }
        if ($manifest->packageUrl !== 'package.php?version=' . $manifest->version) {
            throw new ModuleUpdateException(AdminTranslator::line('Invalid package address.'));
        }
        if (!$manifest->signatureValid($this->publicKey)) {
            throw new ModuleUpdateException(AdminTranslator::line('The update signature is invalid.'));
        }
    }

    private function assertPackageUrl(string $url, string $version): void
    {
        Version::assert($version);
        $parts = parse_url($url);
        $base = parse_url($this->baseUrl);
        if (!is_array($parts) || !is_array($base)) {
            throw new ModuleUpdateException(AdminTranslator::line('Invalid package address.'));
        }

        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $sameHost = strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($base['host'] ?? ''));
        $samePort = (string) ($parts['port'] ?? '') === (string) ($base['port'] ?? '');
        if (!$sameHost || !$samePort || !str_ends_with($path, '/package.php') || $query !== 'version=' . $version) {
            throw new ModuleUpdateException(AdminTranslator::line('Invalid package address.'));
        }
    }

    private function assertPackageHash(string $path, Manifest $manifest): void
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || strlen($hash) !== strlen($manifest->sha256) || !hash_equals($manifest->sha256, $hash)) {
            throw new ModuleUpdateException(AdminTranslator::line('The package fingerprint does not match.'));
        }
    }

    private function assertBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ModuleUpdateException(AdminTranslator::line('Update server address refused.'));
        }
        if (!isset($parts['scheme'], $parts['host']) || str_contains($baseUrl, '\\')) {
            throw new ModuleUpdateException(AdminTranslator::line('Update server address refused.'));
        }
    }

    private static function decodeSecret(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9+\\/=]+$/', $encoded) !== 1) {
            throw new ModuleUpdateException(AdminTranslator::line('Module secret is unreadable.'));
        }

        try {
            $raw = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw new ModuleUpdateException(AdminTranslator::line('Module secret is unreadable.'));
        }
        if (strlen($raw) !== 32) {
            throw new ModuleUpdateException(AdminTranslator::line('Module secret is unreadable.'));
        }

        return $raw;
    }
}
