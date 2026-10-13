<?php

declare(strict_types=1);

namespace Forja\Tests;

use Forja\Build\Manifest;
use Forja\Tests\Fixtures\Sample;
use Forja\Tests\Support\PipelineTestCase;
use Forja\Tests\Support\Server;

/**
 * End to end: a separate PHP process calls Sample::method() the ordinary way,
 * with no reference to this package in the calling code.
 */
final class RuntimeTest extends PipelineTestCase
{
    public function testOrdinaryCallsAreServedByC(): void
    {
        foreach (['sumSquares', 'caesar', 'transpose', 'touch', 'triple'] as $method) {
            self::assertSame(Manifest::VERIFIED, $this->build($method)['status']);
        }
        self::assertSame(5, $this->publish());

        $run = $this->runApp([
            ['sumSquares', [10]],
            ['caesar', ['Hello', 3]],
            ['transpose', [[[1, 2], [3, 4], [5, 6]]]],
            ['touch', [1]],
            ['triple', [14]],
            ['plain', [7]],
            ['dot', [[1.5, 2.0], [3.0, 4.0]]],
        ]);

        self::assertSame(
            [385, 'Khoor', [[1, 3, 5], [2, 4, 6]], null, 42, 7, 12.5],
            array_column($run['results'], 'value')
        );
        self::assertSame(['native' => 5, 'fallback' => 0], $run['stats'], 'plain() and dot() have no module and are not counted');
        self::assertSame([], $run['logs']);
    }

    public function testAssociativeArraysAndQueryRowsCrossTheBoundary(): void
    {
        foreach (['wordCounts', 'totalsByCustomer', 'orDefault'] as $method) {
            self::assertSame(Manifest::VERIFIED, $this->build($method)['status']);
        }
        $this->publish();

        $run = $this->runApp([
            ['wordCounts', ['to be or not to be']],
            ['totalsByCustomer', [[
                ['customer' => 'ann', 'amount' => 10.5, 'qty' => 2],
                ['customer' => 'bob', 'amount' => 1.25, 'qty' => 4],
                ['customer' => 'ann', 'amount' => 0.5, 'qty' => 1],
            ]]],
            ['orDefault', [null, 'none']],
            // An amount that arrives as a string, as some database drivers return decimals: C declines, PHP computes.
            ['totalsByCustomer', [[['customer' => 'ann', 'amount' => '10.5', 'qty' => 2]]]],
        ]);

        self::assertSame(['to' => 2, 'be' => 2, 'or' => 1, 'not' => 1], $run['results'][0]['value']);
        self::assertSame(['ann' => 21.5, 'bob' => 5], $run['results'][1]['value']);
        self::assertSame('none', $run['results'][2]['value']);
        self::assertSame(['ann' => 21], $run['results'][3]['value']);
        self::assertSame(['native' => 3, 'fallback' => 1], $run['stats']);
    }

    public function testGuardedCopyKeepsFileLineAndDirConstants(): void
    {
        $this->build('sumSquares');
        $this->publish();
        $line = array_search("        return __FILE__ . '|' . __DIR__ . '|' . __LINE__;", file($this->sampleFile(), FILE_IGNORE_NEW_LINES), true) + 1;

        $run = $this->runApp([['whereAmI', []]]);

        self::assertSame($this->sampleFile() . '|' . dirname($this->sampleFile()) . '|' . $line, $run['results'][0]['value']);
    }

    public function testBadArgumentsNeverReachCAndPhpRunsInstead(): void
    {
        $this->build('sumList');
        $this->build('transpose');
        $this->publish();

        $run = $this->runApp([
            ['sumList', [[1, 2, 3]]],
            ['sumList', [[1, '2', 3]]],            // numeric string
            ['sumList', [[1.9, 3]]],               // float in an int list
            ['sumList', [['a' => 1, 'b' => 2]]],   // not a list
            ['transpose', [[[1, 2], [3]]]],        // ragged matrix
            ['sumList', [[4, 6]]],                 // good input goes native again
        ]);

        self::assertSame([6, 6, 4, 3, [[1, 3], [2]], 10], array_column($run['results'], 'value'));
        self::assertSame(['native' => 2, 'fallback' => 4], $run['stats']);
        self::assertCount(4, $run['logs']);
        self::assertStringContainsString('ran as PHP: $xs must contain only ints', $run['logs'][0]);
    }

    public function testInputsTheCCodeDeclinesGetPhpBehaviourIncludingExceptions(): void
    {
        $this->build('average');
        $this->build('sumSquares');
        $this->publish();

        $run = $this->runApp([
            ['average', [10, 3]],
            ['average', [10, 0]],            // PHP throws; C sets forja_err; the caller sees the PHP exception
            ['sumSquares', [4000000]],       // overflows int64: PHP's own TypeError, not a wrapped-around number
            ['sumSquares', ['many']],        // wrong scalar type: PHP's own TypeError
        ]);

        self::assertSame(3, $run['results'][0]['value']);
        self::assertSame('InvalidArgumentException', $run['results'][1]['error']);
        self::assertSame('TypeError', $run['results'][2]['error']);
        self::assertSame('TypeError', $run['results'][3]['error']);
        self::assertSame(['native' => 1, 'fallback' => 2], $run['stats']);
    }

    public function testRejectedAndFailedModulesRunAsPhp(): void
    {
        foreach (['crashy' => Manifest::REJECTED, 'wrong' => Manifest::REJECTED, 'broken' => Manifest::FAILED, 'usesThis' => Manifest::UNSUPPORTED] as $method => $status) {
            self::assertSame($status, $this->build($method)['status']);
        }
        self::assertSame(0, $this->publish());

        $run = $this->runApp([['crashy', [5]], ['wrong', [77]], ['broken', [7]], ['usesThis', [10]]]);

        self::assertSame([5, 154, 8, 11], array_column($run['results'], 'value'));
        self::assertSame(['native' => 0, 'fallback' => 0], $run['stats']);
    }

    public function testEditedSourceFileRunsAsPhpUntilRebuilt(): void
    {
        $this->build('sumSquares');
        $this->publish();
        $runtime = $this->layout->buildDir() . '/runtime.php';
        // Same effect as editing Sample.php after the build: the recorded mtime no longer matches.
        file_put_contents($runtime, preg_replace("/'mtime' => (\d+)/", "'mtime' => 1", (string) file_get_contents($runtime)));

        $run = $this->runApp([['sumSquares', [10]]]);

        self::assertSame(385, $run['results'][0]['value']);
        self::assertSame(['native' => 0, 'fallback' => 0], $run['stats']);
        self::assertStringContainsString('file changed since the last forja run', $run['logs'][0]);
    }

    public function testMissingLibraryAndKillSwitch(): void
    {
        $entry = $this->build('sumSquares');
        $this->publish();

        $off = $this->runApp([['sumSquares', [10]]], ['FORJA_DISABLE' => '1']);
        self::assertSame(385, $off['results'][0]['value']);
        self::assertSame(['native' => 0, 'fallback' => 0], $off['stats']);

        unlink($entry['lib']);
        $missing = $this->runApp([['sumSquares', [10]], ['sumSquares', [3]]]);
        self::assertSame([385, 14], array_column($missing['results'], 'value'));
        self::assertSame(['native' => 0, 'fallback' => 0], $missing['stats']);
        self::assertCount(1, $missing['logs'], 'reported once, then left alone');
        self::assertStringContainsString(Sample::class . '::sumSquares disabled', $missing['logs'][0]);
    }

    public function testWebRequestsNeedFfiEnabledOrPreload(): void
    {
        $this->build('sumSquares');
        $this->build('caesar');
        $this->publish();
        $calls = json_encode([['sumSquares', [10]], ['caesar', ['abc', 1]]]);
        $app = dirname(__DIR__) . '/tests/Support/app.php';
        $env = ['FORJA_DIR' => $this->dir];

        // PHP's default: FFI only from preloaded code. Nothing is preloaded, so PHP runs and says why.
        $default = json_decode((new Server($app, ['ffi.enable=preload'], $env))->post($calls), true);
        self::assertSame('cli-server', $default['sapi']);
        self::assertSame([385, 'bcd'], array_column($default['results'], 'value'));
        self::assertSame(['native' => 0, 'fallback' => 0], $default['stats']);
        self::assertStringContainsString('opcache.preload', $default['logs'][0]);

        // Recommended production setup: keep ffi.enable=preload and preload the generated script.
        $preload = ['ffi.enable=preload', 'opcache.enable=1', 'opcache.enable_cli=1', "opcache.preload={$this->layout->buildDir()}/preload.php"];
        $preloaded = json_decode((new Server($app, $preload, $env))->post($calls), true);
        self::assertSame([385, 'bcd'], array_column($preloaded['results'], 'value'));
        self::assertSame(['native' => 2, 'fallback' => 0], $preloaded['stats'], implode("\n", $preloaded['logs']));

        // Simplest setup: ffi.enable=1.
        $enabled = json_decode((new Server($app, ['ffi.enable=1'], $env))->post($calls), true);
        self::assertSame([385, 'bcd'], array_column($enabled['results'], 'value'));
        self::assertSame(['native' => 2, 'fallback' => 0], $enabled['stats']);
    }
}
