<?php

declare(strict_types=1);

namespace Forja\Tests;

use Forja\Build\Manifest;
use Forja\FFI\FFILoader;
use Forja\FFI\NativeException;
use Forja\Tests\Fixtures\Sample;
use Forja\Tests\Support\PipelineTestCase;
use Forja\Verify\Comparator;
use Forja\Verify\InputGenerator;
use PHPUnit\Framework\Attributes\DataProvider;

final class BuildTest extends PipelineTestCase
{
    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        foreach (['sumSquares', 'dot', 'vowels', 'scale', 'isPrime', 'sumList', 'triple', 'caesar', 'transpose', 'rowSums', 'average', 'autoSum'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('methods')]
    public function testNativeMatchesPhpOn1000RandomInputs(string $method): void
    {
        $entry = $this->build($method);
        self::assertSame(Manifest::VERIFIED, $entry['status'], (string) $entry['message']);

        $native = (new FFILoader())->load($entry);
        $reflection = new \ReflectionMethod(Sample::class, $method);
        $object = $reflection->isStatic() ? null : new Sample();
        $inputs = new InputGenerator(20261012, $entry['ranges']);
        $compared = 0;
        for ($i = 0; $i < 1000; $i++) {
            $args = $inputs->generate($entry['params']);
            try {
                $expected = $reflection->invokeArgs($object, $args);
            } catch (\Throwable) {
                $expected = null;
            }
            try {
                $actual = $native($args);
            } catch (NativeException) {
                self::assertNull($expected, "$method declined an input PHP accepts: " . json_encode($args));
                continue;
            }
            self::assertTrue(Comparator::same($expected, $actual), "$method(" . json_encode($args) . ')');
            $compared++;
        }
        self::assertGreaterThan(500, $compared);
    }

    public function testVoidMethodBuilds(): void
    {
        $entry = $this->build('touch');

        self::assertSame(Manifest::VERIFIED, $entry['status'], (string) $entry['message']);
        self::assertNull((new FFILoader())->load($entry)([5]));
    }

    public function testEmptyAndDegenerateShapes(): void
    {
        $transpose = (new FFILoader())->load($this->build('transpose'));
        self::assertSame([], $transpose([[]]));
        self::assertSame([], $transpose([[[], []]]));
        self::assertSame([[1, 4], [2, 5], [3, 6]], $transpose([[[1, 2, 3], [4, 5, 6]]]));

        $rowSums = (new FFILoader())->load($this->build('rowSums'));
        self::assertSame([0.0, 0.0], $rowSums([[[], []]]));

        $caesar = (new FFILoader())->load($this->build('caesar'));
        self::assertSame('', $caesar(['', 3]));
        self::assertSame("Khoor, Zruog!\0\xff", $caesar(["Hello, World!\0\xff", 3]));
    }

    public function testUnchangedMethodIsNotRegenerated(): void
    {
        $first = $this->build('sumSquares');
        $second = $this->build('sumSquares');

        self::assertSame(1, $this->generator->calls);
        self::assertTrue($second['cached']);
        self::assertSame($first['hash'], $second['hash']);
        self::assertSame(Manifest::VERIFIED, $second['status']);

        $this->build('sumSquares', force: true);
        self::assertSame(2, $this->generator->calls);
    }

    public function testModelIsPartOfTheHash(): void
    {
        $spec = $this->spec('sumSquares');
        $hash = $this->builder->hash($spec);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, $this->builder->hash($this->spec('sumSquares')));
        self::assertNotSame($hash, $this->builder->hash($this->spec('isPrime')));
        self::assertNotSame($hash, $this->builder('local:tiny')->hash($spec));
        self::assertSame($this->builder('fast')->hash($spec), $this->builder('local:tiny')->hash($spec), 'alias and full name are the same model');

        self::assertSame('anthropic:claude-sonnet-5-5', $this->build('sumSquares')['model']);
        self::assertSame('anthropic:claude-haiku-5-5', $this->build('vowels')['model'], 'attribute wins over the default');
        self::assertSame('local:tiny', $this->builder('fast')->build($this->spec('vowels'))['model'], '--model wins over the attribute');
    }

    public function testSegfaultingModuleIsRejected(): void
    {
        $entry = $this->build('crashy');

        self::assertSame(Manifest::REJECTED, $entry['status']);
        self::assertStringContainsString('crashed the verification process', $entry['message']);
    }

    public function testWrongTranslationIsRejected(): void
    {
        $entry = $this->build('wrong');

        self::assertSame(Manifest::REJECTED, $entry['status']);
        self::assertStringContainsString('results differ from PHP', $entry['message']);
        self::assertStringContainsString('"args":[77]', $entry['message']);
    }

    public function testTranslationThatAnswersWherePhpThrowsIsRejected(): void
    {
        $entry = $this->build('swallowsError');

        self::assertSame(Manifest::REJECTED, $entry['status']);
        self::assertStringContainsString('DivisionByZeroError', $entry['message']);
    }

    public function testCompileErrorAndDangerousCodeFail(): void
    {
        $broken = $this->build('broken');
        self::assertSame(Manifest::FAILED, $broken['status']);
        self::assertStringContainsString('compiler exited with', $broken['message']);

        $evil = $this->build('evil');
        self::assertSame(Manifest::FAILED, $evil['status']);
        self::assertStringContainsString('forbidden function system()', $evil['message']);
        self::assertSame([], glob($this->cacheDir . '/lib/*'), 'nothing was compiled');
    }

    public function testUnsupportedMethodNeverReachesTheModel(): void
    {
        $entry = $this->build('usesThis');

        self::assertSame(Manifest::UNSUPPORTED, $entry['status']);
        self::assertSame(0, $this->generator->calls);
    }

    public function testLockFreezesTheCSource(): void
    {
        $first = $this->build('sumSquares');
        $this->locks->lock(Sample::class . '::sumSquares', $first);

        // A different model and --force would both normally regenerate.
        $locked = $this->builder('local:tiny')->build($this->spec('sumSquares'), force: true);

        self::assertSame(1, $this->generator->calls);
        self::assertTrue($locked['locked']);
        self::assertSame($first['hash'], $locked['hash']);
        self::assertSame($first['model'], $locked['model'], 'the entry records who wrote the frozen code');
        self::assertSame(Manifest::VERIFIED, $locked['status']);
        self::assertSame('locked: reuse the frozen C source', $this->builder('local:tiny')->plan($this->spec('sumSquares')));

        self::assertTrue($this->locks->unlock(Sample::class . '::sumSquares'));
        $unlocked = $this->builder('local:tiny')->build($this->spec('sumSquares'));
        self::assertSame(2, $this->generator->calls);
        self::assertFalse($unlocked['locked']);
        self::assertNotSame($first['hash'], $unlocked['hash']);
    }

    public function testLockAttributeLocksOnFirstVerifiedBuild(): void
    {
        $entry = $this->build('triple');

        self::assertTrue($entry['locked']);
        self::assertSame('src/' . $entry['hash'] . '.c', $this->locks->get(Sample::class . '::triple')['source']);
        self::assertSame(date('Y-m-d'), $this->locks->get(Sample::class . '::triple')['lockedAt']);

        $this->build('wrong');
        self::assertNull($this->locks->get(Sample::class . '::wrong'));
    }

    public function testLockedSourceCanBeHandTunedButIsStillVerified(): void
    {
        $entry = $this->build('triple');
        $source = (string) file_get_contents($entry['csource']);

        // A harmless manual edit: rebuilt, re-verified, still locked, model never called.
        file_put_contents($entry['csource'], "/* tuned by hand */\n" . $source);
        $tuned = $this->build('triple');
        self::assertSame(Manifest::VERIFIED, $tuned['status'], (string) $tuned['message']);
        self::assertNotSame($entry['lib'], $tuned['lib']);
        self::assertSame(1, $this->generator->calls);

        // A manual edit that breaks it: the lock does not let wrong code through.
        file_put_contents($entry['csource'], str_replace('(n, 3,', '(n, 4,', $source));
        $broken = $this->build('triple');
        self::assertSame(Manifest::REJECTED, $broken['status']);
        self::assertStringContainsString('C source is locked', $broken['message']);
        self::assertSame(1, $this->generator->calls);
    }

    public function testLockDoesNotSurviveASignatureChange(): void
    {
        $entry = $this->build('triple');
        $lockPath = $this->cacheDir . '/forja.lock.json';
        // What the lock file looks like after the developer adds a parameter to the PHP method.
        file_put_contents($lockPath, str_replace('(int64_t n,', '(int64_t n, int64_t m,', (string) file_get_contents($lockPath)));
        $this->locks = new \Forja\Build\LockFile($lockPath);

        $rebuilt = $this->builder()->build($this->spec('triple'));

        self::assertSame(Manifest::REJECTED, $rebuilt['status']);
        self::assertStringContainsString('signature changed since it was locked', $rebuilt['message']);
        self::assertSame($entry['hash'], $rebuilt['hash']);
    }
}
