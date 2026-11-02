<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

use AiDev\ModuleUpdate\Paths;

final class NonceStore
{
    public function __construct(private readonly string $root)
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0750, true) && !is_dir($this->root)) {
            throw new HttpException(503, 'unavailable', 'nonce-store');
        }
    }

    public function consume(string $moduleId, string $nonce, int $timestamp): bool
    {
        $hash = hash('sha256', $moduleId . "\0" . $nonce);
        $currentHour = gmdate('YmdH', $timestamp);
        $previousHour = gmdate('YmdH', $timestamp - 3600);
        foreach ([$currentHour, $previousHour] as $hour) {
            $existing = $this->hourDirectory($hour) . DIRECTORY_SEPARATOR . $hash;
            if (is_file($existing)) {
                return false;
            }
        }

        $directory = $this->hourDirectory($currentHour);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new HttpException(503, 'unavailable', 'nonce-store');
        }

        $handle = @fopen($directory . DIRECTORY_SEPARATOR . $hash, 'x');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, (string) $timestamp);
        fclose($handle);
        $this->purgeOldHours();

        return true;
    }

    private function hourDirectory(string $hour): string
    {
        if (preg_match('/^[0-9]{10}$/', $hour) !== 1) {
            throw new HttpException(503, 'unavailable', 'nonce-store');
        }

        return $this->root . DIRECTORY_SEPARATOR . $hour;
    }

    private function purgeOldHours(): void
    {
        $root = realpath($this->root);
        if ($root === false) {
            return;
        }

        for ($age = 3; $age <= 8; $age++) {
            $hour = gmdate('YmdH', time() - $age * 3600);
            $directory = $root . DIRECTORY_SEPARATOR . $hour;
            if (!is_dir($directory)) {
                continue;
            }
            $real = realpath($directory);
            if ($real === false || !Paths::inside($root, $real) || $real === $root) {
                continue;
            }
            $this->deleteTree($real);
        }
    }

    private function deleteTree(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteTree($path);
                continue;
            }
            unlink($path);
        }
        rmdir($directory);
    }
}
