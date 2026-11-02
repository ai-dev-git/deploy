<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use ZipArchive;

final class ArchiveGuard
{
    public const MAX_ENTRIES = 8000;

    public const MAX_UNCOMPRESSED = 209715200;

    public static function assertSafe(ZipArchive $zip): void
    {
        $count = $zip->numFiles;
        if ($count < 1 || $count > self::MAX_ENTRIES) {
            throw new ModuleUpdateException('Archive refused.');
        }

        $total = 0;
        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name'])) {
                throw new ModuleUpdateException('Archive refused.');
            }

            self::assertName($stat['name']);
            self::assertNotSymlink($stat);
            $size = (int) ($stat['size'] ?? 0);
            if ($size < 0) {
                throw new ModuleUpdateException('Archive refused.');
            }
            $total += $size;
            if ($total > self::MAX_UNCOMPRESSED) {
                throw new ModuleUpdateException('Archive is too large.');
            }
        }
    }

    public static function assertExtractedTree(string $staging): void
    {
        $root = realpath($staging);
        if ($root === false) {
            throw new ModuleUpdateException('Extraction refused.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new ModuleUpdateException('Symbolic link refused in the archive.');
            }
            $real = $item->getRealPath();
            if ($real === false || !Paths::inside($root, $real)) {
                throw new ModuleUpdateException('Extraction refused.');
            }
        }
    }

    public static function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 240 || str_contains($name, "\0") || str_contains($name, '\\')) {
            throw new ModuleUpdateException('Archive path refused.');
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw new ModuleUpdateException('Archive path refused.');
        }

        $trimmed = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
        if ($trimmed === '') {
            throw new ModuleUpdateException('Archive path refused.');
        }

        foreach (explode('/', $trimmed) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 180) {
                throw new ModuleUpdateException('Archive path refused.');
            }
            if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $part) === 1) {
                throw new ModuleUpdateException('Archive path refused.');
            }
        }
    }

    /**
     * @param array<mixed> $stat
     */
    private static function assertNotSymlink(array $stat): void
    {
        $attributes = (int) ($stat['external_attributes'] ?? 0);
        $mode = ($attributes >> 16) & 0xFFFF;
        if (($mode & 0120000) === 0120000) {
            throw new ModuleUpdateException('Symbolic link refused in the archive.');
        }
    }
}
