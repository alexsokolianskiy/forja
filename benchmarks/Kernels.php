<?php

declare(strict_types=1);

namespace Forja\Benchmarks;

use Forja\Attributes\AccelerateToC;

final class Kernels
{
    /**
     * Total escape iterations over a w x h view of the Mandelbrot set.
     */
    #[AccelerateToC(ranges: ['w' => [1, 24], 'h' => [1, 24], 'maxIter' => [1, 60]])]
    public static function mandelbrot(int $w, int $h, int $maxIter): int
    {
        $total = 0;
        for ($py = 0; $py < $h; $py++) {
            $y0 = $py / $h * 2.0 - 1.0;
            for ($px = 0; $px < $w; $px++) {
                $x0 = $px / $w * 3.5 - 2.5;
                $x = 0.0;
                $y = 0.0;
                $i = 0;
                while ($x * $x + $y * $y <= 4.0 && $i < $maxIter) {
                    $t = $x * $x - $y * $y + $x0;
                    $y = 2.0 * $x * $y + $y0;
                    $x = $t;
                    $i++;
                }
                $total += $i;
            }
        }

        return $total;
    }

    /**
     * Iterative quicksort (Hoare partition, explicit stack).
     *
     * @param int[] $a
     * @return int[]
     */
    #[AccelerateToC(ranges: ['a' => [-1000, 1000], 'a.len' => [0, 200]])]
    public static function quicksort(array $a): array
    {
        $n = count($a);
        if ($n < 2) {
            return $a;
        }
        $stack = [0, $n - 1];
        while ($stack) {
            $hi = array_pop($stack);
            $lo = array_pop($stack);
            if ($lo >= $hi) {
                continue;
            }
            $pivot = $a[intdiv($lo + $hi, 2)];
            $i = $lo - 1;
            $j = $hi + 1;
            while (true) {
                do {
                    $i++;
                } while ($a[$i] < $pivot);
                do {
                    $j--;
                } while ($a[$j] > $pivot);
                if ($i >= $j) {
                    break;
                }
                $t = $a[$i];
                $a[$i] = $a[$j];
                $a[$j] = $t;
            }
            $stack[] = $lo;
            $stack[] = $j;
            $stack[] = $j + 1;
            $stack[] = $hi;
        }

        return $a;
    }

    /**
     * Matrix product.
     *
     * @param float[][] $a
     * @param float[][] $b
     * @return float[][]
     */
    #[AccelerateToC(ranges: ['a.len' => [1, 6], 'a.cols' => [3, 3], 'b.len' => [3, 3], 'b.cols' => [1, 6]])]
    public static function matmul(array $a, array $b): array
    {
        $rows = count($a);
        if ($rows === 0) {
            return [];
        }
        $inner = count($a[0]);
        $cols = count($b[0]);
        $c = [];
        for ($i = 0; $i < $rows; $i++) {
            $row = [];
            for ($j = 0; $j < $cols; $j++) {
                $sum = 0.0;
                for ($k = 0; $k < $inner; $k++) {
                    $sum += $a[$i][$k] * $b[$k][$j];
                }
                $row[] = $sum;
            }
            $c[] = $row;
        }

        return $c;
    }

    /**
     * How many distinct words occur more than once in a text. Words are runs of
     * ASCII letters and digits, compared case-insensitively.
     *
     * Written the usual PHP way: every step is a built-in that already runs in C.
     */
    #[AccelerateToC(ranges: ['text.len' => [0, 400]])]
    public static function repeatedWords(string $text): int
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $repeated = 0;
        foreach (array_count_values($words) as $count) {
            if ($count > 1) {
                $repeated++;
            }
        }

        return $repeated;
    }

    /**
     * The same result as repeatedWords(), computed with an explicit loop over the bytes.
     */
    #[AccelerateToC(ranges: ['text.len' => [0, 400]])]
    public static function repeatedWordsLoop(string $text): int
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
        $repeated = 0;
        foreach ($counts as $count) {
            if ($count > 1) {
                $repeated++;
            }
        }

        return $repeated;
    }
}
