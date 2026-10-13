<?php

declare(strict_types=1);

namespace AiAccel\FFI;

use AiAccel\Abi\Signature;
use FFI;

/**
 * Marshals PHP values into the C ABI described by Signature and back.
 *
 * Every argument is validated before the native call: C cannot report a bad
 * pointer or a wrong element type, so nothing unchecked may reach it.
 */
final class NativeFunction
{
    private const PACK = ['int64_t' => 'q', 'double' => 'd'];

    private readonly FFI\CData $err;
    private readonly FFI\CData $errPtr;
    private readonly FFI\CData $sizeA;
    private readonly FFI\CData $sizeB;
    private readonly string $freeSymbol;

    /** @param list<array{name: string, kind: string}> $params */
    public function __construct(
        private readonly FFI $ffi,
        private readonly string $symbol,
        private readonly array $params,
        private readonly string $returnKind,
    ) {
        // The C function never calls back into PHP, so one set of out-cells per function is enough.
        $this->err = $ffi->new('int32_t');
        $this->errPtr = FFI::addr($this->err);
        $this->sizeA = $ffi->new('int64_t');
        $this->sizeB = $ffi->new('int64_t');
        $this->freeSymbol = $symbol . '_free';
    }

    /**
     * @param list<mixed> $args
     * @throws MarshalException when the arguments do not fit the C signature
     * @throws NativeException  when the C function asks for the PHP version to run instead
     */
    public function __invoke(array $args): mixed
    {
        if (count($args) !== count($this->params)) {
            throw new MarshalException('expected exactly ' . count($this->params) . ' arguments');
        }

        $native = [];
        foreach ($this->params as $i => ['name' => $name, 'kind' => $kind]) {
            $value = $args[$i];
            switch ($kind) {
                case 'int':
                    is_int($value) or throw new MarshalException("\$$name must be int");
                    $native[] = $value;
                    break;
                case 'float':
                    (is_float($value) || is_int($value)) or throw new MarshalException("\$$name must be float");
                    $native[] = (float) $value;
                    break;
                case 'bool':
                    is_bool($value) or throw new MarshalException("\$$name must be bool");
                    $native[] = (int) $value;
                    break;
                case 'string':
                    is_string($value) or throw new MarshalException("\$$name must be string");
                    $native[] = $value;
                    $native[] = strlen($value);
                    break;
                case 'int[]':
                case 'float[]':
                    (is_array($value) && array_is_list($value)) or throw new MarshalException("\$$name must be a list");
                    $native[] = $this->pack($name, $value, Signature::elementType($kind));
                    $native[] = count($value);
                    break;
                default:
                    [$flat, $rows, $cols] = $this->flatten($name, $value);
                    $native[] = $this->pack($name, $flat, Signature::elementType($kind));
                    $native[] = $rows;
                    $native[] = $cols;
            }
        }

        $kind = $this->returnKind;
        $buffered = $kind === 'string' || Signature::elementType($kind) !== null;
        if ($buffered) {
            $this->sizeA->cdata = 0;
            $this->sizeB->cdata = 0;
            $native[] = FFI::addr($this->sizeA);
            if (Signature::isMatrix($kind)) {
                $native[] = FFI::addr($this->sizeB);
            }
        }
        $this->err->cdata = 0;
        $native[] = $this->errPtr;

        $result = $this->ffi->{$this->symbol}(...$native);

        if (!$buffered) {
            if ($this->err->cdata !== 0) {
                throw new NativeException('native code declined these arguments');
            }

            return $kind === 'bool' ? $result !== 0 : $result;
        }

        try {
            if ($this->err->cdata !== 0) {
                throw new NativeException('native code declined these arguments');
            }

            return $this->unpackResult($kind, $result, $this->sizeA->cdata, $this->sizeB->cdata);
        } finally {
            if ($result !== null) {
                $this->ffi->{$this->freeSymbol}($result);
            }
        }
    }

    private function unpackResult(string $kind, ?FFI\CData $buffer, int $a, int $b): string|array
    {
        $isMatrix = Signature::isMatrix($kind);
        $count = $isMatrix ? $a * $b : $a;
        if ($a < 0 || $b < 0 || ($count > 0 && $buffer === null)) {
            throw new NativeException("native code returned an invalid buffer (size $a x $b)");
        }
        if ($kind === 'string') {
            return $count === 0 ? '' : FFI::string($buffer, $count);
        }

        $flat = $count === 0
            ? []
            : array_values(unpack(self::PACK[Signature::elementType($kind)] . '*', FFI::string($buffer, $count * 8)));
        if (!$isMatrix) {
            return $flat;
        }

        return $b === 0 ? array_fill(0, $a, []) : array_chunk($flat, $b);
    }

    /**
     * @param list<mixed> $list
     */
    private function pack(string $name, array $list, string $elementType): ?FFI\CData
    {
        $length = count($list);
        if ($length === 0) {
            return null;
        }
        if ($elementType === 'int64_t') {
            foreach ($list as $element) {
                is_int($element) or throw new MarshalException("\$$name must contain only ints");
            }
        } else {
            foreach ($list as $element) {
                (is_float($element) || is_int($element)) or throw new MarshalException("\$$name must contain only floats");
            }
        }

        $buffer = $this->ffi->new("{$elementType}[$length]");
        FFI::memcpy($buffer, pack(self::PACK[$elementType] . '*', ...$list), $length * 8);

        return $buffer;
    }

    /** @return array{0: list<mixed>, 1: int, 2: int} [row-major elements, rows, columns] */
    private function flatten(string $name, mixed $matrix): array
    {
        (is_array($matrix) && array_is_list($matrix)) or throw new MarshalException("\$$name must be a list of rows");
        $rows = count($matrix);
        if ($rows === 0) {
            return [[], 0, 0];
        }
        $cols = is_array($matrix[0]) ? count($matrix[0]) : -1;
        foreach ($matrix as $row) {
            (is_array($row) && count($row) === $cols && array_is_list($row))
                or throw new MarshalException("\$$name must be a rectangular list of lists");
        }

        return [$cols === 0 ? [] : array_merge(...$matrix), $rows, $cols];
    }
}
