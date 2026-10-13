<?php

declare(strict_types=1);

namespace AiAccel\Build;

use AiAccel\Abi\Signature;
use AiAccel\Compiler\CCompiler;
use AiAccel\Compiler\CompileException;
use AiAccel\Generator\CodeSanitizer;
use AiAccel\Generator\GenerationException;
use AiAccel\Generator\GeneratorInterface;
use AiAccel\Generator\ModelResolver;
use AiAccel\Generator\PromptBuilder;
use AiAccel\Parser\MethodSpec;
use AiAccel\Verify\Verifier;

/**
 * Runs one method through generate -> sanitize -> compile -> verify and
 * records the outcome in the manifest.
 *
 * Cache layout under $cacheDir:
 *   src/<hash>.c        generated source (commit and review it)
 *   accel.lock.json     frozen sources (commit it)
 *   lib/                compiled libraries and their FFI headers (machine-specific)
 *   manifest.json       outcome of the last build
 */
final class Builder
{
    public function __construct(
        private readonly GeneratorInterface $generator,
        private readonly CCompiler $compiler,
        private readonly ?Verifier $verifier,
        private readonly Manifest $manifest,
        private readonly LockFile $locks,
        private readonly string $cacheDir,
        private readonly ModelResolver $models,
        private readonly ?string $modelOverride = null,
        private readonly CodeSanitizer $sanitizer = new CodeSanitizer(),
    ) {
    }

    /** SHA-256 of what the PHP side looks like: the method, what it depends on, and its types. */
    public function phpHash(MethodSpec $spec): string
    {
        return hash('sha256', json_encode([$spec->source, $spec->context, $spec->params, $spec->returnKind], JSON_THROW_ON_ERROR));
    }

    /** SHA-256 of everything that determines the generated code: the PHP side, the model and the prompt. */
    public function hash(MethodSpec $spec): string
    {
        return hash('sha256', json_encode([
            $this->phpHash($spec),
            $this->model($spec),
            PromptBuilder::VERSION,
            PromptBuilder::SYSTEM,
        ], JSON_THROW_ON_ERROR));
    }

    public function model(MethodSpec $spec): string
    {
        return $this->models->resolve($this->modelOverride ?? $spec->model);
    }

    /** What build() would do for this method, without doing it. */
    public function plan(MethodSpec $spec): string
    {
        if ($spec->unsupported !== null) {
            return 'unsupported: ' . $spec->unsupported;
        }
        if ($this->locks->get($spec->key()) !== null) {
            return 'locked: reuse the frozen C source';
        }
        try {
            $cached = $spec->deterministic && is_file("{$this->cacheDir}/src/{$this->hash($spec)}.c");

            return $cached ? 'cached: reuse the generated C source' : 'generate with ' . $this->model($spec);
        } catch (GenerationException $e) {
            return 'failed: ' . $e->getMessage();
        }
    }

    /** @return array<string, mixed> the manifest entry */
    public function build(MethodSpec $spec, bool $force = false): array
    {
        $entry = [
            'class' => $spec->class,
            'method' => $spec->method,
            'sourceFile' => realpath($spec->file) ?: $spec->file,
            'sourceMtime' => @filemtime($spec->file) ?: 0,
            'status' => Manifest::UNSUPPORTED,
            'message' => $spec->unsupported,
            'cached' => false,
            'locked' => false,
            'auto' => $spec->auto,
        ];

        if ($spec->unsupported === null) {
            try {
                $entry = $this->buildSupported($spec, $entry, $force);
            } catch (GenerationException | CompileException $e) {
                $entry = ['status' => Manifest::FAILED, 'message' => $e->getMessage()] + $entry;
            }
        }

        $this->manifest->put($spec->key(), $entry);
        $this->manifest->save();

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function buildSupported(MethodSpec $spec, array $entry, bool $force): array
    {
        $key = $spec->key();
        $lock = $this->locks->get($key);
        $hash = $lock['hash'] ?? $this->hash($spec);
        $signature = Signature::fromSpec($spec, $hash);
        $sourcePath = "{$this->cacheDir}/src/$hash.c";

        $entry = [
            'locked' => $lock !== null,
            'hash' => $hash,
            'phpHash' => $this->phpHash($spec),
            'model' => $lock['model'] ?? $this->model($spec),
            'symbol' => $signature->symbol,
            'header' => $signature->header(),
            'params' => $spec->params,
            'return' => $spec->returnKind,
            'ranges' => $spec->ranges,
            'csource' => $sourcePath,
        ] + $entry;

        if ($lock !== null) {
            if (!is_file($sourcePath)) {
                throw new GenerationException("locked C source {$lock['source']} is missing; restore it or run --unlock=$key");
            }
            if ($lock['header'] !== $entry['header']) {
                return ['status' => Manifest::REJECTED, 'message' => "the method's signature changed since it was locked; run --unlock=$key to regenerate"] + $entry;
            }
        } elseif ($force || !$spec->deterministic || !is_file($sourcePath)) {
            $source = $this->generator->generate($spec, $signature, $entry['model']);
            if (!is_dir(dirname($sourcePath))) {
                mkdir(dirname($sourcePath), 0775, true);
            }
            file_put_contents($sourcePath, rtrim($source) . "\n" . $signature->epilogue());
        } else {
            $entry['cached'] = true;
        }

        // The library is named after the C text and the toolchain, so a hand-edited source or new flags rebuild it.
        $source = (string) file_get_contents($sourcePath);
        $libId = substr(hash('sha256', $source . "\0" . $this->compiler->id()), 0, 32);
        $entry['libId'] = $libId;
        $entry['lib'] = "{$this->cacheDir}/lib/$libId." . CCompiler::libraryExtension();
        $entry['scope'] = 'accel_' . $libId;
        $entry['hfile'] = "{$this->cacheDir}/lib/$libId.h";

        $previous = $this->manifest->get($key);
        if ($previous !== null && ($previous['libId'] ?? null) === $libId && ($previous['phpHash'] ?? null) === $entry['phpHash']
            && ($previous['ranges'] ?? null) === $entry['ranges'] && is_file($entry['lib']) && is_file($entry['hfile'])
            && in_array($previous['status'], [Manifest::VERIFIED, Manifest::REJECTED], true)) {
            // Same C, same PHP, same toolchain: the earlier verdict still stands.
            return $this->lockIfRequested($spec, ['status' => $previous['status'], 'message' => $previous['message'], 'cached' => true] + $entry);
        }

        $this->sanitizer->check($source, $signature->symbol);
        if (!is_file($entry['lib'])) {
            $this->compiler->compile($sourcePath, $entry['lib']);
        }
        file_put_contents($entry['hfile'], sprintf(
            "#define FFI_SCOPE \"%s\"\n#define FFI_LIB \"%s\"\n%s\n",
            $entry['scope'],
            $entry['lib'],
            $entry['header']
        ));

        if ($this->verifier === null) {
            return ['status' => Manifest::UNVERIFIED, 'message' => 'verification disabled'] + $entry;
        }
        $result = $this->verifier->verify($entry);
        $message = $result->message;
        if (!$result->ok && $lock !== null) {
            $message .= " (C source is locked; fix {$lock['source']} or run --unlock=$key)";
        }

        return $this->lockIfRequested($spec, ['status' => $result->ok ? Manifest::VERIFIED : Manifest::REJECTED, 'message' => $message] + $entry);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function lockIfRequested(MethodSpec $spec, array $entry): array
    {
        if ($spec->lock && !$entry['locked'] && $entry['status'] === Manifest::VERIFIED) {
            $this->locks->lock($spec->key(), $entry);
            $entry['locked'] = true;
        }

        return $entry;
    }
}
