<?php

namespace App\Pipeline\Graph\Definitions;

use App\Pipeline\Graph\Conditions\UseRefineStrategyCondition;
use App\Pipeline\Graph\Graph;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\Nodes\BatchReduceNode;
use App\Pipeline\Graph\Nodes\ChronologyNode;
use App\Pipeline\Graph\Nodes\DesignerBriefNode;
use App\Pipeline\Graph\Nodes\EngineNode;
use App\Pipeline\Graph\Nodes\InventoryNode;
use App\Pipeline\Graph\Nodes\MapDispatchNode;
use App\Pipeline\Graph\Nodes\RefineReduceNode;
use App\Pipeline\Graph\Nodes\StructuredOpinionNode;

final class ProcessAnalysisGraph
{
    public static function make(): Graph
    {
        $useRefine = new UseRefineStrategyCondition();

        return (new Graph())
            ->node(new InventoryNode())
            ->node(new MapDispatchNode())
            ->node(new RefineReduceNode())
            ->node(new BatchReduceNode())
            ->node(new ChronologyNode())
            ->node(new EngineNode())
            ->node(new StructuredOpinionNode())
            ->node(new DesignerBriefNode())
            ->edge('inventory', 'map_dispatch')
            ->conditionalEdge('map_dispatch', [
                'batch_reduce' => fn (GraphState $state) => (string) $state->get('reduce_strategy', 'auto') === 'batch',
                'refine_reduce' => fn (GraphState $state) => $useRefine($state),
            ])
            ->edge('refine_reduce', 'chronology')
            ->edge('batch_reduce', 'chronology')
            ->edge('chronology', 'engine')
            ->edge('engine', 'structured_opinion')
            ->edge('structured_opinion', 'designer_brief')
            ->compile();
    }
}
