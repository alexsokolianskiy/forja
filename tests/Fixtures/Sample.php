<?php

declare(strict_types=1);

namespace Forja\Tests\Fixtures;

use Forja\Attributes\AccelerateToC;
use Forja\Model;

function userHelper(int $n): int
{
    return $n;
}

final class Sample
{
    private const LETTERS = 26;

    private int $offset = 1;

    #[AccelerateToC(ranges: ['n' => [0, 500]])]
    public static function sumSquares(int $n): int
    {
        $sum = 0;
        for ($i = 1; $i <= $n; $i++) {
            $sum += $i * $i;
        }

        return $sum;
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    #[AccelerateToC]
    public static function dot(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    #[AccelerateToC(model: 'claude-haiku-5-5')]
    public static function vowels(string $s): int
    {
        $count = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if (str_contains('aeiou', $s[$i])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param int[] $xs
     * @return int[]
     */
    #[AccelerateToC]
    public static function scale(array $xs, int $k): array
    {
        $out = [];
        foreach ($xs as $x) {
            $out[] = $x * $k;
        }

        return $out;
    }

    #[AccelerateToC(ranges: ['n' => [-5, 2000]])]
    public static function isPrime(int $n): bool
    {
        if ($n < 2) {
            return false;
        }
        for ($d = 2; $d * $d <= $n; $d++) {
            if ($n % $d === 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int> $xs */
    #[AccelerateToC]
    public static function sumList(array $xs): int
    {
        $sum = 0;
        foreach ($xs as $x) {
            $sum += (int) $x;
        }

        return $sum;
    }

    #[AccelerateToC(model: Model::OPUS, lock: true)]
    public function triple(int $n): int
    {
        return $n * 3;
    }

    #[AccelerateToC(ranges: ['shift' => [-60, 60]])]
    public function caesar(string $s, int $shift): string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $code = ord($s[$i]);
            if ($code >= 97 && $code <= 122) {
                $out .= chr($this->rotate($code, 97, $shift));
            } elseif ($code >= 65 && $code <= 90) {
                $out .= chr(self::rotate($code, 65, $shift));
            } else {
                $out .= $s[$i];
            }
        }

        return $out;
    }

    private static function rotate(int $code, int $base, int $shift): int
    {
        return $base + (($code - $base + $shift) % self::LETTERS + self::LETTERS) % self::LETTERS;
    }

    /**
     * @param int[][] $m
     * @return int[][]
     */
    #[AccelerateToC]
    public static function transpose(array $m): array
    {
        $out = [];
        foreach ($m as $i => $row) {
            foreach ($row as $j => $value) {
                $out[$j][$i] = $value;
            }
        }

        return $out;
    }

    /**
     * @param list<list<float>> $m
     * @return list<float>
     */
    #[AccelerateToC]
    public static function rowSums(array $m): array
    {
        $out = [];
        foreach ($m as $row) {
            $sum = 0.0;
            foreach ($row as $value) {
                $sum += $value;
            }
            $out[] = $sum;
        }

        return $out;
    }

    #[AccelerateToC(ranges: ['count' => [-3, 3]])]
    public static function average(int $total, int $count): int
    {
        if ($count === 0) {
            throw new \InvalidArgumentException('count must not be zero');
        }

        return intdiv($total, $count);
    }

    #[AccelerateToC]
    public static function touch(int $n): void
    {
        $unused = $n + 1;
    }

    #[AccelerateToC(ranges: ['count' => [-3, 3]])]
    public static function swallowsError(int $total, int $count): int
    {
        return intdiv($total, $count);
    }

    #[AccelerateToC]
    public static function crashy(int $n): int
    {
        return $n + 0;
    }

    #[AccelerateToC]
    public static function wrong(int $n): int
    {
        return $n * 2;
    }

    #[AccelerateToC]
    public static function broken(int $n): int
    {
        return $n + 1;
    }

    #[AccelerateToC]
    public static function evil(int $n): int
    {
        return $n + 0;
    }

    #[AccelerateToC]
    public function usesThis(int $n): int
    {
        return $n + $this->offset;
    }

    #[AccelerateToC]
    public static function untyped($n): int
    {
        return (int) $n;
    }

    /**
     * @return array<string, int>
     */
    #[AccelerateToC(ranges: ['text.len' => [0, 200]], examples: [['The cat and the hat. THE END 7 7 007'], ['']])]
    public static function wordCounts(string $text): array
    {
        $counts = [];
        $word = '';
        $length = strlen($text);
        for ($i = 0; $i <= $length; $i++) {
            $code = $i < $length ? ord($text[$i]) : 0;
            if ($code >= 65 && $code <= 90) {
                $code += 32;
            }
            if (($code >= 97 && $code <= 122) || ($code >= 48 && $code <= 57)) {
                $word .= chr($code);
            } elseif ($word !== '') {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
                $word = '';
            }
        }

        return $counts;
    }

    /**
     * The shape of rows an ORM query returns after ->toArray().
     *
     * @param list<array{customer: string, amount: float, qty: int}> $orders
     * @return array<string, float>
     */
    #[AccelerateToC]
    public static function totalsByCustomer(array $orders): array
    {
        $totals = [];
        foreach ($orders as $order) {
            $customer = $order['customer'];
            $totals[$customer] = ($totals[$customer] ?? 0.0) + $order['amount'] * $order['qty'];
        }

        return $totals;
    }

    #[AccelerateToC]
    public static function orDefault(?int $value, int|string $default): int|string
    {
        return $value ?? $default;
    }

    /** @param array<int|string, int> $map */
    #[AccelerateToC]
    public static function dropNegatives(array $map, ?int $limit): array
    {
        foreach ($map as $key => $value) {
            if ($value < 0 || ($limit !== null && $value > $limit)) {
                unset($map[$key]);
            }
        }
        $map[] = count($map);

        return $map;
    }

    #[AccelerateToC]
    public static function takesObject(\DateTimeInterface $when): int
    {
        return 1;
    }

    #[AccelerateToC]
    public static function callsUserFunction(int $n): int
    {
        return userHelper($n);
    }

    #[AccelerateToC]
    public static function prints(int $n): int
    {
        echo $n;

        return $n;
    }

    // No attribute, but a loop: picked up only by --auto.
    public static function autoSum(int $n): int
    {
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $i;
        }

        return $sum;
    }

    // No attribute and a loop, but tied to object state: --auto must skip it silently.
    public function autoSkipped(int $n): int
    {
        for ($i = 0; $i < $n; $i++) {
            $this->offset++;
        }

        return $this->offset;
    }

    public static function plain(int $n): int
    {
        return $n;
    }

    public static function whereAmI(): string
    {
        return __FILE__ . '|' . __DIR__ . '|' . __LINE__;
    }
}
