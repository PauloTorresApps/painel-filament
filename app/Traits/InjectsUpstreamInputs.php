<?php

namespace App\Traits;

use App\Models\DocumentMicroAnalysis;
use Illuminate\Support\Facades\Log;

/**
 * Trait para injeção de dados de análise na chave META.upstream_inputs
 * de prompts JSON estruturados (Opção B do pipeline Map-Reduce).
 *
 * Quando o prompt cadastrado no banco é um JSON com estrutura META,
 * este trait injeta os dados das micro-análises diretamente no JSON,
 * tornando o prompt autocontido antes do envio à OpenRouter.
 *
 * Se o prompt for texto puro (não JSON), retorna-o sem modificações,
 * garantindo compatibilidade total com o fluxo original.
 */
trait InjectsUpstreamInputs
{
    /**
     * Verifica se o conteúdo do prompt é um JSON com estrutura META.
     */
    protected function isJsonPromptWithMeta(string $promptContent): bool
    {
        $trimmed = ltrim($promptContent);
        if (!str_starts_with($trimmed, '{')) {
            return false;
        }

        try {
            $data = json_decode($promptContent, true, 512, JSON_THROW_ON_ERROR);
            return isset($data['META']);
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Injeta os dados de upstream_inputs na chave META de um prompt JSON.
     *
     * Se o prompt não for JSON válido ou não tiver a estrutura META esperada,
     * retorna o prompt original sem modificações (compatibilidade com texto puro).
     *
     * @param string $promptContent Conteúdo do prompt (JSON ou texto)
     * @param array  $upstreamInputs Array de inputs estruturados a injetar
     * @param array  $aggregatedEntities Entidades agregadas do processo
     * @return string O prompt modificado (JSON serializado) ou original (texto)
     */
    protected function injectUpstreamInputs(
        string $promptContent,
        array $upstreamInputs,
        array $aggregatedEntities = []
    ): string {
        try {
            $promptData = json_decode($promptContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $promptContent;
        }

        if (!isset($promptData['META'])) {
            return $promptContent;
        }

        // Injeta os dados no JSON
        $promptData['META']['upstream_inputs'] = $upstreamInputs;
        $promptData['META']['aggregated_entities'] = $aggregatedEntities;
        $promptData['META']['total_documents_analyzed'] = count($upstreamInputs);
        $promptData['META']['injection_timestamp'] = now()->toISOString();

        Log::info('InjectsUpstreamInputs: Dados injetados no prompt JSON', [
            'total_inputs' => count($upstreamInputs),
            'has_entities' => !empty($aggregatedEntities),
            'total_chars' => array_sum(array_map(
                fn($input) => mb_strlen($input['analysis_content'] ?? ''),
                $upstreamInputs
            )),
        ]);

        return json_encode($promptData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Monta array de upstream_inputs a partir de uma coleção de micro-análises.
     *
     * Cada entrada contém: índice, descrição, conteúdo da análise,
     * pontos-chave, partes mencionadas, valores monetários e timeline.
     *
     * @param iterable $microAnalyses Coleção de DocumentMicroAnalysis
     * @return array Array de inputs estruturados
     */
    protected function buildUpstreamInputsFromMicroAnalyses($microAnalyses): array
    {
        $inputs = [];

        foreach ($microAnalyses as $micro) {
            $entry = [
                'type' => 'document_analysis',
                'document_index' => $micro->document_index,
                'description' => $micro->descricao,
                'analysis_content' => $micro->micro_analysis,
            ];

            // Dados estruturados extraídos na fase MAP
            $entities = $micro->getEntities();

            if (!empty($entities['pontos_chave'])) {
                $entry['key_points'] = $entities['pontos_chave'];
            }
            if (!empty($entities['partes_mencionadas'])) {
                $entry['parties_mentioned'] = $entities['partes_mencionadas'];
            }
            if (!empty($entities['valores_monetarios'])) {
                $entry['monetary_values'] = $entities['valores_monetarios'];
            }

            // Timeline de eventos se disponível
            if ($micro->timeline_events) {
                $entry['timeline'] = $micro->timeline_events;
            }

            $inputs[] = $entry;
        }

        return $inputs;
    }

    /**
     * Monta upstream_inputs para o cenário de refinamento sequencial (último documento).
     *
     * Neste caso, não temos todas as micro-análises separadamente;
     * temos o resumo evolutivo acumulado + a análise do último documento.
     *
     * @param string $evolutiveSummary Resumo evolutivo acumulado
     * @param \App\Models\DocumentMicroAnalysis $lastMicroAnalysis Último documento
     * @return array Array de inputs estruturados
     */
    protected function buildUpstreamInputsForSequentialFinal(
        string $evolutiveSummary,
        $lastMicroAnalysis
    ): array {
        $inputs = [
            [
                'type' => 'evolutionary_summary',
                'description' => 'Resumo evolutivo acumulado de todos os documentos anteriores',
                'analysis_content' => $evolutiveSummary,
            ],
            [
                'type' => 'document_analysis',
                'document_index' => $lastMicroAnalysis->document_index,
                'description' => $lastMicroAnalysis->descricao,
                'analysis_content' => $lastMicroAnalysis->micro_analysis,
            ],
        ];

        // Dados estruturados do último documento
        $entities = $lastMicroAnalysis->getEntities();
        if (!empty($entities['pontos_chave'])) {
            $inputs[1]['key_points'] = $entities['pontos_chave'];
        }
        if (!empty($entities['partes_mencionadas'])) {
            $inputs[1]['parties_mentioned'] = $entities['partes_mencionadas'];
        }
        if (!empty($entities['valores_monetarios'])) {
            $inputs[1]['monetary_values'] = $entities['valores_monetarios'];
        }

        if ($lastMicroAnalysis->timeline_events) {
            $inputs[1]['timeline'] = $lastMicroAnalysis->timeline_events;
        }

        return $inputs;
    }
}
