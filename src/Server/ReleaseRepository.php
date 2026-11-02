<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

use AiDev\ModuleUpdate\JsonFile;
use AiDev\ModuleUpdate\Manifest;
use AiDev\ModuleUpdate\ModuleId;
use AiDev\ModuleUpdate\ModuleUpdateException;
use AiDev\ModuleUpdate\Paths;
use AiDev\ModuleUpdate\Version;

final class ReleaseRepository
{
    private const MANIFEST_MAX_BYTES = 1500000;

    public function __construct(private readonly string $storage)
    {
    }

    public function latest(string $moduleId): Manifest
    {
        $path = $this->moduleDirectory($moduleId) . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($path)) {
            throw new HttpException(404, 'not_found', 'manifest');
        }

        return $this->readManifest($moduleId, $path);
    }

    public function atVersion(string $moduleId, string $version): Manifest
    {
        $version = Version::assert($version);
        $path = $this->releasesDirectory($moduleId) . DIRECTORY_SEPARATOR . $version . '.json';
        if (!is_file($path)) {
            throw new HttpException(404, 'not_found', 'version');
        }

        $manifest = $this->readManifest($moduleId, $path);
        if ($manifest->version !== $version) {
            throw new HttpException(503, 'unavailable', 'version-mismatch');
        }

        return $manifest;
    }

    public function package(string $moduleId, string $version, int $maxBytes): string
    {
        $version = Version::assert($version);
        $path = $this->releasesDirectory($moduleId) . DIRECTORY_SEPARATOR . $version . '.zip';
        $real = realpath($path);
        $releases = realpath($this->releasesDirectory($moduleId));
        if ($real === false || $releases === false || !is_file($real) || !Paths::inside($releases, $real)) {
            throw new HttpException(404, 'not_found', 'package');
        }

        $size = filesize($real);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new HttpException(503, 'unavailable', 'size');
        }

        return $real;
    }

    public function publicKey(string $moduleId): string
    {
        $moduleId = ModuleId::assert($moduleId);
        $keys = realpath($this->storage . DIRECTORY_SEPARATOR . 'keys');
        if ($keys === false) {
            throw new HttpException(503, 'unavailable', 'public-key');
        }

        $path = $keys . DIRECTORY_SEPARATOR . $moduleId . '.public.key';
        $real = realpath($path);
        if ($real === false || !is_file($real) || !Paths::inside($keys, $real)) {
            throw new HttpException(503, 'unavailable', 'public-key');
        }

        $contents = file_get_contents($real);
        if (!is_string($contents) || trim($contents) === '') {
            throw new HttpException(503, 'unavailable', 'public-key');
        }

        return trim($contents);
    }

    public function moduleDirectory(string $moduleId): string
    {
        $moduleId = ModuleId::assert($moduleId);
        $modules = realpath($this->storage . DIRECTORY_SEPARATOR . 'modules');
        if ($modules === false) {
            throw new HttpException(404, 'not_found', 'module');
        }

        $path = $modules . DIRECTORY_SEPARATOR . $moduleId;
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !Paths::inside($modules, $real)) {
            throw new HttpException(404, 'not_found', 'module');
        }

        return $real;
    }

    public function releasesDirectory(string $moduleId): string
    {
        $releases = realpath($this->moduleDirectory($moduleId) . DIRECTORY_SEPARATOR . 'releases');
        if ($releases === false || !is_dir($releases)) {
            throw new HttpException(404, 'not_found', 'releases');
        }

        return $releases;
    }

    private function readManifest(string $moduleId, string $path): Manifest
    {
        try {
            $data = JsonFile::read($path, self::MANIFEST_MAX_BYTES);
            $manifest = Manifest::fromArray($data);
        } catch (ModuleUpdateException) {
            throw new HttpException(503, 'unavailable', 'manifest-invalid');
        }

        if ($manifest->moduleId !== $moduleId || !$manifest->signatureValid($this->publicKey($moduleId))) {
            throw new HttpException(503, 'unavailable', 'signature');
        }

        return $manifest;
    }
}
