<?php

declare(strict_types=1);

namespace Forja\Build;

/**
 * forja.lock.json: methods whose C source is frozen.
 *
 * A locked method is never sent to a model again, whatever happens to the
 * default model, the prompt or --force. Its C file may also be tuned by hand.
 * It is still compiled and verified against the PHP method on every build, so
 * a lock can never make a stale translation run. Commit this file together
 * with src/.
 */
final class LockFile
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $locks = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->locks === null) {
            $data = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
            $this->locks = is_array($data) ? $data : [];
        }

        return $this->locks;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $entry a verified manifest entry */
    public function lock(string $key, array $entry): void
    {
        $this->all();
        $this->locks[$key] = [
            'hash' => $entry['hash'],
            'source' => 'src/' . $entry['hash'] . '.c',
            'header' => $entry['header'],
            'model' => $entry['model'],
            'lockedAt' => date('Y-m-d'),
        ];
        $this->save();
    }

    public function unlock(string $key): bool
    {
        if ($this->get($key) === null) {
            return false;
        }
        unset($this->locks[$key]);
        $this->save();

        return true;
    }

    private function save(): void
    {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }
        ksort($this->locks);
        file_put_contents($this->path, json_encode($this->locks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
