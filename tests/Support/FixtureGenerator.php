<?php

declare(strict_types=1);

namespace Forja\Tests\Support;

use Forja\Abi\Signature;
use Forja\Generator\GenerationException;
use Forja\Generator\GeneratorInterface;
use Forja\Parser\MethodSpec;

/**
 * Stands in for the model: serves hand-written C from <dir>/<method>.c,
 * with {{symbol}} replaced by the required function name.
 */
final class FixtureGenerator implements GeneratorInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $dir)
    {
    }

    public function generate(MethodSpec $spec, Signature $signature, string $model): string
    {
        $this->calls++;
        $file = "{$this->dir}/{$spec->method}.c";
        if (!is_file($file)) {
            throw new GenerationException("no fixture for {$spec->method}");
        }

        return str_replace('{{symbol}}', $signature->symbol, (string) file_get_contents($file));
    }
}
