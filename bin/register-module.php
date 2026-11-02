<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

use AiDev\ModuleUpdate\ModuleId;
use AiDev\ModuleUpdate\Paths;
use AiDev\ModuleUpdate\Server\SecretStore;

try {
    module_update_require('sodium');
    $cli = module_update_cli($argv);
    $moduleId = ModuleId::assert($cli['args'][0] ?? '');
    $root = module_update_cli_root($cli['opts']);
    $publicKey = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . $moduleId . '.public.key';
    if (!is_file($publicKey)) {
        throw new RuntimeException('Public key is missing. Run generate-keys.php and place the public key in storage/keys/.');
    }

    $storage = $root . DIRECTORY_SEPARATOR . 'storage';
    $module = $storage . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $moduleId;
    $releases = $module . DIRECTORY_SEPARATOR . 'releases';
    foreach ([$storage . DIRECTORY_SEPARATOR . 'modules', $module, $releases] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the module directory.');
        }
    }
    $modulesRoot = realpath($storage . DIRECTORY_SEPARATOR . 'modules');
    $moduleReal = realpath($module);
    if ($modulesRoot === false || $moduleReal === false || !Paths::inside($modulesRoot, $moduleReal)) {
        throw new RuntimeException('Module directory refused.');
    }

    $deny = module_update_deny_htaccess();
    file_put_contents($storage . DIRECTORY_SEPARATOR . '.htaccess', $deny);
    file_put_contents($module . DIRECTORY_SEPARATOR . '.htaccess', $deny);
    file_put_contents($releases . DIRECTORY_SEPARATOR . '.htaccess', $deny);
    file_put_contents($module . DIRECTORY_SEPARATOR . 'index.php', "<?php\nhttp_response_code(403);\nexit;\n");

    $secret = (new SecretStore($storage))->register($moduleId);
    fwrite(STDOUT, "Module: {$moduleId}\n");
    fwrite(STDOUT, "Secret: {$secret}\n");
    fwrite(STDOUT, "Copy this identifier and this secret into config/update.php. The secret will not be shown again.\n");
} catch (Throwable $error) {
    module_update_cli_fail($error);
}
