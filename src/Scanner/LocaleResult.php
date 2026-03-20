<?php

namespace phrazor\Scanner;

class LocaleResult
{
    public function __construct(
        public readonly string $locale,
        public readonly string $path,
        public readonly Diff   $diff,
        public readonly bool   $existed,
    ) {}
}
