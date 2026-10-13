<?php

declare(strict_types=1);

namespace Forja\Generator;

use Forja\Abi\Signature;
use Forja\Parser\MethodSpec;

interface GeneratorInterface
{
    /**
     * Returns a complete C translation unit that defines $signature->symbol
     * with exactly $signature->prototype().
     *
     * @throws GenerationException
     */
    public function generate(MethodSpec $spec, Signature $signature, string $model): string;
}
