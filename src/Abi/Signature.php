<?php

declare(strict_types=1);

namespace Forja\Abi;

use Forja\Parser\MethodSpec;

/**
 * The C ABI of an accelerated method. It is derived from the PHP type hints,
 * never from model output, so the FFI header always matches what PHP passes.
 *
 *   int        int64_t x
 *   float      double x
 *   bool       int32_t x
 *   string     const char *x, size_t x_len
 *   int[]      const int64_t *x, size_t x_len
 *   float[]    const double *x, size_t x_len
 *   int[][]    const int64_t *x, size_t x_rows, size_t x_cols     (row-major)
 *   float[][]  const double *x, size_t x_rows, size_t x_cols
 *
 * Strings and arrays are returned as a malloc'd buffer plus length
 * out-parameters. Every function ends with `int32_t *forja_err`: setting it
 * to non-zero makes the caller discard the result and run the PHP method.
 */
final class Signature
{
    public const KINDS = ['int', 'float', 'bool', 'string', 'int[]', 'float[]', 'int[][]', 'float[][]'];

    private const SCALARS = ['int' => 'int64_t', 'float' => 'double', 'bool' => 'int32_t'];
    private const ELEMENTS = ['int[]' => 'int64_t', 'float[]' => 'double', 'int[][]' => 'int64_t', 'float[][]' => 'double'];

    private const C_KEYWORDS = [
        'auto', 'break', 'case', 'char', 'const', 'continue', 'default', 'do', 'double', 'else', 'enum', 'extern',
        'float', 'for', 'goto', 'if', 'inline', 'int', 'long', 'register', 'restrict', 'return', 'short', 'signed',
        'sizeof', 'static', 'struct', 'switch', 'typedef', 'union', 'unsigned', 'void', 'volatile', 'while',
        'bool', 'true', 'false', 'NULL', 'forja_err', 'forja_len', 'forja_rows', 'forja_cols',
    ];

    /** @param list<array{name: string, kind: string}> $params */
    public function __construct(
        public readonly string $symbol,
        public readonly array $params,
        public readonly string $returnKind,
    ) {
    }

    public static function fromSpec(MethodSpec $spec, string $hash): self
    {
        return new self('forja_' . substr($hash, 0, 16), $spec->params, $spec->returnKind);
    }

    public static function elementType(string $kind): ?string
    {
        return self::ELEMENTS[$kind] ?? null;
    }

    public static function isMatrix(string $kind): bool
    {
        return str_ends_with($kind, '[][]');
    }

    public function freeSymbol(): string
    {
        return $this->symbol . '_free';
    }

    public function prototype(): string
    {
        $args = [];
        foreach ($this->params as ['name' => $name, 'kind' => $kind]) {
            $c = in_array($name, self::C_KEYWORDS, true) ? $name . '_' : $name;
            $args[] = match (true) {
                isset(self::SCALARS[$kind]) => self::SCALARS[$kind] . ' ' . $c,
                $kind === 'string' => "const char *$c, size_t {$c}_len",
                self::isMatrix($kind) => 'const ' . self::ELEMENTS[$kind] . " *$c, size_t {$c}_rows, size_t {$c}_cols",
                default => 'const ' . self::ELEMENTS[$kind] . " *$c, size_t {$c}_len",
            };
        }

        $kind = $this->returnKind;
        if ($kind === 'string') {
            $return = 'char *';
            $args[] = 'int64_t *forja_len';
        } elseif (self::isMatrix($kind)) {
            $return = self::ELEMENTS[$kind] . ' *';
            $args[] = 'int64_t *forja_rows, int64_t *forja_cols';
        } elseif (isset(self::ELEMENTS[$kind])) {
            $return = self::ELEMENTS[$kind] . ' *';
            $args[] = 'int64_t *forja_len';
        } else {
            $return = (self::SCALARS[$kind] ?? 'void') . ' ';
        }
        $args[] = 'int32_t *forja_err';

        return sprintf('%s%s(%s);', $return, $this->symbol, implode(', ', $args));
    }

    /** What FFI is told about the library. */
    public function header(): string
    {
        return $this->prototype() . "\nvoid {$this->freeSymbol()}(void *p);";
    }

    /** Appended to the generated source so PHP can release returned buffers with the library's own allocator. */
    public function epilogue(): string
    {
        return "\n/* forja: release hook */\n#include <stdlib.h>\nvoid {$this->freeSymbol()}(void *p) { free(p); }\n";
    }
}
