<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

try {
    $cli = module_update_cli($argv);
    $destination = $cli['args'][0] ?? '';
    if ($destination === '') {
        throw new RuntimeException('Usage: export-client.php <destination-directory>');
    }
    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException('Destination not found.');
    }
    $destination = realpath($destination);
    $source = dirname(__DIR__);
    if ($destination === false || Paths_inside_source($source, $destination)) {
        throw new RuntimeException('Choose a directory outside module-updates.');
    }

    copy_file($source . '/bootstrap.php', $destination . '/bootstrap.php');
    copy_tree($source . '/src', $destination . '/src', ['Server']);
    $config = $destination . '/config';
    if (!is_dir($config) && !mkdir($config, 0755, true) && !is_dir($config)) {
        throw new RuntimeException('Config directory not found.');
    }
    copy_file($source . '/examples/update.php', $config . '/update.php.example');
    file_put_contents($destination . '/.htaccess', module_update_deny_htaccess());
    file_put_contents($destination . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n");
    fwrite(STDOUT, "Client exported: {$destination}\n");
} catch (Throwable $error) {
    module_update_cli_fail($error);
}

function Paths_inside_source(string $root, string $path): bool
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = rtrim(str_replace('\\', '/', $path), '/');
    if (PHP_OS_FAMILY === 'Windows') {
        $root = strtolower($root);
        $path = strtolower($path);
    }

    return $path === $root || str_starts_with($path, $root . '/');
}

function copy_file(string $from, string $to): void
{
    if (!is_file($from) || !copy($from, $to)) {
        throw new RuntimeException('Unable to copy: ' . basename($from));
    }
}

/**
 * @param list<string> $skipDirectories
 */
function copy_tree(string $from, string $to, array $skipDirectories): void
{
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
        throw new RuntimeException('Unable to copy.');
    }
    $items = scandir($from);
    if ($items === false) {
        throw new RuntimeException('Unable to read.');
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $source = $from . DIRECTORY_SEPARATOR . $item;
        $target = $to . DIRECTORY_SEPARATOR . $item;
        if (is_dir($source)) {
            if (in_array($item, $skipDirectories, true)) {
                continue;
            }
            copy_tree($source, $target, []);
            continue;
        }
        if (str_ends_with($item, '.php')) {
            copy_file($source, $target);
        }
    }
}
