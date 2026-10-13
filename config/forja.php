<?php

declare(strict_types=1);

use Forja\Model;

return [
    // Which model writes the C. "provider:model", an alias from below, or an Forja\Model constant.
    // #[AccelerateToC(model: ...)] overrides it per method, `forja --model=...` for one run.
    'default_model' => getenv('FORJA_MODEL') ?: Model::SONNET,

    // Your own names for models: #[AccelerateToC(model: 'fast')]
    'aliases' => [
        // 'fast'  => 'local:qwen2.5-coder:14b',
        // 'smart' => Model::OPUS,
    ],

    'providers' => [
        // Claude through the Anthropic API. Needs a key.
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => getenv('ANTHROPIC_API_KEY') ?: null,
            'default_model' => 'claude-sonnet-5-5',
        ],

        // A model on your own machine: Ollama (default URL below), LM Studio (http://localhost:1234/v1),
        // llama.cpp server, vLLM... anything with an OpenAI-compatible /chat/completions. No key needed.
        'local' => [
            'driver' => 'openai',
            'base_url' => getenv('FORJA_LOCAL_URL') ?: 'http://localhost:11434/v1',
            'api_key' => null,
            'default_model' => getenv('FORJA_LOCAL_MODEL') ?: 'qwen2.5-coder:14b',
        ],

        // Any other OpenAI-compatible service works the same way:
        // 'openrouter' => ['driver' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1', 'api_key' => getenv('OPENROUTER_API_KEY')],
    ],

    // The project's forja/ directory. Commit forja/src and forja/forja.lock.json: they are the verified
    // translations every machine builds from. forja/build is machine-specific and ignored by git.
    // Override with FORJA_DIR.
    'dir' => Forja\Runtime::defaultDir(),

    // true (or FORJA_FROZEN=1, or --frozen): never call a model. Build only from the committed sources,
    // like `composer install` builds only from composer.lock. Use it on CI and in production.
    'frozen' => (bool) getenv('FORJA_FROZEN'),

    'compiler' => getenv('CC') ?: 'cc',

    // -ffp-contract=off stops the compiler fusing a*b+c into one FMA instruction, which rounds
    // differently from PHP. For the same reason, do not add -ffast-math.
    'cflags' => ['-O3', '-std=c11', '-ffp-contract=off'],

    // Directories scanned by `vendor/bin/forja` when no path is given.
    'paths' => ['app', 'src'],

    // true: also translate every method that has a loop and is fully translatable, without an attribute.
    'auto' => false,

    'verify' => [
        // With verification off, modules are loaded untested. A crash in one takes the PHP process down.
        'enabled' => true,
        'iterations' => 1000,
        'timeout' => 60,
    ],
];
