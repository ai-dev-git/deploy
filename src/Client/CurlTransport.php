<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Client;

use AiDev\ModuleUpdate\ModuleUpdateException;

final class CurlTransport
{
    public function __construct(private readonly bool $allowInsecureHttp)
    {
        module_update_require('curl');
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers, int $maxBytes, int $timeout): array
    {
        $this->assertUrl($url);
        $body = '';
        $status = $this->execute($url, $headers, $timeout, $maxBytes, function (string $chunk) use (&$body, $maxBytes): int {
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                return 0;
            }

            return strlen($chunk);
        });

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @param list<string> $headers
     */
    public function download(string $url, array $headers, string $destination, int $maxBytes, int $timeout): int
    {
        $this->assertUrl($url);
        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new ModuleUpdateException(AdminTranslator::line('Download failed.'));
        }

        $written = 0;
        try {
            return $this->execute($url, $headers, $timeout, $maxBytes, function (string $chunk) use ($handle, &$written, $maxBytes): int {
                $length = strlen($chunk);
                $written += $length;
                if ($written > $maxBytes) {
                    return 0;
                }
                $stored = fwrite($handle, $chunk);

                return $stored === false ? 0 : $length;
            });
        } catch (ModuleUpdateException $exception) {
            fclose($handle);
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $exception;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param list<string> $headers
     */
    private function execute(string $url, array $headers, int $timeout, int $maxBytes, callable $write): int
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ModuleUpdateException(AdminTranslator::line('cURL is unavailable.'));
        }

        $localHttp = $this->localHttp($url);
        $verifyCertificate = !$this->localShop();
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $localHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'ModuleUpdate/1',
            CURLOPT_HTTPHEADER => array_merge($headers, ['Accept-Encoding: identity']),
            CURLOPT_SSL_VERIFYPEER => $verifyCertificate,
            CURLOPT_SSL_VERIFYHOST => $verifyCertificate ? 2 : 0,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($write): int {
                unset($curl);

                return $write($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = $localHttp ? 'http,https' : 'https';
            unset($options[CURLOPT_PROTOCOLS]);
        }

        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($ok === false) {
            if (str_contains(strtolower($error), 'write')) {
                throw new ModuleUpdateException(AdminTranslator::line('Response is too large.'));
            }
            throw new ModuleUpdateException(AdminTranslator::line('cURL call failed.'));
        }
        if ($status >= 300 && $status < 400) {
            throw new ModuleUpdateException(AdminTranslator::line('Redirect refused.'));
        }
        if ($status === 0) {
            throw new ModuleUpdateException(AdminTranslator::line('cURL call failed.'));
        }

        unset($maxBytes);

        return $status;
    }

    private function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ModuleUpdateException(AdminTranslator::line('Update address refused.'));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || str_contains($url, '\\') || str_contains($url, "\n") || str_contains($url, "\r")) {
            throw new ModuleUpdateException(AdminTranslator::line('Update address refused.'));
        }

        $local = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && $local && $this->allowInsecureHttp) {
            return;
        }

        throw new ModuleUpdateException(AdminTranslator::line('The update address must use HTTPS.'));
    }

    private function localShop(): bool
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $server = (string) ($_SERVER['SERVER_NAME'] ?? '');

        return $script === './vendor/bin/phpunit' || $server === 'localhost';
    }

    private function localHttp(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'http'
            && $this->allowInsecureHttp
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }
}
