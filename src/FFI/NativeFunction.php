<?php

declare(strict_types=1);

namespace Forja\FFI;

use Forja\Abi\Signature;
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
    private readonly bool $serializedReturn;

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
        $this->serializedReturn = Signature::returnsSerialized($params, $returnKind);
        if (Signature::usesRuntime($params, $returnKind) && !in_array(ini_get('serialize_precision'), ['-1', '17'], true)) {
            // Floats would lose digits on the way to C.
            throw new \RuntimeException('serialize_precision must be -1 (the PHP default) for methods that pass arrays of mixed values');
        }
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
                case 'mixed':
                    $this->ensureRoomToSerialize($name, $value);
                    try {
                        $serialized = serialize($value);
                    } catch (\Throwable $e) {
                        throw new MarshalException("\$$name cannot be passed to C: " . $e->getMessage());
                    }
                    $native[] = $serialized;
                    $native[] = strlen($serialized);
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
        $buffered = $this->serializedReturn || $kind === 'string' || Signature::elementType($kind) !== null;
        if ($buffered) {
            $this->sizeA->cdata = 0;
            $this->sizeB->cdata = 0;
            $native[] = FFI::addr($this->sizeA);
            if (!$this->serializedReturn && Signature::isMatrix($kind)) {
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

            if ($this->serializedReturn) {
                return $this->unserializeResult($result, $this->sizeA->cdata);
            }

            return $this->unpackResult($kind, $result, $this->sizeA->cdata, $this->sizeB->cdata);
        } finally {
            if ($result !== null) {
                $this->ffi->{$this->freeSymbol}($result);
            }
        }
    }

    /**
     * Serializing copies the value, and running out of memory is a fatal error that no fallback
     * can catch. So a value that might not fit under memory_limit is not sent to C at all.
     */
    private function ensureRoomToSerialize(string $name, mixed $value): void
    {
        $limit = self::memoryLimit();
        if ($limit <= 0) {
            return;
        }
        $estimate = is_array($value) ? count($value, COUNT_RECURSIVE) * 64 : (is_string($value) ? strlen($value) : 0);
        if ($estimate * 2 > $limit - memory_get_usage()) {
            throw new MarshalException("\$$name is too large to copy within memory_limit");
        }
    }

    private static function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value << 30,
            'm' => $value << 20,
            'k' => $value << 10,
            default => $value,
        };
    }

    private function unserializeResult(?FFI\CData $buffer, int $length): mixed
    {
        if ($buffer === null || $length <= 0) {
            throw new NativeException('native code returned no value');
        }
        $serialized = FFI::string($buffer, $length);
        $value = @unserialize($serialized, ['allowed_classes' => false]);
        if ($value === false && $serialized !== 'b:0;') {
            throw new NativeException('native code returned a value PHP cannot read');
        }

        return $value;
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
