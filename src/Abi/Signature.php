<?php

declare(strict_types=1);

namespace Forja\Abi;

use Forja\Parser\MethodSpec;

/**
 * The C ABI of an accelerated method. It is derived from the PHP type hints,
 * never from model output, so the FFI header always matches what PHP passes.
 *
 * Kinds with a fixed C layout are passed directly:
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
 * Everything else is the kind `mixed`: any PHP value built from null, bool,
 * int, float, string and arrays. It crosses the boundary in PHP's serialize
 * format. A method that uses `mixed` anywhere is "dynamic": the model
 * implements `<symbol>_impl` against the runtime in forja_rt.h, and the
 * exported function is a generated wrapper that decodes the arguments, owns
 * the memory arena and encodes the result.
 *
 * Every exported function ends with `int32_t *forja_err`: non-zero makes the
 * caller discard the result and run the PHP method.
 */
final class Signature
{
    public const KINDS = ['int', 'float', 'bool', 'string', 'int[]', 'float[]', 'int[][]', 'float[][]', 'mixed'];

    private const SCALARS = ['int' => 'int64_t', 'float' => 'double', 'bool' => 'int32_t'];
    private const ELEMENTS = ['int[]' => 'int64_t', 'float[]' => 'double', 'int[][]' => 'int64_t', 'float[][]' => 'double'];

    private const C_KEYWORDS = [
        'auto', 'break', 'case', 'char', 'const', 'continue', 'default', 'do', 'double', 'else', 'enum', 'extern',
        'float', 'for', 'goto', 'if', 'inline', 'int', 'long', 'register', 'restrict', 'return', 'short', 'signed',
        'sizeof', 'static', 'struct', 'switch', 'typedef', 'union', 'unsigned', 'void', 'volatile', 'while',
        'bool', 'true', 'false', 'NULL', 'ctx', 'result', 'forja_err', 'forja_len', 'forja_rows', 'forja_cols',
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

    /** @param list<array{name: string, kind: string}> $params */
    public static function usesRuntime(array $params, string $returnKind): bool
    {
        return $returnKind === 'mixed' || in_array('mixed', array_column($params, 'kind'), true);
    }

    /**
     * In a dynamic method every non-scalar result travels serialized, so the implementation
     * never has to hand a malloc'd buffer across a possible fj_fail().
     *
     * @param list<array{name: string, kind: string}> $params
     */
    public static function returnsSerialized(array $params, string $returnKind): bool
    {
        return self::usesRuntime($params, $returnKind) && !isset(self::SCALARS[$returnKind]) && $returnKind !== 'void';
    }

    public function isDynamic(): bool
    {
        return self::usesRuntime($this->params, $this->returnKind);
    }

    public function freeSymbol(): string
    {
        return $this->symbol . '_free';
    }

    /** The function PHP calls. */
    public function prototype(): string
    {
        return $this->declaration(false) . ';';
    }

    /** The function the model has to write. */
    public function modelPrototype(): string
    {
        return $this->isDynamic() ? $this->declaration(true) . ';' : $this->prototype();
    }

    /** The name the model's function must have. */
    public function modelSymbol(): string
    {
        return $this->isDynamic() ? $this->symbol . '_impl' : $this->symbol;
    }

    /** What FFI is told about the library. */
    public function header(): string
    {
        return $this->prototype() . "\nvoid {$this->freeSymbol()}(void *p);";
    }

    /** Appended to the model's source: the release hook and, for dynamic methods, the wrapper. */
    public function epilogue(): string
    {
        $code = "\n" . \Forja\Generator\CodeSanitizer::GENERATED_MARKER . "\n#include <stdlib.h>\n"
            . "void {$this->freeSymbol()}(void *p) { free(p); }\n";
        if (!$this->isDynamic()) {
            return $code;
        }

        $serialized = self::returnsSerialized($this->params, $this->returnKind);
        $zero = $this->returnKind === 'void' ? '' : ($serialized ? ' NULL' : ' 0');
        $call = "{$this->symbol}_impl(" . implode(', ', ['ctx', ...array_column($this->parts(), 'pass')]) . ')';
        $body = match (true) {
            $serialized => "    fj_val result = $call;\n    char *out = fj_serialize(ctx, result, forja_len);\n"
                . "    fj_ctx_free(ctx);\n    return out;\n",
            $this->returnKind === 'void' => "    $call;\n    fj_ctx_free(ctx);\n",
            default => '    ' . self::SCALARS[$this->returnKind] . " result = $call;\n    fj_ctx_free(ctx);\n    return result;\n",
        };

        return $code . "#include \"forja_rt.h\"\n" . $this->declaration(false) . "\n{\n"
            . "    fj_ctx *ctx = fj_ctx_new();\n"
            . "    if (ctx == NULL) {\n        *forja_err = 1;\n        return$zero;\n    }\n"
            . "    if (setjmp(ctx->escape)) {\n        fj_ctx_free(ctx);\n        *forja_err = 1;\n        return$zero;\n    }\n"
            . $body . "}\n";
    }

    /**
     * @return list<array{exported: string, impl: string, pass: string}> one entry per PHP parameter
     */
    private function parts(): array
    {
        $parts = [];
        foreach ($this->params as ['name' => $name, 'kind' => $kind]) {
            $c = in_array($name, self::C_KEYWORDS, true) ? $name . '_' : $name;
            if ($kind === 'mixed') {
                $parts[] = [
                    'exported' => "const char *$c, size_t {$c}_len",
                    'impl' => "fj_val $c",
                    'pass' => "fj_unserialize(ctx, $c, {$c}_len)",
                ];
                continue;
            }
            [$decl, $pass] = match (true) {
                isset(self::SCALARS[$kind]) => [self::SCALARS[$kind] . ' ' . $c, $c],
                $kind === 'string' => ["const char *$c, size_t {$c}_len", "$c, {$c}_len"],
                self::isMatrix($kind) => ['const ' . self::ELEMENTS[$kind] . " *$c, size_t {$c}_rows, size_t {$c}_cols", "$c, {$c}_rows, {$c}_cols"],
                default => ['const ' . self::ELEMENTS[$kind] . " *$c, size_t {$c}_len", "$c, {$c}_len"],
            };
            $parts[] = ['exported' => $decl, 'impl' => $decl, 'pass' => $pass];
        }

        return $parts;
    }

    private function declaration(bool $impl): string
    {
        $kind = $this->returnKind;
        $scalar = (self::SCALARS[$kind] ?? 'void') . ' ';

        if ($impl) {
            $return = self::returnsSerialized($this->params, $kind) ? 'fj_val ' : $scalar;

            return sprintf('static %s%s_impl(%s)', $return, $this->symbol, implode(', ', ['fj_ctx *ctx', ...array_column($this->parts(), 'impl')]));
        }

        $args = array_column($this->parts(), 'exported');
        if (self::returnsSerialized($this->params, $kind) || $kind === 'string') {
            $return = 'char *';
            $args[] = 'int64_t *forja_len';
        } elseif (self::isMatrix($kind)) {
            $return = self::ELEMENTS[$kind] . ' *';
            $args[] = 'int64_t *forja_rows, int64_t *forja_cols';
        } elseif (isset(self::ELEMENTS[$kind])) {
            $return = self::ELEMENTS[$kind] . ' *';
            $args[] = 'int64_t *forja_len';
        } else {
            $return = $scalar;
        }
        $args[] = 'int32_t *forja_err';

        return sprintf('%s%s(%s)', $return, $this->symbol, implode(', ', $args));
    }
}
