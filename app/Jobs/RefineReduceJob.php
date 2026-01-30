<?php

namespace App\Jobs;

use App\Models\DocumentAnalysis;
use App\Models\DocumentMicroAnalysis;
use App\Models\User;
use App\Contracts\AIProviderInterface;
use App\Services\GeminiService;
use App\Services\DeepSeekService;
use App\Services\OpenAIService;
use App\Services\RateLimiterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification as FilamentNotification;

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
class RefineReduceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // 30 minutos (processo sequencial pode demorar)
    public int $tries = 2;
    public int $backoff = 60;

    public array $contextoDados = [];

    public function __construct(
        public int $documentAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public string $promptTemplate,
        public ?string $aiModelId = null,
        public int $startFromIndex = 0 // Permite retomada
    ) {}

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

            $this->notifyUser($documentAnalysis, 'info',
                'Fase 2/2: Refinamento',
                "Construindo narrativa processual a partir de {$totalDocs} documento(s)..."
            );

            $aiService = $this->getAIService($this->aiProvider);
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Estado evolutivo - começa vazio ou com resumo anterior
            $evolutiveSummary = '';
            $processedCount = 0;

            // Se estamos retomando, recupera o resumo até o ponto anterior
            if ($this->startFromIndex > 0) {
                $evolutiveSummary = $this->recoverPreviousSummary($documentAnalysis, $this->startFromIndex);
            }

            // Processa cada documento sequencialmente
            foreach ($microAnalyses as $index => $microAnalysis) {
                // Pula documentos já processados na retomada
                if ($index < $this->startFromIndex) {
                    continue;
                }

                $docNum = $index + 1;
                $processedCount++;

                Log::info('RefineReduceJob: Processando documento', [
                    'analysis_id' => $this->documentAnalysisId,
                    'doc_num' => $docNum,
                    'total' => $totalDocs,
                    'descricao' => $microAnalysis->descricao,
                ]);

                // Atualiza progresso
                $documentAnalysis->update([
                    'progress_message' => "Refinando análise: documento {$docNum}/{$totalDocs}...",
                    'reduce_processed_batches' => $processedCount,
                    'reduce_total_batches' => $totalDocs,
                ]);

                // Aplica rate limiting
                RateLimiterService::apply($this->aiProvider);

                // Monta o prompt de refinamento
                $prompt = $this->buildRefinePrompt(
                    $microAnalysis,
                    $docNum,
                    $totalDocs,
                    !empty($evolutiveSummary)
                );

                // Monta o contexto: resumo anterior + novo documento
                $content = $this->buildRefineContent(
                    $evolutiveSummary,
                    $microAnalysis,
                    $docNum
                );

                // Chama a IA para refinar
                $evolutiveSummary = $aiService->analyzeSingleDocument(
                    $prompt,
                    $content,
                    // Usa deep thinking apenas nos últimos documentos (quando a narrativa está completa)
                    $this->deepThinkingEnabled && ($docNum >= $totalDocs - 2)
                );

                // Salva checkpoint do resumo evolutivo
                $this->saveCheckpoint($documentAnalysis, $index, $evolutiveSummary);
            }

            // Gera análise final usando o resumo evolutivo completo
            Log::info('RefineReduceJob: Gerando análise final', [
                'analysis_id' => $this->documentAnalysisId,
            ]);

            $documentAnalysis->update([
                'progress_message' => "Gerando análise final consolidada...",
            ]);

            RateLimiterService::apply($this->aiProvider);

            $finalAnalysis = $this->generateFinalAnalysis(
                $aiService,
                $documentAnalysis,
                $evolutiveSummary
            );

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
                'evolutionary_summary' => $evolutiveSummary, // Salva o resumo evolutivo
            ]);

            Log::info('RefineReduceJob: Análise concluída com sucesso', [
                'analysis_id' => $this->documentAnalysisId,
                'total_processing_time_ms' => $totalProcessingTime,
            ]);

            $this->notifyUser($documentAnalysis, 'success',
                'Análise Concluída',
                "Análise de {$totalDocs} documento(s) do processo {$documentAnalysis->numero_processo} concluída!"
            );

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

                $this->notifyUser($documentAnalysis, 'danger',
                    'Análise Falhou',
                    'Erro: ' . $e->getMessage()
                );
            }

            throw $e;
        }
    }

    /**
     * Monta o prompt de refinamento
     */
    private function buildRefinePrompt(
        DocumentMicroAnalysis $microAnalysis,
        int $docNum,
        int $totalDocs,
        bool $hasHistory
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

        // Documentos subsequentes - refina com contexto
        $isLast = ($docNum === $totalDocs);
        $lastInstructions = $isLast
            ? "\n\n**ATENÇÃO:** Este é o ÚLTIMO documento. Sua análise deve concluir a narrativa processual."
            : "";

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
{$lastInstructions}

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
     * Gera a análise final usando o prompt do usuário
     */
    private function generateFinalAnalysis(
        AIProviderInterface $aiService,
        DocumentAnalysis $documentAnalysis,
        string $evolutiveSummary
    ): string {
        $prompt = <<<PROMPT
# ANÁLISE FINAL DO PROCESSO

Você recebeu o RESUMO EVOLUTIVO completo de todos os documentos do processo judicial.

Com base nessa narrativa consolidada, responda à solicitação do usuário:

---

{$this->promptTemplate}

---

## INSTRUÇÕES

1. Use o resumo evolutivo como base para sua análise
2. A narrativa já está em ordem cronológica - mantenha essa estrutura
3. Fundamente suas conclusões nos documentos analisados
4. Seja objetivo e direto
5. Use markdown para estruturar a resposta
PROMPT;

        return $aiService->analyzeSingleDocument(
            $prompt,
            $evolutiveSummary,
            $this->deepThinkingEnabled
        );
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
            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->status($status)
                ->sendToDatabase($user);
        } catch (\Exception $e) {
            Log::warning('RefineReduceJob: Erro ao notificar', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Retorna o serviço de IA
     */
    private function getAIService(string $provider): AIProviderInterface
    {
        return match ($provider) {
            'deepseek' => new DeepSeekService(),
            'gemini' => new GeminiService(),
            'openai' => new OpenAIService(),
            default => new GeminiService(),
        };
    }
}
