<?php

declare(strict_types=1);

namespace Forja\Verify;

final class Comparator
{
    /** Relative tolerance for floats; compilers may legally differ in the last bits of libm results. */
    private const EPSILON = 1e-9;

    public static function same(mixed $expected, mixed $actual): bool
    {
        if (is_float($expected) && is_float($actual)) {
            if (is_nan($expected) || is_nan($actual)) {
                return is_nan($expected) && is_nan($actual);
            }
            if (is_infinite($expected) || is_infinite($actual)) {
                return $expected === $actual;
            }

            return abs($expected - $actual) <= self::EPSILON * max(1.0, abs($expected), abs($actual));
        }
        if (is_array($expected) && is_array($actual)) {
            if (count($expected) !== count($actual) || !array_is_list($expected)) {
                return false;
            }
            foreach ($expected as $i => $value) {
                if (!self::same($value, $actual[$i])) {
                    return false;
                }
            }

            return true;
        }

        return $expected === $actual;
    }
}
