<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Client;

use AiDev\ModuleUpdate\ArchiveGuard;
use AiDev\ModuleUpdate\Manifest;
use AiDev\ModuleUpdate\ModuleUpdateException;
use AiDev\ModuleUpdate\Paths;
use AiDev\ModuleUpdate\ZipBuilder;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

final class Installer
{
    /**
     * @param list<string> $preserve
     */
    public function install(
        string $zipPath,
        string $modulePath,
        Manifest $manifest,
        string $publicKey,
        string $installedVersion,
        array $preserve = ['var', 'config/update.php'],
    ): string {
        module_update_require('zip', 'sodium');
        if (!$manifest->signatureValid($publicKey)) {
            throw new ModuleUpdateException(AdminTranslator::line('The update signature is invalid.'));
        }

        $zipReal = realpath($zipPath);
        if ($zipReal === false || !is_file($zipReal)) {
            throw new ModuleUpdateException(AdminTranslator::line('Package not found.'));
        }
        $hash = hash_file('sha256', $zipReal);
        if (!is_string($hash) || strlen($hash) !== strlen($manifest->sha256) || !hash_equals($manifest->sha256, $hash)) {
            throw new ModuleUpdateException(AdminTranslator::line('The package fingerprint does not match.'));
        }

        $moduleReal = realpath($modulePath);
        if ($moduleReal === false || !is_dir($moduleReal) || Paths::isDriveRoot($moduleReal)) {
            throw new ModuleUpdateException(AdminTranslator::line('Module directory not found.'));
        }
        if (basename($moduleReal) !== $manifest->moduleId) {
            throw new ModuleUpdateException(AdminTranslator::line('The module directory does not match the manifest.'));
        }

        $backup = $this->backup($moduleReal, $installedVersion);
        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'muex' . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0700) && !is_dir($staging)) {
            throw new ModuleUpdateException(AdminTranslator::line('Installation could not be prepared.'));
        }

        $created = [];
        try {
            $this->extract($zipReal, $staging);
            $source = $this->sourceRoot($staging, $manifest->moduleId);
            $created = $this->copyTree($source, $moduleReal, $preserve);
        } catch (Throwable $exception) {
            $this->restore($backup, $moduleReal, $created);
            throw new ModuleUpdateException(
                AdminTranslator::line('Installation stopped. The backup was restored (%s).', basename($backup)),
                0,
                $exception instanceof ModuleUpdateException ? $exception : null,
            );
        } finally {
            $this->deleteTree($staging);
        }

        return basename($backup);
    }

    private function backup(string $moduleReal, string $installedVersion): string
    {
        $safeVersion = preg_replace('/[^0-9A-Za-z._+-]/', '', $installedVersion) ?: 'courante';
        $directory = $moduleReal . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ModuleUpdateException(AdminTranslator::line('Backup failed.'));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mubak');
        if ($temporary === false) {
            throw new ModuleUpdateException(AdminTranslator::line('Backup failed.'));
        }
        unlink($temporary);
        $temporary .= '.zip';
        // panel.lock est ouvert pendant l’installation : le lire dans le zip échoue sous Windows.
        (new ZipBuilder())->zipDirectory($moduleReal, $temporary, ['var/backups', 'var/update/panel.lock']);
        $destination = $directory . DIRECTORY_SEPARATOR . $safeVersion . '-' . gmdate('YmdHis') . '.zip';
        if (!@rename($temporary, $destination)) {
            if (!copy($temporary, $destination)) {
                unlink($temporary);
                throw new ModuleUpdateException(AdminTranslator::line('Backup failed.'));
            }
            unlink($temporary);
        }

        return $destination;
    }

    private function extract(string $zipPath, string $staging): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ModuleUpdateException(AdminTranslator::line('Package is unreadable.'));
        }
        try {
            ArchiveGuard::assertSafe($zip);
            if (!$zip->extractTo($staging)) {
                throw new ModuleUpdateException(AdminTranslator::line('Extraction failed.'));
            }
        } finally {
            $zip->close();
        }
        ArchiveGuard::assertExtractedTree($staging);
    }

    private function sourceRoot(string $staging, string $moduleId): string
    {
        $items = array_values(array_filter(
            scandir($staging) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
        if (count($items) === 1) {
            $only = $staging . DIRECTORY_SEPARATOR . $items[0];
            if (is_dir($only) && !is_link($only)) {
                if ($items[0] !== $moduleId) {
                    throw new ModuleUpdateException(AdminTranslator::line('The package must contain the module directory or its files.'));
                }

                return $only;
            }
        }

        return $staging;
    }

    /**
     * @param list<string> $preserve
     * @return list<string>
     */
    private function copyTree(string $source, string $moduleReal, array $preserve): array
    {
        $created = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new ModuleUpdateException(AdminTranslator::line('Symbolic link refused in the archive.'));
            }
            $full = str_replace('\\', '/', $item->getPathname());
            $prefix = rtrim(str_replace('\\', '/', $source), '/') . '/';
            $seen = PHP_OS_FAMILY === 'Windows' ? strtolower($full) : $full;
            $expected = PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix;
            if (!str_starts_with($seen, $expected)) {
                throw new ModuleUpdateException(AdminTranslator::line('Copy refused.'));
            }
            $relative = substr($full, strlen($prefix));
            if ($relative === '' || $this->preserved($relative, $preserve)) {
                continue;
            }

            $target = $moduleReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new ModuleUpdateException(AdminTranslator::line('Copy failed.'));
                }
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new ModuleUpdateException(AdminTranslator::line('Copy failed.'));
            }
            $parentReal = realpath($parent);
            if ($parentReal === false || !Paths::inside($moduleReal, $parentReal)) {
                throw new ModuleUpdateException(AdminTranslator::line('Copy refused.'));
            }
            if (basename($target) === '.' || basename($target) === '..') {
                throw new ModuleUpdateException(AdminTranslator::line('Copy refused.'));
            }

            $already = is_file($target);
            if (!copy($item->getPathname(), $target)) {
                throw new ModuleUpdateException(AdminTranslator::line('Copy failed.'));
            }
            if (!$already) {
                $created[] = $target;
            }
        }

        return $created;
    }

    /**
     * @param list<string> $preserve
     */
    private function preserved(string $relative, array $preserve): bool
    {
        foreach ($preserve as $prefix) {
            $prefix = trim(str_replace('\\', '/', $prefix), '/');
            if ($prefix === '') {
                continue;
            }
            if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $created
     */
    private function restore(string $backup, string $moduleReal, array $created): void
    {
        foreach ($created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $staging = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'muex' . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0700) && !is_dir($staging)) {
            return;
        }
        try {
            $this->extract($backup, $staging);
            $this->copyTree($staging, $moduleReal, ['var/backups']);
        } catch (Throwable) {
            // La sauvegarde reste dans var/backups pour une restauration manuelle.
        } finally {
            $this->deleteTree($staging);
        }
    }

    private function deleteTree(string $directory): void
    {
        $base = basename($directory);
        if (!str_starts_with($base, 'muex') || strlen($base) < 12 || !is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
