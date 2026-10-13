<?php

declare(strict_types=1);

namespace AiAccel\Generator;

use AiAccel\Abi\Signature;
use AiAccel\Parser\MethodSpec;

/**
 * Claude through the Anthropic Messages API (needs an API key).
 */
final class AnthropicGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly int $maxTokens = 16000,
        private readonly int $timeout = 300,
        private readonly PromptBuilder $prompts = new PromptBuilder(),
    ) {
    }

    public function generate(MethodSpec $spec, Signature $signature, string $model): string
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new GenerationException('no API key for Anthropic: set ANTHROPIC_API_KEY, or pick a local model');
        }

        $response = Http::postJson(
            rtrim($this->baseUrl, '/') . '/v1/messages',
            ['anthropic-version: 2023-06-01', 'x-api-key: ' . $this->apiKey],
            [
                'model' => $model,
                'max_tokens' => $this->maxTokens,
                'system' => PromptBuilder::SYSTEM,
                'messages' => [['role' => 'user', 'content' => $this->prompts->user($spec, $signature)]],
            ],
            $this->timeout
        );

        if (($response['stop_reason'] ?? null) === 'max_tokens') {
            throw new GenerationException('model output was truncated (max_tokens reached)');
        }
        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        return ReplyParser::extractCode($text);
    }
}
