<?php

declare(strict_types=1);

namespace AiAccel\Fallback;

use AiAccel\FFI\MarshalException;
use AiAccel\FFI\NativeException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Bookkeeping for calls that could not run natively. The PHP method body
 * itself is the fallback; this class decides whether the module stays in
 * use and what gets logged.
 */
final class FallbackHandler
{
    /** @var array<string, true> */
    private array $disabled = [];

    /** @var array{native: int, fallback: int} */
    private array $stats = ['native' => 0, 'fallback' => 0];

    public function __construct(private LoggerInterface $logger = new NullLogger())
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function isDisabled(string $key): bool
    {
        return isset($this->disabled[$key]);
    }

    /** Stops using a module for the rest of the process. */
    public function disable(string $key, string $reason): void
    {
        $this->disabled[$key] = true;
        $this->logger->warning('ai-accel: {key} disabled, using PHP: {reason}', ['key' => $key, 'reason' => $reason]);
    }

    public function attempted(): void
    {
        $this->stats['native']++;
    }

    public function failed(string $key, \Throwable $error): void
    {
        $this->stats['native']--;
        $this->stats['fallback']++;

        if ($error instanceof NativeException) {
            // Routine: the C code met an input it must not handle (PHP raises an error there, an int overflowed...).
            $this->logger->debug('ai-accel: {key} ran as PHP: {reason}', ['key' => $key, 'reason' => $error->getMessage()]);
        } elseif ($error instanceof MarshalException) {
            $this->logger->notice('ai-accel: {key} ran as PHP: {reason}', ['key' => $key, 'reason' => $error->getMessage()]);
        } else {
            $this->disable($key, get_class($error) . ': ' . $error->getMessage());
        }
    }

    /** @return array{native: int, fallback: int} */
    public function stats(): array
    {
        return $this->stats;
    }
}
