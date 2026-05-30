<?php

namespace App\Pipeline\Graph;

use App\Pipeline\Graph\Contracts\Node;
use Closure;
use InvalidArgumentException;

final class Graph
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<int, Edge> */
    private array $edges = [];

    private ?string $entryNodeId = null;

    public function node(Node $node): self
    {
        $id = $node->id();

        if (isset($this->nodes[$id])) {
            throw new InvalidArgumentException("Node [{$id}] already registered in graph.");
        }

        $this->nodes[$id] = $node;

        if ($this->entryNodeId === null) {
            $this->entryNodeId = $id;
        }

        return $this;
    }

    public function entry(string $nodeId): self
    {
        if (!isset($this->nodes[$nodeId])) {
            throw new InvalidArgumentException("Cannot set entry to unknown node [{$nodeId}].");
        }

        $this->entryNodeId = $nodeId;

        return $this;
    }

    public function edge(string $from, string $to, ?Closure $condition = null, ?string $label = null): self
    {
        $this->edges[] = Edge::make($from, $to, $condition, $label);

        return $this;
    }

    /**
     * @param array<string, Closure> $targetConditions
     */
    public function conditionalEdge(string $from, array $targetConditions): self
    {
        foreach ($targetConditions as $to => $condition) {
            $this->edge($from, $to, $condition, "{$from}->{$to}");
        }

        return $this;
    }

    public function compile(): self
    {
        if (empty($this->nodes)) {
            throw new InvalidArgumentException('Graph must contain at least one node.');
        }

        if ($this->entryNodeId === null || !isset($this->nodes[$this->entryNodeId])) {
            throw new InvalidArgumentException('Graph entry node is not defined.');
        }

        foreach ($this->edges as $edge) {
            if (!isset($this->nodes[$edge->from])) {
                throw new InvalidArgumentException("Edge references unknown source node [{$edge->from}].");
            }

            if (!isset($this->nodes[$edge->to])) {
                throw new InvalidArgumentException("Edge references unknown target node [{$edge->to}].");
            }
        }

        $this->assertAcyclic();
        $this->assertAllNodesReachable();

        return $this;
    }

    public function entryNodeId(): string
    {
        if ($this->entryNodeId === null) {
            throw new InvalidArgumentException('Graph entry node is not defined.');
        }

        return $this->entryNodeId;
    }

    public function nodeById(string $nodeId): Node
    {
        $node = $this->nodes[$nodeId] ?? null;

        if (!$node) {
            throw new InvalidArgumentException("Node [{$nodeId}] not found in graph.");
        }

        return $node;
    }

    public function resolveNextNodeId(string $nodeId, GraphState $state): ?string
    {
        foreach ($this->edges as $edge) {
            if ($edge->from !== $nodeId) {
                continue;
            }

            if ($edge->matches($state)) {
                return $edge->to;
            }
        }

        return null;
    }

    /**
     * @return array<string, Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return array<int, Edge>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    private function assertAcyclic(): void
    {
        $visited = [];
        $stack = [];

        foreach (array_keys($this->nodes) as $nodeId) {
            $this->visitForCycle($nodeId, $visited, $stack);
        }
    }

    /**
     * @param array<string, bool> $visited
     * @param array<string, bool> $stack
     */
    private function visitForCycle(string $nodeId, array &$visited, array &$stack): void
    {
        if (($stack[$nodeId] ?? false) === true) {
            throw new InvalidArgumentException("Graph contains a cycle at node [{$nodeId}].");
        }

        if (($visited[$nodeId] ?? false) === true) {
            return;
        }

        $visited[$nodeId] = true;
        $stack[$nodeId] = true;

        foreach ($this->edges as $edge) {
            if ($edge->from === $nodeId) {
                $this->visitForCycle($edge->to, $visited, $stack);
            }
        }

        $stack[$nodeId] = false;
    }

    private function assertAllNodesReachable(): void
    {
        $reachable = [];
        $this->walkReachable($this->entryNodeId(), $reachable);

        $unreachable = array_diff(array_keys($this->nodes), array_keys($reachable));

        if (!empty($unreachable)) {
            $list = implode(', ', $unreachable);
            throw new InvalidArgumentException("Graph has unreachable node(s): {$list}.");
        }
    }

    /**
     * @param array<string, bool> $reachable
     */
    private function walkReachable(string $nodeId, array &$reachable): void
    {
        if (($reachable[$nodeId] ?? false) === true) {
            return;
        }

        $reachable[$nodeId] = true;

        foreach ($this->edges as $edge) {
            if ($edge->from === $nodeId) {
                $this->walkReachable($edge->to, $reachable);
            }
        }
    }
}
