<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

final class Paths
{
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
        }

        return rtrim($path, '/');
    }

    public static function inside(string $root, string $path): bool
    {
        $root = self::normalize($root);
        $path = self::normalize($path);
        if ($root === '' || $path === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    public static function isDriveRoot(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]?|/)$#', $path) === 1;
    }
}
