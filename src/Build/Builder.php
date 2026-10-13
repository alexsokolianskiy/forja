<?php

declare(strict_types=1);

namespace Forja\Build;

use Forja\Abi\Signature;
use Forja\Compiler\CCompiler;
use Forja\Compiler\CompileException;
use Forja\Generator\CodeSanitizer;
use Forja\Generator\GenerationException;
use Forja\Generator\GeneratorInterface;
use Forja\Generator\ModelResolver;
use Forja\Generator\PromptBuilder;
use Forja\Parser\MethodSpec;
use Forja\Verify\Verifier;

/**
 * Takes one method from PHP to a verified C module.
 *
 * A verified source already in forja/src is reused byte for byte. Otherwise a
 * model writes a candidate, and only a candidate that compiles and matches
 * the PHP method on every test input is promoted into forja/src and recorded
 * in forja.lock.json.
 */
final class Builder
{
    public function __construct(
        private readonly GeneratorInterface $generator,
        private readonly CCompiler $compiler,
        private readonly ?Verifier $verifier,
        private readonly Manifest $manifest,
        private readonly LockFile $locks,
        private readonly Layout $layout,
        private readonly ModelResolver $models,
        private readonly ?string $modelOverride = null,
        private readonly bool $frozen = false,
        private readonly CodeSanitizer $sanitizer = new CodeSanitizer(),
    ) {
    }

    /** SHA-256 of what the PHP side looks like: the method, what it depends on, and its types. */
    public function phpHash(MethodSpec $spec): string
    {
        return hash('sha256', json_encode([$spec->source, $spec->context, $spec->params, $spec->returnKind], JSON_THROW_ON_ERROR));
    }

    /** SHA-256 of everything that determines the generated code: the PHP side, the model, the prompt, the runtime. */
    public function hash(MethodSpec $spec): string
    {
        return hash('sha256', json_encode([
            $this->phpHash($spec),
            $this->model($spec),
            PromptBuilder::VERSION,
            PromptBuilder::SYSTEM,
            Signature::usesRuntime($spec->params, $spec->returnKind) ? PromptBuilder::runtimeApi() : null,
        ], JSON_THROW_ON_ERROR));
    }

    public function model(MethodSpec $spec): string
    {
        return $this->models->resolve($this->modelOverride ?? $spec->model);
    }

    /** What build() would do for this method, without doing it. */
    public function plan(MethodSpec $spec, bool $force = false): string
    {
        if ($spec->unsupported !== null) {
            return 'unsupported: ' . $spec->unsupported;
        }
        try {
            $lock = $this->reusable($spec, $force);
            if ($lock !== null) {
                return ($lock['locked'] ?? false) ? 'locked: reuse ' . $lock['source'] : 'reuse ' . $lock['source'];
            }

            return $this->frozen ? 'missing: no verified C source, and --frozen forbids generating one' : 'generate with ' . $this->model($spec);
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
     * The lock entry whose committed source should be used as is, if there is one.
     *
     * @return array<string, mixed>|null
     */
    private function reusable(MethodSpec $spec, bool $force): ?array
    {
        $lock = $this->locks->get($spec->key());
        if ($lock === null || !is_file($this->layout->dir . '/' . $lock['source'])) {
            return null;
        }
        if ($lock['locked'] ?? false) {
            return $lock;
        }
        if ($lock['phpHash'] !== $this->phpHash($spec)) {
            return null; // the PHP method changed: this translation belongs to an older version of it
        }

        // An unchanged method keeps its translation, even if the default model or the prompt has moved on:
        // regenerating would only produce different bytes for the same behaviour. --force asks for that explicitly.
        return $this->frozen || (!$force && $spec->deterministic) ? $lock : null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function buildSupported(MethodSpec $spec, array $entry, bool $force): array
    {
        $key = $spec->key();
        $this->layout->prepare();
        $lock = $this->reusable($spec, $force);
        $hash = $lock['hash'] ?? $this->hash($spec);
        $signature = Signature::fromSpec($spec, $hash);

        $entry = [
            'locked' => (bool) ($lock['locked'] ?? false),
            'hash' => $hash,
            'phpHash' => $this->phpHash($spec),
            'model' => $lock['model'] ?? $this->model($spec),
            'symbol' => $signature->symbol,
            'header' => $signature->header(),
            'params' => $spec->params,
            'return' => $spec->returnKind,
            'ranges' => $spec->ranges,
            'examples' => $spec->examples,
        ] + $entry;

        if ($lock !== null) {
            $sourcePath = $this->layout->dir . '/' . $lock['source'];
            $entry['cached'] = true;
            if ($lock['header'] !== $entry['header']) {
                return ['status' => Manifest::REJECTED, 'csource' => $sourcePath,
                    'message' => "the method's signature changed since it was locked; run --unlock=$key to regenerate"] + $entry;
            }
        } elseif ($this->frozen) {
            return ['status' => Manifest::MISSING,
                'message' => 'no verified C source for this version of the method; run `forja` on a development machine and commit forja/'] + $entry;
        } else {
            $sourcePath = $this->layout->candidatePath($hash);
            $source = $this->generator->generate($spec, $signature, $entry['model']);
            if (!is_dir(dirname($sourcePath))) {
                mkdir(dirname($sourcePath), 0775, true);
            }
            file_put_contents($sourcePath, rtrim($source) . "\n" . $signature->epilogue());
        }
        $entry['csource'] = $sourcePath;

        // The library is named after the C text and the toolchain, so a hand-edited source or new flags rebuild it.
        $source = (string) file_get_contents($sourcePath);
        $sha = hash('sha256', $source);
        $libId = substr(hash('sha256', $source . "\0" . $this->compiler->id()), 0, 32);
        $entry['libId'] = $libId;
        $entry['lib'] = $this->layout->libDir() . "/$libId." . CCompiler::libraryExtension();
        $entry['scope'] = 'forja_' . $libId;
        $entry['hfile'] = $this->layout->libDir() . "/$libId.h";

        $previous = $this->manifest->get($key);
        $verdictStands = $previous !== null && ($previous['libId'] ?? null) === $libId && ($previous['phpHash'] ?? null) === $entry['phpHash']
            && ($previous['ranges'] ?? null) === $entry['ranges'] && ($previous['examples'] ?? null) === $entry['examples']
            && is_file($entry['lib']) && is_file($entry['hfile'])
            && in_array($previous['status'], [Manifest::VERIFIED, Manifest::REJECTED], true);

        if ($verdictStands) {
            // Same C, same PHP, same toolchain: the earlier verdict still stands.
            $entry = ['status' => $previous['status'], 'message' => $previous['message']] + $entry;
        } else {
            $this->sanitizer->check($source, $signature->modelSymbol());
            if (!is_file($entry['lib'])) {
                $this->compiler->compile($sourcePath, $entry['lib']);
            }
            file_put_contents($entry['hfile'], sprintf("#define FFI_SCOPE \"%s\"\n#define FFI_LIB \"%s\"\n%s\n", $entry['scope'], $entry['lib'], $entry['header']));

            if ($this->verifier === null) {
                return ['status' => Manifest::UNVERIFIED, 'message' => 'verification disabled; the source stays in forja/build/candidates'] + $entry;
            }
            $result = $this->verifier->verify($entry);
            $entry = ['status' => $result->ok ? Manifest::VERIFIED : Manifest::REJECTED, 'message' => $result->message] + $entry;
        }

        if ($entry['status'] !== Manifest::VERIFIED) {
            if ($lock !== null) {
                $entry['message'] .= ($entry['locked'] ? " (C source is locked; fix {$lock['source']} or run --unlock=$key)" : " ({$lock['source']})");
            } else {
                $entry['message'] .= ' (candidate kept in ' . $sourcePath . ')';
            }

            return $entry;
        }

        return $this->promote($spec, $entry, $lock, $source, $sha);
    }

    /**
     * Verified: the source becomes part of the project.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $lock
     * @return array<string, mixed>
     */
    private function promote(MethodSpec $spec, array $entry, ?array $lock, string $source, string $sha): array
    {
        $name = $lock['source'] ?? $this->layout->sourceName($spec->key());
        $target = $this->layout->dir . '/' . $name;
        if ($lock === null) {
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $source);
        } elseif ($lock['sha256'] !== $sha) {
            $entry['message'] .= ' (source was edited by hand; checksum updated)';
        }
        $entry['csource'] = $target;
        $entry['locked'] = $entry['locked'] || $spec->lock;

        if (!$this->frozen) {
            $this->locks->put($spec->key(), [
                'source' => $name,
                'sha256' => $sha,
                'hash' => $entry['hash'],
                'phpHash' => $lock !== null && ($lock['locked'] ?? false) ? $lock['phpHash'] : $entry['phpHash'],
                'header' => $entry['header'],
                'model' => $entry['model'],
                'locked' => $entry['locked'],
            ]);
        } elseif ($lock !== null && $lock['sha256'] !== $sha) {
            // A frozen build must use exactly what was committed.
            return ['status' => Manifest::REJECTED, 'message' => "{$lock['source']} does not match the checksum in forja.lock.json"] + $entry;
        }

        return $entry;
    }
}
