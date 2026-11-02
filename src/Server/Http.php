<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

final class Http
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): void
    {
        self::prepare($status, 'application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function error(int $status, string $code): void
    {
        self::json($status, ['error' => $code]);
    }

    public static function file(string $path, string $filename, string $sha256): void
    {
        $safeName = str_replace(['"', "\r", "\n", '\\'], '', $filename);
        self::prepare(200, 'application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-SHA256: ' . $sha256);
        readfile($path);
    }

    private static function prepare(int $status, string $contentType): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code($status);
            header('Content-Type: ' . $contentType);
            header('Content-Encoding: identity');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('X-Frame-Options: DENY');
            header('Cache-Control: no-store, private');
        }
    }
}
