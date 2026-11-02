<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

final class ModuleId
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}[a-z0-9]$/';

    public static function isValid(string $moduleId): bool
    {
        return strlen($moduleId) >= 2
            && strlen($moduleId) <= 64
            && preg_match(self::PATTERN, $moduleId) === 1;
    }

    public static function assert(string $moduleId): string
    {
        if (!self::isValid($moduleId)) {
            throw new ModuleUpdateException('Invalid module identifier.');
        }

        return $moduleId;
    }
}
