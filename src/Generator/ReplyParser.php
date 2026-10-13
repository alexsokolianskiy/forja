<?php

declare(strict_types=1);

namespace AiAccel\Generator;

final class ReplyParser
{
    /** Pulls the C source out of a model reply. */
    public static function extractCode(string $text): string
    {
        // Reasoning models served locally often leak their scratchpad into the reply.
        $text = trim((string) preg_replace('~<think>.*?</think>~s', '', $text));

        if (preg_match('/^UNSUPPORTED:\s*(.+)$/m', $text, $m) && !str_contains($text, '```')) {
            throw new GenerationException('model declined: ' . trim($m[1]));
        }
        if (preg_match_all('/```[a-zA-Z+]*[ \t]*\r?\n(.*?)```/s', $text, $m)) {
            // Take the longest block: some models add a short usage example after the real code.
            usort($m[1], static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            return trim($m[1][0]) . "\n";
        }
        if ($text === '') {
            throw new GenerationException('model returned no code');
        }

        return $text . "\n";
    }
}
