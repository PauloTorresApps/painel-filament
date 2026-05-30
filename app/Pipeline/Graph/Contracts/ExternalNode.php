<?php

namespace App\Pipeline\Graph\Contracts;

interface ExternalNode extends Node
{
    public function endpoint(): string;
}
