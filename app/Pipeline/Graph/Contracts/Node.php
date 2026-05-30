<?php

namespace App\Pipeline\Graph\Contracts;

use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;

interface Node
{
    public function id(): string;

    public function run(GraphState $state): NodeResult;
}
