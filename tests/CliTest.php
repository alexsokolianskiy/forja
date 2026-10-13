<?php

declare(strict_types=1);

namespace AiAccel\Tests;

use AiAccel\Console\CompileCommand;
use AiAccel\Tests\Fixtures\Sample;
use AiAccel\Tests\Support\PipelineTestCase;

final class CliTest extends PipelineTestCase
{
    private string $config;

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->cacheDir, 0775, true);
        $this->config = $this->cacheDir . '/config.php';
        file_put_contents($this->config, '<?php return ' . var_export([
            'cache_dir' => $this->cacheDir,
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
        self::assertFileDoesNotExist($this->cacheDir . '/manifest.json');
    }

    public function testFullBuildPublishesAndReports(): void
    {
        [$exit, $out] = $this->cli();

        self::assertSame(0, $exit, 'without --strict, methods that stay PHP do not fail the deploy');
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::caesar\n\s+\[anthropic:claude-sonnet-5-5\] 1000 inputs matched/', $out);
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::triple \(locked\)/', $out);
        self::assertMatchesRegularExpression('/REJECTED    .*Sample::crashy/', $out);
        self::assertMatchesRegularExpression('/UNSUPPORTED .*Sample::prints/', $out);
        self::assertStringNotContainsString('autoSum', $out);
        self::assertStringContainsString("12 method(s) now run as C; everything else runs as PHP.", $out);
        self::assertStringContainsString("opcache.preload={$this->cacheDir}/preload.php", $out);

        $run = $this->runApp([['sumSquares', [10]], ['caesar', ['abc', 1]], ['crashy', [1]]]);
        self::assertSame([385, 'bcd', 1], array_column($run['results'], 'value'));
        self::assertSame(['native' => 2, 'fallback' => 0], $run['stats']);

        [$strict] = $this->cli('--strict');
        self::assertSame(1, $strict);

        [, $second] = $this->cli();
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::caesar \(cached\)/', $second);
    }

    public function testAutoModeAndForgettingRemovedMethods(): void
    {
        [, $out] = $this->cli('--auto');
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::autoSum \(auto\)/', $out);
        self::assertSame(1, $this->runApp([['autoSum', [100]]])['stats']['native']);

        // Without --auto the method is no longer a candidate, so its module is dropped.
        [, $out] = $this->cli();
        self::assertStringNotContainsString('autoSum', $out);
        self::assertSame(0, $this->runApp([['autoSum', [100]]])['stats']['native']);
    }

    public function testLockAndUnlockCommands(): void
    {
        $key = Sample::class . '::sumSquares';
        self::assertSame("Nothing verified to lock. Run ai-c-compile first.\n", $this->cli('--lock')[1]);

        $this->cli($this->sampleFile());
        $calls = $this->generator->calls;

        [$exit, $out] = $this->cli("--lock=$key");
        self::assertSame(0, $exit);
        self::assertSame("LOCKED      $key\n", $out);
        self::assertSame([1, "AiAccel\\Tests\\Fixtures\\Sample::wrong is rejected, only verified code can be locked\n"], $this->cli('--lock=' . Sample::class . '::wrong'));

        // Locked: a forced rebuild with another model regenerates everything except this method.
        [, $out] = $this->cli('--force', '--model=fast');
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::sumSquares \(cached, locked\)\n\s+\[anthropic:claude-sonnet-5-5\]/', $out);
        self::assertMatchesRegularExpression('/VERIFIED    .*Sample::dot\n\s+\[local:tiny\]/', $out);
        self::assertStringContainsString('sumSquares', (string) file_get_contents($this->cacheDir . '/accel.lock.json'));
        $regenerated = $this->generator->calls - $calls;

        [, $out] = $this->cli('--status');
        self::assertMatchesRegularExpression('/Sample::sumSquares \(cached, locked\)/', $out);

        [, $out] = $this->cli('--lock');
        self::assertStringContainsString('LOCKED      ' . Sample::class . '::dot', $out);
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
        self::assertStringContainsString('--lock[=KEY]', $this->cli('--help')[1]);
    }
}
