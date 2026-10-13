<?php

declare(strict_types=1);

namespace Forja\Verify;

/**
 * Seeded random arguments for a parameter list. Ranges come from the
 * attribute: "name" bounds the values, "name.len" the length of a string or
 * list (or the number of matrix rows), "name.cols" the matrix width.
 */
final class InputGenerator
{
    private const DEFAULT_INT = [-100, 100];
    private const DEFAULT_FLOAT = [-100.0, 100.0];
    private const DEFAULT_LENGTH = [0, 64];
    private const DEFAULT_MATRIX = [0, 8];

    /** @param array<string, array{0: int|float, 1: int|float}> $ranges */
    public function __construct(int $seed, private readonly array $ranges = [])
    {
        mt_srand($seed);
    }

    /**
     * @param list<array{name: string, kind: string}> $params
     * @param 'min'|'max'|null $edge take every value and length from one end of its range
     * @return list<mixed>
     */
    public function generate(array $params, ?string $edge = null): array
    {
        $args = [];
        foreach ($params as ['name' => $name, 'kind' => $kind]) {
            $element = str_starts_with($kind, 'int') ? fn () => $this->int($name, $edge) : fn () => $this->float($name, $edge);
            $args[] = match ($kind) {
                'int', 'float' => $element(),
                'bool' => $edge === null ? mt_rand(0, 1) === 1 : $edge === 'max',
                'string' => $this->string($name, $edge),
                'int[]', 'float[]' => $this->fill($this->size("$name.len", self::DEFAULT_LENGTH, $edge), $element),
                'int[][]', 'float[][]' => $this->matrix($name, $element, $edge),
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

        return $lo + ($hi - $lo) * (mt_rand() / mt_getrandmax());
    }

    private function string(string $name, ?string $edge): string
    {
        // Mostly printable ASCII, with one string in four drawn from all 256 byte values.
        [$lo, $hi] = mt_rand(0, 3) === 0 ? [0, 255] : [32, 126];
        $out = '';
        for ($i = $this->size("$name.len", self::DEFAULT_LENGTH, $edge); $i > 0; $i--) {
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
}
