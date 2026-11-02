<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

use AiDev\ModuleUpdate\ZipBuilder;

try {
    module_update_require('zip');
    $cli = module_update_cli($argv);
    $source = $cli['args'][0] ?? '';
    $destination = $cli['args'][1] ?? '';
    if ($source === '' || $destination === '') {
        throw new RuntimeException('Usage: build-zip.php <module-directory> <file.zip> [--exclude=path]');
    }

    $exclude = ['var', 'config/update.php', '.git', 'tests', 'private', '*.secret.key'];
    if (isset($cli['opts']['exclude']) && is_array($cli['opts']['exclude'])) {
        foreach ($cli['opts']['exclude'] as $item) {
            if (is_string($item) && $item !== '') {
                $exclude[] = $item;
            }
        }
    }

    $resolved = realpath($source);
    $archiveRoot = $resolved === false ? '' : basename($resolved);
    (new ZipBuilder())->zipDirectory($source, $destination, $exclude, $archiveRoot);
    fwrite(STDOUT, "Archive: {$destination}\n");
    fwrite(STDOUT, "Root directory: {$archiveRoot}/\n");
} catch (Throwable $error) {
    module_update_cli_fail($error);
}
