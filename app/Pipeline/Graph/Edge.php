<?php

namespace App\Pipeline\Graph;

use Closure;

final class Edge
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?Closure $condition = null,
        public readonly ?string $label = null,
    ) {
    }

    public static function make(string $from, string $to, ?Closure $condition = null, ?string $label = null): self
    {
        return new self($from, $to, $condition, $label);
    }

    public function matches(GraphState $state): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return (bool) ($this->condition)($state);
    }
}
