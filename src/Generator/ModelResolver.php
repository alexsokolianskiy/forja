<?php

declare(strict_types=1);

namespace AiAccel\Generator;

/**
 * Turns what a developer writes in `model:` into a canonical "provider:model".
 *
 *   null                      -> the default model
 *   'fast'                    -> whatever the `aliases` config maps it to
 *   'local'                   -> the local provider's default model
 *   'local:qwen2.5-coder:7b'  -> as written
 *   'claude-opus-5-5'         -> 'anthropic:claude-opus-5-5'
 */
final class ModelResolver
{
    /**
     * @param array<string, array<string, mixed>> $providers
     * @param array<string, string> $aliases
     */
    public function __construct(
        private readonly array $providers,
        private readonly array $aliases,
        private readonly string $default,
        private readonly string $fallbackProvider = 'anthropic',
    ) {
    }

    public function resolve(?string $model): string
    {
        $model = trim((string) $model) ?: $this->default;
        for ($i = 0; $i < 5 && isset($this->aliases[$model]); $i++) {
            $model = $this->aliases[$model];
        }

        if (isset($this->providers[$model])) {
            $default = $this->providers[$model]['default_model'] ?? null;
            if (!$default) {
                throw new GenerationException("provider '$model' has no default_model; write '$model:<model name>'");
            }

            return $model . ':' . $default;
        }
        [$provider, $name] = array_pad(explode(':', $model, 2), 2, '');
        if ($name !== '' && isset($this->providers[$provider])) {
            return $model;
        }

        return $this->fallbackProvider . ':' . $model;
    }

    /** @return array{0: string, 1: string} [provider, model] */
    public static function split(string $canonical): array
    {
        return array_pad(explode(':', $canonical, 2), 2, '');
    }
}
