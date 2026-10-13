<?php

declare(strict_types=1);

namespace AiAccel\Generator;

use AiAccel\Abi\Signature;
use AiAccel\Parser\MethodSpec;

final class PromptBuilder
{
    /** Bump whenever the prompt text changes: it is part of the module hash. */
    public const VERSION = '2';

    public const SYSTEM = <<<'PROMPT'
        You translate one PHP method into one self-contained C11 translation unit that returns exactly what the PHP method returns.

        Output rules:
        - Reply with exactly one ```c fenced block and nothing else.
        - Define the exported function with exactly the prototype you are given. Everything else must be `static`.
        - Translate the helper methods and class constants you are shown into static functions and constants.
        - Allowed headers only: <stdint.h>, <stddef.h>, <stdbool.h>, <stdlib.h>, <string.h>, <math.h>, <limits.h>, <float.h>.
        - No I/O, no global mutable state, no threads, no system calls, no inline assembly. Free every temporary allocation.
        - If the method cannot be translated faithfully at all (it depends on I/O, time, randomness, locale, or PHP features
          with no C equivalent), reply with the single line `UNSUPPORTED: <reason>` instead of code.

        Calling convention:
        - int -> int64_t, float -> double, bool -> int32_t (0 or 1).
        - string -> `const char *s, size_t s_len`: raw bytes, not NUL-terminated, may contain NUL bytes.
        - int[] / float[] -> `const T *a, size_t a_len`: a zero-indexed list.
        - int[][] / float[][] -> `const T *m, size_t m_rows, size_t m_cols`: a rectangular matrix in row-major order,
          so PHP `$m[$i][$j]` is `m[i * m_cols + j]`. The caller guarantees every row has m_cols elements.
        - Inputs are read-only. To modify an array the way PHP modifies its by-value copy, copy it first.
        - A string or array result is returned as a buffer allocated with malloc; the caller frees it.
          Store its size in `*accel_len` (bytes or elements), or in `*accel_rows` and `*accel_cols` for a matrix.
          An empty result may be returned as NULL with size 0.
        - `int32_t *accel_err` is always the last parameter and is 0 on entry. Set `*accel_err = 1` and return
          (0 or NULL, after freeing what you allocated) whenever you cannot produce PHP's exact result. The caller
          then runs the PHP method instead, so this is always safe. You MUST do this when:
            * PHP would raise a warning, error or exception: an index outside an array or string, division or
              modulo by zero, a `throw` statement, an invalid argument to a built-in function;
            * an int operation would overflow int64_t (PHP switches to float there; use __builtin_add_overflow,
              __builtin_sub_overflow, __builtin_mul_overflow wherever overflow is possible for valid inputs);
            * a result that must be an int[] or int[][] would contain a non-integer, or the PHP array would not be
              a rectangular list;
            * malloc fails.

        PHP semantics to preserve:
        - `/` yields a float unless both operands are ints that divide exactly, in which case it yields an int.
          `intdiv()` and `%` truncate toward zero. `**` on ints yields an int only if it does not overflow.
        - Evaluate floating-point expressions in the PHP source order. Do not reassociate, and do not use fma().
        - Integer-to-float and float-to-string conversions, comparison and rounding must follow PHP 8 rules;
          if you are not certain of a rule, set `*accel_err = 1` for the inputs it affects.
        - String functions operate on bytes unless they are mb_* functions; treat mb_* as UNSUPPORTED.
        - Never read or write outside the bounds you were given, for any input whatsoever.
        PROMPT;

    public function user(MethodSpec $spec, Signature $signature): string
    {
        $context = $spec->context === '' ? '' : <<<TEXT

            Constants and helper methods of the same class that it uses:

            ```php
            {$spec->context}
            ```

            TEXT;

        return <<<TEXT
            PHP method ({$spec->key()}):

            ```php
            {$spec->source}
            ```
            {$context}
            Required C prototype:

            ```c
            {$signature->prototype()}
            ```
            TEXT;
    }
}
