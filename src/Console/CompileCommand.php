<?php

declare(strict_types=1);

namespace Forja\Console;

use Forja\Build\Builder;
use Forja\Build\LockFile;
use Forja\Build\Manifest;
use Forja\Build\Publisher;
use Forja\Compiler\CCompiler;
use Forja\Config;
use Forja\FFI\FFILoader;
use Forja\Generator\GeneratorInterface;
use Forja\Generator\ModelRouter;
use Forja\Parser\MethodExtractor;
use Forja\Verify\Verifier;

final class CompileCommand
{
    private const USAGE = <<<'TXT'
        Usage: forja [paths...] [options]

          paths              Files or directories to scan (default: `paths` from the config)

        Choosing the model
          --model=NAME       Model for this run, overriding the config and the attributes:
                               anthropic:claude-opus-5-5   Claude through the API (needs ANTHROPIC_API_KEY)
                               local:qwen2.5-coder:14b     a model served on this machine (Ollama, LM Studio...)
                               local                       the local provider's default model
                               <alias>                     a name from `aliases` in the config

        What to translate
          --auto             Also translate methods without #[AccelerateToC] that contain a loop and qualify
          --dry-run          Show what would be generated, reused or skipped; call no model, build nothing

        Freezing good results
          --lock[=KEY]       Freeze the verified C of Class::method (or of everything verified):
                             it is never regenerated again, only re-verified
          --unlock[=KEY]     Remove the lock from Class::method (or from everything)

        Building
          --force            Regenerate and rebuild even when cached (locked methods keep their C)
          --no-verify        Skip the differential test and use the modules untested
          --strict           Exit with status 1 unless every attributed method ends up accelerated
          --status           Print the last build's results and exit
          --config=FILE      Config file (default: ./config/forja.php, then the package default)

        TXT;

    private const FLAGS = ['auto', 'dry-run', 'force', 'no-verify', 'strict', 'status'];
    private const VALUES = ['config', 'model'];
    private const OPTIONAL_VALUES = ['lock', 'unlock'];

    /** @param resource $out */
    public function __construct(
        private readonly ?GeneratorInterface $generator = null,
        private $out = STDOUT,
    ) {
    }

    /** @param list<string> $argv without the script name */
    public function run(array $argv): int
    {
        $paths = [];
        $options = [];
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                fwrite($this->out, self::USAGE);

                return 0;
            }
            if (!str_starts_with($arg, '-')) {
                $paths[] = $arg;
                continue;
            }
            [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
            $valid = str_starts_with($arg, '--') && match (true) {
                in_array($name, self::FLAGS, true) => $value === null,
                in_array($name, self::VALUES, true) => $value !== null && $value !== '',
                default => in_array($name, self::OPTIONAL_VALUES, true),
            };
            if (!$valid) {
                fwrite($this->out, "Invalid option $arg\n\n" . self::USAGE);

                return 2;
            }
            $options[$name] = $value ?? true;
        }

        $config = Config::fromFile($this->configFile($options['config'] ?? null));
        $manifest = new Manifest($config->manifestPath());
        $locks = new LockFile($config->lockPath());

        if (isset($options['status'])) {
            $this->report($manifest->all());

            return 0;
        }
        if (isset($options['lock']) || isset($options['unlock'])) {
            return $this->changeLocks($options, $manifest, $locks);
        }

        $verify = $config->verifyEnabled && !isset($options['no-verify']);
        $builder = new Builder(
            $this->generator ?? new ModelRouter($config->providers),
            new CCompiler($config->compiler, $config->cflags),
            $verify ? new Verifier($config->verifyIterations, $config->verifyTimeout) : null,
            $manifest,
            $locks,
            $config->cacheDir,
            $config->models(),
            $options['model'] ?? null,
        );

        $auto = $config->auto || isset($options['auto']);
        $extractor = new MethodExtractor();
        $entries = [];
        $scanned = [];
        foreach ($this->phpFiles($paths ?: $config->paths) as $file) {
            $scanned[realpath($file) ?: $file] = true;
            // Cheap pre-filter so the parser only runs on files that can contain a candidate.
            if (!$auto && !str_contains((string) file_get_contents($file), 'AccelerateToC')) {
                continue;
            }
            try {
                $specs = $extractor->extractFromFile($file, $auto);
            } catch (\Throwable $e) {
                fwrite($this->out, "SKIPPED     $file\n            " . $e->getMessage() . "\n");
                continue;
            }
            foreach ($specs as $spec) {
                if (isset($options['dry-run'])) {
                    fwrite($this->out, sprintf("%s\n    %s\n", $spec->key(), $builder->plan($spec)));
                    $entries[$spec->key()] = null;
                    continue;
                }
                $entries[$spec->key()] = $entry = $builder->build($spec, isset($options['force']));
                $this->report([$spec->key() => $entry]);
            }
        }

        if (isset($options['dry-run'])) {
            fwrite($this->out, $entries ? '' : "No methods to accelerate found.\n");

            return 0;
        }

        // Forget methods that lost their attribute, were renamed, or whose file is gone.
        foreach ($manifest->all() as $key => $entry) {
            if (!isset($entries[$key]) && (isset($scanned[$entry['sourceFile']]) || !is_file($entry['sourceFile']))) {
                $manifest->remove($key);
            }
        }
        $manifest->save();
        $published = (new Publisher($config->cacheDir))->publish($manifest, !$verify);

        $this->summary($entries, $published, $config);
        $bad = array_filter($entries, static fn (array $e): bool => !$e['auto']
            && !in_array($e['status'], $verify ? [Manifest::VERIFIED] : [Manifest::VERIFIED, Manifest::UNVERIFIED], true));

        // Outside --strict a failed module is not a failed deploy: that method keeps running as PHP.
        return isset($options['strict']) && $bad ? 1 : 0;
    }

    /** @param array<string, string|true> $options */
    private function changeLocks(array $options, Manifest $manifest, LockFile $locks): int
    {
        $unlock = isset($options['unlock']);
        $target = $options[$unlock ? 'unlock' : 'lock'];
        $keys = $target === true
            ? array_keys($unlock ? $locks->all() : $manifest->all())
            : [ltrim((string) $target, '\\')];

        $changed = 0;
        foreach ($keys as $key) {
            if ($unlock) {
                $done = $locks->unlock($key);
                $why = 'is not locked';
            } else {
                $entry = $manifest->get($key);
                $done = $entry !== null && $entry['status'] === Manifest::VERIFIED;
                $why = $entry === null ? 'has not been built' : "is {$entry['status']}, only verified code can be locked";
                if ($done && $locks->get($key) === null) {
                    $locks->lock($key, $entry);
                    $manifest->put($key, ['locked' => true] + $entry);
                }
            }
            if ($done) {
                $changed++;
                fwrite($this->out, ($unlock ? 'UNLOCKED    ' : 'LOCKED      ') . "$key\n");
            } elseif ($target !== true) {
                fwrite($this->out, "$key $why\n");

                return 1;
            }
        }
        $manifest->save();
        fwrite($this->out, $changed ? '' : ($unlock ? "Nothing is locked.\n" : "Nothing verified to lock. Run forja first.\n"));

        return 0;
    }

    private function configFile(?string $explicit): string
    {
        foreach ([$explicit, getcwd() . '/config/forja.php', dirname(__DIR__, 2) . '/config/forja.php'] as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                return $candidate;
            }
            if ($candidate === $explicit && $explicit !== null) {
                throw new \RuntimeException("config file not found: $explicit");
            }
        }

        throw new \RuntimeException('config file not found');
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
            } elseif (is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function report(array $entries): void
    {
        foreach ($entries as $key => $entry) {
            $tags = array_keys(array_filter([
                'cached' => $entry['cached'] ?? false,
                'locked' => $entry['locked'] ?? false,
                'auto' => $entry['auto'] ?? false,
            ]));
            fwrite($this->out, sprintf(
                "%-11s %s%s\n            %s%s\n",
                strtoupper($entry['status']),
                $key,
                $tags ? ' (' . implode(', ', $tags) . ')' : '',
                isset($entry['model']) ? "[{$entry['model']}] " : '',
                strtok((string) ($entry['message'] ?? ''), "\n") ?: '-'
            ));
        }
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function summary(array $entries, int $published, Config $config): void
    {
        if (!$entries) {
            fwrite($this->out, "No methods to accelerate found.\n");
        }
        fwrite($this->out, sprintf("\n%d method(s) now run as C; everything else runs as PHP.\n", $published));
        if ($published > 0 && strtolower((string) ini_get('ffi.enable')) === 'preload') {
            fwrite($this->out, "Web requests need one of these in php.ini (ffi.enable is 'preload'):\n"
                . "  opcache.preload={$config->cacheDir}/preload.php\n  ffi.enable=1\n");
        } elseif ($published > 0 && !FFILoader::unrestricted()) {
            fwrite($this->out, "FFI is disabled (ffi.enable=" . ini_get('ffi.enable') . "), so nothing will use these modules yet.\n");
        }
    }
}
