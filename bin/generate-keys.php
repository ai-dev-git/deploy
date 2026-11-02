<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

use AiDev\ModuleUpdate\Keys;
use AiDev\ModuleUpdate\ModuleId;

try {
    module_update_require('sodium');
    $cli = module_update_cli($argv);
    $moduleId = ModuleId::assert($cli['args'][0] ?? '');
    $root = module_update_cli_root($cli['opts']);
    $force = ($cli['opts']['force'] ?? '') === '1';
    $privateDirectory = $root . DIRECTORY_SEPARATOR . 'private';
    $publicDirectory = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'keys';
    foreach ([$privateDirectory, $publicDirectory] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the keys directory.');
        }
    }

    $privatePath = $privateDirectory . DIRECTORY_SEPARATOR . $moduleId . '.secret.key';
    $publicPath = $publicDirectory . DIRECTORY_SEPARATOR . $moduleId . '.public.key';
    if (!$force && (is_file($privatePath) || is_file($publicPath))) {
        throw new RuntimeException('A key pair already exists. Run again with --force only for a module that is not in service yet.');
    }

    $keys = Keys::generate();
    if (file_put_contents($privatePath, $keys['secret'] . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the private key.');
    }
    if (file_put_contents($publicPath, $keys['public'] . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the public key.');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($privatePath, 0600);
        chmod($publicPath, 0644);
        chmod($privateDirectory, 0700);
    }
    file_put_contents($privateDirectory . DIRECTORY_SEPARATOR . '.htaccess', module_update_deny_htaccess());
    file_put_contents($publicDirectory . DIRECTORY_SEPARATOR . '.htaccess', module_update_deny_htaccess());

    fwrite(STDOUT, "Module: {$moduleId}\n");
    fwrite(STDOUT, "Public key: {$keys['public']}\n");
    fwrite(STDOUT, "Private key: private/{$moduleId}.secret.key\n");
    fwrite(STDOUT, "Never copy the private key to the web server.\n");
} catch (Throwable $error) {
    module_update_cli_fail($error);
}
