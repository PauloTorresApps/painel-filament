<?php

namespace App\Jobs;

use App\Models\AiPrompt;
use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use App\Mail\ProcessAnalysisCompleted;
use App\Services\AIServiceFactory;
use App\Services\NotificationService;
use App\Traits\InjectsUpstreamInputs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job que implementa a estratégia de "Refinamento Sequencial" (Refine Strategy).
 *
 * Em vez de consolidar todos os documentos de uma vez (batch reduce),
 * processa sequencialmente mantendo um "resumo evolutivo":
 *
 * 1. Analisa Documento 1 → Resumo A
 * 2. Passa Resumo A + Documento 2 → Resumo B (atualizado)
 * 3. Passa Resumo B + Documento 3 → Resumo C (atualizado)
 * ...
 * N. Resumo final contém a narrativa completa e conectada
 *
 * Vantagens:
 * - Mantém o "fio da meada" da narrativa processual
 * - Evita o problema de "esquecimento" da IA
 * - Produz uma análise mais coesa e cronológica
 */
class RefineReduceJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable, InjectsUpstreamInputs;

    public int $timeout;
    public int $tries;
    public int $backoff;
    public int $uniqueFor;

    public array $contextoDados = [];

    public function __construct(
        public int $documentAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $promptTemplate,
        public ?string $aiModelId = null,
        public int $startFromIndex = 0 // Permite retomada
    ) {
        $this->timeout = config('analysis.jobs.refine_reduce.timeout', 1800);
        $this->tries = config('analysis.jobs.refine_reduce.tries', 2);
        $this->backoff = config('analysis.jobs.refine_reduce.backoff', 60);
        $this->uniqueFor = 1800; // 30 min
    }

    /**
     * Chave única para evitar execução duplicada
     */
    public function uniqueId(): string
    {
        return "refine_reduce_{$this->documentAnalysisId}";
    }

    /**
     * Define os dados de contexto
     */
    public function setContextoDados(array $dados): self
    {
        $this->contextoDados = $dados;
        return $this;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            $documentAnalysis = DocumentAnalysis::find($this->documentAnalysisId);

            if (!$documentAnalysis) {
                Log::error('RefineReduceJob: DocumentAnalysis não encontrada', [
                    'id' => $this->documentAnalysisId
                ]);
                return;
            }

            if ($documentAnalysis->status === 'cancelled') {
                return;
            }

            // Busca todas as micro-análises MAP completadas, ordenadas cronologicamente
            $microAnalyses = $documentAnalysis->microAnalyses()
                ->mapLevel()
                ->completed()
                ->orderBy('document_index')
                ->get();

            if ($microAnalyses->isEmpty()) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Nenhuma micro-análise disponível para refinamento'
                ]);
                return;
            }

            $totalDocs = $microAnalyses->count();

            Log::info('RefineReduceJob: Iniciando refinamento sequencial', [
                'analysis_id' => $this->documentAnalysisId,
                'total_docs' => $totalDocs,
                'start_from' => $this->startFromIndex,
            ]);

            // Atualiza fase
            $documentAnalysis->update([
                'current_phase' => DocumentAnalysis::PHASE_REDUCE,
                'progress_message' => "Refinando análise: documento 1/{$totalDocs}...",
            ]);

            $this->notifyUser(
                $documentAnalysis,
                'info',
                'Fase 2/2: Refinamento',
                "Construindo narrativa processual a partir de {$totalDocs} documento(s)..."
            );

            $aiService = AIServiceFactory::make($this->aiProvider);
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Permite um limite estendido de tempo para chamadas pesadas de IA
            $aiService->setTimeout(1800);

            // Desativa limite de caracteres para evitar sumarização desnecessária
            $aiService->setInputCharLimit(null);

            // Busca o prompt do parecer final
            $finalOpinionPrompt = $this->getFinalOpinionPrompt();

            // Calcula tamanho total das micro-análises para decidir a estratégia
            $totalChars = $microAnalyses->sum(fn ($m) => mb_strlen($m->micro_analysis ?? ''));

            // Se todas as micro-análises cabem em uma janela de contexto razoável,
            // faz consolidação direta em 1 única chamada ao invés de N chamadas sequenciais
            $directConsolidationLimit = 200000; // ~50k tokens — cabe confortavelmente em modelos modernos

            if ($this->startFromIndex === 0 && $totalChars <= $directConsolidationLimit) {
                // CONSOLIDAÇÃO DIRETA: 1 única chamada à API
                $finalAnalysis = $this->directConsolidation(
                    $aiService,
                    $documentAnalysis,
                    $microAnalyses,
                    $finalOpinionPrompt,
                    $totalDocs
                );
            } else {
                // REFINAMENTO SEQUENCIAL: para retomadas ou quando o conteúdo é muito grande
                $finalAnalysis = $this->sequentialRefinement(
                    $aiService,
                    $documentAnalysis,
                    $microAnalyses,
                    $finalOpinionPrompt,
                    $totalDocs
                );
            }

            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Calcula tempo total
            $totalProcessingTime = $documentAnalysis->microAnalyses()
                ->whereNotNull('processing_time_ms')
                ->sum('processing_time_ms');
            $totalProcessingTime += $processingTimeMs;

            // Finaliza
            $documentAnalysis->update([
                'status' => 'completed',
                'current_phase' => DocumentAnalysis::PHASE_COMPLETED,
                'ai_analysis' => $finalAnalysis,
                'processing_time_ms' => $totalProcessingTime,
                'is_resumable' => false,
                'last_processed_at' => now(),
                'progress_message' => 'Análise concluída com sucesso!',
                'evolutionary_summary' => $finalAnalysis,
            ]);

            Log::info('RefineReduceJob: Análise concluída com sucesso', [
                'analysis_id' => $this->documentAnalysisId,
                'total_processing_time_ms' => $totalProcessingTime,
            ]);

            $this->notifyUser(
                $documentAnalysis,
                'success',
                'Análise Concluída',
                "Análise de {$totalDocs} documento(s) do processo {$documentAnalysis->numero_processo} concluída!"
            );

            // Envia e-mail com PDF se o usuário habilitou
            $user = User::find($documentAnalysis->user_id);
            if ($user && $user->wantsEmailFor('process_analysis')) {
                try {
                    Mail::to($user)->send(new ProcessAnalysisCompleted($documentAnalysis, $user));
                    Log::info('RefineReduceJob: E-mail de análise enviado', [
                        'user_id' => $user->id,
                        'analysis_id' => $documentAnalysis->id,
                    ]);
                } catch (\Exception $emailException) {
                    Log::warning('RefineReduceJob: Falha ao enviar e-mail', [
                        'user_id' => $user->id,
                        'error' => $emailException->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('RefineReduceJob: Erro no processamento', [
                'analysis_id' => $this->documentAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $documentAnalysis = DocumentAnalysis::find($this->documentAnalysisId);
            if ($documentAnalysis) {
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Erro no refinamento: ' . $e->getMessage(),
                    'is_resumable' => true, // Permite retomada
                ]);

                $this->notifyUser(
                    $documentAnalysis,
                    'danger',
                    'Análise Falhou',
                    'Erro: ' . $e->getMessage()
                );
            }

            throw $e;
        }
    }

    /**
     * Consolidação direta: envia todas as micro-análises em 1 única chamada à API.
     * Usado quando o conteúdo total cabe na janela de contexto do modelo.
     */
    private function directConsolidation(
        \App\Contracts\AIProviderInterface $aiService,
        DocumentAnalysis $documentAnalysis,
        $microAnalyses,
        string $finalOpinionPrompt,
        int $totalDocs
    ): string {
        Log::info('RefineReduceJob: Usando consolidação direta (1 chamada)', [
            'analysis_id' => $this->documentAnalysisId,
            'total_docs' => $totalDocs,
        ]);

        $documentAnalysis->update([
            'progress_message' => "Gerando parecer final ({$totalDocs} documento(s))...",
            'reduce_processed_batches' => $totalDocs,
            'reduce_total_batches' => $totalDocs,
        ]);

        $aggregatedEntities = $this->aggregateEntitiesFromMicros($microAnalyses);

        // Verifica se o prompt é JSON com estrutura META (suporta injeção de upstream_inputs)
        if ($this->isJsonPromptWithMeta($finalOpinionPrompt)) {
            // Opção B: Injeta análises diretamente no JSON do prompt
            $upstreamInputs = $this->buildUpstreamInputsFromMicroAnalyses($microAnalyses);
            $injectedPrompt = $this->injectUpstreamInputs(
                $finalOpinionPrompt,
                $upstreamInputs,
                $aggregatedEntities
            );

            Log::info('RefineReduceJob: Prompt JSON com upstream_inputs injetados', [
                'analysis_id' => $this->documentAnalysisId,
                'total_inputs' => count($upstreamInputs),
            ]);

            $result = $aiService->analyzeSingleDocument(
                $injectedPrompt,
                '', // Conteúdo já injetado em META.upstream_inputs
                $this->deepThinkingEnabled
            );
        } else {
            // Fluxo original para prompts em texto puro
            $nomeClasse = $this->contextoDados['classeProcessualNome']
                ?? $this->contextoDados['classeProcessual']
                ?? 'Não informada';

            $prompt = <<<PROMPT
# PARECER FINAL DO PROCESSO

**Classe Processual:** {$nomeClasse}
**Total de documentos analisados:** {$totalDocs}

Você receberá as análises individuais de TODOS os documentos do processo em ordem cronológica.

## TAREFA

Com base em todas as análises abaixo, gere o parecer final solicitado:

---

{$finalOpinionPrompt}

---

## INSTRUÇÕES

1. Considere TODOS os documentos analisados
2. Mantenha a perspectiva cronológica e causal dos eventos
3. Fundamente suas conclusões nos documentos analisados
4. Seja objetivo e direto nas conclusões
5. Use markdown para estruturar a resposta

Responda com o parecer final completo.
PROMPT;

            $content = '';
            foreach ($microAnalyses as $index => $microAnalysis) {
                $docNum = $index + 1;
                $content .= "# DOCUMENTO {$docNum}/{$totalDocs}: {$microAnalysis->descricao}\n\n";
                $content .= $microAnalysis->micro_analysis . "\n\n---\n\n";
            }

            $content .= $this->buildEntitiesBlock($aggregatedEntities);

            $result = $aiService->analyzeSingleDocument(
                $prompt,
                $content,
                $this->deepThinkingEnabled
            );
        }

        // Salva o resumo evolutivo (é a consolidação completa neste caso)
        $documentAnalysis->update([
            'evolutionary_summary' => $result,
            'last_processed_at' => now(),
        ]);

        return $result;
    }

    /**
     * Refinamento sequencial: processa documento a documento mantendo resumo evolutivo.
     * Usado quando o conteúdo total é grande demais para uma única chamada.
     */
    private function sequentialRefinement(
        \App\Contracts\AIProviderInterface $aiService,
        DocumentAnalysis $documentAnalysis,
        $microAnalyses,
        string $finalOpinionPrompt,
        int $totalDocs
    ): string {
        // Estado evolutivo - começa vazio ou com resumo anterior
        $evolutiveSummary = '';
        $processedCount = 0;

        if ($this->startFromIndex > 0) {
            $evolutiveSummary = $this->recoverPreviousSummary($documentAnalysis, $this->startFromIndex);
        }

        Log::info('RefineReduceJob: Usando refinamento sequencial', [
            'analysis_id' => $this->documentAnalysisId,
            'total_docs' => $totalDocs,
            'start_from' => $this->startFromIndex,
        ]);

        foreach ($microAnalyses as $index => $microAnalysis) {
            if ($index < $this->startFromIndex) {
                continue;
            }

            $docNum = $index + 1;
            $processedCount++;
            $isLast = ($docNum === $totalDocs);

            Log::info('RefineReduceJob: Processando documento', [
                'analysis_id' => $this->documentAnalysisId,
                'doc_num' => $docNum,
                'total' => $totalDocs,
                'descricao' => $microAnalysis->descricao,
                'is_last' => $isLast,
            ]);

            $progressMsg = $isLast
                ? "Gerando parecer final (documento {$docNum}/{$totalDocs})..."
                : "Refinando análise: documento {$docNum}/{$totalDocs}...";

            $documentAnalysis->update([
                'progress_message' => $progressMsg,
                'reduce_processed_batches' => $processedCount,
                'reduce_total_batches' => $totalDocs,
            ]);

            // Último documento + prompt JSON: injeta upstream_inputs no JSON
            if ($isLast && $this->isJsonPromptWithMeta($finalOpinionPrompt)) {
                $aggregatedEntities = $this->aggregateEntitiesFromMicros($microAnalyses);
                $upstreamInputs = $this->buildUpstreamInputsForSequentialFinal(
                    $evolutiveSummary,
                    $microAnalysis
                );
                $injectedPrompt = $this->injectUpstreamInputs(
                    $finalOpinionPrompt,
                    $upstreamInputs,
                    $aggregatedEntities
                );

                Log::info('RefineReduceJob: Prompt JSON final com upstream_inputs (sequencial)', [
                    'analysis_id' => $this->documentAnalysisId,
                    'doc_num' => $docNum,
                ]);

                $evolutiveSummary = $aiService->analyzeSingleDocument(
                    $injectedPrompt,
                    '', // Conteúdo já injetado em META.upstream_inputs
                    $this->deepThinkingEnabled
                );
            } else {
                // Fluxo original para prompts em texto ou documentos intermediários
                $prompt = $this->buildRefinePrompt(
                    $microAnalysis,
                    $docNum,
                    $totalDocs,
                    !empty($evolutiveSummary),
                    $isLast ? $finalOpinionPrompt : null
                );

                $content = $this->buildRefineContent(
                    $evolutiveSummary,
                    $microAnalysis,
                    $docNum
                );

                // No último documento, injeta entidades agregadas de todo o processo
                if ($isLast) {
                    $content .= $this->buildEntitiesBlock($this->aggregateEntitiesFromMicros($microAnalyses));
                }

                $evolutiveSummary = $aiService->analyzeSingleDocument(
                    $prompt,
                    $content,
                    $this->deepThinkingEnabled && $isLast
                );
            }

            $this->saveCheckpoint($documentAnalysis, $index, $evolutiveSummary);
        }

        return $evolutiveSummary;
    }

    /**
     * Monta o prompt de refinamento.
     * No último documento, incorpora o prompt do parecer final para eliminar uma chamada extra à API.
     */
    private function buildRefinePrompt(
        DocumentMicroAnalysis $microAnalysis,
        int $docNum,
        int $totalDocs,
        bool $hasHistory,
        ?string $finalOpinionPrompt = null
    ): string {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        if (!$hasHistory) {
            // Primeiro documento - apenas analisa
            return <<<PROMPT
# ANÁLISE DO PRIMEIRO DOCUMENTO DO PROCESSO

**Documento {$docNum}/{$totalDocs}:** {$microAnalysis->descricao}
**Classe Processual:** {$nomeClasse}

Este é o PRIMEIRO documento do processo. Analise-o e extraia:

1. **TIPO DO DOCUMENTO** (petição inicial, decisão, recurso, etc.)
2. **PARTES ENVOLVIDAS** e seus papéis
3. **FATOS NARRADOS** em ordem cronológica
4. **PEDIDOS OU DECISÕES** formulados
5. **FUNDAMENTOS LEGAIS** citados
6. **DATAS E VALORES** importantes

Sua análise será usada como base para incorporar os próximos documentos.
Responda em markdown estruturado.
PROMPT;
        }

        // Último documento: incorpora parecer final no mesmo passo
        $isLast = ($docNum === $totalDocs);

        if ($isLast && $finalOpinionPrompt) {
            return <<<PROMPT
# REFINAMENTO FINAL E PARECER - DOCUMENTO {$docNum}/{$totalDocs}

**Último Documento:** {$microAnalysis->descricao}
**Classe Processual:** {$nomeClasse}

Você receberá:
1. O RESUMO EVOLUTIVO de todos os documentos anteriores
2. A ANÁLISE do ÚLTIMO documento a incorporar

## TAREFA EM DUAS ETAPAS

### ETAPA 1: Incorpore o último documento
- INTEGRE cronologicamente os novos fatos à narrativa existente
- IDENTIFIQUE CONEXÕES entre este documento e os anteriores
- ATUALIZE o estado do processo

### ETAPA 2: Gere o Parecer Final
Com base na narrativa completa (todos os documentos incorporados), responda ao seguinte:

---

{$finalOpinionPrompt}

---

## INSTRUÇÕES ADICIONAIS

1. Considere TODOS os documentos que foram analisados
2. Mantenha a perspectiva cronológica e causal dos eventos
3. Fundamente suas conclusões nos documentos analisados
4. Seja objetivo e direto nas conclusões
5. Use markdown para estruturar a resposta

Responda DIRETAMENTE com o parecer final solicitado acima, já considerando todos os documentos do processo.
PROMPT;
        }

        // Documentos intermediários - refina com contexto
        return <<<PROMPT
# REFINAMENTO DA ANÁLISE PROCESSUAL - DOCUMENTO {$docNum}/{$totalDocs}

**Novo Documento:** {$microAnalysis->descricao}
**Classe Processual:** {$nomeClasse}

Você receberá:
1. O RESUMO EVOLUTIVO da análise até o documento anterior
2. A ANÁLISE do novo documento a incorporar

## TAREFA

Atualize o resumo evolutivo incorporando as novas informações:

1. **INTEGRE cronologicamente** os novos fatos à narrativa existente
2. **IDENTIFIQUE CONEXÕES** entre este documento e os anteriores
3. **ATUALIZE o estado do processo** (o que mudou? o que evoluiu?)
4. **DESTAQUE contradições** ou confirmações de fatos anteriores
5. **MANTENHA a coesão** - o resultado deve ser uma narrativa fluida

## REGRAS

- NÃO repita informações já consolidadas sem necessidade
- MANTENHA a ordem cronológica dos eventos
- PRESERVE detalhes importantes (datas, valores, decisões)
- ATUALIZE conclusões anteriores se novas informações as modificarem

Responda com o RESUMO EVOLUTIVO ATUALIZADO em markdown estruturado.
PROMPT;
    }

    /**
     * Monta o conteúdo para refinamento
     */
    private function buildRefineContent(
        string $previousSummary,
        DocumentMicroAnalysis $microAnalysis,
        int $docNum
    ): string {
        if (empty($previousSummary)) {
            // Primeiro documento - só o texto do documento
            return "# ANÁLISE DO DOCUMENTO\n\n{$microAnalysis->micro_analysis}";
        }

        // Documentos subsequentes - resumo + nova análise
        return <<<CONTENT
# RESUMO EVOLUTIVO ATÉ AQUI

{$previousSummary}

---

# DOCUMENTO {$docNum} - NOVA INFORMAÇÃO A INCORPORAR

**{$microAnalysis->descricao}**

{$microAnalysis->micro_analysis}
CONTENT;
    }

    /**
     * Obtém o prompt do parecer final para incorporar no último passo de refinamento.
     * Busca o prompt padrão ativo de "Parecer Final" do banco para garantir consistência.
     */
    private function getFinalOpinionPrompt(): string
    {
        $promptFromDb = AiPrompt::getDefaultForSystemAndType(1, AiPrompt::TYPE_FINAL_OPINION);

        return $promptFromDb?->content ?? $this->promptTemplate;
    }

    /**
     * Salva checkpoint do resumo evolutivo para retomada
     */
    private function saveCheckpoint(DocumentAnalysis $documentAnalysis, int $index, string $summary): void
    {
        $documentAnalysis->update([
            'evolutionary_summary' => $summary,
            'current_document_index' => $index,
            'last_processed_at' => now(),
        ]);
    }

    /**
     * Recupera resumo anterior para retomada
     */
    private function recoverPreviousSummary(DocumentAnalysis $documentAnalysis, int $fromIndex): string
    {
        return $documentAnalysis->evolutionary_summary ?? '';
    }

    /**
     * Notifica o usuário
     */
    private function notifyUser(DocumentAnalysis $documentAnalysis, string $status, string $title, string $body): void
    {
        $user = User::find($documentAnalysis->user_id);
        if (!$user) {
            return;
        }

        try {
            NotificationService::send($user, $title, $body, $status);
        } catch (\Exception $e) {
            Log::warning('RefineReduceJob: Erro ao notificar', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Agrega entidades "duras" de todas as micro-análises para injeção no parecer final.
     *
     * @param \Illuminate\Support\Collection $microAnalyses
     */
    private function aggregateEntitiesFromMicros($microAnalyses): array
    {
        $partes = [];
        $valores = [];
        $pontos = [];

        foreach ($microAnalyses as $micro) {
            $entities = $micro->getEntities();
            array_push($partes, ...$entities['partes_mencionadas']);
            array_push($valores, ...$entities['valores_monetarios']);
            array_push($pontos, ...$entities['pontos_chave']);
        }

        return [
            'partes_mencionadas' => array_values(array_unique($partes)),
            'valores_monetarios' => array_values(array_unique($valores)),
            'pontos_chave' => array_values(array_unique($pontos)),
        ];
    }

    /**
     * Monta bloco markdown de entidades para injeção no texto consolidado.
     */
    private function buildEntitiesBlock(array $entities): string
    {
        $hasAny = !empty($entities['partes_mencionadas'])
            || !empty($entities['valores_monetarios'])
            || !empty($entities['pontos_chave']);

        if (!$hasAny) {
            return '';
        }

        $block = "\n\n---\n\n## ENTIDADES EXTRAÍDAS (dados consolidados pelo sistema - NÃO omitir)\n\n";

        if (!empty($entities['partes_mencionadas'])) {
            $block .= "### Partes Mencionadas\n";
            foreach ($entities['partes_mencionadas'] as $parte) {
                $block .= "- {$parte}\n";
            }
            $block .= "\n";
        }

        if (!empty($entities['valores_monetarios'])) {
            $block .= "### Valores Monetários\n";
            foreach ($entities['valores_monetarios'] as $valor) {
                $block .= "- {$valor}\n";
            }
            $block .= "\n";
        }

        if (!empty($entities['pontos_chave'])) {
            $block .= "### Pontos-Chave\n";
            foreach ($entities['pontos_chave'] as $ponto) {
                $block .= "- {$ponto}\n";
            }
            $block .= "\n";
        }

        return $block;
    }

}
