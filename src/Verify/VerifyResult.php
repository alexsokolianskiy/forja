<?php

declare(strict_types=1);

namespace Forja\Verify;

final class VerifyResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {
    }
}
