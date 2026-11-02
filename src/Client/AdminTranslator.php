<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Client;

use Closure;
use Throwable;

/**
 * Traduit les textes affichés dans l'administration.
 * Sans traducteur, la phrase source anglaise est conservée.
 */
final class AdminTranslator
{
    /** @var Closure(string): string|null */
    private static ?Closure $translate = null;

    /**
     * @param Closure(string): string|null $translate
     */
    public static function set(?Closure $translate): void
    {
        self::$translate = $translate;
    }

    public static function line(string $message, string ...$values): string
    {
        $template = $message;
        if (self::$translate !== null) {
            try {
                $translated = (self::$translate)($message);
                if (is_string($translated) && $translated !== '') {
                    $template = $translated;
                }
            } catch (Throwable) {
                $template = $message;
            }
        }

        return $values === [] ? $template : sprintf($template, ...$values);
    }
}
