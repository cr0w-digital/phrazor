<?php

namespace phrazor\Tests\Scanner;

use PHPUnit\Framework\TestCase;
use phrazor\Scanner\Extractor;
use phrazor\Scanner\ExtractedPattern;

class ExtractorTest extends TestCase
{
    private Extractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new Extractor(['t']);
    }

    // -------------------------------------------------------------------------
    // Basic extraction
    // -------------------------------------------------------------------------

    public function test_extracts_single_quoted_string(): void
    {
        $results = $this->extract("<?php t('Hello');");

        $this->assertCount(1, $results);
        $this->assertSame('Hello', $results[0]->pattern);
        $this->assertFalse($results[0]->skipped);
    }

    public function test_extracts_double_quoted_string(): void
    {
        $results = $this->extract('<?php t("Hello");');

        $this->assertCount(1, $results);
        $this->assertSame('Hello', $results[0]->pattern);
    }

    public function test_extracts_pattern_with_icu_placeholders(): void
    {
        $results = $this->extract("<?php t('Welcome, {name}', ['name' => \$name]);");

        $this->assertCount(1, $results);
        $this->assertSame('Welcome, {name}', $results[0]->pattern);
    }

    public function test_extracts_multiple_calls(): void
    {
        $source = <<<'PHP'
        <?php
        t('Hello');
        t('Goodbye');
        t('Welcome, {name}', ['name' => $n]);
        PHP;

        $results = $this->extractFound($source);

        $this->assertCount(3, $results);
        $this->assertSame('Hello',           $results[0]->pattern);
        $this->assertSame('Goodbye',         $results[1]->pattern);
        $this->assertSame('Welcome, {name}', $results[2]->pattern);
    }

    // -------------------------------------------------------------------------
    // Multiline patterns
    // -------------------------------------------------------------------------

    public function test_extracts_multiline_single_quoted_string(): void
    {
        $source = <<<'PHP'
        <?php
        t('{count, plural,
            =0 {No items}
            one {# item}
            other {# items}
        }');
        PHP;

        $results = $this->extractFound($source);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('plural', $results[0]->pattern);
        $this->assertStringContainsString('No items', $results[0]->pattern);
    }

    public function test_extracts_nowdoc(): void
    {
        $source = <<<'PHP'
        <?php
        t(<<<'EOT'
        {count, plural,
            =0 {No items}
            one {# item}
            other {# items}
        }
        EOT);
        PHP;

        $results = $this->extractFound($source);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('plural', $results[0]->pattern);
    }

    public function test_extracts_static_heredoc(): void
    {
        $source = <<<'PHP'
        <?php
        t(<<<EOT
        Hello world
        EOT);
        PHP;

        $results = $this->extractFound($source);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Hello world', $results[0]->pattern);
    }

    // -------------------------------------------------------------------------
    // Skip cases
    // -------------------------------------------------------------------------

    public function test_skips_variable_pattern(): void
    {
        $results = $this->extract('<?php t($pattern);');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->skipped);
    }

    public function test_skips_concatenated_pattern(): void
    {
        $results = $this->extract("<?php t('Hello ' . \$name);");

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->skipped);
    }

    public function test_skips_interpolated_heredoc(): void
    {
        $source = <<<'PHP'
        <?php
        t(<<<EOT
        Hello $name
        EOT);
        PHP;

        $results = $this->extract($source);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->skipped);
    }

    public function test_skips_function_call_as_pattern(): void
    {
        $results = $this->extract('<?php t(getPattern());');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->skipped);
    }

    // -------------------------------------------------------------------------
    // Method/static call exclusion
    // -------------------------------------------------------------------------

    public function test_does_not_extract_method_call(): void
    {
        $results = $this->extract('<?php $obj->t("Hello");');

        $this->assertCount(0, $results);
    }

    public function test_does_not_extract_static_call(): void
    {
        $results = $this->extract('<?php Foo::t("Hello");');

        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // Namespaced call
    // -------------------------------------------------------------------------

    public function test_extracts_namespaced_call(): void
    {
        $results = $this->extract("<?php \\phrazor\\t('Hello');");

        $this->assertCount(1, $results);
        $this->assertSame('Hello', $results[0]->pattern);
        $this->assertFalse($results[0]->skipped);
    }

    public function test_namespaced_call_always_extracted_regardless_of_functions(): void
    {
        $extractor = new Extractor(['_']); // only _ configured
        $results   = $extractor->extractFromSource("<?php \\phrazor\\t('Hello');");

        $this->assertCount(1, $results);
        $this->assertSame('Hello', $results[0]->pattern);
    }

    // -------------------------------------------------------------------------
    // Custom function names
    // -------------------------------------------------------------------------

    public function test_extracts_custom_function_name(): void
    {
        $extractor = new Extractor(['_', 'trans']);
        $results   = $extractor->extractFromSource("<?php _('Hello'); trans('Goodbye');");

        $found = array_filter($results, fn($r) => !$r->skipped);
        $found = array_values($found);

        $this->assertCount(2, $found);
        $this->assertSame('Hello',   $found[0]->pattern);
        $this->assertSame('Goodbye', $found[1]->pattern);
    }

    public function test_does_not_extract_unconfigured_function(): void
    {
        $extractor = new Extractor(['_']);
        $results   = $extractor->extractFromSource("<?php t('Hello');");

        // t() not in configured functions, phrazor\t() is always included
        // but plain t() should not match
        $found = array_filter($results, fn($r) => !$r->skipped && $r->pattern === 'Hello');

        $this->assertCount(0, $found);
    }

    // -------------------------------------------------------------------------
    // Escape sequences
    // -------------------------------------------------------------------------

    public function test_unescapes_single_quoted_string(): void
    {
        $results = $this->extract("<?php t('it\\'s here');");

        $this->assertCount(1, $results);
        $this->assertSame("it's here", $results[0]->pattern);
    }

    public function test_unescapes_double_quoted_newline(): void
    {
        $results = $this->extract('<?php t("Hello\nWorld");');

        $this->assertCount(1, $results);
        $this->assertSame("Hello\nWorld", $results[0]->pattern);
    }

    public function test_unescapes_double_quoted_tab(): void
    {
        $results = $this->extract('<?php t("Hello\tWorld");');

        $this->assertCount(1, $results);
        $this->assertSame("Hello\tWorld", $results[0]->pattern);
    }

    // -------------------------------------------------------------------------
    // Line numbers
    // -------------------------------------------------------------------------

    public function test_records_correct_line_number(): void
    {
        $source = <<<'PHP'
        <?php
        $x = 1;
        $y = 2;
        t('Hello');
        PHP;

        $results = $this->extractFound($source);

        $this->assertCount(1, $results);
        $this->assertSame(4, $results[0]->line);
    }

    public function test_records_function_name(): void
    {
        $results = $this->extract("<?php t('Hello');");

        $this->assertCount(1, $results);
        $this->assertSame('t', $results[0]->function);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return ExtractedPattern[] */
    private function extract(string $source): array
    {
        return $this->extractor->extractFromSource($source);
    }

    /** @return ExtractedPattern[] */
    private function extractFound(string $source): array
    {
        return array_values(
            array_filter($this->extract($source), fn($r) => !$r->skipped)
        );
    }
}
