<?php

namespace phrazor\Scanner;

class Config
{
    public readonly string  $i18nPath;
    /** @var string[] */
    public readonly array   $source;
    /** @var string[] */
    public readonly array   $locales;
    /** @var string[] */
    public readonly array   $functions;
    public readonly string  $missingLocale;

    // Scan options
    public readonly string  $removed;
    public readonly string  $newKeys;
    public readonly bool    $sort;
    public readonly bool    $identity;
    /** @var string[] */
    public readonly array   $extensions;
    public readonly string  $output;
    public readonly string  $onSyntaxError;
    public readonly string  $configDiscovery;

    private function __construct(array $data)
    {
        $scan = $data['scan'] ?? [];

        $this->i18nPath        = $data['i18n_path']       ?? getcwd() . '/i18n';
        $this->source          = $data['source']           ?? [getcwd() . '/src'];
        $this->locales         = $data['locales']          ?? [];
        $this->functions       = $data['functions']        ?? ['t'];
        $this->missingLocale   = $data['missing_locale']   ?? 'warn';

        $this->removed         = $scan['removed']          ?? 'comment';
        $this->newKeys         = $scan['new_keys']         ?? 'bottom';
        $this->sort            = $scan['sort']             ?? false;
        $this->identity        = $scan['identity']         ?? false;
        $this->extensions      = $scan['extensions']       ?? ['php'];
        $this->output          = $scan['output']           ?? 'summary';
        $this->onSyntaxError   = $scan['on_syntax_error']  ?? 'error';
        $this->configDiscovery = $scan['config_discovery'] ?? 'cwd';

        $this->validate();
    }

    /**
     * Load config from an explicit path.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("phrazor: Config file not found: {$path}");
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new \RuntimeException("phrazor: Config file did not return an array: {$path}");
        }

        return new self($data);
    }

    /**
     * Discover and load config file, starting from cwd.
     * Falls back to defaults if no config file is found.
     */
    public static function discover(?string $startDir = null): self
    {
        $startDir = $startDir ?? getcwd();
        $path     = self::findConfigFile($startDir, 'cwd');

        if ($path === null) {
            return self::defaults();
        }

        return self::fromFile($path);
    }

    /**
     * Return a Config with all defaults and no config file.
     */
    public static function defaults(): self
    {
        return new self([]);
    }

    /**
     * Merge CLI overrides on top of the loaded config.
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $scan = [];

        if (isset($overrides['removed']))         $scan['removed']         = $overrides['removed'];
        if (isset($overrides['new_keys']))         $scan['new_keys']        = $overrides['new_keys'];
        if (isset($overrides['sort']))             $scan['sort']            = $overrides['sort'];
        if (isset($overrides['identity']))         $scan['identity']        = $overrides['identity'];
        if (isset($overrides['output']))           $scan['output']          = $overrides['output'];
        if (isset($overrides['on_syntax_error']))  $scan['on_syntax_error'] = $overrides['on_syntax_error'];

        $data = [
            'i18n_path'     => $overrides['i18n_path']   ?? $this->i18nPath,
            'source'        => $overrides['source']       ?? $this->source,
            'locales'       => $overrides['locales']      ?? $this->locales,
            'functions'     => $overrides['functions']    ?? $this->functions,
            'missing_locale'=> $overrides['missing_locale'] ?? $this->missingLocale,
            'scan'          => array_merge([
                'removed'         => $this->removed,
                'new_keys'        => $this->newKeys,
                'sort'            => $this->sort,
                'identity'        => $this->identity,
                'extensions'      => $this->extensions,
                'output'          => $this->output,
                'on_syntax_error' => $this->onSyntaxError,
                'config_discovery'=> $this->configDiscovery,
            ], $scan),
        ];

        return new self($data);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function validate(): void
    {
        $this->assertOneOf('removed',         $this->removed,       ['comment', 'delete', 'keep']);
        $this->assertOneOf('new_keys',        $this->newKeys,       ['bottom', 'top', 'sort']);
        $this->assertOneOf('output',          $this->output,        ['summary', 'verbose', 'silent']);
        $this->assertOneOf('on_syntax_error', $this->onSyntaxError, ['error', 'warn', 'skip']);
        $this->assertOneOf('config_discovery',$this->configDiscovery,['cwd', 'walk']);
        $this->assertOneOf('missing_locale',  $this->missingLocale, ['error', 'warn', 'silent']);
    }

    private function assertOneOf(string $key, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \RuntimeException(
                "phrazor: Invalid config value for '{$key}': '{$value}'. Allowed: " . implode(', ', $allowed),
            );
        }
    }

    private static function findConfigFile(string $startDir, string $discovery): ?string
    {
        $candidates = ['phrazor.php', 'phrazor.config.php'];
        $dir        = realpath($startDir);

        while ($dir !== false && $dir !== '') {
            foreach ($candidates as $candidate) {
                $path = $dir . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($path)) {
                    return $path;
                }
            }

            if ($discovery === 'cwd') {
                break;
            }

            // Walk up one level
            $parent = dirname($dir);

            if ($parent === $dir) {
                break; // reached filesystem root
            }

            $dir = $parent;
        }

        return null;
    }
}
