<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

use AiDev\ModuleUpdate\ModuleId;
use AiDev\ModuleUpdate\ReleasePublisher;
use AiDev\ModuleUpdate\Version;

try {
    $cli = module_update_cli($argv);
    $moduleId = ModuleId::assert($cli['args'][0] ?? '');
    $version = Version::assert($cli['args'][1] ?? '');
    $zip = $cli['args'][2] ?? '';
    if ($zip === '') {
        throw new RuntimeException('Usage: publish-release.php <module> <version> <file.zip> (--changelog-file=file | --changelog=text) [--force]');
    }

    $file = $cli['opts']['changelog-file'] ?? '';
    $text = $cli['opts']['changelog'] ?? '';
    if (is_string($file) && $file !== '' && is_string($text) && $text !== '' && $text !== '1') {
        throw new RuntimeException('Provide either --changelog-file or --changelog.');
    }
    if (is_string($file) && $file !== '') {
        $changelog = file_get_contents($file);
        if (!is_string($changelog)) {
            throw new RuntimeException('Changelog file not found.');
        }
    } elseif (is_string($text) && $text !== '' && $text !== '1') {
        $changelog = $text;
    } else {
        throw new RuntimeException('Changelog is missing.');
    }

    $root = module_update_cli_root($cli['opts']);
    $manifest = (new ReleasePublisher($root))->publish(
        $moduleId,
        $version,
        $zip,
        $changelog,
        ($cli['opts']['force'] ?? '') === '1',
    );
    fwrite(STDOUT, "Release: {$manifest->moduleId} {$manifest->version}\n");
    fwrite(STDOUT, "Fingerprint: {$manifest->sha256}\n");
    fwrite(STDOUT, "Package: storage/modules/{$manifest->moduleId}/releases/{$manifest->version}.zip\n");
} catch (Throwable $error) {
    module_update_cli_fail($error);
}
