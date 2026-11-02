<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class ZipBuilder
{
    /**
     * @param list<string> $exclude
     */
    public function zipDirectory(string $source, string $destination, array $exclude = [], string $archiveRoot = ''): void
    {
        $root = realpath($source);
        if ($root === false || !is_dir($root) || Paths::isDriveRoot($root)) {
            throw new ModuleUpdateException('Source directory not found.');
        }

        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new ModuleUpdateException('Destination directory not found.');
        }

        $destinationRealParent = realpath($parent);
        if ($destinationRealParent !== false && Paths::inside($root, $destinationRealParent)) {
            throw new ModuleUpdateException('The archive cannot be created inside the source directory.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new ModuleUpdateException('Unable to create the archive.');
        }

        $added = 0;
        $prefix = $this->archivePrefix($archiveRoot);
        if ($prefix !== '') {
            $zip->addEmptyDir(rtrim($prefix, '/'));
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                $zip->close();
                unlink($destination);
                throw new ModuleUpdateException('Symbolic link refused in the module.');
            }

            $full = $item->getPathname();
            $relative = substr($full, strlen($root) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($relative === '' || $this->excluded($relative, $exclude)) {
                continue;
            }

            $archived = $prefix . $relative;
            if ($item->isDir()) {
                $zip->addEmptyDir($archived);
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            if (!$zip->addFile($full, $archived)) {
                $zip->close();
                unlink($destination);
                throw new ModuleUpdateException('Unable to add a file to the archive.');
            }
            $added++;
        }

        if ($zip->close() !== true) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw new ModuleUpdateException('Unable to create the archive.');
        }
        if ($added < 1) {
            if (is_file($destination)) {
                unlink($destination);
            }
            throw new ModuleUpdateException('The module directory has no file to publish.');
        }
    }

    /**
     * @param list<string> $exclude
     */
    private function excluded(string $relative, array $exclude): bool
    {
        foreach ($exclude as $prefix) {
            $prefix = trim(str_replace('\\', '/', $prefix), '/');
            if ($prefix === '') {
                continue;
            }
            if (str_starts_with($prefix, '*.')) {
                $suffix = substr($prefix, 1);
                if (str_ends_with('/' . $relative, $suffix) || str_ends_with($relative, $suffix)) {
                    return true;
                }
                continue;
            }
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function archivePrefix(string $archiveRoot): string
    {
        if ($archiveRoot === '') {
            return '';
        }

        $archiveRoot = str_replace('\\', '/', trim($archiveRoot, '/'));
        if ($archiveRoot === '' || str_contains($archiveRoot, '/') || $archiveRoot === '.' || $archiveRoot === '..') {
            throw new ModuleUpdateException('Archive directory name refused.');
        }

        return $archiveRoot . '/';
    }
}
