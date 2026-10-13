<?php

declare(strict_types=1);

namespace AiAccel\Verify;

final class VerifyResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {
    }
}
