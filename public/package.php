<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require dirname(__DIR__) . '/bootstrap.php';

use AiDev\ModuleUpdate\Server\ReleaseServer;

try {
    ReleaseServer::fromRoot(module_update_root())->package();
} catch (Throwable) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    echo '{"error":"unavailable"}';
}
