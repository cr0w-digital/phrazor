<?php

namespace phrazor\Scanner;

class ExtractedPattern
{
    private function __construct(
        public readonly ?string $pattern,
        public readonly string  $file,
        public readonly int     $line,
        public readonly string  $function,
        public readonly bool    $skipped,
    ) {}

    public static function found(string $pattern, string $file, int $line, string $function): self
    {
        return new self($pattern, $file, $line, $function, false);
    }

    public static function skipped(string $file, int $line, string $function): self
    {
        return new self(null, $file, $line, $function, true);
    }
}
