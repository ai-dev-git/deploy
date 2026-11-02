<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

use AiDev\ModuleUpdate\Hmac;
use AiDev\ModuleUpdate\Version;
use Throwable;

final class ReleaseServer
{
    public function __construct(
        private readonly ReleaseRepository $repository,
        private readonly AccessGate $access,
        private readonly RateLimiter $limiter,
        private readonly string $logPath,
        private readonly int $maxPackageBytes,
        private readonly bool $trustProxy,
    ) {
    }

    public static function fromRoot(string $root): self
    {
        module_update_require('sodium');
        $storage = $root . DIRECTORY_SEPARATOR . 'storage';
        $config = self::config($root);
        $directory = dirname($storage . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'server.log');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new HttpException(503, 'unavailable', 'log');
        }

        return new self(
            new ReleaseRepository($storage),
            new AccessGate(
                new SecretStore($storage),
                new NonceStore($storage . DIRECTORY_SEPARATOR . 'nonces'),
                $config['timestamp_tolerance'],
            ),
            new RateLimiter(
                $storage . DIRECTORY_SEPARATOR . 'ratelimit',
                $config['rate_limit'],
                $config['rate_window'],
            ),
            $storage . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'server.log',
            $config['max_package_bytes'],
            $config['trust_proxy'],
        );
    }

    public function manifest(): void
    {
        $this->dispatch('manifest.php', function (string $moduleId): void {
            $manifest = $this->repository->latest($moduleId);
            $this->repository->package($moduleId, $manifest->version, $this->maxPackageBytes);
            Http::json(200, $manifest->toArray());
        });
    }

    public function package(): void
    {
        set_time_limit(120);
        $this->dispatch('package.php', function (string $moduleId): void {
            $version = $_GET['version'] ?? '';
            if (!is_string($version) || !Version::isValid($version)) {
                throw new HttpException(401, 'unauthorized', 'query');
            }
            $manifest = $this->repository->atVersion($moduleId, $version);
            $path = $this->repository->package($moduleId, $version, $this->maxPackageBytes);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || strlen($hash) !== strlen($manifest->sha256) || !hash_equals($manifest->sha256, $hash)) {
                throw new HttpException(503, 'unavailable', 'hash');
            }
            Http::file($path, $manifest->filename, $hash);
        });
    }

    private function dispatch(string $script, callable $action): void
    {
        $moduleId = '-';
        $ip = $this->clientIp();
        try {
            ini_set('zlib.output_compression', '0');
            $token = $this->token();
            $parsed = $token === null ? null : Hmac::parseToken($token);
            if ($parsed === null) {
                $this->limiter->hit($ip, '-');
                hash_hmac('sha256', 'anonymous', 'module-update');
                throw new HttpException(401, 'unauthorized', 'token');
            }

            $moduleId = $parsed['module_id'];
            $this->limiter->hit($ip, $moduleId);
            $query = $this->canonicalQuery($script);
            $this->access->assert($parsed, $script, $query);
            $action($moduleId);
            $this->log($ip, $moduleId, $script, 'ok');
        } catch (HttpException $exception) {
            $this->log($ip, $moduleId, $script, $exception->reason());
            Http::error($exception->status(), $exception->publicError());
        } catch (Throwable) {
            $this->log($ip, $moduleId, $script, 'internal');
            Http::error(503, 'unavailable');
        }
    }

    private function canonicalQuery(string $script): string
    {
        $keys = array_keys($_GET);
        sort($keys);
        if ($script === 'manifest.php') {
            if ($keys !== []) {
                throw new HttpException(401, 'unauthorized', 'query');
            }

            return '';
        }

        $version = $_GET['version'] ?? null;
        if ($keys !== ['version'] || !is_string($version) || !Version::isValid($version)) {
            throw new HttpException(401, 'unauthorized', 'query');
        }

        return 'version=' . $version;
    }

    private function token(): ?string
    {
        $candidates = [];
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($authorization) && str_starts_with($authorization, 'ModuleUpdate ')) {
            $candidates[] = trim(substr($authorization, strlen('ModuleUpdate ')));
        }

        $header = $_SERVER['HTTP_X_MODULE_TOKEN'] ?? '';
        if (is_string($header) && $header !== '') {
            $candidates[] = trim($header);
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (!is_string($name) || !is_string($value)) {
                        continue;
                    }
                    if (strcasecmp($name, 'Authorization') === 0 && str_starts_with($value, 'ModuleUpdate ')) {
                        $candidates[] = trim(substr($value, strlen('ModuleUpdate ')));
                    }
                    if (strcasecmp($name, 'X-Module-Token') === 0 && $value !== '') {
                        $candidates[] = trim($value);
                    }
                }
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            if (strlen($candidate) > 512) {
                return null;
            }
            $already = false;
            foreach ($unique as $known) {
                if (strlen($known) === strlen($candidate) && hash_equals($known, $candidate)) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $unique[] = $candidate;
            }
        }

        if (count($unique) !== 1) {
            return null;
        }

        return $unique[0];
    }

    private function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($this->trustProxy) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $candidate = trim(explode(',', $forwarded)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    private function log(string $ip, string $moduleId, string $script, string $reason): void
    {
        $line = gmdate('c') . "\t" . $ip . "\t" . $moduleId . "\t" . $script . "\t" . $reason . "\n";
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array{timestamp_tolerance: int, max_package_bytes: int, trust_proxy: bool, rate_limit: int, rate_window: int}
     */
    private static function config(string $root): array
    {
        $config = [
            'timestamp_tolerance' => 300,
            'max_package_bytes' => 52428800,
            'trust_proxy' => false,
            'rate_limit' => 60,
            'rate_window' => 600,
        ];
        $path = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'server.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
            }
        }

        $tolerance = (int) $config['timestamp_tolerance'];
        $maxBytes = (int) $config['max_package_bytes'];
        $limit = (int) $config['rate_limit'];
        $window = (int) $config['rate_window'];

        return [
            'timestamp_tolerance' => ($tolerance >= 30 && $tolerance <= 900) ? $tolerance : 300,
            'max_package_bytes' => ($maxBytes >= 1048576 && $maxBytes <= 209715200) ? $maxBytes : 52428800,
            'trust_proxy' => (bool) $config['trust_proxy'],
            'rate_limit' => ($limit >= 10 && $limit <= 1000) ? $limit : 60,
            'rate_window' => ($window >= 60 && $window <= 3600) ? $window : 600,
        ];
    }
}
