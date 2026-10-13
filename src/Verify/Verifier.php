<?php

declare(strict_types=1);

namespace Forja\Verify;

/**
 * Differential test of a freshly compiled module against the original PHP
 * method, run in a child process.
 *
 * A segfault in C cannot be caught by try/catch: it kills the process. Running
 * the comparison in a throwaway child is what keeps a bad module from ever
 * being loaded into a request-serving process.
 */
final class Verifier
{
    public function __construct(
        private readonly int $iterations = 1000,
        private readonly int $timeout = 60,
        private readonly ?string $autoload = null,
    ) {
    }

    /** @param array<string, mixed> $entry manifest entry of the module under test */
    public function verify(array $entry): VerifyResult
    {
        $job = $entry + [
            'autoload' => $this->autoload ?? self::composerAutoload(),
            'iterations' => $this->iterations,
            'seed' => crc32($entry['hash']),
        ];

        $process = proc_open(
            [PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return new VerifyResult(false, 'could not start the verification process');
        }
        fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = $stderr = '';
        $deadline = microtime(true) + $this->timeout;
        do {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if ($status['running'] && microtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                return new VerifyResult(false, "verification timed out after {$this->timeout}s");
            }
            usleep(2000);
        } while ($status['running']);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        proc_close($process);

        if ($status['signaled']) {
            return new VerifyResult(false, "native code crashed the verification process (signal {$status['termsig']})");
        }
        $report = json_decode(trim($stdout), true);
        if ($status['exitcode'] !== 0 || !is_array($report)) {
            return new VerifyResult(false, 'verification process failed: ' . trim($stderr ?: $stdout));
        }
        if (!$report['ok']) {
            $failure = json_encode($report['failure'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return new VerifyResult(false, 'results differ from PHP: ' . (strlen($failure) > 600 ? substr($failure, 0, 600) . '...' : $failure));
        }
        if ($report['checked'] === 0) {
            return new VerifyResult(false, $report['phpErrors'] > 0
                ? "the PHP method failed on every generated input ({$report['firstPhpError']}); set `ranges` on the attribute"
                : 'the C code declined every generated input, so it would never be used');
        }

        return new VerifyResult(true, sprintf(
            '%d inputs matched%s%s',
            $report['checked'],
            $report['declined'] ? ", {$report['declined']} left to PHP by the C code" : '',
            $report['phpErrors'] ? ", {$report['phpErrors']} rejected by both" : ''
        ));
    }

    private static function composerAutoload(): string
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

        return dirname((string) $loader, 2) . '/autoload.php';
    }
}
