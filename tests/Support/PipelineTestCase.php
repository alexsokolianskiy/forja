<?php

declare(strict_types=1);

namespace Forja\Tests\Support;

use Forja\Build\Builder;
use Forja\Build\Layout;
use Forja\Build\LockFile;
use Forja\Build\Manifest;
use Forja\Build\Publisher;
use Forja\Compiler\CCompiler;
use Forja\Config;
use Forja\Generator\GeneratorInterface;
use Forja\Generator\ModelResolver;
use Forja\Parser\MethodExtractor;
use Forja\Parser\MethodSpec;
use Forja\Tests\Fixtures\Sample;
use Forja\Verify\Verifier;
use PHPUnit\Framework\TestCase;

abstract class PipelineTestCase extends TestCase
{
    /** A throwaway project's forja/ directory. */
    protected string $dir;
    protected Layout $layout;
    protected FixtureGenerator $generator;
    protected Manifest $manifest;
    protected LockFile $locks;
    protected Builder $builder;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/forja-test-' . bin2hex(random_bytes(6)) . '/forja';
        $this->layout = new Layout($this->dir);
        $this->generator = new FixtureGenerator(dirname(__DIR__) . '/c');
        $this->manifest = new Manifest($this->layout->manifestPath());
        $this->locks = new LockFile($this->layout->lockPath());
        $this->builder = $this->builder();
    }

    protected function tearDown(): void
    {
        self::removeTree(dirname($this->dir));
    }

    protected static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    /** @param array<string, array<string, mixed>> $providers */
    protected function builder(
        ?string $modelOverride = null,
        ?GeneratorInterface $generator = null,
        array $providers = Config::DEFAULT_PROVIDERS,
        bool $frozen = false,
    ): Builder {
        return new Builder(
            $generator ?? $this->generator,
            new CCompiler(),
            new Verifier(iterations: 1000, timeout: 30),
            new Manifest($this->layout->manifestPath()),
            new LockFile($this->layout->lockPath()),
            $this->layout,
            new ModelResolver($providers, ['fast' => 'local:tiny'], 'anthropic:claude-sonnet-5-5'),
            $modelOverride,
            $frozen,
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
        return $this->builder()->build($this->spec($method), $force);
    }

    /** @return array<string, mixed>|null the method's entry in forja.lock.json */
    protected function lock(string $method): ?array
    {
        return (new LockFile($this->layout->lockPath()))->get(Sample::class . '::' . $method);
    }

    protected function publish(): int
    {
        return (new Publisher($this->layout->buildDir()))->publish(new Manifest($this->layout->manifestPath()));
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
            $env + ['FORJA_DIR' => $this->dir] + getenv()
        );
        fwrite($pipes[0], json_encode($calls));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);

        return json_decode((string) $out, true) ?? self::fail("app failed: $out $err");
    }
}
