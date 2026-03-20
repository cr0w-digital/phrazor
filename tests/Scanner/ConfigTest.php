<?php

namespace phrazor\Tests\Scanner;

use PHPUnit\Framework\TestCase;
use phrazor\Scanner\Config;

class ConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phrazor_config_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public function test_defaults_are_applied(): void
    {
        $config = Config::defaults();

        $this->assertSame(['t'],       $config->functions);
        $this->assertSame([],          $config->locales);
        $this->assertSame(['php'],     $config->extensions);
        $this->assertSame('comment',   $config->removed);
        $this->assertSame('bottom',    $config->newKeys);
        $this->assertFalse($config->sort);
        $this->assertFalse($config->identity);
        $this->assertSame('summary',   $config->output);
        $this->assertSame('error',     $config->onSyntaxError);
        $this->assertSame('cwd',       $config->configDiscovery);
        $this->assertSame('warn',      $config->missingLocale);
    }

    // -------------------------------------------------------------------------
    // Loading from file
    // -------------------------------------------------------------------------

    public function test_loads_from_file(): void
    {
        $path = $this->writeConfig([
            'locales'   => ['fr_FR', 'de_DE'],
            'functions' => ['t', '_'],
            'scan'      => ['removed' => 'delete', 'output' => 'verbose'],
        ]);

        $config = Config::fromFile($path);

        $this->assertSame(['fr_FR', 'de_DE'], $config->locales);
        $this->assertSame(['t', '_'],          $config->functions);
        $this->assertSame('delete',            $config->removed);
        $this->assertSame('verbose',           $config->output);
    }

    public function test_throws_on_missing_config_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Config file not found/');

        Config::fromFile('/does/not/exist/phrazor.php');
    }

    public function test_throws_if_config_does_not_return_array(): void
    {
        $path = $this->tempDir . '/phrazor.php';
        file_put_contents($path, '<?php return "not an array";');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not return an array/');

        Config::fromFile($path);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_throws_on_invalid_removed_value(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Invalid config value for 'removed'/");

        $path = $this->writeConfig(['scan' => ['removed' => 'invalid']]);
        Config::fromFile($path);
    }

    public function test_throws_on_invalid_output_value(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Invalid config value for 'output'/");

        $path = $this->writeConfig(['scan' => ['output' => 'invalid']]);
        Config::fromFile($path);
    }

    public function test_throws_on_invalid_new_keys_value(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Invalid config value for 'new_keys'/");

        $path = $this->writeConfig(['scan' => ['new_keys' => 'invalid']]);
        Config::fromFile($path);
    }

    // -------------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------------

    public function test_discover_finds_phrazor_php_in_cwd(): void
    {
        $path = $this->writeConfig(['locales' => ['fr_FR']], 'phrazor.php');

        $config = Config::discover($this->tempDir);

        $this->assertSame(['fr_FR'], $config->locales);
    }

    public function test_discover_finds_phrazor_config_php_in_cwd(): void
    {
        $path = $this->writeConfig(['locales' => ['de_DE']], 'phrazor.config.php');

        $config = Config::discover($this->tempDir);

        $this->assertSame(['de_DE'], $config->locales);
    }

    public function test_discover_returns_defaults_when_no_config_found(): void
    {
        $config = Config::discover($this->tempDir);

        $this->assertSame([], $config->locales);
    }

    public function test_discover_does_not_walk_up_when_mode_is_cwd(): void
    {
        // Write config in parent dir, not in tempDir
        $parentConfig = dirname($this->tempDir) . '/phrazor.php';

        // Should NOT be found when discovery is 'cwd'
        $config = Config::discover($this->tempDir);

        $this->assertSame([], $config->locales);
    }

    // -------------------------------------------------------------------------
    // Overrides
    // -------------------------------------------------------------------------

    public function test_with_overrides_merges_values(): void
    {
        $config = Config::defaults()->withOverrides([
            'locales' => ['fr_FR'],
            'removed' => 'delete',
        ]);

        $this->assertSame(['fr_FR'], $config->locales);
        $this->assertSame('delete',  $config->removed);
        $this->assertSame('bottom',  $config->newKeys); // unchanged default
    }

    public function test_with_overrides_does_not_mutate_original(): void
    {
        $original = Config::defaults();
        $override = $original->withOverrides(['locales' => ['fr_FR']]);

        $this->assertSame([],       $original->locales);
        $this->assertSame(['fr_FR'], $override->locales);
    }

    public function test_with_overrides_sort_flag(): void
    {
        $config = Config::defaults()->withOverrides(['sort' => true]);

        $this->assertTrue($config->sort);
    }

    public function test_with_overrides_identity_flag(): void
    {
        $config = Config::defaults()->withOverrides(['identity' => true]);

        $this->assertTrue($config->identity);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeConfig(array $data, string $filename = 'phrazor.php'): string
    {
        $path     = $this->tempDir . '/' . $filename;
        $exported = var_export($data, true);
        file_put_contents($path, "<?php\n\nreturn {$exported};\n");
        return $path;
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
