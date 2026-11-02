<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

use AiDev\ModuleUpdate\JsonFile;
use AiDev\ModuleUpdate\ModuleId;
use AiDev\ModuleUpdate\ModuleUpdateException;

final class SecretStore
{
    public function __construct(private readonly string $storage)
    {
    }

    public function has(string $moduleId): bool
    {
        return $this->raw($moduleId) !== null;
    }

    public function raw(string $moduleId): ?string
    {
        if (!ModuleId::isValid($moduleId)) {
            return null;
        }

        $modules = $this->read()['modules'] ?? null;
        if (!is_array($modules) || !isset($modules[$moduleId]) || !is_array($modules[$moduleId])) {
            return null;
        }

        $encoded = $modules[$moduleId]['secret'] ?? null;
        if (!is_string($encoded)) {
            return null;
        }

        $raw = $this->decodeSecret($encoded);
        if ($raw === null || strlen($raw) !== 32) {
            return null;
        }

        return $raw;
    }

    public function dummy(): string
    {
        $path = $this->storage . DIRECTORY_SEPARATOR . 'dummy.key';
        if (!is_file($path)) {
            $this->ensureStorage();
            $raw = random_bytes(32);
            if (file_put_contents($path, sodium_bin2base64($raw, SODIUM_BASE64_VARIANT_ORIGINAL), LOCK_EX) === false) {
                throw new HttpException(503, 'unavailable', 'dummy');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($path, 0640);
            }

            return $raw;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new HttpException(503, 'unavailable', 'dummy');
        }
        $raw = $this->decodeSecret($contents);
        if ($raw === null || strlen($raw) !== 32) {
            throw new HttpException(503, 'unavailable', 'dummy');
        }

        return $raw;
    }

    public function register(string $moduleId): string
    {
        $moduleId = ModuleId::assert($moduleId);
        $lock = $this->lock();
        try {
            $data = $this->read();
            if (!isset($data['modules']) || !is_array($data['modules'])) {
                $data['modules'] = [];
            }
            if (isset($data['modules'][$moduleId])) {
                throw new ModuleUpdateException('This module is already registered.');
            }

            $secret = random_bytes(32);
            $data['modules'][$moduleId] = [
                'secret' => sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_ORIGINAL),
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $this->write($data);

            return sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_ORIGINAL);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<mixed>
     */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['modules' => []];
        }

        try {
            return JsonFile::read($path, 1048576);
        } catch (ModuleUpdateException) {
            throw new HttpException(503, 'unavailable', 'secrets');
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function write(array $data): void
    {
        $this->ensureStorage();
        JsonFile::writeAtomic($this->path(), $data, 0640);
    }

    private function path(): string
    {
        return $this->storage . DIRECTORY_SEPARATOR . 'secrets.json';
    }

    /**
     * @return resource
     */
    private function lock()
    {
        $this->ensureStorage();
        $handle = fopen($this->storage . DIRECTORY_SEPARATOR . 'secrets.lock', 'c');
        if ($handle === false) {
            throw new ModuleUpdateException('Unable to lock the registry.');
        }
        flock($handle, LOCK_EX);

        return $handle;
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storage) && !mkdir($this->storage, 0750, true) && !is_dir($this->storage)) {
            throw new ModuleUpdateException('Storage not found.');
        }
    }

    private function decodeSecret(string $encoded): ?string
    {
        $encoded = trim($encoded);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9+\\/=]+$/', $encoded) !== 1) {
            return null;
        }

        try {
            return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            return null;
        }
    }
}
