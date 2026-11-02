<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

/**
 * @return array{args: list<string>, opts: array<string, mixed>}
 */
function module_update_cli(array $argv): array
{
    $args = [];
    $opts = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }
        if (!str_starts_with($argument, '--')) {
            $args[] = $argument;
            continue;
        }
        $body = substr($argument, 2);
        [$key, $value] = array_pad(explode('=', $body, 2), 2, '1');
        if ($key === '') {
            continue;
        }
        if ($key === 'exclude') {
            if (!isset($opts['exclude']) || !is_array($opts['exclude'])) {
                $opts['exclude'] = [];
            }
            $opts['exclude'][] = $value;
            continue;
        }
        $opts[$key] = $value;
    }

    return ['args' => $args, 'opts' => $opts];
}

/**
 * @param array<string, mixed> $opts
 */
function module_update_cli_root(array $opts): string
{
    $root = $opts['root'] ?? '';
    if (!is_string($root) || $root === '') {
        $env = getenv('MODULE_UPDATE_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__);
    }
    $real = realpath($root);
    if ($real === false) {
        throw new RuntimeException('Root directory not found.');
    }

    return $real;
}

function module_update_cli_fail(Throwable $error): never
{
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
