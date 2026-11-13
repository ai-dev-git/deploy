<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit;
}

/**
 * Plusieurs modules d'une même boutique incluent chacun ce fichier.
 * Les fonctions sont partagées. Chaque copie enregistre ensuite son dossier src.
 */
if (!function_exists('module_update_root')) {
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
}

if (!function_exists('module_update_require')) {
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
}

if (!function_exists('module_update_deny_htaccess')) {
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
}

if (!function_exists('module_update_register_autoload')) {
    function module_update_register_autoload(string $directory): void
    {
        static $directories = [];
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (isset($directories[$directory])) {
            return;
        }
        $directories[$directory] = true;
        if (count($directories) !== 1) {
            return;
        }

        spl_autoload_register(static function (string $class) use (&$directories): void {
            $prefix = 'AiDev\\ModuleUpdate\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            if (preg_match('/^[A-Za-z0-9\\\\]+$/', $relative) !== 1) {
                return;
            }

            $relativePath = 'src/' . str_replace('\\', '/', $relative) . '.php';
            foreach (array_keys($directories) as $base) {
                $path = $base . '/' . $relativePath;
                if (is_file($path)) {
                    require $path;

                    return;
                }
            }
        });
    }
}

module_update_register_autoload(__DIR__);
