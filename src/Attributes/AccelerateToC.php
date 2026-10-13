<?php

declare(strict_types=1);

namespace Forja\Attributes;

use Attribute;

/**
 * Marks a method for translation to C.
 *
 * The method may use its arguments, local variables, built-in functions,
 * constants of its own class and other methods of its own class that follow
 * the same rules. It may not read or write object state.
 *
 * Parameters and the return value may be anything built from null, bool,
 * int, float, string and arrays (lists, associative, nested). Objects,
 * callables and resources are not supported. Scalars, strings and arrays
 * documented as int[], float[], int[][] or float[][] are passed to C
 * directly and are the fastest; everything else is copied through PHP's
 * serialize format.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AccelerateToC
{
    /**
     * @param string|null $model  "provider:model", a config alias, or an Forja\Model constant;
     *                            null uses `default_model` from the config.
     * @param bool $deterministic Reuse the cached C source for an unchanged method instead of regenerating it.
     * @param array<string, array{0: int|float, 1: int|float}> $ranges
     *        Ranges of the random inputs used for verification, keyed by parameter name.
     *        "name.len" bounds the length of a string or list (rows of a matrix), "name.cols" the matrix width.
     * @param bool $lock          Freeze the C source once it verifies: it is never regenerated,
     *                            only re-checked against the PHP method.
     * @param list<list<mixed>> $examples Argument lists that verification must always try,
     *        in addition to the random ones: [['some text', 3], ['', 0]].
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly bool $deterministic = true,
        public readonly array $ranges = [],
        public readonly bool $lock = false,
        public readonly array $examples = [],
    ) {
    }
}
