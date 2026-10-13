<?php

declare(strict_types=1);

namespace Forja\Build;

/**
 * forja.lock.json: one entry per method whose C translation has been verified.
 *
 * It plays the role composer.lock plays for dependencies. The C sources in
 * forja/src and this file are committed; every other machine builds from
 * exactly those bytes (the sha256 is checked) and never asks a model, so the
 * code that runs in production is the code that was reviewed.
 *
 * An entry with "locked": true is additionally frozen: it is kept even when
 * the PHP method, the model or the prompt changes, and is only re-verified.
 */
final class LockFile
{
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
        if ($this->get($key) === $entry) {
            return;
        }
        $this->entries[$key] = $entry;
        $this->save();
    }

    public function remove(string $key): bool
    {
        if ($this->get($key) === null) {
            return false;
        }
        unset($this->entries[$key]);
        $this->save();

        return true;
    }

    /** @return bool false when the method has no verified translation to lock or unlock */
    public function setLocked(string $key, bool $locked): bool
    {
        $entry = $this->get($key);
        if ($entry === null) {
            return false;
        }
        $this->put($key, ['locked' => $locked] + $entry);

        return true;
    }

    private function save(): void
    {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }
        // Sorted and without timestamps: the same state always produces the same file.
        ksort($this->entries);
        foreach ($this->entries as &$entry) {
            ksort($entry);
        }
        unset($entry);
        file_put_contents($this->path, json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
