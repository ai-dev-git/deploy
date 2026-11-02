<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}

/**
 * Racine des données (stockage, clés publiques, secrets).
 * Le code peut vivre ailleurs : MODULE_UPDATE_ROOT pointe vers les données.
 */
function module_update_root(): string
{
    static $root = null;
    if (is_string($root)) {
        return $root;
    }

    $env = getenv('MODULE_UPDATE_ROOT');
    $candidate = is_string($env) && $env !== '' ? $env : __DIR__;
    $real = realpath($candidate);
    if ($real === false) {
        throw new RuntimeException('Update service root not found.');
    }

    $root = $real;

    return $root;
}

function module_update_require(string ...$extensions): void
{
    $missing = [];
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('Required PHP extensions: ' . implode(', ', $missing) . '.');
    }
}

function module_update_deny_htaccess(): string
{
    return <<<'HTA'
Options -Indexes
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Deny from all
</IfModule>
HTA;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'AiDev\\ModuleUpdate\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z0-9\\\\]+$/', $relative) !== 1) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
