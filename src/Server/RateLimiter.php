<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

final class RateLimiter
{
    public function __construct(
        private readonly string $root,
        private readonly int $limit,
        private readonly int $window,
    ) {
        if (!is_dir($this->root) && !mkdir($this->root, 0750, true) && !is_dir($this->root)) {
            throw new HttpException(503, 'unavailable', 'ratelimit');
        }
    }

    public function hit(string $ip, string $moduleId): void
    {
        $bucket = hash('sha256', $ip . "\0" . $moduleId);
        $path = $this->root . DIRECTORY_SEPARATOR . $bucket;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new HttpException(503, 'unavailable', 'ratelimit');
        }

        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            $start = is_array($data) ? (int) ($data['start'] ?? 0) : 0;
            $count = is_array($data) ? (int) ($data['count'] ?? 0) : 0;
            if ($start < 1 || ($now - $start) >= $this->window) {
                $start = $now;
                $count = 0;
            }
            $count++;
            if ($count > $this->limit) {
                throw new HttpException(429, 'too_many_requests', 'rate');
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(['start' => $start, 'count' => $count], JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
