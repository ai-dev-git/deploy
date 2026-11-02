<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

final class Version
{
    public const PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+)?$/';

    public static function isValid(string $version): bool
    {
        return strlen($version) <= 32 && preg_match(self::PATTERN, $version) === 1;
    }

    public static function assert(string $version): string
    {
        if (!self::isValid($version)) {
            throw new ModuleUpdateException('Invalid version number.');
        }

        return $version;
    }
}
