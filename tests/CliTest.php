<?php

declare(strict_types=1);

namespace Forja\Tests;

use Forja\Console\CompileCommand;
use Forja\Tests\Fixtures\Sample;
use Forja\Tests\Support\PipelineTestCase;

final class CliTest extends PipelineTestCase
{
    private const VERIFIED = 17; // attributed methods of Sample that translate and verify

    private string $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = dirname($this->dir) . '/config.php';
        mkdir(dirname($this->dir), 0775, true);
        file_put_contents($this->config, '<?php return ' . var_export([
            'dir' => $this->dir,
            'paths' => [dirname($this->sampleFile())],
            'aliases' => ['fast' => 'local:tiny'],
        ], true) . ';');
    }

    /** @return array{0: int, 1: string} */
    private function cli(string ...$args): array
    {
        $out = fopen('php://memory', 'w+');
        $exit = (new CompileCommand($this->generator, $out))->run(["--config={$this->config}", ...$args]);
        rewind($out);

        return [$exit, (string) stream_get_contents($out)];
    }

    public function testDryRunCallsNoModelAndBuildsNothing(): void
    {
        [$exit, $out] = $this->cli('--dry-run', '--auto', '--model=fast');

        self::assertSame(0, $exit);
        self::assertSame(0, $this->generator->calls);
        self::assertStringContainsString(Sample::class . "::sumSquares\n    generate with local:tiny", $out);
        self::assertStringContainsString(Sample::class . "::autoSum\n    generate with local:tiny", $out);
        self::assertStringContainsString(Sample::class . "::usesThis\n    unsupported: line", $out);
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testFullBuildPublishesAndReports(): void
    {
        [$exit, $out] = $this->cli();

        self::assertSame(0, $exit, 'without --strict, methods that stay PHP do not fail the deploy');
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::caesar\n\s+\[anthropic:claude-sonnet-5-5\] 100\d inputs matched/', $out);
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::wordCounts\n/', $out);
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::triple \(locked\)/', $out);
        self::assertMatchesRegularExpression('/REJECTED    .*Sample::crashy/', $out);
        self::assertMatchesRegularExpression('/UNSUPPORTED .*Sample::prints/', $out);
        self::assertStringNotContainsString('autoSum', $out);
        self::assertStringContainsString(self::VERIFIED . ' method(s) now run as C; everything else runs as PHP.', $out);
        self::assertStringContainsString("opcache.preload={$this->dir}/build/preload.php", $out);
        self::assertCount(self::VERIFIED, glob($this->dir . '/src/Forja/Tests/Fixtures/Sample/*.c'));

        $run = $this->runApp([['sumSquares', [10]], ['caesar', ['abc', 1]], ['wordCounts', ['a b a']], ['crashy', [1]]]);
        self::assertSame([385, 'bcd', ['a' => 2, 'b' => 1], 1], array_column($run['results'], 'value'));
        self::assertSame(['native' => 3, 'fallback' => 0], $run['stats']);

        [$strict] = $this->cli('--strict');
        self::assertSame(1, $strict);

        [, $second] = $this->cli();
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::caesar \(cached\)/', $second);
    }

    public function testFrozenDeployBuildsFromTheRepositoryOnly(): void
    {
        $this->cli($this->sampleFile());
        $generated = $this->generator->calls;
        $lock = file_get_contents($this->dir . '/forja.lock.json');
        self::removeTree($this->dir . '/build'); // a fresh checkout: forja/src and forja.lock.json, nothing else

        [$exit, $out] = $this->cli('--frozen');

        self::assertSame(0, $exit);
        self::assertSame($generated, $this->generator->calls, 'no model was called');
        self::assertStringContainsString(self::VERIFIED . ' method(s) now run as C', $out);
        self::assertMatchesRegularExpression('/MISSING     .*Sample::wrong\n\s+.*no verified C source/', $out);
        self::assertSame($lock, file_get_contents($this->dir . '/forja.lock.json'));
        self::assertSame(385, $this->runApp([['sumSquares', [10]]])['results'][0]['value']);
        self::assertSame(1, $this->cli('--frozen', '--strict')[0]);
    }

    public function testAutoModeAndForgettingRemovedMethods(): void
    {
        $source = $this->dir . '/src/Forja/Tests/Fixtures/Sample/autoSum.c';

        [, $out] = $this->cli('--auto');
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::autoSum \(auto\)/', $out);
        self::assertFileExists($source);
        self::assertSame(1, $this->runApp([['autoSum', [100]]])['stats']['native']);

        // Without --auto the method is no longer a candidate, so its module and its source are dropped.
        [, $out] = $this->cli();
        self::assertStringNotContainsString('autoSum', $out);
        self::assertFileDoesNotExist($source);
        self::assertNull($this->lock('autoSum'));
        self::assertSame(0, $this->runApp([['autoSum', [100]]])['stats']['native']);
    }

    public function testLockAndUnlockCommands(): void
    {
        $key = Sample::class . '::sumSquares';
        self::assertSame("Nothing new to lock.\n", $this->cli('--lock')[1]);

        $this->cli($this->sampleFile());
        $calls = $this->generator->calls;

        [$exit, $out] = $this->cli("--lock=$key");
        self::assertSame(0, $exit);
        self::assertSame("LOCKED      $key\n", $out);
        self::assertTrue($this->lock('sumSquares')['locked']);
        self::assertSame(
            [1, Sample::class . "::wrong has no verified translation; run forja first\n"],
            $this->cli('--lock=' . Sample::class . '::wrong')
        );

        // Locked: a forced rebuild with another model regenerates everything except locked methods.
        [, $out] = $this->cli('--force', '--model=fast');
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::sumSquares \(cached, locked\)\n\s+\[anthropic:claude-sonnet-5-5\]/', $out);
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::dot\n\s+\[local:tiny\]/', $out);
        $regenerated = $this->generator->calls - $calls;

        [, $out] = $this->cli('--status');
        self::assertMatchesRegularExpression('/Sample::sumSquares \(cached, locked\)/', $out);

        [, $out] = $this->cli('--lock');
        self::assertStringContainsString('LOCKED      ' . Sample::class . '::dot', $out);
        self::assertStringNotContainsString($key, $out, 'already locked');
        [, $out] = $this->cli('--unlock');
        self::assertStringContainsString("UNLOCKED    $key", $out);
        self::assertSame("Nothing is locked.\n", $this->cli('--unlock')[1]);

        $before = $this->generator->calls;
        $this->cli('--force', '--model=fast');
        self::assertSame($regenerated + 2, $this->generator->calls - $before, 'sumSquares and triple are generated again once unlocked');
    }

    public function testInvalidOptionsAreRejected(): void
    {
        self::assertSame(2, $this->cli('--modle=x')[0]);
        self::assertSame(2, $this->cli('--model')[0]);
        self::assertSame(2, $this->cli('--force=yes')[0]);
        self::assertStringContainsString('--frozen', $this->cli('--help')[1]);
    }
}
