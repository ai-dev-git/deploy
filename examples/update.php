<?php

declare(strict_types=1);

/**
 * Copier ce fichier vers config/update.php dans le module, puis remplacer
 * les trois valeurs. Ne pas le régénérer lors d'une mise à jour : l'installeur
 * conserve ce fichier.
 */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

if (!defined('MODULE_UPDATE_INTERNAL') && !defined('_PS_VERSION_')) {
    http_response_code(403);
    exit;
}

return [
    'base_url' => 'https://updates.example.com',
    'module_id' => 'monmodule',
    'module_secret' => 'REMPLACER',
    'public_key' => 'REMPLACER',
    'allow_insecure_http' => false,
    'timeout' => 30,
    'download_timeout' => 120,
    'max_package_bytes' => 52428800,
    'preserve_paths' => ['var', 'config/update.php'],
];
