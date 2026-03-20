<?php

namespace phrazor\Scanner;

class ScanResult
{
    /**
     * @param string[]           $patterns      Unique patterns extracted from source
     * @param ExtractedPattern[] $skipped       Dynamic/unanalyzable calls that were skipped
     * @param LocaleResult[]     $localeResults One per locale processed
     */
    public function __construct(
        public readonly int   $files,
        public readonly array $patterns,
        public readonly array $skipped,
        public readonly array $localeResults,
    ) {}

    public function patternCount(): int
    {
        return count($this->patterns);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function hasChanges(): bool
    {
        foreach ($this->localeResults as $result) {
            if (!$result->diff->isEmpty()) {
                return true;
            }
        }
        return false;
    }
}
