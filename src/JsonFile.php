<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use JsonException;

final class JsonFile
{
    /**
     * @return array<mixed>
     */
    public static function read(string $path, int $maxBytes): array
    {
        if (!is_file($path)) {
            throw new ModuleUpdateException('JSON file not found.');
        }

        $size = filesize($path);
        if ($size === false || $size < 2 || $size > $maxBytes) {
            throw new ModuleUpdateException('JSON file refused.');
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new ModuleUpdateException('JSON file is unreadable.');
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ModuleUpdateException('Invalid JSON.');
        }

        if (!is_array($data)) {
            throw new ModuleUpdateException('Invalid JSON.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    public static function writeAtomic(string $path, array $data, int $mode = 0644): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ModuleUpdateException('Output directory not found.');
        }

        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new ModuleUpdateException('JSON is unreadable.');
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new ModuleUpdateException('Unable to write.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($tmp, $mode);
        }

        if (is_file($path) && PHP_OS_FAMILY === 'Windows' && !unlink($path)) {
            unlink($tmp);
            throw new ModuleUpdateException('Unable to write.');
        }

        if (!rename($tmp, $path)) {
            unlink($tmp);
            throw new ModuleUpdateException('Unable to write.');
        }
    }
}
