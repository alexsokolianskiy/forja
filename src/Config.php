<?php

declare(strict_types=1);

namespace Forja;

use Forja\Build\Layout;
use Forja\Generator\ModelResolver;

final class Config
{
    public const DEFAULT_CFLAGS = ['-O3', '-std=c11', '-ffp-contract=off'];

    public const DEFAULT_PROVIDERS = [
        'anthropic' => ['driver' => 'anthropic', 'api_key' => null, 'default_model' => 'claude-sonnet-5-5'],
        'local' => ['driver' => 'openai', 'base_url' => 'http://localhost:11434/v1', 'api_key' => null, 'default_model' => null],
    ];

    /**
     * @param array<string, array<string, mixed>> $providers
     * @param array<string, string> $aliases
     * @param list<string> $cflags
     * @param list<string> $paths
     */
    public function __construct(
        public readonly string $dir,
        public readonly string $defaultModel = Model::SONNET,
        public readonly array $providers = self::DEFAULT_PROVIDERS,
        public readonly array $aliases = [],
        public readonly string $compiler = 'cc',
        public readonly array $cflags = self::DEFAULT_CFLAGS,
        public readonly array $paths = ['app', 'src'],
        public readonly bool $auto = false,
        public readonly bool $frozen = false,
        public readonly bool $verifyEnabled = true,
        public readonly int $verifyIterations = 1000,
        public readonly int $verifyTimeout = 60,
    ) {
    }

    /** @param array<string, mixed> $values as returned by config/forja.php */
    public static function fromArray(array $values): self
    {
        return new self(
            dir: rtrim($values['dir'] ?? Runtime::defaultDir(), '/'),
            defaultModel: $values['default_model'] ?? Model::SONNET,
            providers: ($values['providers'] ?? []) + self::DEFAULT_PROVIDERS,
            aliases: $values['aliases'] ?? [],
            compiler: $values['compiler'] ?? 'cc',
            cflags: $values['cflags'] ?? self::DEFAULT_CFLAGS,
            paths: $values['paths'] ?? ['app', 'src'],
            auto: $values['auto'] ?? false,
            frozen: $values['frozen'] ?? false,
            verifyEnabled: $values['verify']['enabled'] ?? true,
            verifyIterations: $values['verify']['iterations'] ?? 1000,
            verifyTimeout: $values['verify']['timeout'] ?? 60,
        );
    }

    public static function fromFile(string $file): self
    {
        return self::fromArray(require $file);
    }

    public function layout(): Layout
    {
        return new Layout($this->dir);
    }

    public function models(): ModelResolver
    {
        return new ModelResolver($this->providers, $this->aliases, $this->defaultModel);
    }
}
