<?php

declare(strict_types=1);

namespace Forja\Verify;

/**
 * Seeded random arguments for a parameter list.
 *
 * Ranges come from the attribute: "name" bounds numeric values, "name.len"
 * the length of a string or array (or the number of matrix rows), and
 * "name.cols" the matrix width. For `mixed` parameters the declared type
 * (int|string, ?float, array<string, int>, list<array<string, mixed>>, ...)
 * decides what is generated.
 */
final class InputGenerator
{
    private const DEFAULT_INT = [-100, 100];
    private const DEFAULT_FLOAT = [-100.0, 100.0];
    private const DEFAULT_LENGTH = [0, 64];
    private const DEFAULT_MATRIX = [0, 8];
    private const DEFAULT_DYNAMIC = [0, 12];
    private const MAX_DEPTH = 3;
    private const COMMON_STRINGS = ['a', 'b', 'ab', 'A', '7', '-3', '07', '', 'x y'];

    /** @param array<string, array{0: int|float, 1: int|float}> $ranges */
    public function __construct(int $seed, private readonly array $ranges = [])
    {
        mt_srand($seed);
    }

    /**
     * @param list<array{name: string, kind: string, shape?: string}> $params
     * @param 'min'|'max'|null $edge take every value and length from one end of its range
     * @return list<mixed>
     */
    public function generate(array $params, ?string $edge = null): array
    {
        $args = [];
        foreach ($params as $param) {
            ['name' => $name, 'kind' => $kind] = $param;
            $element = str_starts_with($kind, 'int') ? fn () => $this->int($name, $edge) : fn () => $this->float($name, $edge);
            $args[] = match ($kind) {
                'int', 'float' => $element(),
                'bool' => $edge === null ? mt_rand(0, 1) === 1 : $edge === 'max',
                'string' => $this->string($name, $edge),
                'int[]', 'float[]' => $this->fill($this->size("$name.len", self::DEFAULT_LENGTH, $edge), $element),
                'int[][]', 'float[][]' => $this->matrix($name, $element, $edge),
                'mixed' => $this->fromShape($param['shape'] ?? 'mixed', $name, $edge, 0),
            };
        }

        return $args;
    }

    private function int(string $name, ?string $edge): int
    {
        [$lo, $hi] = $this->ranges[$name] ?? self::DEFAULT_INT;

        return $edge === null ? mt_rand((int) $lo, (int) $hi) : (int) ($edge === 'min' ? $lo : $hi);
    }

    private function float(string $name, ?string $edge): float
    {
        [$lo, $hi] = $this->ranges[$name] ?? self::DEFAULT_FLOAT;
        if ($edge !== null) {
            return (float) ($edge === 'min' ? $lo : $hi);
        }
        $value = $lo + ($hi - $lo) * (mt_rand() / mt_getrandmax());

        // Whole-number floats catch code that confuses 2.0 with 2.
        return mt_rand(0, 5) === 0 ? round($value) : $value;
    }

    private function string(string $name, ?string $edge, ?array $length = null): string
    {
        // Mostly printable ASCII, with one string in four drawn from all 256 byte values.
        [$lo, $hi] = mt_rand(0, 3) === 0 ? [0, 255] : [32, 126];
        $out = '';
        for ($i = $this->size("$name.len", $length ?? self::DEFAULT_LENGTH, $edge); $i > 0; $i--) {
            $out .= chr(mt_rand($lo, $hi));
        }

        return $out;
    }

    /** @return list<list<int|float>> */
    private function matrix(string $name, callable $element, ?string $edge): array
    {
        $rows = $this->size("$name.len", self::DEFAULT_MATRIX, $edge);
        $cols = $this->size("$name.cols", self::DEFAULT_MATRIX, $edge);

        return $this->fill($rows, fn () => $this->fill($cols, $element));
    }

    /** @return list<mixed> */
    private function fill(int $count, callable $make): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $make();
        }

        return $out;
    }

    /** @param array{0: int, 1: int} $default */
    private function size(string $key, array $default, ?string $edge): int
    {
        [$lo, $hi] = $this->ranges[$key] ?? $default;

        return max(0, $edge === null ? mt_rand((int) $lo, (int) $hi) : (int) ($edge === 'min' ? $lo : $hi));
    }

    private function fromShape(string $shape, string $name, ?string $edge, int $depth): mixed
    {
        $shape = trim($shape);
        $alternatives = $this->split($shape, '|');
        if (count($alternatives) > 1) {
            $pick = $edge === null ? mt_rand(0, count($alternatives) - 1) : ($edge === 'min' ? 0 : count($alternatives) - 1);

            return $this->fromShape($alternatives[$pick], $name, $edge, $depth);
        }
        if (str_starts_with($shape, '?')) {
            return ($edge === 'min' || ($edge === null && mt_rand(0, 3) === 0)) ? null : $this->fromShape(substr($shape, 1), $name, $edge, $depth);
        }
        if (preg_match('/^(?:array|list)\{(.*)\}$/s', $shape, $m)) {
            // array{customer: string, amount: float, note?: string}: the row of a query result, typically.
            $row = [];
            foreach ($this->split($m[1], ',') as $i => $field) {
                [$key, $type] = array_pad(array_map('trim', explode(':', $field, 2)), -2, (string) $i);
                if (str_ends_with($key, '?')) {
                    if (mt_rand(0, 1) === 0) {
                        continue;
                    }
                    $key = substr($key, 0, -1);
                }
                $row[trim($key, '\'"')] = $this->fromShape($type, $name, $edge, $depth + 1);
            }

            return $row;
        }
        if (str_ends_with($shape, '[]')) {
            return $this->container($name, $edge, $depth, null, substr($shape, 0, -2));
        }
        if (preg_match('/^([\w-]+)<(.*)>$/s', $shape, $m)) {
            $args = $this->split($m[2], ',');
            $isList = str_contains($m[1], 'list') || count($args) === 1;

            return $this->container($name, $edge, $depth, $isList ? null : $args[0], $args[count($args) - 1]);
        }

        return match (strtolower($shape)) {
            'int', 'positive-int', 'negative-int', 'non-negative-int' => $this->int($name, $edge),
            'float' => $this->float($name, $edge),
            'numeric', 'int|float' => mt_rand(0, 1) ? $this->int($name, $edge) : $this->float($name, $edge),
            'bool', 'boolean' => $edge === null ? mt_rand(0, 1) === 1 : $edge === 'max',
            'true' => true,
            'false' => false,
            'null' => null,
            'string', 'non-empty-string', 'numeric-string' => $depth === 0
                ? $this->string($name, $edge)
                // Inside arrays, repeat a few values often: grouping and lookup code needs collisions,
                // and "7" becomes an int key when it is used as one.
                : (mt_rand(0, 1) ? self::COMMON_STRINGS[mt_rand(0, count(self::COMMON_STRINGS) - 1)] : $this->string($name, $edge, [0, 8])),
            'scalar' => $this->scalar($name, $edge),
            'array', 'list', 'iterable' => $this->container($name, $edge, $depth, mt_rand(0, 1) ? null : 'int|string', 'mixed'),
            default => $this->anything($name, $edge, $depth),
        };
    }

    private function scalar(string $name, ?string $edge): int|float|bool|string
    {
        return match (mt_rand(0, 3)) {
            0 => $this->int($name, $edge),
            1 => $this->float($name, $edge),
            2 => mt_rand(0, 1) === 1,
            default => $this->string($name, $edge, [0, 8]),
        };
    }

    private function anything(string $name, ?string $edge, int $depth): mixed
    {
        return match (mt_rand(0, $depth >= self::MAX_DEPTH ? 4 : 6)) {
            0 => null,
            1, 2, 3, 4 => $this->scalar($name, $edge),
            default => $this->container($name, $edge, $depth, mt_rand(0, 1) ? null : 'int|string', 'mixed'),
        };
    }

    /**
     * @param string|null $keyShape null for a list, otherwise the key type
     * @return array<array-key, mixed>
     */
    private function container(string $name, ?string $edge, int $depth, ?string $keyShape, string $valueShape): array
    {
        $count = $depth === 0
            ? $this->size("$name.len", self::DEFAULT_DYNAMIC, $edge)
            : ($depth >= self::MAX_DEPTH ? 0 : mt_rand(0, 4));
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $this->fromShape($valueShape, $name, $edge, $depth + 1);
            if ($keyShape === null) {
                $out[] = $value;
                continue;
            }
            $wantsInt = !str_contains($keyShape, 'string') || (str_contains($keyShape, 'int') && mt_rand(0, 1) === 1);
            // Few distinct keys, so that lookups hit and values get overwritten; "7" exercises PHP's
            // rule that a numeric string key is an int key.
            $out[$wantsInt ? mt_rand(-2, 9) : (mt_rand(0, 7) === 0 ? (string) mt_rand(0, 9) : substr('abcab', mt_rand(0, 3), mt_rand(1, 2)))] = $value;
        }

        return $out;
    }

    /** @return list<string> the parts of $text separated by $separator outside any <...> */
    private function split(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            $depth += ($c === '<' || $c === '{' || $c === '(') <=> ($c === '>' || $c === '}' || $c === ')');
            if ($c === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $c;
            }
        }
        $parts[] = trim($current);

        return $parts;
    }
}
