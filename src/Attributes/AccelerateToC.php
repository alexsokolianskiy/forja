<?php

declare(strict_types=1);

namespace AiAccel\Attributes;

use Attribute;

/**
 * Marks a method for translation to C.
 *
 * The method may use its arguments, local variables, built-in functions,
 * constants of its own class and other methods of its own class that follow
 * the same rules. It may not read or write object state.
 *
 * Parameter types: int, float, bool, string, and arrays documented in the
 * docblock as int[], float[], int[][] or float[][]. The return type may be
 * any of those, or void.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AccelerateToC
{
    /**
     * @param string|null $model  "provider:model", a config alias, or an AiAccel\Model constant;
     *                            null uses `default_model` from the config.
     * @param bool $deterministic Reuse the cached C source for an unchanged method instead of regenerating it.
     * @param array<string, array{0: int|float, 1: int|float}> $ranges
     *        Ranges of the random inputs used for verification, keyed by parameter name.
     *        "name.len" bounds the length of a string or list (rows of a matrix), "name.cols" the matrix width.
     * @param bool $lock          Freeze the C source once it verifies: it is never regenerated,
     *                            only re-checked against the PHP method.
     */
    public function __construct(
        public readonly ?string $model = null,
        public readonly bool $deterministic = true,
        public readonly array $ranges = [],
        public readonly bool $lock = false,
    ) {
    }
}
