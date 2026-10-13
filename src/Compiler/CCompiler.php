<?php

declare(strict_types=1);

namespace Forja\Compiler;

/**
 * Builds a shared library from a C source file with the system compiler.
 */
final class CCompiler
{
    /** @param list<string> $flags */
    public function __construct(
        private readonly string $compiler = 'cc',
        private readonly array $flags = ['-O3', '-std=c11', '-ffp-contract=off'],
    ) {
    }

    /** Identifies the toolchain, so libraries built with other flags or on another platform are not reused. */
    public function id(): string
    {
        return implode(' ', [$this->compiler, ...$this->flags, PHP_OS_FAMILY, php_uname('m')]);
    }

    public static function libraryExtension(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'dll',
            'Darwin' => 'dylib',
            default => 'so',
        };
    }

    /** @throws CompileException */
    public function compile(string $sourcePath, string $libraryPath): void
    {
        if (!is_dir(dirname($libraryPath))) {
            mkdir(dirname($libraryPath), 0775, true);
        }
        // Build next to the target and rename, so a concurrent reader never maps a half-written library.
        $tmp = $libraryPath . '.' . getmypid() . '.tmp';
        $command = [$this->compiler, '-shared', '-fPIC', ...$this->flags, '-o', $tmp, $sourcePath, '-lm'];

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new CompileException("could not start compiler '{$this->compiler}'");
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || !is_file($tmp)) {
            @unlink($tmp);
            throw new CompileException(sprintf("compiler exited with %d:\n%s", $exit, trim($output)));
        }
        rename($tmp, $libraryPath);
    }
}
