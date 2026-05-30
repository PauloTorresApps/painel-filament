<?php

namespace App\Pipeline\Graph;

final class GraphState
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @param mixed $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $values = $this->values;
        $values[$key] = $value;

        return new self($values);
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function merge(array $updates): self
    {
        return new self(array_merge($this->values, $updates));
    }
}
