<?php

declare(strict_types=1);

// Compares plain PHP, PHP with the opcache tracing JIT, and the C modules on
// three CPU-bound kernels. The kernels are called the ordinary way,
// Kernels::mandelbrot(...): the runtime swaps in the C version by itself.
//
//   php benchmarks/run.php [--model=provider:model]
//
// With --model, or with ANTHROPIC_API_KEY set, the C is written by that model.
// Otherwise the hand-written reference translations in benchmarks/c/ are used.

use Forja\Benchmarks\Kernels;
use Forja\Build\Builder;
use Forja\Build\LockFile;
use Forja\Build\Manifest;
use Forja\Build\Publisher;
use Forja\Compiler\CCompiler;
use Forja\Config;
use Forja\Generator\ModelRouter;
use Forja\Parser\MethodExtractor;
use Forja\Runtime;
use Forja\Tests\Support\FixtureGenerator;
use Forja\Verify\Comparator;
use Forja\Verify\Verifier;

require dirname(__DIR__) . '/vendor/autoload.php';

const REPEATS = 5;

/** @return array<string, list<mixed>> */
function workloads(): array
{
    mt_srand(42);
    $ints = [];
    for ($i = 0; $i < 100_000; $i++) {
        $ints[] = mt_rand(-1_000_000, 1_000_000);
    }
    $n = 120;
    $a = $b = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            $a[$i][$j] = mt_rand() / mt_getrandmax();
            $b[$i][$j] = mt_rand() / mt_getrandmax();
        }
    }

    return [
        'mandelbrot' => [320, 240, 300],
        'quicksort' => [$ints],
        'matmul' => [$a, $b],
    ];
}

/** @return array{0: float, 1: mixed} best wall time in ms, last result */
function measure(callable $fn): array
{
    $best = INF;
    $result = null;
    for ($i = 0; $i < REPEATS; $i++) {
        $start = hrtime(true);
        $result = $fn();
        $best = min($best, (hrtime(true) - $start) / 1e6);
    }

    return [$best, $result];
}

if (in_array('--child', $argv, true)) {
    Runtime::enable(false);
    $timings = $results = [];
    foreach (workloads() as $method => $args) {
        [$timings[$method], $result] = measure(static fn () => Kernels::$method(...$args));
        $results[$method] = md5(serialize($result));
    }
    $jit = function_exists('opcache_get_status') ? (opcache_get_status(false)['jit']['on'] ?? false) : false;
    echo json_encode(['jit' => $jit, 'timings' => $timings, 'results' => $results]);
    exit(0);
}

/** @param list<string> $ini */
function child(array $ini): ?array
{
    $command = [PHP_BINARY];
    foreach ($ini as $setting) {
        array_push($command, '-d', $setting);
    }
    array_push($command, __FILE__, '--child');
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    proc_close($process);

    return json_decode((string) $out, true);
}

// --- build the native modules ------------------------------------------------

$model = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--model=')) {
        $model = substr($arg, 8);
    }
}
$config = Config::fromFile(dirname(__DIR__) . '/config/forja.php');
$useModel = $model !== null || (bool) getenv('ANTHROPIC_API_KEY');
$cacheDir = __DIR__ . '/.cache/' . ($useModel ? 'model' : 'reference');
$manifest = new Manifest($cacheDir . '/manifest.json');
$builder = new Builder(
    $useModel ? new ModelRouter($config->providers) : new FixtureGenerator(__DIR__ . '/c'),
    new CCompiler(),
    new Verifier(iterations: 1000),
    $manifest,
    new LockFile($cacheDir . '/forja.lock.json'),
    $cacheDir,
    $config->models(),
    $model,
);
echo 'C source: ', $useModel ? 'written by the model' : 'hand-written reference (no --model and no ANTHROPIC_API_KEY)', "\n";
foreach ((new MethodExtractor())->extractFromFile(__DIR__ . '/Kernels.php') as $spec) {
    $entry = $builder->build($spec);
    printf("  %-11s %-10s %s%s\n", $spec->method, $entry['status'], $useModel ? "[{$entry['model']}] " : '', strtok((string) $entry['message'], "\n"));
}
(new Publisher($cacheDir))->publish($manifest);
Runtime::configure($cacheDir);

// --- measure -----------------------------------------------------------------

$plain = child(['opcache.enable_cli=0']);
$jit = child(['opcache.enable_cli=1', 'opcache.jit=tracing', 'opcache.jit_buffer_size=128M']);
$jitOn = ($jit['jit'] ?? false) === true;

printf("\nPHP %s, best of %d runs, wall time in ms\n\n", PHP_VERSION, REPEATS);
printf("%-11s %10s %10s %10s %12s %12s\n", 'kernel', 'PHP', 'PHP+JIT', 'PHP+C', 'C vs PHP', 'C vs JIT');
foreach (workloads() as $method => $args) {
    $native = null;
    $note = '';
    if (Runtime::isAccelerated(Kernels::class, $method)) {
        $before = Runtime::stats()['native'];
        [$native, $actual] = measure(static fn () => Kernels::$method(...$args));
        Runtime::enable(false);
        $expected = Kernels::$method(...$args);
        Runtime::enable(true);
        $note = Comparator::same($expected, $actual) ? '' : '  RESULT MISMATCH';
        if (Runtime::stats()['native'] - $before !== REPEATS) {
            $note .= '  (fell back to PHP)';
        }
    }
    $php = $plain['timings'][$method];
    $jitMs = $jitOn ? $jit['timings'][$method] : null;
    printf(
        "%-11s %10.2f %10s %10s %12s %12s%s\n",
        $method,
        $php,
        $jitMs === null ? 'n/a' : sprintf('%.2f', $jitMs),
        $native === null ? 'n/a' : sprintf('%.2f', $native),
        $native === null ? '-' : sprintf('%.1fx', $php / $native),
        $native === null || $jitMs === null ? '-' : sprintf('%.1fx', $jitMs / $native),
        $note
    );
}
if (!$jitOn) {
    echo "\nThe opcache JIT is not available in this PHP build, so the PHP+JIT column is empty.\n";
}
echo "\nPHP+C times include converting the arguments to C buffers and the result back to PHP arrays.\n";
