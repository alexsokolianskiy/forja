<?php

declare(strict_types=1);

namespace AiAccel\Parser;

/**
 * Everything the pipeline needs to know about one method to accelerate.
 */
final class MethodSpec
{
    /**
     * @param list<array{name: string, kind: string}> $params kind is one of Signature::KINDS
     * @param string $context source of the class constants and helper methods the method uses
     * @param array<string, array{0: int|float, 1: int|float}> $ranges
     * @param bool $auto picked up by --auto rather than by an attribute
     */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly string $file,
        public readonly array $params,
        public readonly string $returnKind,
        public readonly string $source,
        public readonly string $context = '',
        public readonly ?string $model = null,
        public readonly bool $deterministic = true,
        public readonly array $ranges = [],
        public readonly bool $lock = false,
        public readonly bool $auto = false,
        public readonly ?string $unsupported = null,
    ) {
    }

    public function key(): string
    {
        return $this->class . '::' . $this->method;
    }
}
