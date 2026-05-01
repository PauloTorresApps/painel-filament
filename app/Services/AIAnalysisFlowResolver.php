<?php

namespace App\Services;

class AIAnalysisFlowResolver
{
    public const FLOW_CONTRACT = 'contract';
    public const FLOW_SIMPLE = 'simple';

    /**
     * @param array<string,mixed> $contextoDados
     */
    public function resolveFlow(array $contextoDados): string
    {
        return $this->isContractAnalysis($contextoDados)
            ? self::FLOW_CONTRACT
            : self::FLOW_SIMPLE;
    }

    /**
     * @param array<string,mixed> $contextoDados
     */
    public function isContractAnalysis(array $contextoDados): bool
    {
        if (!isset($contextoDados['tipo']) || !is_string($contextoDados['tipo'])) {
            return false;
        }

        return in_array($contextoDados['tipo'], ['Contrato', 'Parecer Jurídico'], true);
    }
}
