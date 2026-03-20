<?php

namespace phrazor\Scanner;

class LocaleFile
{
    private string $path;
    private string $locale;

    /** @var array<string, string> */
    private array $translations = [];

    private bool $loaded = false;

    public function __construct(string $i18nPath, string $locale)
    {
        $this->locale = $locale;
        $this->path   = rtrim($i18nPath, '/') . "/{$locale}.php";
    }

    /**
     * Load the locale file if it exists.
     *
     * @param 'error'|'warn'|'skip' $onSyntaxError
     */
    public function load(string $onSyntaxError = 'error'): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!is_file($this->path)) {
            return;
        }

        try {
            $map = require $this->path;
        } catch (\Throwable $e) {
            $this->handleBadFile($onSyntaxError,
                "phrazor: Syntax error in locale file {$this->path}: {$e->getMessage()} — skipping",
                $e,
            );
            return;
        }

        if (!is_array($map)) {
            $this->handleBadFile($onSyntaxError,
                "phrazor: Locale file did not return an array: {$this->path} — skipping",
            );
            return;
        }

        $this->translations = $map;
    }

    /**
     * Diff extracted patterns against current translations.
     *
     * @param  string[] $patterns
     * @return Diff
     */
    public function diff(array $patterns): Diff
    {
        $existing = array_keys($this->translations);
        $new      = array_diff($patterns, $existing);
        $removed  = array_diff($existing, $patterns);
        $kept     = array_intersect($existing, $patterns);

        return new Diff(
            new: array_values($new),
            removed: array_values($removed),
            kept: array_values($kept),
        );
    }

    /**
     * Apply a diff to the current translations and write the file.
     *
     * @param 'bottom'|'top'|'sort' $newKeys
     * @param 'comment'|'delete'|'keep' $removed
     */
    public function apply(
        Diff   $diff,
        string $newKeys  = 'bottom',
        string $removed  = 'comment',
        bool   $sort     = false,
        bool   $identity = false,
    ): void {
        $translations = $this->translations;

        // Handle removed keys
        foreach ($diff->removed as $pattern) {
            match ($removed) {
                'delete'  => array_splice($translations, array_search($pattern, array_keys($translations), true), 1),
                'comment' => null, // handled in writer
                default   => null,
            };

            if ($removed === 'delete') {
                unset($translations[$pattern]);
            }
        }

        // Build new entries
        $newEntries = [];
        foreach ($diff->new as $pattern) {
            $newEntries[$pattern] = $identity ? $pattern : '';
        }

        // Position new keys
        $translations = match ($newKeys) {
            'top'  => [...$newEntries, ...$translations],
            default => [...$translations, ...$newEntries],
        };

        // Sort entire file if requested
        if ($sort || $newKeys === 'sort') {
            ksort($translations);
        }

        $this->translations = $translations;

        $this->write($diff->removed, $removed);
    }

    /**
     * @return array<string, string>
     */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Handle a bad locale file according to the configured error mode.
     *
     * Using trigger_error() directly inside a match() arm swallows the error in
     * some PHP/PHPUnit combinations because match() catches the control-flow
     * side-effect before PHPUnit's registered error handler can convert it into
     * a PHPUnit\Framework\Error\Warning exception.  Calling trigger_error() from
     * a plain method body (outside an expression context) ensures the PHP error
     * handler fires in the normal call-stack position where PHPUnit expects it.
     */
    private function handleBadFile(
        string      $mode,
        string      $message,
        ?\Throwable $previous = null,
    ): void {
        if ($mode === 'error') {
            throw new \RuntimeException($message, previous: $previous);
        }

        if ($mode === 'warn') {
            trigger_error($message, E_USER_WARNING);
        }

        // 'skip' — do nothing
    }

    /**
     * Write the translations array back to the locale file.
     *
     * @param string[] $removedPatterns
     * @param 'comment'|'delete'|'keep' $removedMode
     */
    private function write(array $removedPatterns, string $removedMode): void
    {
        $dir = dirname($this->path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("phrazor: Could not create i18n directory: {$dir}");
        }

        $lines   = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'return [';
        $lines[] = '';

        foreach ($this->translations as $pattern => $translation) {
            $isRemoved = in_array($pattern, $removedPatterns, true);

            if ($isRemoved && $removedMode === 'delete') {
                continue;
            }

            $keyLine = '    ' . $this->exportString($pattern) . ' => ' . $this->exportString($translation) . ',';
            $isEmpty = $translation === '';

            if ($isRemoved && $removedMode === 'comment') {
                foreach (explode("\n", $keyLine) as $line) {
                    $lines[] = '    // [removed] ' . ltrim($line);
                }
                $lines[] = '';
                continue;
            }

            if ($isEmpty) {
                // FIX: write the key as a real array entry so require picks it
                // up on the next scan — otherwise the TODO comment is invisible
                // to the loader and the key gets re-detected as new every run.
                // The TODO comment above is just a hint for the translator.
                $lines[] = '    // TODO: translate';
                $lines[] = $keyLine;
                $lines[] = '';
                continue;
            }

            // Multiline patterns get a bit of breathing room
            $isMultiline = str_contains($pattern, "\n") || str_contains($translation, "\n");

            if ($isMultiline) {
                $lines[] = $keyLine;
                $lines[] = '';
            } else {
                $lines[] = $keyLine;
            }
        }

        $lines[] = '];';
        $lines[] = '';

        $result = file_put_contents($this->path, implode("\n", $lines));

        if ($result === false) {
            throw new \RuntimeException("phrazor: Could not write locale file: {$this->path}");
        }
    }

    /**
     * Export a string value as PHP source, using nowdoc for multiline strings.
     */
    private function exportString(string $value): string
    {
        if (!str_contains($value, "\n")) {
            return "'" . addcslashes($value, "'\\") . "'";
        }

        // Use nowdoc for multiline — clean and unambiguous
        $delimiter = 'EOT';

        // Make sure delimiter doesn't appear in the value
        while (str_contains($value, $delimiter)) {
            $delimiter .= '_';
        }

        return "<<<'{$delimiter}'\n{$value}\n{$delimiter}";
    }
}