<?php

namespace phrazor\Scanner;

class Scanner
{
    private Config    $config;
    private Crawler   $crawler;
    private Extractor $extractor;

    public function __construct(Config $config)
    {
        $this->config    = $config;
        $this->crawler   = new Crawler($config->extensions);
        $this->extractor = new Extractor($config->functions);
    }

    /**
     * Run the scanner and return a ScanResult.
     */
    public function run(): ScanResult
    {
        // 1. Collect source files
        $files = $this->crawler->crawl(
            $this->config->source,
            excludeDir: $this->config->i18nPath,
        );

        // 2. Extract all patterns from source
        $extracted = [];
        $skipped   = [];

        foreach ($files as $file) {
            foreach ($this->extractor->extractFromFile($file) as $pattern) {
                if ($pattern->skipped) {
                    $skipped[] = $pattern;
                } else {
                    $extracted[] = $pattern;
                }
            }
        }

        // Deduplicate patterns — keep first occurrence for reporting
        $patterns    = [];
        $occurrences = []; // pattern => ExtractedPattern (first seen)

        foreach ($extracted as $ep) {
            if (!isset($occurrences[$ep->pattern])) {
                $occurrences[$ep->pattern] = $ep;
                $patterns[]                = $ep->pattern;
            }
        }

        // 3. Resolve locales
        $locales = $this->resolveLocales();

        // 4. Process each locale
        $localeResults = [];

        foreach ($locales as $locale) {
            $localeFile = new LocaleFile($this->config->i18nPath, $locale);
            $localeFile->load($this->config->onSyntaxError);

            $diff = $localeFile->diff($patterns);

            if (!$diff->isEmpty()) {
                $localeFile->apply(
                    diff:     $diff,
                    newKeys:  $this->config->newKeys,
                    removed:  $this->config->removed,
                    sort:     $this->config->sort,
                    identity: $this->config->identity,
                );
            }

            $localeResults[] = new LocaleResult(
                locale:  $locale,
                path:    $localeFile->getPath(),
                diff:    $diff,
                existed: $localeFile->exists(),
            );
        }

        return new ScanResult(
            files:         count($files),
            patterns:      $patterns,
            skipped:       $skipped,
            localeResults: $localeResults,
        );
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Resolve the list of locales to process.
     * If config has explicit locales, use those.
     * Otherwise discover from existing locale files in the i18n directory.
     */
    private function resolveLocales(): array
    {
        if ($this->config->locales !== []) {
            return $this->config->locales;
        }

        // Discover from existing files
        $i18nPath = $this->config->i18nPath;

        if (!is_dir($i18nPath)) {
            return [];
        }

        $locales = [];

        foreach (glob($i18nPath . '/*.php') as $file) {
            $locales[] = basename($file, '.php');
        }

        sort($locales);

        return $locales;
    }
}
