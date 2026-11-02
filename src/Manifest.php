<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use DateTimeImmutable;
use DateTimeZone;
use SodiumException;

/**
 * Manifeste d'une version.
 *
 * La signature Ed25519 porte sur signedPayload(), pas sur le JSON brut.
 * L'empreinte SHA-256 du zip y est liée à l'identifiant, la version,
 * la date, l'adresse du paquet et l'empreinte du changelog. Signer
 * uniquement l'empreinte permettrait de recycler une signature sur un
 * autre changelog ou une autre version.
 */
final class Manifest
{
    public readonly string $filename;

    public readonly string $packageUrl;

    public function __construct(
        public readonly string $moduleId,
        public readonly string $version,
        public readonly string $releasedAt,
        public readonly string $changelog,
        public readonly string $sha256,
        public readonly string $signature,
    ) {
        ModuleId::assert($this->moduleId);
        Version::assert($this->version);
        self::assertReleasedAt($this->releasedAt);
        self::assertChangelog($this->changelog);
        if (preg_match('/^[a-f0-9]{64}$/', $this->sha256) !== 1) {
            throw new ModuleUpdateException('Invalid SHA-256 fingerprint.');
        }
        if ($this->signature !== '' && !self::isSignatureShape($this->signature)) {
            throw new ModuleUpdateException('Invalid signature.');
        }

        $this->filename = $this->moduleId . '-' . $this->version . '.zip';
        $this->packageUrl = 'package.php?version=' . $this->version;
    }

    /**
     * Charge utile canonique, UTF-8, lignes LF, y compris le saut final.
     */
    public function signedPayload(): string
    {
        return implode("\n", [
            'module-update-v1',
            'alg=ed25519',
            'module_id=' . $this->moduleId,
            'version=' . $this->version,
            'released_at=' . $this->releasedAt,
            'filename=' . $this->filename,
            'sha256=' . $this->sha256,
            'package_url=' . $this->packageUrl,
            'changelog_sha256=' . hash('sha256', $this->changelog),
            '',
        ]);
    }

    public function signatureValid(string $publicKey): bool
    {
        if (!self::isSignatureShape($this->signature)) {
            return false;
        }

        try {
            $signature = sodium_base642bin($this->signature, SODIUM_BASE64_VARIANT_ORIGINAL);
            $key = Keys::public($publicKey);
            if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($signature, $this->signedPayload(), $key);
        } catch (SodiumException | ModuleUpdateException) {
            return false;
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach ([
            'module_id',
            'version',
            'released_at',
            'changelog',
            'filename',
            'package_url',
            'sha256',
            'signature_alg',
            'signature',
        ] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new ModuleUpdateException('Incomplete manifest.');
            }
        }

        if ($data['signature_alg'] !== 'ed25519') {
            throw new ModuleUpdateException('Signature algorithm refused.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $data['sha256']) !== 1) {
            throw new ModuleUpdateException('Invalid SHA-256 fingerprint.');
        }

        $manifest = new self(
            $data['module_id'],
            $data['version'],
            $data['released_at'],
            $data['changelog'],
            $data['sha256'],
            $data['signature'],
        );

        if ($data['filename'] !== $manifest->filename || $data['package_url'] !== $manifest->packageUrl) {
            throw new ModuleUpdateException('Inconsistent manifest.');
        }
        if ($manifest->signature === '') {
            throw new ModuleUpdateException('Missing signature.');
        }

        return $manifest;
    }

    public static function normalizeChangelog(string $changelog): string
    {
        if (str_starts_with($changelog, "\xEF\xBB\xBF")) {
            $changelog = substr($changelog, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $changelog);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'module_id' => $this->moduleId,
            'version' => $this->version,
            'released_at' => $this->releasedAt,
            'changelog' => $this->changelog,
            'filename' => $this->filename,
            'package_url' => $this->packageUrl,
            'sha256' => $this->sha256,
            'signature_alg' => 'ed25519',
            'signature' => $this->signature,
        ];
    }

    public function withSignature(string $signature): self
    {
        return new self(
            $this->moduleId,
            $this->version,
            $this->releasedAt,
            $this->changelog,
            $this->sha256,
            $signature,
        );
    }

    private static function assertReleasedAt(string $releasedAt): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $releasedAt, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        $warnings = is_array($errors) ? (int) ($errors['warning_count'] ?? 0) : 0;
        $errorCount = is_array($errors) ? (int) ($errors['error_count'] ?? 0) : 0;
        if (!$date || $warnings > 0 || $errorCount > 0 || $date->format('Y-m-d\TH:i:s\Z') !== $releasedAt) {
            throw new ModuleUpdateException('Invalid release date.');
        }
    }

    private static function assertChangelog(string $changelog): void
    {
        if (strlen($changelog) > 200000 || str_contains($changelog, "\0") || !mb_check_encoding($changelog, 'UTF-8')) {
            throw new ModuleUpdateException('Changelog refused.');
        }
    }

    private static function isSignatureShape(string $signature): bool
    {
        if ($signature === '' || strlen($signature) > 128 || preg_match('/^[A-Za-z0-9+\\/=]+$/', $signature) !== 1) {
            return false;
        }

        try {
            $raw = sodium_base642bin($signature, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            return false;
        }

        return strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES;
    }
}
