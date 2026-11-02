<?php

declare(strict_types=1);

/**
 * Réglages du serveur de dépôts. Copier vers config/server.php pour les modifier.
 * La clé privée n'a rien à faire ici : elle reste sur la machine qui publie.
 */
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

return [
    'timestamp_tolerance' => 300,
    'max_package_bytes' => 52428800,
    'trust_proxy' => false,
    'rate_limit' => 60,
    'rate_window' => 600,
];
