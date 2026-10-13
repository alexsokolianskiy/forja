<?php

declare(strict_types=1);

namespace Forja;

/**
 * Shorthand for the `model:` argument of #[AccelerateToC].
 *
 * A model is written "provider:model". The provider names come from the
 * `providers` section of the config; a bare model name uses `anthropic`,
 * and a bare provider name uses that provider's `default_model`.
 */
final class Model
{
    public const FABLE = 'anthropic:claude-fable-5-1';
    public const OPUS = 'anthropic:claude-opus-5-5';
    public const SONNET = 'anthropic:claude-sonnet-5-5';
    public const HAIKU = 'anthropic:claude-haiku-5-5';

    /** The `local` provider (Ollama, LM Studio, llama.cpp, vLLM...) with its configured default model. */
    public const LOCAL = 'local';
}
