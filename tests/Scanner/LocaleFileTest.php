<?php

namespace phrazor\Tests\Scanner;

use PHPUnit\Framework\TestCase;
use phrazor\Scanner\LocaleFile;
use phrazor\Scanner\Diff;

class LocaleFileTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phrazor_locale_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Loading
    // -------------------------------------------------------------------------

    public function test_loads_existing_file(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $this->assertSame(['Hello' => 'Bonjour'], $file->getTranslations());
    }

    public function test_empty_translations_for_missing_file(): void
    {
        $file = new LocaleFile($this->tempDir, 'de_DE');
        $file->load();

        $this->assertSame([], $file->getTranslations());
    }

    public function test_throws_on_syntax_error_when_mode_is_error(): void
    {
        file_put_contents($this->tempDir . '/fr_FR.php', '<?php return "not an array";');

        $file = new LocaleFile($this->tempDir, 'fr_FR');

        $this->expectException(\RuntimeException::class);
        $file->load('error');
    }

    public function test_warns_on_syntax_error_when_mode_is_warn(): void
    {
        file_put_contents($this->tempDir . '/fr_FR.php', '<?php return "not an array";');

        $file = new LocaleFile($this->tempDir, 'fr_FR');

        $triggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$triggered): bool {
            $triggered = true;
            $this->assertSame(E_USER_WARNING, $errno);
            $this->assertStringContainsString('fr_FR', $errstr);
            return true;
        });

        $file->load('warn');
        restore_error_handler();

        $this->assertTrue($triggered, 'Expected a user warning to be triggered');
        $this->assertSame([], $file->getTranslations());
    }

    public function test_silently_skips_syntax_error_when_mode_is_skip(): void
    {
        file_put_contents($this->tempDir . '/fr_FR.php', '<?php return "not an array";');

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load('skip');

        $this->assertSame([], $file->getTranslations());
    }

    public function test_load_is_idempotent(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();
        $file->load(); // second call should be a no-op

        $this->assertSame(['Hello' => 'Bonjour'], $file->getTranslations());
    }

    // -------------------------------------------------------------------------
    // Diff
    // -------------------------------------------------------------------------

    public function test_diff_identifies_new_patterns(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = $file->diff(['Hello', 'Goodbye']);

        $this->assertSame(['Goodbye'], $diff->new);
        $this->assertSame([], $diff->removed);
        $this->assertSame(['Hello'], $diff->kept);
    }

    public function test_diff_identifies_removed_patterns(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour', 'Goodbye' => 'Au revoir']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = $file->diff(['Hello']);

        $this->assertSame([], $diff->new);
        $this->assertSame(['Goodbye'], $diff->removed);
        $this->assertSame(['Hello'], $diff->kept);
    }

    public function test_diff_is_empty_when_no_changes(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = $file->diff(['Hello']);

        $this->assertTrue($diff->isEmpty());
    }

    // -------------------------------------------------------------------------
    // Apply — new key placement
    // -------------------------------------------------------------------------

    public function test_apply_adds_new_keys_at_bottom_by_default(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Goodbye'], removed: [], kept: ['Hello']);
        $file->apply($diff, newKeys: 'bottom');

        $keys = array_keys($file->getTranslations());
        $this->assertSame(['Hello', 'Goodbye'], $keys);
    }

    public function test_apply_adds_new_keys_at_top(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Goodbye'], removed: [], kept: ['Hello']);
        $file->apply($diff, newKeys: 'top');

        $keys = array_keys($file->getTranslations());
        $this->assertSame(['Goodbye', 'Hello'], $keys);
    }

    public function test_apply_sorts_entire_file_when_sort_true(): void
    {
        $this->writeLocale('fr_FR', ['Zebra' => 'Zèbre', 'Apple' => 'Pomme']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Mango'], removed: [], kept: ['Zebra', 'Apple']);
        $file->apply($diff, sort: true);

        $keys = array_keys($file->getTranslations());
        $this->assertSame(['Apple', 'Mango', 'Zebra'], $keys);
    }

    // -------------------------------------------------------------------------
    // Apply — removed key handling
    // -------------------------------------------------------------------------

    public function test_apply_deletes_removed_keys(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour', 'Goodbye' => 'Au revoir']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: [], removed: ['Goodbye'], kept: ['Hello']);
        $file->apply($diff, removed: 'delete');

        $this->assertArrayNotHasKey('Goodbye', $file->getTranslations());
    }

    public function test_apply_keeps_removed_keys_when_mode_is_keep(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour', 'Goodbye' => 'Au revoir']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: [], removed: ['Goodbye'], kept: ['Hello']);
        $file->apply($diff, removed: 'keep');

        $this->assertArrayHasKey('Goodbye', $file->getTranslations());
    }

    // -------------------------------------------------------------------------
    // Apply — identity
    // -------------------------------------------------------------------------

    public function test_apply_writes_identity_mapping_when_flag_set(): void
    {
        $file = new LocaleFile($this->tempDir, 'en_US');
        $file->load();

        $diff = new Diff(new: ['Hello'], removed: [], kept: []);
        $file->apply($diff, identity: true);

        $this->assertSame('Hello', $file->getTranslations()['Hello']);
    }

    public function test_apply_writes_empty_string_when_identity_false(): void
    {
        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Hello'], removed: [], kept: []);
        $file->apply($diff, identity: false);

        $this->assertSame('', $file->getTranslations()['Hello']);
    }

    // -------------------------------------------------------------------------
    // Written file contents
    // -------------------------------------------------------------------------

    public function test_written_file_is_valid_php(): void
    {
        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Hello', 'Goodbye'], removed: [], kept: []);
        $file->apply($diff, identity: true);

        $result = require $this->tempDir . '/fr_FR.php';
        $this->assertIsArray($result);
    }

    public function test_written_file_contains_todo_comment_for_empty_translation(): void
    {
        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Hello'], removed: [], kept: []);
        $file->apply($diff, identity: false);

        $contents = file_get_contents($this->tempDir . '/fr_FR.php');
        $this->assertStringContainsString('TODO', $contents);
    }

    public function test_written_file_comments_out_removed_keys(): void
    {
        $this->writeLocale('fr_FR', ['Hello' => 'Bonjour', 'Goodbye' => 'Au revoir']);

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: [], removed: ['Goodbye'], kept: ['Hello']);
        $file->apply($diff, removed: 'comment');

        $contents = file_get_contents($this->tempDir . '/fr_FR.php');
        $this->assertStringContainsString('[removed]', $contents);
        $this->assertStringContainsString('Goodbye', $contents);
    }

    public function test_creates_i18n_directory_if_missing(): void
    {
        $nestedPath = $this->tempDir . '/nested/i18n';

        $file = new LocaleFile($nestedPath, 'fr_FR');
        $file->load();

        $diff = new Diff(new: ['Hello'], removed: [], kept: []);
        $file->apply($diff, identity: true);

        $this->assertFileExists($nestedPath . '/fr_FR.php');
    }

    public function test_multiline_pattern_uses_nowdoc_in_output(): void
    {
        $pattern = "{count, plural,\n    =0 {No items}\n    other {# items}\n}";

        $file = new LocaleFile($this->tempDir, 'fr_FR');
        $file->load();

        $diff = new Diff(new: [$pattern], removed: [], kept: []);
        $file->apply($diff, identity: true);

        $contents = file_get_contents($this->tempDir . '/fr_FR.php');
        $this->assertStringContainsString("<<<'EOT'", $contents);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeLocale(string $locale, array $translations): void
    {
        $lines = ["<?php\n\nreturn [\n"];
        foreach ($translations as $key => $value) {
            $lines[] = "    " . var_export($key, true) . " => " . var_export($value, true) . ",\n";
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