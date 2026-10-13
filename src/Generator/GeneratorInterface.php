<?php

declare(strict_types=1);

namespace AiAccel\Generator;

use AiAccel\Abi\Signature;
use AiAccel\Parser\MethodSpec;

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
