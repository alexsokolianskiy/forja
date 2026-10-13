<?php

declare(strict_types=1);

namespace Forja\Generator;

use Forja\Abi\Signature;
use Forja\Parser\MethodSpec;

final class PromptBuilder
{
    /** Bump whenever the prompt text changes: it is part of the module hash. */
    public const VERSION = '3';

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
          Store its size in `*forja_len` (bytes or elements), or in `*forja_rows` and `*forja_cols` for a matrix.
          An empty result may be returned as NULL with size 0.
        - `int32_t *forja_err` is always the last parameter and is 0 on entry. Set `*forja_err = 1` and return
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
          if you are not certain of a rule, set `*forja_err = 1` for the inputs it affects.
        - String functions operate on bytes unless they are mb_* functions; treat mb_* as UNSUPPORTED.
        - Never read or write outside the bounds you were given, for any input whatsoever.

        Dynamic methods:
        - When the prototype you are given is `static ... <name>_impl(fj_ctx *ctx, ...)`, the method handles PHP values
          without a fixed C layout (fj_val: null, bool, int, float, string, or a PHP array). Then:
            * start with `#include "forja_rt.h"` and implement exactly that `_impl` function; the exported wrapper,
              argument decoding and result encoding are generated for you;
            * use only the runtime API you are shown for values, strings and arrays; allocate with fj_alloc and
              never call malloc or free: all memory is released after the call;
            * there is no forja_err parameter: call fj_fail(ctx) instead, in every situation listed above for
              forja_err, and whenever a value does not have the type your code handles. Check `type` before reading
              a fj_val. Do not emulate PHP's type juggling (numeric strings, int/float/bool/null conversions,
              loose comparison): on such input call fj_fail(ctx) and let PHP do it;
            * reading a missing array key is a PHP warning: call fj_fail(ctx), unless the PHP code guards it with
              isset(), ?? or array_key_exists();
            * build the result with the same keys, in the same order, with the same value types as PHP would:
              1 and 1.0 are different results, and so are "1" and 1.
        PROMPT;

    /** The declarations of forja_rt.h that generated code may use. */
    public static function runtimeApi(): string
    {
        static $api = null;
        if ($api === null) {
            $header = (string) file_get_contents(dirname(__DIR__, 2) . '/runtime/forja_rt.h');
            preg_match('~/\* API-BEGIN \*/(.*?)/\* API-END \*/~s', $header, $m);
            $api = trim($m[1] ?? '');
        }

        return $api;
    }

    public function user(MethodSpec $spec, Signature $signature): string
    {
        $text = "PHP method ({$spec->key()}):\n\n```php\n{$spec->source}\n```\n";
        if ($spec->context !== '') {
            $text .= "\nConstants and helper methods of the same class that it uses:\n\n```php\n{$spec->context}\n```\n";
        }
        if ($signature->isDynamic()) {
            $text .= "\nRuntime API (forja_rt.h):\n\n```c\n" . self::runtimeApi() . "\n```\n";
        }

        return $text . "\nRequired C prototype:\n\n```c\n{$signature->modelPrototype()}\n```\n";
    }
}
