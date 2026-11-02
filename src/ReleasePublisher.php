<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use AiDev\ModuleUpdate\Server\SecretStore;
use ZipArchive;

final class ReleasePublisher
{
    public function __construct(private readonly string $root)
    {
    }

    public function publish(string $moduleId, string $version, string $zipPath, string $changelog, bool $force = false): Manifest
    {
        module_update_require('sodium', 'zip');
        $moduleId = ModuleId::assert($moduleId);
        $version = Version::assert($version);
        $changelog = Manifest::normalizeChangelog($changelog);
        $secrets = new SecretStore($this->root . DIRECTORY_SEPARATOR . 'storage');
        if (!$secrets->has($moduleId)) {
            throw new ModuleUpdateException('Module is not registered.');
        }

        $privateKey = $this->root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $moduleId . '.secret.key';
        $signer = Signer::fromFile($privateKey);
        $zip = $this->checkedZip($zipPath);
        $sha256 = hash_file('sha256', $zip);
        if (!is_string($sha256)) {
            throw new ModuleUpdateException('Unable to fingerprint the package.');
        }

        $maxBytes = $this->maxBytes();
        $size = filesize($zip);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new ModuleUpdateException('Package is too large.');
        }

        $draft = new Manifest(
            $moduleId,
            $version,
            gmdate('Y-m-d\TH:i:s\Z'),
            $changelog,
            $sha256,
            '',
        );
        $manifest = $draft->withSignature($signer->sign($draft->signedPayload()));
        if (!$manifest->signatureValid($this->publicKey($moduleId))) {
            throw new ModuleUpdateException('The produced signature does not verify.');
        }

        $moduleDirectory = $this->prepareModule($moduleId);
        $current = $this->currentVersion($moduleDirectory);
        if ($current !== null) {
            $comparison = version_compare($version, $current);
            if ($comparison < 0 && !$force) {
                throw new ModuleUpdateException('The published version is older than the current version.');
            }
            if ($comparison === 0 && !$force) {
                throw new ModuleUpdateException('This version is already published.');
            }
        }

        $releases = $moduleDirectory . DIRECTORY_SEPARATOR . 'releases';
        $this->place($zip, $releases . DIRECTORY_SEPARATOR . $version . '.zip');
        $storedHash = hash_file('sha256', $releases . DIRECTORY_SEPARATOR . $version . '.zip');
        if (!is_string($storedHash) || !hash_equals($sha256, $storedHash)) {
            throw new ModuleUpdateException('The copied package does not match the fingerprint.');
        }

        JsonFile::writeAtomic($releases . DIRECTORY_SEPARATOR . $version . '.json', $manifest->toArray());
        if ($current === null || version_compare($version, $current, '>=')) {
            JsonFile::writeAtomic($moduleDirectory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest->toArray());
        }

        return $manifest;
    }

    private function checkedZip(string $zipPath): string
    {
        $real = realpath($zipPath);
        if ($real === false || !is_file($real)) {
            throw new ModuleUpdateException('Package not found.');
        }

        $zip = new ZipArchive();
        if ($zip->open($real) !== true) {
            throw new ModuleUpdateException('Package is unreadable.');
        }
        try {
            ArchiveGuard::assertSafe($zip);
        } finally {
            $zip->close();
        }

        return $real;
    }

    private function publicKey(string $moduleId): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . $moduleId . '.public.key';
        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            throw new ModuleUpdateException('Public key not found.');
        }

        return trim($contents);
    }

    private function prepareModule(string $moduleId): string
    {
        $modules = $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'modules';
        $module = $modules . DIRECTORY_SEPARATOR . $moduleId;
        $releases = $module . DIRECTORY_SEPARATOR . 'releases';
        foreach ([$modules, $module, $releases] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new ModuleUpdateException('Unable to create the module directory.');
            }
        }

        $deny = module_update_deny_htaccess();
        file_put_contents($module . DIRECTORY_SEPARATOR . '.htaccess', $deny);
        file_put_contents($releases . DIRECTORY_SEPARATOR . '.htaccess', $deny);
        file_put_contents($module . DIRECTORY_SEPARATOR . 'index.php', "<?php\nhttp_response_code(403);\nexit;\n");

        $real = realpath($module);
        $root = realpath($modules);
        if ($real === false || $root === false || !Paths::inside($root, $real)) {
            throw new ModuleUpdateException('Module directory refused.');
        }

        return $real;
    }

    private function currentVersion(string $moduleDirectory): ?string
    {
        $path = $moduleDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($path)) {
            return null;
        }

        return Manifest::fromArray(JsonFile::read($path, 1500000))->version;
    }

    private function place(string $source, string $destination): void
    {
        $tmp = $destination . '.tmp';
        if (!copy($source, $tmp)) {
            throw new ModuleUpdateException('Unable to copy the package.');
        }
        if (is_file($destination) && PHP_OS_FAMILY === 'Windows' && !unlink($destination)) {
            unlink($tmp);
            throw new ModuleUpdateException('Unable to replace the package.');
        }
        if (!rename($tmp, $destination)) {
            unlink($tmp);
            throw new ModuleUpdateException('Unable to copy the package.');
        }
    }

    private function maxBytes(): int
    {
        $max = 52428800;
        $path = $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'server.php';
        if (!is_file($path)) {
            return $max;
        }
        $loaded = require $path;
        if (!is_array($loaded) || !isset($loaded['max_package_bytes'])) {
            return $max;
        }
        $value = (int) $loaded['max_package_bytes'];

        return ($value >= 1048576 && $value <= 209715200) ? $value : $max;
    }
}
