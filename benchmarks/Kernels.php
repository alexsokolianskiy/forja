<?php

declare(strict_types=1);

namespace AiAccel\Benchmarks;

use AiAccel\Attributes\AccelerateToC;

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
}
