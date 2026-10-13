<?php

declare(strict_types=1);

namespace Forja\Build;

/**
 * Where things live inside the project's forja/ directory.
 *
 *   forja/src/App/Report/total.c   verified C, one file per method      (commit)
 *   forja/forja.lock.json          checksums, models, locks             (commit)
 *   forja/build/                   everything machine-specific          (ignored)
 *     candidates/                  generated C that has not passed verification
 *     lib/                         shared libraries and FFI headers
 *     php/                         guarded copies of the PHP sources
 *     manifest.json, runtime.php, preload.php
 */
final class Layout
{
    public readonly string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public function lockPath(): string
    {
        return $this->dir . '/forja.lock.json';
    }

    public function buildDir(): string
    {
        return $this->dir . '/build';
    }

    public function manifestPath(): string
    {
        return $this->buildDir() . '/manifest.json';
    }

    /** Path of a method's verified source, relative to the forja directory. */
    public function sourceName(string $key): string
    {
        [$class, $method] = explode('::', $key);

        return 'src/' . str_replace('\\', '/', $class) . '/' . $method . '.c';
    }

    public function candidatePath(string $hash): string
    {
        return $this->buildDir() . "/candidates/$hash.c";
    }

    public function libDir(): string
    {
        return $this->buildDir() . '/lib';
    }

    /** Creates the directory and the .gitignore that keeps build products out of the repository. */
    public function prepare(): void
    {
        if (!is_dir($this->buildDir())) {
            mkdir($this->buildDir(), 0775, true);
        }
        if (!is_file($this->dir . '/.gitignore')) {
            file_put_contents($this->dir . '/.gitignore', "/build/\n");
        }
    }
}
