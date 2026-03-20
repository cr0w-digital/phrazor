<?php

namespace phrazor;

/**
 * Internal cache — holds translations and formatters across calls.
 * Not part of the public API; use phrazor\reset() to clear.
 *
 * @internal
 */
final class Cache
{
    /** @var array<string, array<string, string>> */
    public static array $translations = [];

    /** @var array<string, \MessageFormatter> */
    public static array $formatters = [];

    public static function clear(): void
    {
        self::$translations = [];
        self::$formatters   = [];
    }
}

function t(string $pattern, array $vars = [], ?string $locale = null): string
{
    $explicitLocale = $locale !== null;

    $locale ??= defined('PHRAZOR_LOCALE')
        ? PHRAZOR_LOCALE
        : ($_SERVER['APP_LOCALE'] ?? null);

    $explicitLocale = $explicitLocale || $locale !== null;

    $locale ??= 'en_US';

    if (!isset(Cache::$translations[$locale])) {
        $i18nPath = defined('PHRAZOR_I18N_PATH')
            ? PHRAZOR_I18N_PATH
            : __DIR__ . '/../i18n';

        $file = rtrim($i18nPath, '/') . "/{$locale}.php";

        if (!is_file($file)) {
            if ($explicitLocale) {
                $sensitivity = defined('PHRAZOR_MISSING_LOCALE')
                    ? PHRAZOR_MISSING_LOCALE
                    : (defined('APP_DEBUG') && APP_DEBUG ? 'warn' : 'silent');

                match ($sensitivity) {
                    'error'  => throw new \RuntimeException("phrazor: Locale file not found: {$file}"),
                    'warn'   => trigger_error("phrazor: Locale file not found: {$file}", E_USER_WARNING),
                    default  => null,
                };
            }

            Cache::$translations[$locale] = [];
        } else {
            $map = require $file;
            Cache::$translations[$locale] = is_array($map) ? $map : [];
        }
    }

    $translated = Cache::$translations[$locale][$pattern] ?? $pattern;

    if ($vars === []) {
        return $translated;
    }

    if (!class_exists(\MessageFormatter::class)) {
        throw new \RuntimeException(
            'phrazor: The intl extension (MessageFormatter) is required for variable substitution.'
        );
    }

    $cacheKey = $locale . "\0" . $translated;

    if (!isset(Cache::$formatters[$cacheKey])) {
        // MessageFormatter::__construct() may either return null/false or throw
        // depending on the PHP/ICU version. Normalise both failure modes into a
        // single \IntlException with a consistent message so callers (and tests)
        // have one contract to rely on.
        try {
            $formatter = new \MessageFormatter($locale, $translated);
        } catch (\Throwable) {
            $formatter = null;
        }

        if ($formatter === null) {
            throw new \IntlException(
                "Invalid ICU message pattern: {$translated}"
            );
        }

        Cache::$formatters[$cacheKey] = $formatter;
    }

    $out = Cache::$formatters[$cacheKey]->format($vars);

    if ($out === false) {
        throw new \RuntimeException(
            "phrazor: Formatting failed for pattern: {$translated}"
        );
    }

    return $out;
}

/**
 * Clear all cached translations and formatters.
 *
 * Useful in tests and long-running processes (Swoole, RoadRunner, etc.)
 * where locale files may change or memory should be reclaimed.
 */
function reset(): void
{
    Cache::clear();
}