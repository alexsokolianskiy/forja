<?php

declare(strict_types=1);

namespace AiAccel\FFI;

use FFI;

/**
 * Opens compiled modules, once per library per process.
 *
 * With `ffi.enable=preload` (the PHP default) a web request may only use FFI
 * from preloaded code, so the module is fetched from the scope registered by
 * the generated preload.php. Otherwise it is opened directly with cdef().
 */
final class FFILoader
{
    /** @var array<string, FFI> */
    private array $loaded = [];

    public static function available(): bool
    {
        return extension_loaded('ffi') && class_exists(FFI::class);
    }

    public static function unrestricted(): bool
    {
        $mode = strtolower((string) ini_get('ffi.enable'));

        return in_array($mode, ['1', 'true', 'on', 'yes'], true)
            || ($mode === 'preload' && in_array(PHP_SAPI, ['cli', 'phpdbg'], true));
    }

    /** @param array<string, mixed> $module manifest or runtime-map entry */
    public function load(array $module): NativeFunction
    {
        $ffi = $this->loaded[$module['lib']] ??= $this->open($module);

        return new NativeFunction($ffi, $module['symbol'], $module['params'], $module['return']);
    }

    /** @param array<string, mixed> $module */
    private function open(array $module): FFI
    {
        if (self::unrestricted()) {
            return FFI::cdef($module['header'], $module['lib']);
        }
        try {
            return FFI::scope($module['scope']);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'FFI is not usable in this SAPI (ffi.enable=' . ini_get('ffi.enable') . '): set ffi.enable=1, '
                . 'or point opcache.preload at the generated preload.php and restart PHP',
                0,
                $e
            );
        }
    }
}
