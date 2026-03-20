<?php

namespace phrazor\Tests;

use PHPUnit\Framework\TestCase;

class TranslationFunctionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phrazor_fn_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        \phrazor\reset();
    }

    protected function tearDown(): void
    {
        \phrazor\reset();
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Core behaviour (locale-independent)
    // -------------------------------------------------------------------------

    public function test_returns_pattern_when_no_translation_found(): void
    {
        $result = \phrazor\t('Hello', [], 'zz_ZZ');
        $this->assertSame('Hello', $result);
    }

    public function test_returns_pattern_when_vars_empty(): void
    {
        $result = \phrazor\t('Hello World', [], 'zz_ZZ');
        $this->assertSame('Hello World', $result);
    }

    public function test_formats_simple_variable(): void
    {
        $result = \phrazor\t('Hello, {name}', ['name' => 'Alice'], 'en_US');
        $this->assertSame('Hello, Alice', $result);
    }

    public function test_formats_plural(): void
    {
        $pattern = '{count, plural, =0 {No items} one {# item} other {# items}}';

        $this->assertSame('No items', \phrazor\t($pattern, ['count' => 0],  'en_US'));
        $this->assertSame('1 item',   \phrazor\t($pattern, ['count' => 1],  'en_US'));
        $this->assertSame('5 items',  \phrazor\t($pattern, ['count' => 5],  'en_US'));
    }

    public function test_formats_select(): void
    {
        $pattern = '{gender, select, male {He} female {She} other {They}}';

        $this->assertSame('He',   \phrazor\t($pattern, ['gender' => 'male'],   'en_US'));
        $this->assertSame('She',  \phrazor\t($pattern, ['gender' => 'female'], 'en_US'));
        $this->assertSame('They', \phrazor\t($pattern, ['gender' => 'other'],  'en_US'));
    }

    public function test_throws_on_invalid_icu_pattern(): void
    {
        $this->expectException(\IntlException::class);
        $this->expectExceptionMessageMatches('/Invalid ICU message pattern/');

        \phrazor\t('{unclosed', ['x' => 1], 'en_US');
    }

    // -------------------------------------------------------------------------
    // Locale file loading
    // -------------------------------------------------------------------------

    public function test_uses_translation_from_locale_file(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        // Use Cache to inject the i18n path without relying on the constant
        \phrazor\Cache::$translations['fr_FR'] = require $this->tempDir . '/fr_FR.php';

        $result = \phrazor\t('Hello', [], 'fr_FR');
        $this->assertSame('Bonjour', $result);
    }

    public function test_falls_back_to_pattern_when_key_missing_in_locale(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        \phrazor\Cache::$translations['fr_FR'] = require $this->tempDir . '/fr_FR.php';

        $result = \phrazor\t('Goodbye', [], 'fr_FR');
        $this->assertSame('Goodbye', $result);
    }

    public function test_translates_icu_pattern_from_locale_file(): void
    {
        $pattern     = '{count, plural, =0 {No items} one {# item} other {# items}}';
        $translation = '{count, plural, =0 {Aucun élément} one {# élément} other {# éléments}}';

        \phrazor\Cache::$translations['fr_FR'] = [$pattern => $translation];

        $result = \phrazor\t($pattern, ['count' => 2], 'fr_FR');
        $this->assertSame('2 éléments', $result);
    }

    // -------------------------------------------------------------------------
    // Reset
    // -------------------------------------------------------------------------

    public function test_reset_clears_translation_cache(): void
    {
        \phrazor\Cache::$translations['fr_FR'] = ['Hello' => 'Bonjour'];

        \phrazor\reset();

        $this->assertSame([], \phrazor\Cache::$translations);
    }

    public function test_reset_clears_formatter_cache(): void
    {
        // Populate formatter cache by calling t() with vars
        \phrazor\t('Hello, {name}', ['name' => 'Alice'], 'en_US');

        $this->assertNotEmpty(\phrazor\Cache::$formatters);

        \phrazor\reset();

        $this->assertSame([], \phrazor\Cache::$formatters);
    }

    public function test_after_reset_locale_is_reloaded(): void
    {
        \phrazor\Cache::$translations['fr_FR'] = ['Hello' => 'Bonjour'];

        \phrazor\reset();

        // After reset, fr_FR is no longer cached — t() will try to load from disk
        // With no file on disk it falls back to the pattern
        $result = \phrazor\t('Hello', [], 'fr_FR');
        $this->assertSame('Hello', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeLocale(string $locale, array $translations): void
    {
        $lines = ["<?php\n\nreturn [\n"];
        foreach ($translations as $key => $value) {
            $lines[] = '    ' . var_export($key, true) . ' => ' . var_export($value, true) . ",\n";
        }
        $lines[] = "];\n";
        file_put_contents($this->tempDir . "/{$locale}.php", implode('', $lines));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }
}
