<?php

namespace App\Evaluation\Contracts;

use App\Evaluation\MetricResult;
use App\Models\DocumentAnalysis;

interface MetricInterface
{
    public function evaluate(DocumentAnalysis $analysis): MetricResult;
}
