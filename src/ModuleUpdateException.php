<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate;

use RuntimeException;

/**
 * Erreur montrable à l'administrateur du module ou à l'opérateur CLI.
 * Le message ne contient ni secret, ni clé, ni contenu de paquet.
 */
class ModuleUpdateException extends RuntimeException
{
}
