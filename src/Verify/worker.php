<?php

declare(strict_types=1);

// Child process of Verifier: compares the native module with the PHP original
// on random inputs and prints a JSON report. May be killed by the code it tests.

ini_set('display_errors', 'stderr');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
require $job['autoload'];
Forja\Runtime::enable(false); // the PHP side of the comparison must be the real PHP method
if (!class_exists($job['class'])) {
    require_once $job['sourceFile'];
}

// A warning or notice means PHP considers the input invalid; C must not answer it either.
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $level, $file, $line);
});

$native = (new Forja\FFI\FFILoader())->load($job);
$method = new ReflectionMethod($job['class'], $job['method']);
$object = $method->isStatic() ? null : (new ReflectionClass($job['class']))->newInstanceWithoutConstructor();
$inputs = new Forja\Verify\InputGenerator($job['seed'], $job['ranges'] ?? []);

$report = ['ok' => true, 'checked' => 0, 'declined' => 0, 'phpErrors' => 0, 'firstPhpError' => null, 'failure' => null];
$examples = $job['examples'] ?? [];
for ($i = -count($examples); $i < $job['iterations']; $i++) {
    $args = $i < 0 ? $examples[$i + count($examples)] : $inputs->generate($job['params'], [0 => 'min', 1 => 'max'][$i] ?? null);

    $phpError = null;
    try {
        $expected = $method->invokeArgs($object, $args);
    } catch (Throwable $e) {
        $phpError = get_class($e) . ': ' . $e->getMessage();
    }

    $declined = false;
    try {
        $actual = $native($args);
    } catch (Forja\FFI\NativeException) {
        $declined = true;
    } catch (Throwable $e) {
        $actual = get_class($e) . ': ' . $e->getMessage();
    }

    if ($phpError !== null) {
        $report['phpErrors']++;
        $report['firstPhpError'] ??= $phpError;
        if (!$declined) {
            // In production the caller would get a value where PHP raises an error.
            $report['failure'] = ['args' => $args, 'expected' => $phpError, 'actual' => $actual];
            break;
        }
    } elseif ($declined) {
        $report['declined']++; // safe: the PHP method runs for this input
    } elseif (!Forja\Verify\Comparator::same($expected, $actual)) {
        $report['failure'] = ['args' => $args, 'expected' => $expected, 'actual' => $actual];
        break;
    } else {
        $report['checked']++;
    }
}
$report['ok'] = $report['failure'] === null;

echo json_encode($report, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
