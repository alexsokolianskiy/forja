<?php

declare(strict_types=1);

namespace AiAccel;

use AiAccel\Fallback\FallbackHandler;
use AiAccel\FFI\FFILoader;
use AiAccel\FFI\NativeFunction;
use Psr\Log\LoggerInterface;

/**
 * What runs inside the application.
 *
 * `ai-c-compile` writes a copy of every source file that has verified
 * modules, with one guard line at the top of each accelerated method. The
 * autoloader registered here serves that copy instead of the original, so
 * callers keep writing `$service->method(...)` and get the C version. When
 * the guard cannot use the module, execution simply continues into the
 * original method body.
 */
final class Runtime
{
    /** @var array{classes: array<string, array<string, mixed>>, modules: array<string, array<string, mixed>>}|null */
    private static ?array $map = null;
    private static ?string $cacheDir = null;
    private static ?bool $enabled = null;
    private static bool $registered = false;
    private static ?FallbackHandler $fallback = null;
    private static ?FFILoader $loader = null;

    /** @var array<string, NativeFunction|false> */
    private static array $natives = [];

    /** @var array<string, true> */
    private static array $staleReported = [];

    public static function defaultCacheDir(): string
    {
        $env = getenv('AI_ACCEL_CACHE_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        $root = class_exists(\Composer\InstalledVersions::class)
            ? (\Composer\InstalledVersions::getRootPackage()['install_path'] ?? null)
            : null;

        return rtrim((string) (($root ? realpath($root) : false) ?: getcwd()), '/') . '/storage/c_cache';
    }

    /**
     * Optional: call once at boot to use a non-default cache directory or to receive log messages.
     */
    public static function configure(?string $cacheDir = null, ?LoggerInterface $logger = null): void
    {
        if ($cacheDir !== null && rtrim($cacheDir, '/') !== self::$cacheDir) {
            self::$cacheDir = rtrim($cacheDir, '/');
            self::$map = null;
            self::$natives = [];
        }
        if ($logger !== null) {
            self::fallback()->setLogger($logger);
        }
        self::register();
    }

    /** Installs the autoloader that swaps in the guarded copies. Called from the Composer `files` autoload. */
    public static function register(): void
    {
        if (!self::$registered && self::map()['classes']) {
            spl_autoload_register(self::autoload(...), true, true);
            self::$registered = true;
        }
    }

    /** Kill switch: with false every method runs as plain PHP. AI_ACCEL_DISABLE=1 does the same. */
    public static function enable(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled ??= !in_array(strtolower((string) getenv('AI_ACCEL_DISABLE')), ['1', 'true', 'on', 'yes'], true);
    }

    public static function autoload(string $class): void
    {
        $entry = self::map()['classes'][$class] ?? null;
        if ($entry === null || !self::isEnabled()) {
            return;
        }
        if (@filemtime($entry['source']) !== $entry['mtime'] || !is_file($entry['php'])) {
            if (!isset(self::$staleReported[$entry['source']])) {
                self::$staleReported[$entry['source']] = true;
                self::fallback()->disable($entry['source'], 'file changed since the last ai-c-compile run');
            }

            return; // Composer loads the original file
        }
        require_once $entry['php'];
    }

    /**
     * Called by the guard line. Returns null when the method should run as PHP.
     */
    public static function native(string $key): ?NativeFunction
    {
        $native = self::$natives[$key] ?? self::resolve($key);
        if ($native === false || !self::isEnabled() || self::fallback()->isDisabled($key)) {
            return null;
        }
        self::$fallback->attempted();

        return $native;
    }

    /** Called by the guard line when the native call threw; the PHP body runs next. */
    public static function failed(string $key, \Throwable $error): void
    {
        self::fallback()->failed($key, $error);
    }

    public static function isAccelerated(string $class, string $method): bool
    {
        $key = ltrim($class, '\\') . '::' . $method;

        return self::isEnabled() && (self::$natives[$key] ?? self::resolve($key)) !== false && !self::fallback()->isDisabled($key);
    }

    /** @return array{native: int, fallback: int} calls served by C, and calls that fell through to PHP */
    public static function stats(): array
    {
        return self::fallback()->stats();
    }

    private static function resolve(string $key): NativeFunction|false
    {
        $module = self::map()['modules'][$key] ?? null;
        if ($module === null || !self::isEnabled()) {
            return $module === null ? self::$natives[$key] = false : false;
        }
        if (!FFILoader::available()) {
            self::fallback()->disable($key, 'the FFI extension is not loaded');

            return self::$natives[$key] = false;
        }
        try {
            return self::$natives[$key] = (self::$loader ??= new FFILoader())->load($module);
        } catch (\Throwable $e) {
            self::fallback()->disable($key, $e->getMessage());

            return self::$natives[$key] = false;
        }
    }

    /** @return array{classes: array<string, array<string, mixed>>, modules: array<string, array<string, mixed>>} */
    private static function map(): array
    {
        if (self::$map === null) {
            $file = (self::$cacheDir ??= self::defaultCacheDir()) . '/runtime.php';
            $map = is_file($file) ? require $file : null;
            self::$map = is_array($map) ? $map : ['classes' => [], 'modules' => []];
        }

        return self::$map;
    }

    private static function fallback(): FallbackHandler
    {
        return self::$fallback ??= new FallbackHandler();
    }
}
