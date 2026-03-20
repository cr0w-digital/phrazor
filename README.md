# // phrazor

Minimal gettext-style i18n for PHP with ICU message formatting.

- Source patterns are the keys — no separate message IDs
- ICU `MessageFormatter` for pluralization, gender, and variable substitution
- Scanner CLI to extract patterns and keep locale files in sync
- Zero config for simple projects, fully configurable for complex ones

**Requirements:** PHP 8.2+, `ext-intl`

---

## Installation

```bash
composer require cr0w/phrazor
```

---

## Basic usage

```php
// Simplest case — returns the pattern if no translation found
echo t('Welcome');

// Variable substitution (requires ext-intl)
echo t('Welcome, {name}', ['name' => 'Alice']);

// Pluralization
echo t('{count, plural,
    =0 {No items}
    one {# item}
    other {# items}
}', ['count' => 3]);

// Gender
echo t('{gender, select,
    male {He liked this}
    female {She liked this}
    other {They liked this}
}', ['gender' => 'female']);

// Canonical namespaced call
echo \phrazor\t('Welcome, {name}', ['name' => 'Alice']);
```

The global `t()` alias is registered automatically at autoload time. If `t()` is already defined in your project, phrazor will issue an `E_USER_NOTICE` and you can use `\phrazor\t()` instead.

---

## Locale files

Locale files live in your i18n directory and return a plain PHP array. The source pattern is the key, the translation is the value.

```php
// i18n/fr_FR.php
<?php

return [

    // Simple string
    'Welcome' => 'Bienvenue',

    // Variable substitution
    'Welcome, {name}' => 'Bienvenue, {name}',

    // Pluralization
    '{count, plural,
        =0 {No items}
        one {# item}
        other {# items}
    }' =>
    '{count, plural,
        =0 {Aucun élément}
        one {# élément}
        other {# éléments}
    }',

];
```

If a pattern has no translation, the pattern itself is returned — so `en_US` is a valid no-op locale.

---

## Configuration

### Runtime (constants)

Set these in your bootstrap before autoload:

```php
define('PHRAZOR_I18N_PATH', __DIR__ . '/path/to/i18n');
define('PHRAZOR_LOCALE', 'fr_FR');
define('PHRAZOR_MISSING_LOCALE', 'warn'); // 'error' | 'warn' | 'silent'
```

**Locale resolution order:**

1. `$locale` argument passed to `t()`
2. `PHRAZOR_LOCALE` constant
3. `$_SERVER['APP_LOCALE']`
4. `'en_US'`

**Missing locale file behaviour:**

If the resolved locale file doesn't exist, phrazor will:

- Throw a `RuntimeException` if `PHRAZOR_MISSING_LOCALE` is `'error'`
- Issue an `E_USER_WARNING` if `'warn'`
- Silently fall back if `'silent'`

If `PHRAZOR_MISSING_LOCALE` is not set, the default is `'warn'` when `APP_DEBUG` is truthy, `'silent'` otherwise.

### Scanner (config file)

Create `phrazor.php` in your project root:

```php
<?php

return [
    'i18n_path' => __DIR__ . '/i18n',
    'source'    => [__DIR__ . '/src'],
    'locales'   => ['fr_FR', 'de_DE'],
    'functions' => ['t', '_', 'trans'],  // function names to scan for

    'scan' => [
        'removed'         => 'comment', // 'comment' | 'delete' | 'keep'
        'new_keys'        => 'bottom',  // 'bottom' | 'top' | 'sort'
        'sort'            => false,     // sort entire file after update
        'identity'        => false,     // write key => key for new entries
        'extensions'      => ['php'],   // file extensions to crawl
        'output'          => 'summary', // 'summary' | 'verbose' | 'silent'
        'on_syntax_error' => 'error',   // 'error' | 'warn' | 'skip'
        'config_discovery'=> 'cwd',     // 'cwd' | 'walk'
    ],
];
```

All options have sensible defaults — an empty config file or no config file at all will work for simple projects.

---

## Scanner CLI

```bash
# Scan using phrazor.php in cwd
vendor/bin/phrazor scan

# Explicit config file
vendor/bin/phrazor scan --config=config/phrazor.php

# Override locales and output verbosity
vendor/bin/phrazor scan --locale=fr_FR,de_DE --output=verbose

# Multiple source directories
vendor/bin/phrazor scan --source=src/,templates/,resources/views/

# Custom function names (your own aliases for t())
vendor/bin/phrazor scan --functions=t,_,trans

# Write identity mappings for new keys (key => key)
vendor/bin/phrazor scan --identity

# Sort the entire locale file after update
vendor/bin/phrazor scan --sort
```

### All flags

| Flag | Description | Default |
|------|-------------|---------|
| `--config=PATH` | Path to config file | `phrazor.php` in cwd |
| `--source=DIR,...` | Source directories to scan | `src/` |
| `--locale=LOCALE,...` | Locales to update | discovered from i18n dir |
| `--functions=NAMES,...` | Function names to scan for | `t` |
| `--i18n-path=PATH` | Path to i18n directory | `i18n/` |
| `--removed=MODE` | Handle removed keys: `comment`, `delete`, `keep` | `comment` |
| `--new-keys=MODE` | Place new keys: `bottom`, `top`, `sort` | `bottom` |
| `--sort` | Sort entire locale file after update | off |
| `--identity` | Write `key => key` for new entries | off |
| `--output=MODE` | Verbosity: `summary`, `verbose`, `silent` | `summary` |
| `--on-syntax-error=MODE` | Handle syntax errors: `error`, `warn`, `skip` | `error` |

### How the scanner works

- Uses `token_get_all()` to parse PHP source — handles multiline patterns, single/double quoted strings, heredocs, and nowdocs correctly
- Skips dynamic patterns like `t($var)` or `t('a' . $b)` with a warning showing the file and line
- `phrazor\t()` is always scanned regardless of `--functions`
- The i18n directory is automatically excluded from source scanning
- New keys with no translation are written as `// TODO:` comments so translators know what needs attention
- Removed keys are commented out by default (`// [removed]`) so translations aren't lost if a key comes back

---

## How new keys appear in locale files

With `--identity` off (default), new keys are written as TODO comments:

```php
// TODO: translate
'Hello, {name}' => '',
```

With `--identity` on, identity mappings are written directly:

```php
'Hello, {name}' => 'Hello, {name}',
```

Removed keys (with `removed = 'comment'`) are preserved but flagged:

```php
// [removed] 'This string is no longer in source' => 'Cette chaîne...',
```

---

## License

MIT