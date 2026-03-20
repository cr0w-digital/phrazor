<?php

namespace phrazor\Scanner;

// Define T_HEREDOC for compatibility if not defined (PHP 8+ does not define it)
if (!defined('T_HEREDOC')) {
    define('T_HEREDOC', -1); // Use a value that won't conflict with real tokens
}

class Extractor
{
    /** @var string[] */
    private array $functions;

    /**
     * @param string[] $functions Function names to scan for, e.g. ['t', '_', 'trans']
     */
    public function __construct(array $functions = ['t'])
    {
        // Always include the canonical namespaced call (with and without leading backslash)
        $this->functions = array_unique([
            ...$functions,
            'phrazor\\t',
            '\\phrazor\\t',
        ]);
    }

    /**
     * Extract all translation patterns from a PHP source file.
     *
     * @return ExtractedPattern[]
     */
    public function extractFromFile(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new \RuntimeException("phrazor: Could not read file: {$path}");
        }

        return $this->extractFromSource($source, $path);
    }

    /**
     * Extract all translation patterns from a PHP source string.
     *
     * @return ExtractedPattern[]
     */
    public function extractFromSource(string $source, string $file = '<string>'): array
    {
        $tokens  = token_get_all($source);
        $count   = count($tokens);
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            // We're looking for a T_STRING or T_NS_SEPARATOR that starts a function call
            $functionName = $this->matchFunctionName($tokens, $i, $count);

            if ($functionName === null) {
                continue;
            }

            // Advance past the function name tokens
            $i = $this->skipFunctionNameTokens($tokens, $i, $count);

            // Next non-whitespace must be '('
            $j = $this->nextMeaningful($tokens, $i + 1, $count);

            if ($j === null || $tokens[$j] !== '(') {
                continue;
            }

            // Now extract the first argument
            $result = $this->extractFirstArgument($tokens, $j + 1, $count, $file);

            if ($result === null) {
                // Dynamic or complex expression — record as skipped
                $line = $this->lineAt($tokens, $j);
                $results[] = ExtractedPattern::skipped($file, $line, $functionName);
                continue;
            }

            $results[] = ExtractedPattern::found($result['pattern'], $file, $result['line'], $functionName);

            // Advance past what we consumed
            $i = $result['end'];
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Try to match a configured function name starting at token $i.
     * Returns the matched function name or null.
     */
    private function matchFunctionName(array $tokens, int $i, int $count): ?string
    {
        $token = $tokens[$i];

        // Always skip if preceded by -> or :: (method/static call).
        // Both T_OBJECT_OPERATOR (->) and T_DOUBLE_COLON (::) are array tokens,
        // so we check token type constants rather than string values.
        $prev = $this->prevMeaningful($tokens, $i);
        if ($prev !== null) {
            $prevToken = $tokens[$prev];
            if (is_array($prevToken) && in_array($prevToken[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                return null;
            }
        }

        // Simple function call: t(
        if (is_array($token) && $token[0] === T_STRING) {
            $name = $token[1];
            if (in_array($name, $this->functions, true)) {
                return $name;
            }
        }

        // Namespaced call (multi-token, PHP 7 style): phrazor\t( or \phrazor\t(
        if (is_array($token) && $token[0] === T_NS_SEPARATOR) {
            $name = $this->tryMatchNamespacedFunction($tokens, $i, $count);
            if ($name !== null) {
                $normalized = ltrim($name, '\\');
                if (in_array($normalized, $this->functions, true)) {
                    return $name;
                }
            }
        }

        // Namespaced call (single-token, PHP 8 style): T_NAME_QUALIFIED or T_NAME_FULLY_QUALIFIED
        // e.g. token value is "phrazor\t" or "\phrazor\t"
        if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name       = $token[1];
            $normalized = ltrim($name, '\\');
            if (in_array($normalized, $this->functions, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Try to read a namespaced function name starting at $i.
     * e.g. phrazor\t or \phrazor\t
     */
    private function tryMatchNamespacedFunction(array $tokens, int $i, int $count): ?string
    {
        $name = '';

        for ($j = $i; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $token[1];
            } elseif ($token === '(') {
                break;
            } elseif (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            } else {
                return null;
            }
        }

        return $name !== '' ? $name : null;
    }

    /**
     * Skip past the tokens that form the function name, return the index of the
     * last name-part token (i.e. the final T_STRING/T_NS_SEPARATOR, NOT the '(').
     *
     * FIX: previously returned $j - 1, which happened to equal the index of '('
     * itself for multi-token namespaced names like `\phrazor\t`. That made the
     * caller's `nextMeaningful($tokens, $i + 1, ...)` start one position past
     * '(', missing it entirely. Now we track $last — the last real name token —
     * and return that, so `$i + 1` always starts scanning from immediately after
     * the name regardless of how many tokens it spans.
     */
    private function skipFunctionNameTokens(array $tokens, int $i, int $count): int
    {
        $last = $i;
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $last = $j; // track last actual name token
                continue;
            }
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue; // skip whitespace but don't update $last
            }
            break;
        }
        return $last;
    }

    /**
     * Extract the first argument of a function call starting just after '('.
     * Returns ['pattern' => string, 'line' => int, 'end' => int] or null if not a static string.
     *
     * @return array{pattern: string, line: int, end: int}|null
     */
    private function extractFirstArgument(array $tokens, int $start, int $count, string $file): ?array
    {
        $j = $this->nextMeaningful($tokens, $start, $count);

        if ($j === null) {
            return null;
        }

        $token = $tokens[$j];
        $line  = $this->lineAt($tokens, $j);

        // Single or double quoted string
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            // Check it's not followed by a concatenation operator
            $next = $this->nextMeaningful($tokens, $j + 1, $count);
            if ($next !== null && $tokens[$next] === '.') {
                return null; // concatenation — skip
            }
            return [
                'pattern' => $this->unquoteString($token[1]),
                'line'    => $line,
                'end'     => $j,
            ];
        }

        // Heredoc / nowdoc
        if (is_array($token) && in_array($token[0], [T_START_HEREDOC, T_HEREDOC, T_END_HEREDOC], true)) {
            $result = $this->extractHeredoc($tokens, $j, $count);
            if ($result !== null) {
                return [...$result, 'line' => $line];
            }
            return null;
        }

        // Anything else (variable, expression, etc.) — skip
        return null;
    }

    /**
     * Extract a heredoc/nowdoc string starting at the T_START_HEREDOC token.
     *
     * @return array{pattern: string, end: int}|null
     */
    private function extractHeredoc(array $tokens, int $start, int $count): ?array
    {
        $isNowdoc = str_contains($tokens[$start][1], "'");
        $body     = '';

        for ($j = $start + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && $token[0] === T_END_HEREDOC) {
                // Nowdoc is always static; heredoc is static only if no variables
                return ['pattern' => $body, 'end' => $j];
            }

            if (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $body .= $token[1];
                continue;
            }

            if (is_array($token) && $token[0] === T_VARIABLE) {
                if (!$isNowdoc) {
                    return null; // interpolated variable — skip
                }
            }

            if (is_array($token)) {
                $body .= $token[1];
            }
        }

        return null;
    }

    /**
     * Find the next meaningful (non-whitespace, non-comment) token index.
     */
    private function nextMeaningful(array $tokens, int $start, int $count): ?int
    {
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Find the previous meaningful token index.
     *
     * FIX: removed is_array() guard so that single-character tokens like
     * '->' and '::' (stored as plain strings) are correctly returned,
     * allowing method/static call exclusion to work.
     */
    private function prevMeaningful(array $tokens, int $start): ?int
    {
        for ($i = $start - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Get the line number of a token.
     */
    private function lineAt(array $tokens, int $i): int
    {
        $token = $tokens[$i];
        return is_array($token) ? $token[2] : 0;
    }

    /**
     * Strip surrounding quotes from a T_CONSTANT_ENCAPSED_STRING value.
     */
    private function unquoteString(string $value): string
    {
        $quote = $value[0];

        if ($quote === "'" || $quote === '"') {
            $inner = substr($value, 1, -1);

            if ($quote === "'") {
                // Unescape \\ and \'
                return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
            }

            // For double-quoted strings, use a sandboxed eval to let PHP handle
            // all escape sequences (\n, \t, \u{}, \x, etc.) correctly
            return $this->unescapeDoubleQuoted($inner);
        }

        return $value;
    }

    /**
     * Unescape a double-quoted string body without eval.
     */
    private function unescapeDoubleQuoted(string $s): string
    {
        return preg_replace_callback(
            '/\\\\(n|r|t|v|e|f|\\\\|\$|"|\d{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
            static function (array $m): string {
                return match (true) {
                    $m[1] === 'n'                    => "\n",
                    $m[1] === 'r'                    => "\r",
                    $m[1] === 't'                    => "\t",
                    $m[1] === 'v'                    => "\v",
                    $m[1] === 'e'                    => "\e",
                    $m[1] === 'f'                    => "\f",
                    $m[1] === '\\'                   => '\\',
                    $m[1] === '$'                    => '$',
                    $m[1] === '"'                    => '"',
                    ctype_digit($m[1])               => chr(octdec($m[1])),
                    str_starts_with($m[1], 'x')      => chr(hexdec(substr($m[1], 1))),
                    str_starts_with($m[1], 'u')      => mb_chr(hexdec(substr($m[1], 2, -1)), 'UTF-8'),
                    default                          => $m[0],
                };
            },
            $s
        );
    }
}