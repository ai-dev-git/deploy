<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Server;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $publicError,
        private readonly string $reason,
    ) {
        parent::__construct($reason);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function publicError(): string
    {
        return $this->publicError;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
