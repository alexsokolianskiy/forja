<?php

declare(strict_types=1);

namespace Forja\Tests;

use Forja\Build\Manifest;
use Forja\Generator\GenerationException;
use Forja\Generator\ModelRouter;
use Forja\Tests\Support\PipelineTestCase;
use Forja\Tests\Support\Server;

/**
 * The real HTTP drivers against a local stand-in for the model servers.
 */
final class ProviderTest extends PipelineTestCase
{
    private Server $server;
    private string $log;

    /** @var array<string, array<string, mixed>> */
    private array $providers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir() . '/forja-llm-' . bin2hex(random_bytes(6)) . '.log';
        $this->server = new Server(__DIR__ . '/Support/fake-llm.php', [], ['FAKE_LLM_LOG' => $this->log]);
        $this->providers = [
            'anthropic' => ['driver' => 'anthropic', 'api_key' => 'test-key', 'base_url' => $this->server->url, 'default_model' => 'claude-sonnet-5-5'],
            'local' => ['driver' => 'openai', 'base_url' => $this->server->url . '/v1', 'default_model' => 'qwen2.5-coder:14b'],
            'keyless' => ['driver' => 'anthropic', 'api_key' => null],
        ];
    }

    protected function tearDown(): void
    {
        unset($this->server);
        @unlink($this->log);
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function requests(): array
    {
        return array_map(static fn (string $line) => json_decode($line, true), file($this->log, FILE_IGNORE_NEW_LINES));
    }

    public function testClaudeThroughTheApi(): void
    {
        $builder = $this->builder(null, new ModelRouter($this->providers), $this->providers);

        $entry = $builder->build($this->spec('caesar'));

        self::assertSame(Manifest::VERIFIED, $entry['status'], (string) $entry['message']);
        self::assertSame('anthropic:claude-sonnet-5-5', $entry['model']);
        self::assertSame(
            [['path' => '/v1/messages', 'model' => 'claude-sonnet-5-5', 'apiKey' => 'test-key', 'authorization' => null, 'hasContext' => true]],
            $this->requests()
        );
    }

    public function testLocalModelNeedsNoKey(): void
    {
        $builder = $this->builder('local', new ModelRouter($this->providers), $this->providers);

        $entry = $builder->build($this->spec('sumSquares'));

        self::assertSame(Manifest::VERIFIED, $entry['status'], (string) $entry['message']);
        self::assertSame('local:qwen2.5-coder:14b', $entry['model']);
        self::assertSame(
            [['path' => '/v1/chat/completions', 'model' => 'qwen2.5-coder:14b', 'apiKey' => null, 'authorization' => null, 'hasContext' => false]],
            $this->requests()
        );
        self::assertStringNotContainsString('<think>', (string) file_get_contents($entry['csource']));
        self::assertStringNotContainsString('int x;', (string) file_get_contents($entry['csource']), 'the usage example was not taken for the code');
    }

    public function testPerMethodModelsGoToDifferentProvidersInOneRun(): void
    {
        $builder = $this->builder(null, new ModelRouter($this->providers), $this->providers);

        $builder->build($this->spec('triple'));   // attribute: Model::OPUS
        $this->builder('local:phi4', new ModelRouter($this->providers), $this->providers)->build($this->spec('isPrime'));

        self::assertSame(['claude-opus-5-5', 'phi4'], array_column($this->requests(), 'model'));
        self::assertSame(['/v1/messages', '/v1/chat/completions'], array_column($this->requests(), 'path'));
    }

    public function testMissingKeyAndUnreachableServerAreReportedNotFatal(): void
    {
        $providers = $this->providers;
        $providers['local']['base_url'] = 'http://127.0.0.1:9/v1';
        $router = new ModelRouter($providers);

        $noKey = $this->builder('keyless:claude-sonnet-5-5', $router, $providers)->build($this->spec('sumSquares'));
        self::assertSame(Manifest::FAILED, $noKey['status']);
        self::assertStringContainsString('set ANTHROPIC_API_KEY, or pick a local model', $noKey['message']);

        $down = $this->builder('local', $router, $providers)->build($this->spec('sumSquares'));
        self::assertSame(Manifest::FAILED, $down['status']);
        self::assertStringContainsString('request to http://127.0.0.1:9/v1/chat/completions failed', $down['message']);

        $this->expectException(GenerationException::class);
        $router->generate($this->spec('sumSquares'), \Forja\Abi\Signature::fromSpec($this->spec('sumSquares'), str_repeat('a', 64)), 'nowhere:model');
    }
}
