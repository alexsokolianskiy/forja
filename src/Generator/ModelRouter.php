<?php

declare(strict_types=1);

namespace AiAccel\Generator;

use AiAccel\Abi\Signature;
use AiAccel\Parser\MethodSpec;

/**
 * Sends each generation request to the provider named in "provider:model".
 */
final class ModelRouter implements GeneratorInterface
{
    /** @var array<string, GeneratorInterface> */
    private array $drivers = [];

    /** @param array<string, array<string, mixed>> $providers */
    public function __construct(private readonly array $providers)
    {
    }

    public function generate(MethodSpec $spec, Signature $signature, string $model): string
    {
        [$provider, $name] = ModelResolver::split($model);

        return $this->driver($provider)->generate($spec, $signature, $name);
    }

    private function driver(string $provider): GeneratorInterface
    {
        if (isset($this->drivers[$provider])) {
            return $this->drivers[$provider];
        }
        $config = $this->providers[$provider] ?? throw new GenerationException("unknown model provider '$provider'");

        return $this->drivers[$provider] = match ($config['driver'] ?? $provider) {
            'anthropic' => new AnthropicGenerator($config['api_key'] ?? null, $config['base_url'] ?? 'https://api.anthropic.com'),
            'openai' => new OpenAiCompatibleGenerator(
                $config['base_url'] ?? throw new GenerationException("provider '$provider' needs a base_url"),
                $config['api_key'] ?? null,
            ),
            default => throw new GenerationException("provider '$provider' has unknown driver '{$config['driver']}'"),
        };
    }
}
