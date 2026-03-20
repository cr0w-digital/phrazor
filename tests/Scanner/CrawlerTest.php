<?php

namespace phrazor\Tests\Scanner;

use PHPUnit\Framework\TestCase;
use phrazor\Scanner\Crawler;

class CrawlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phrazor_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_finds_php_files(): void
    {
        $this->touch('a.php');
        $this->touch('b.php');

        $files = (new Crawler(['php']))->crawl([$this->tempDir]);

        $this->assertCount(2, $files);
    }

    public function test_ignores_non_matching_extensions(): void
    {
        $this->touch('a.php');
        $this->touch('b.twig');
        $this->touch('c.html');

        $files = (new Crawler(['php']))->crawl([$this->tempDir]);

        $this->assertCount(1, $files);
    }

    public function test_crawls_multiple_extensions(): void
    {
        $this->touch('a.php');
        $this->touch('b.twig');
        $this->touch('c.html');

        $files = (new Crawler(['php', 'twig']))->crawl([$this->tempDir]);

        $this->assertCount(2, $files);
    }

    public function test_recurses_subdirectories(): void
    {
        mkdir($this->tempDir . '/sub/deep', 0755, true);
        $this->touch('a.php');
        $this->touch('sub/b.php');
        $this->touch('sub/deep/c.php');

        $files = (new Crawler(['php']))->crawl([$this->tempDir]);

        $this->assertCount(3, $files);
    }

    public function test_crawls_multiple_source_directories(): void
    {
        $dir2 = $this->tempDir . '_2';
        mkdir($dir2, 0755, true);

        $this->touch('a.php');
        file_put_contents($dir2 . '/b.php', '<?php');

        $files = (new Crawler(['php']))->crawl([$this->tempDir, $dir2]);

        $this->assertCount(2, $files);

        $this->removeDir($dir2);
    }

    public function test_excludes_specified_directory(): void
    {
        mkdir($this->tempDir . '/i18n', 0755, true);
        $this->touch('a.php');
        $this->touch('i18n/fr_FR.php');

        $files = (new Crawler(['php']))->crawl(
            [$this->tempDir],
            excludeDir: $this->tempDir . '/i18n',
        );

        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('i18n', $files[0]);
    }

    public function test_deduplicates_files_across_directories(): void
    {
        $this->touch('a.php');

        // Pass the same directory twice
        $files = (new Crawler(['php']))->crawl([$this->tempDir, $this->tempDir]);

        $this->assertCount(1, $files);
    }

    public function test_throws_on_missing_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Source directory not found/');

        (new Crawler(['php']))->crawl(['/does/not/exist']);
    }

    public function test_extensions_can_include_leading_dot(): void
    {
        $this->touch('a.php');

        // .php and php should both work
        $files = (new Crawler(['.php']))->crawl([$this->tempDir]);

        $this->assertCount(1, $files);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function touch(string $relativePath): void
    {
        $path = $this->tempDir . '/' . $relativePath;
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, '<?php');
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
