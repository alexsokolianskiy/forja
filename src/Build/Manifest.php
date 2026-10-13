<?php

declare(strict_types=1);

namespace AiAccel\Build;

/**
 * Index of built modules, keyed by "Class::method". Written by the compile
 * command, read once per process by the runtime.
 */
final class Manifest
{
    public const VERIFIED = 'verified';
    public const UNVERIFIED = 'unverified';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';
    public const UNSUPPORTED = 'unsupported';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->entries === null) {
            $data = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
            $this->entries = is_array($data) ? $data : [];
        }

        return $this->entries;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $entry */
    public function put(string $key, array $entry): void
    {
        $this->all();
        $this->entries[$key] = $entry;
    }

    public function remove(string $key): void
    {
        $this->all();
        unset($this->entries[$key]);
    }

    public function save(): void
    {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($this->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($tmp, $this->path);
    }
}
