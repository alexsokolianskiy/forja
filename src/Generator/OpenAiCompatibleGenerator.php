<?php

declare(strict_types=1);

namespace AiAccel\Generator;

use AiAccel\Abi\Signature;
use AiAccel\Parser\MethodSpec;

/**
 * Any server that speaks the OpenAI chat-completions protocol: Ollama,
 * LM Studio, llama.cpp, vLLM, or a hosted OpenAI-compatible API.
 * No key is needed for a local server.
 */
final class OpenAiCompatibleGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 600,
        private readonly PromptBuilder $prompts = new PromptBuilder(),
    ) {
    }

    public function generate(MethodSpec $spec, Signature $signature, string $model): string
    {
        $response = Http::postJson(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            $this->apiKey ? ['authorization: Bearer ' . $this->apiKey] : [],
            [
                'model' => $model,
                'temperature' => 0,
                'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => PromptBuilder::SYSTEM],
                    ['role' => 'user', 'content' => $this->prompts->user($spec, $signature)],
                ],
            ],
            $this->timeout
        );

        $choice = $response['choices'][0] ?? null;
        if (!is_array($choice) || !is_string($choice['message']['content'] ?? null)) {
            throw new GenerationException('unexpected response shape from ' . $this->baseUrl);
        }
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new GenerationException('model output was truncated (token limit reached)');
        }

        return ReplyParser::extractCode($choice['message']['content']);
    }
}
