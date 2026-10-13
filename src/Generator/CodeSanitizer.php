<?php

declare(strict_types=1);

namespace Forja\Generator;

/**
 * Tripwire for generated C before it is compiled and loaded into the PHP
 * process. This is a deny-list, not a sandbox: review generated sources
 * before shipping them.
 */
final class CodeSanitizer
{
    public const GENERATED_MARKER = '/* forja: generated below this line, do not edit */';

    private const ALLOWED_HEADERS = ['stdint.h', 'stddef.h', 'stdbool.h', 'stdlib.h', 'string.h', 'math.h', 'limits.h', 'float.h'];

    private const FORBIDDEN_CALLS = 'setjmp|longjmp|system|popen|fork|vfork|exec[lv]p?e?|dlopen|dlsym|socket|connect|fopen|freopen|open|creat'
        . '|unlink|remove|rename|getenv|setenv|putenv|signal|sigaction|mmap|mprotect|syscall|ptrace|kill|abort|exit|_exit';

    public function check(string $source, string $symbol): void
    {
        // Only the part the model wrote is inspected; the generated wrapper below the marker is ours.
        $source = strstr($source, self::GENERATED_MARKER, true) ?: $source;
        $withStrings = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $source);
        $code = $this->stripCommentsAndStrings($source);

        preg_match_all('/^[ \t]*#[ \t]*include[ \t]*(.+)$/m', $withStrings, $includes);
        foreach ($includes[1] as $include) {
            if (trim($include) === '"forja_rt.h"') {
                continue;
            }
            if (!preg_match('/^<([\w.\/]+)>/', trim($include), $m) || !in_array($m[1], self::ALLOWED_HEADERS, true)) {
                throw new GenerationException('generated C includes a header that is not allowed: ' . trim($include));
            }
        }
        if (preg_match('/\b(' . self::FORBIDDEN_CALLS . ')\s*\(/', $code, $m)) {
            throw new GenerationException("generated C calls forbidden function {$m[1]}()");
        }
        if (preg_match('/\b(__asm__|__asm|asm)\b|__attribute__\s*\(\(\s*constructor/', $code, $m)) {
            throw new GenerationException('generated C uses inline assembly or a constructor attribute');
        }
        if (!preg_match('/\b' . preg_quote($symbol, '/') . '\s*\(/', $code)) {
            throw new GenerationException("generated C does not define $symbol()");
        }
    }

    private function stripCommentsAndStrings(string $source): string
    {
        return (string) preg_replace(
            ['~/\*.*?\*/~s', '~//[^\n]*~', '~"(?:\\\\.|[^"\\\\\n])*"~'],
            [' ', ' ', '""'],
            $source
        );
    }
}
