<?php

declare(strict_types=1);

namespace AiAccel\Tests\Support;

use AiAccel\Build\Builder;
use AiAccel\Build\LockFile;
use AiAccel\Build\Manifest;
use AiAccel\Build\Publisher;
use AiAccel\Compiler\CCompiler;
use AiAccel\Config;
use AiAccel\Generator\GeneratorInterface;
use AiAccel\Generator\ModelResolver;
use AiAccel\Parser\MethodExtractor;
use AiAccel\Parser\MethodSpec;
use AiAccel\Tests\Fixtures\Sample;
use AiAccel\Verify\Verifier;
use PHPUnit\Framework\TestCase;

abstract class PipelineTestCase extends TestCase
{
    protected string $cacheDir;
    protected FixtureGenerator $generator;
    protected Manifest $manifest;
    protected LockFile $locks;
    protected Builder $builder;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ai-accel-test-' . bin2hex(random_bytes(6));
        $this->generator = new FixtureGenerator(dirname(__DIR__) . '/c');
        $this->manifest = new Manifest($this->cacheDir . '/manifest.json');
        $this->locks = new LockFile($this->cacheDir . '/accel.lock.json');
        $this->builder = $this->builder();
    }

    protected function tearDown(): void
    {
        foreach (['/src', '/lib', '/php', ''] as $sub) {
            array_map('unlink', glob($this->cacheDir . $sub . '/*.*') ?: []);
            @rmdir($this->cacheDir . $sub);
        }
    }

    /** @param array<string, array<string, mixed>> $providers */
    protected function builder(?string $modelOverride = null, ?GeneratorInterface $generator = null, array $providers = Config::DEFAULT_PROVIDERS): Builder
    {
        return new Builder(
            $generator ?? $this->generator,
            new CCompiler(),
            new Verifier(iterations: 1000, timeout: 30),
            $this->manifest,
            $this->locks,
            $this->cacheDir,
            new ModelResolver($providers, ['fast' => 'local:tiny'], 'anthropic:claude-sonnet-5-5'),
            $modelOverride,
        );
    }

    protected function spec(string $method): MethodSpec
    {
        foreach ((new MethodExtractor())->extractFromFile($this->sampleFile(), true) as $spec) {
            if ($spec->method === $method) {
                return $spec;
            }
        }
        self::fail("no spec for $method");
    }

    protected function sampleFile(): string
    {
        return (string) (new \ReflectionClass(Sample::class))->getFileName();
    }

    /** @return array<string, mixed> */
    protected function build(string $method, bool $force = false): array
    {
        return $this->builder->build($this->spec($method), $force);
    }

    protected function publish(): int
    {
        return (new Publisher($this->cacheDir))->publish($this->manifest);
    }

    /**
     * Runs calls in a fresh PHP process the way an application would: `Sample::method(...)`.
     *
     * @param list<array{0: string, 1: list<mixed>}> $calls
     * @param array<string, string> $env
     * @return array{results: list<array<string, mixed>>, stats: array{native: int, fallback: int}, logs: list<string>, sapi: string}
     */
    protected function runApp(array $calls, array $env = []): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/app.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['AI_ACCEL_CACHE_DIR' => $this->cacheDir] + getenv()
        );
        fwrite($pipes[0], json_encode($calls));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);

        return json_decode((string) $out, true) ?? self::fail("app failed: $out $err");
    }
}
