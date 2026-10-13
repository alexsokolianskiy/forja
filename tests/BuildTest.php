<?php

declare(strict_types=1);

namespace Forja\Tests;

use Forja\Build\LockFile;
use Forja\Build\Manifest;
use Forja\FFI\FFILoader;
use Forja\FFI\NativeException;
use Forja\Parser\MethodSpec;
use Forja\Tests\Fixtures\Sample;
use Forja\Tests\Support\PipelineTestCase;
use Forja\Verify\Comparator;
use Forja\Verify\InputGenerator;
use PHPUnit\Framework\Attributes\DataProvider;

final class BuildTest extends PipelineTestCase
{
    private const SOURCE = 'src/Forja/Tests/Fixtures/Sample/sumSquares.c';

    /** The same method after the developer edited its body. */
    private function edited(string $method): MethodSpec
    {
        $spec = $this->spec($method);

        return new MethodSpec(
            $spec->class, $spec->method, $spec->file, $spec->params, $spec->returnKind,
            $spec->source . "\n// edited", $spec->context, $spec->model, $spec->deterministic, $spec->ranges, $spec->lock,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        $methods = [
            'sumSquares', 'dot', 'vowels', 'scale', 'isPrime', 'sumList', 'triple', 'caesar', 'transpose', 'rowSums', 'average', 'autoSum',
            // through the runtime: associative arrays, nested rows, null, unions, untyped
            'wordCounts', 'totalsByCustomer', 'orDefault', 'dropNegatives', 'untyped',
        ];
        foreach ($methods as $method) {
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
            $threw = false;
            try {
                $expected = $reflection->invokeArgs($object, $args);
            } catch (\Throwable) {
                $threw = true;
            }
            try {
                $actual = $native($args);
            } catch (NativeException) {
                continue; // the C code left this input to PHP, which is always allowed
            }
            self::assertFalse($threw, "$method returned a value where PHP throws: " . json_encode($args));
            self::assertTrue(Comparator::same($expected, $actual), "$method(" . json_encode($args) . ')');
            $compared++;
        }
        self::assertGreaterThan(100, $compared);
    }

    public function testAssociativeAndNestedValuesRoundTripExactly(): void
    {
        $counts = (new FFILoader())->load($this->build('wordCounts'));
        self::assertSame(['the' => 3, 'cat' => 1, 7 => 2, '007' => 1], $counts(['The cat, the 7 THE 7 007']));
        self::assertSame([], $counts(['']));

        $totals = (new FFILoader())->load($this->build('totalsByCustomer'));
        self::assertSame(['ann' => 25.0, 12 => 1.5], $totals([[
            ['customer' => 'ann', 'amount' => 10.0, 'qty' => 2],
            ['customer' => '12', 'amount' => 0.5, 'qty' => 3],
            ['customer' => 'ann', 'amount' => 2.5, 'qty' => 2],
        ]]));

        $orDefault = (new FFILoader())->load($this->build('orDefault'));
        self::assertSame(5, $orDefault([5, 'x']));
        self::assertSame('x', $orDefault([null, 'x']));

        $drop = (new FFILoader())->load($this->build('dropNegatives'));
        self::assertSame(['a' => 1, 5 => 2, 10 => 2], $drop([['a' => 1, 'b' => -1, 5 => 2, 9 => 50], 10]), 'like PHP, the pushed key follows 9 even though 9 was unset');
        self::assertSame([0 => 3, 2 => 4, 3 => 2], $drop([[3, -1, 4], null]));
    }

    public function testValuesCCannotRepresentAreLeftToPhp(): void
    {
        $totals = (new FFILoader())->load($this->build('totalsByCustomer'));

        foreach ([
            [[['customer' => 'ann', 'amount' => '10', 'qty' => 2]]],           // a numeric string where a float is expected
            [[['customer' => 'ann', 'amount' => 1.0]]],                        // missing key: a warning in PHP
            [[new \ArrayObject()]],                                            // an object
        ] as $args) {
            try {
                $totals($args);
                self::fail('accepted ' . json_encode($args));
            } catch (NativeException) {
                $this->addToAssertionCount(1);
            }
        }
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

    public function testOnlyVerifiedCodeEntersTheProject(): void
    {
        $entry = $this->build('sumSquares');

        self::assertSame(Manifest::VERIFIED, $entry['status']);
        self::assertSame($this->dir . '/' . self::SOURCE, $entry['csource']);
        self::assertFileExists($entry['csource']);
        self::assertSame("/build/\n", file_get_contents($this->dir . '/.gitignore'));
        $lock = $this->lock('sumSquares');
        self::assertSame(self::SOURCE, $lock['source']);
        self::assertSame(hash_file('sha256', $entry['csource']), $lock['sha256']);
        self::assertSame('anthropic:claude-sonnet-5-5', $lock['model']);
        self::assertFalse($lock['locked']);

        foreach (['wrong', 'crashy', 'swallowsError'] as $method) {
            $rejected = $this->build($method);
            self::assertSame(Manifest::REJECTED, $rejected['status']);
            self::assertNull($this->lock($method));
            self::assertFileDoesNotExist($this->dir . "/src/Forja/Tests/Fixtures/Sample/$method.c");
            self::assertStringStartsWith($this->dir . '/build/candidates/', $rejected['csource'], 'kept aside for inspection');
        }
        self::assertSame(['sumSquares.c'], array_map('basename', glob($this->dir . '/src/Forja/Tests/Fixtures/Sample/*')));
    }

    public function testUnchangedMethodKeepsItsTranslation(): void
    {
        $first = $this->build('sumSquares');
        $bytes = file_get_contents($first['csource']);

        $second = $this->build('sumSquares');
        self::assertTrue($second['cached']);
        self::assertSame(Manifest::VERIFIED, $second['status']);

        // Another default model does not touch code that already works.
        $other = $this->builder('local:tiny')->build($this->spec('sumSquares'));
        self::assertSame('anthropic:claude-sonnet-5-5', $other['model'], 'the entry says who wrote the code in use');
        self::assertSame($bytes, file_get_contents($first['csource']));
        self::assertSame(1, $this->generator->calls);

        // --force asks again, and the new code replaces the old only because it verifies.
        $forced = $this->builder('local:tiny')->build($this->spec('sumSquares'), force: true);
        self::assertSame(2, $this->generator->calls);
        self::assertSame('local:tiny', $forced['model']);
        self::assertSame('local:tiny', $this->lock('sumSquares')['model']);
    }

    public function testEditedMethodIsTranslatedAgain(): void
    {
        $first = $this->build('sumSquares');
        $edited = $this->builder()->build($this->edited('sumSquares'));

        self::assertSame(2, $this->generator->calls);
        self::assertNotSame($first['hash'], $edited['hash']);
        self::assertSame($edited['phpHash'], $this->lock('sumSquares')['phpHash']);
    }

    public function testModelSelection(): void
    {
        $spec = $this->spec('sumSquares');
        $hash = $this->builder->hash($spec);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertNotSame($hash, $this->builder->hash($this->spec('isPrime')));
        self::assertNotSame($hash, $this->builder('local:tiny')->hash($spec));
        self::assertSame($this->builder('fast')->hash($spec), $this->builder('local:tiny')->hash($spec), 'alias and full name are the same model');

        self::assertSame('anthropic:claude-sonnet-5-5', $this->build('sumSquares')['model']);
        self::assertSame('anthropic:claude-haiku-5-5', $this->build('vowels')['model'], 'attribute wins over the default');
        self::assertSame('local:tiny', $this->builder('fast')->build($this->spec('dot'))['model'], '--model wins');
    }

    public function testFrozenBuildUsesCommittedSourcesAndNeverCallsAModel(): void
    {
        self::assertSame(Manifest::MISSING, $this->builder(frozen: true)->build($this->spec('sumSquares'))['status']);
        self::assertSame(0, $this->generator->calls);

        // A developer builds and commits forja/src + forja.lock.json ...
        $this->build('sumSquares');
        $committed = file_get_contents($this->dir . '/' . self::SOURCE);
        $lockFile = file_get_contents($this->layout->lockPath());
        // ... and another machine has only those: no build directory, no API key.
        self::removeTree($this->layout->buildDir());

        $deployed = $this->builder(frozen: true)->build($this->spec('sumSquares'));

        self::assertSame(Manifest::VERIFIED, $deployed['status'], (string) $deployed['message']);
        self::assertSame(1, $this->generator->calls, 'only the developer machine generated');
        self::assertFileExists($deployed['lib']);
        self::assertSame($committed, file_get_contents($this->dir . '/' . self::SOURCE));
        self::assertSame($lockFile, file_get_contents($this->layout->lockPath()), 'a frozen build never rewrites the lock file');
        self::assertSame('reuse ' . self::SOURCE, $this->builder(frozen: true)->plan($this->spec('sumSquares')));

        // The PHP method was edited but nobody regenerated: the stale C must not be used.
        $stale = $this->builder(frozen: true)->build($this->edited('sumSquares'));
        self::assertSame(Manifest::MISSING, $stale['status']);

        // The committed file was altered without updating the lock: refuse it.
        file_put_contents($this->dir . '/' . self::SOURCE, "/* sneaky */\n" . $committed);
        $tampered = $this->builder(frozen: true)->build($this->spec('sumSquares'));
        self::assertSame(Manifest::REJECTED, $tampered['status']);
        self::assertStringContainsString('does not match the checksum in forja.lock.json', $tampered['message']);
        self::assertSame(1, $this->generator->calls);
    }

    public function testLockFileIsStableAcrossRebuilds(): void
    {
        $this->build('vowels');
        $this->build('sumSquares');
        $first = file_get_contents($this->layout->lockPath());

        self::removeTree($this->layout->buildDir());
        $this->build('sumSquares');
        $this->build('vowels');

        self::assertSame($first, file_get_contents($this->layout->lockPath()));
        self::assertStringNotContainsString($this->dir, $first, 'no machine-specific paths');
        self::assertSame(1, substr_count($first, '"sumSquares.c') + substr_count($first, 'sumSquares.c"'));
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
        self::assertSame([], glob($this->layout->libDir() . '/*'), 'nothing was compiled');
        self::assertDirectoryDoesNotExist($this->dir . '/src');
    }

    public function testUnsupportedMethodNeverReachesTheModel(): void
    {
        self::assertSame(Manifest::UNSUPPORTED, $this->build('usesThis')['status']);
        self::assertSame(Manifest::UNSUPPORTED, $this->build('takesObject')['status']);
        self::assertSame(0, $this->generator->calls);
    }

    public function testLockKeepsTheSourceEvenWhenThePhpMethodChanges(): void
    {
        $first = $this->build('sumSquares');
        (new LockFile($this->layout->lockPath()))->setLocked(Sample::class . '::sumSquares', true);

        // An edit that does not change behaviour, a different model and --force: all three would normally regenerate.
        $locked = $this->builder('local:tiny')->build($this->edited('sumSquares'), force: true);

        self::assertSame(1, $this->generator->calls);
        self::assertTrue($locked['locked']);
        self::assertSame($first['hash'], $locked['hash']);
        self::assertSame($first['model'], $locked['model']);
        self::assertSame(Manifest::VERIFIED, $locked['status'], 'still verified against the PHP method as it is now');
        self::assertSame('locked: reuse ' . self::SOURCE, $this->builder('local:tiny')->plan($this->edited('sumSquares'), true));

        (new LockFile($this->layout->lockPath()))->setLocked(Sample::class . '::sumSquares', false);
        $unlocked = $this->builder('local:tiny')->build($this->edited('sumSquares'));
        self::assertSame(2, $this->generator->calls);
        self::assertFalse($unlocked['locked']);
        self::assertNotSame($first['hash'], $unlocked['hash']);
    }

    public function testLockAttributeLocksOnFirstVerifiedBuild(): void
    {
        self::assertTrue($this->build('triple')['locked']);
        self::assertTrue($this->lock('triple')['locked']);
    }

    public function testCommittedSourceCanBeHandTunedButIsStillVerified(): void
    {
        $entry = $this->build('triple');
        $source = (string) file_get_contents($entry['csource']);

        // A harmless manual edit: rebuilt, re-verified, checksum updated, model never called.
        file_put_contents($entry['csource'], "/* tuned by hand */\n" . $source);
        $tuned = $this->build('triple');
        self::assertSame(Manifest::VERIFIED, $tuned['status'], (string) $tuned['message']);
        self::assertStringContainsString('edited by hand', $tuned['message']);
        self::assertNotSame($entry['lib'], $tuned['lib']);
        self::assertSame(hash_file('sha256', $entry['csource']), $this->lock('triple')['sha256']);

        // A manual edit that breaks it does not get through.
        file_put_contents($entry['csource'], str_replace('(n, 3,', '(n, 4,', $source));
        $broken = $this->build('triple');
        self::assertSame(Manifest::REJECTED, $broken['status']);
        self::assertStringContainsString('C source is locked', $broken['message']);
        self::assertSame(1, $this->generator->calls);
    }

    public function testLockDoesNotSurviveASignatureChange(): void
    {
        $entry = $this->build('triple');
        $lockPath = $this->layout->lockPath();
        // What the lock file looks like after the developer adds a parameter to the PHP method.
        file_put_contents($lockPath, str_replace('(int64_t n,', '(int64_t n, int64_t m,', (string) file_get_contents($lockPath)));

        $rebuilt = $this->build('triple');

        self::assertSame(Manifest::REJECTED, $rebuilt['status']);
        self::assertStringContainsString('signature changed since it was locked', $rebuilt['message']);
        self::assertSame($entry['hash'], $rebuilt['hash']);
    }
}
