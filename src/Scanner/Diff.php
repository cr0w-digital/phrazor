<?php

namespace phrazor\Scanner;

class Diff
{
    /**
     * @param string[] $new     Patterns found in source but not in locale file
     * @param string[] $removed Patterns in locale file but not found in source
     * @param string[] $kept    Patterns present in both
     */
    public function __construct(
        public readonly array $new,
        public readonly array $removed,
        public readonly array $kept,
    ) {}

    public function isEmpty(): bool
    {
        return $this->new === [] && $this->removed === [];
    }

    public function newCount(): int
    {
        return count($this->new);
    }

    public function removedCount(): int
    {
        return count($this->removed);
    }

    public function keptCount(): int
    {
        return count($this->kept);
    }
}
