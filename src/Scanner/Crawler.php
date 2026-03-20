<?php

namespace phrazor\Scanner;

class Crawler
{
    /** @var string[] */
    private array $extensions;

    /**
     * @param string[] $extensions File extensions to include, e.g. ['php']
     */
    public function __construct(array $extensions = ['php'])
    {
        $this->extensions = array_map(
            static fn(string $ext) => ltrim($ext, '.'),
            $extensions,
        );
    }

    /**
     * Crawl one or more source directories and return all matching file paths.
     *
     * @param  string[]  $directories
     * @param  ?string   $excludeDir  Absolute path to exclude (e.g. the i18n directory)
     * @return string[]
     */
    public function crawl(array $directories, ?string $excludeDir = null): array
    {
        $files      = [];
        $excludeDir = $excludeDir ? realpath($excludeDir) : null;

        foreach ($directories as $dir) {
            $dir = rtrim($dir, '/');

            if (!is_dir($dir)) {
                throw new \RuntimeException("phrazor: Source directory not found: {$dir}");
            }

            foreach ($this->iterate($dir, $excludeDir) as $file) {
                $files[] = $file;
            }
        }

        return array_unique($files);
    }

    /**
     * Recursively iterate a directory, yielding matching file paths.
     *
     * @return \Generator<string>
     */
    private function iterate(string $dir, ?string $excludeDir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $realPath = $file->getRealPath();

            if ($excludeDir && str_starts_with($realPath, $excludeDir . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (in_array($file->getExtension(), $this->extensions, true)) {
                yield $realPath;
            }
        }
    }
}
