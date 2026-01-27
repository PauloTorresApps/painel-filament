<?php

namespace App\Jobs;

use App\Models\DocumentMicroAnalysis;
use App\Models\DocumentAnalysis;
use App\Contracts\AIProviderInterface;
use App\Services\GeminiService;
use App\Services\DeepSeekService;
use App\Services\OpenAIService;
use App\Services\RateLimiterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job responsável pela fase MAP do map-reduce.
 * Processa um único documento e gera uma micro-análise.
 */
class MapDocumentAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300; // 5 minutos por documento
    public int $tries = 3;
    public int $backoff = 30; // 30 segundos entre tentativas

    public function __construct(
        public int $microAnalysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId = null // ID do modelo específico (ex: gemini-2.5-flash)
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            $microAnalysis = DocumentMicroAnalysis::find($this->microAnalysisId);

            if (!$microAnalysis) {
                Log::error('MapDocumentAnalysisJob: MicroAnalysis não encontrada', [
                    'id' => $this->microAnalysisId
                ]);
                return;
            }

            // Verifica se já foi processada
            if ($microAnalysis->isCompleted()) {
                Log::info('MapDocumentAnalysisJob: Já processada, pulando', [
                    'id' => $this->microAnalysisId
                ]);
                return;
            }

            // Verifica se a análise pai foi cancelada
            $documentAnalysis = $microAnalysis->documentAnalysis;
            if (!$documentAnalysis || $documentAnalysis->status === 'cancelled') {
                Log::info('MapDocumentAnalysisJob: Análise pai cancelada', [
                    'micro_id' => $this->microAnalysisId
                ]);
                return;
            }

            $microAnalysis->markAsProcessing();

            Log::info('MapDocumentAnalysisJob: Iniciando processamento', [
                'micro_id' => $this->microAnalysisId,
                'document_index' => $microAnalysis->document_index,
                'descricao' => $microAnalysis->descricao,
                'mimetype' => $microAnalysis->mimetype,
                'provider' => $this->aiProvider,
                'text_length' => mb_strlen($microAnalysis->extracted_text ?? ''),
            ]);

            // Obtém o serviço de IA
            $aiService = $this->getAIService($this->aiProvider);

            // Define o modelo específico se configurado
            if ($this->aiModelId) {
                $aiService->setModel($this->aiModelId);
            }

            // Monta o prompt para micro-análise (agora sempre texto, OCR já extraiu das imagens)
            $prompt = $this->buildMapPrompt($microAnalysis);

            // Aplica rate limiting
            RateLimiterService::apply($this->aiProvider);

            // Chama a IA para análise de texto (imagens já tiveram texto extraído via OCR)
            $result = $aiService->analyzeSingleDocument(
                $prompt,
                $microAnalysis->extracted_text,
                $this->deepThinkingEnabled
            );

            $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Marca como completo
            $microAnalysis->markAsCompleted(
                $result,
                $this->estimateTokenCount($result),
                $processingTimeMs
            );

            Log::info('MapDocumentAnalysisJob: Concluído com sucesso', [
                'micro_id' => $this->microAnalysisId,
                'processing_time_ms' => $processingTimeMs
            ]);

            // Verifica se todas as micro-análises MAP foram concluídas
            $this->checkAndTriggerReduce($documentAnalysis);

        } catch (\Exception $e) {
            Log::error('MapDocumentAnalysisJob: Erro no processamento', [
                'micro_id' => $this->microAnalysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if (isset($microAnalysis)) {
                $microAnalysis->markAsFailed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Monta o prompt para micro-análise de um documento
     *
     * Nota: Imagens já tiveram seu texto extraído via OCR antes de chegar aqui,
     * então todos os documentos são tratados como texto.
     */
    private function buildMapPrompt(DocumentMicroAnalysis $microAnalysis): string
    {
        $nomeClasse = $this->contextoDados['classeProcessualNome']
            ?? $this->contextoDados['classeProcessual']
            ?? 'Não informada';

        $assuntos = $this->formatAssuntos($this->contextoDados['assunto'] ?? []);
        $numeroProcesso = $this->contextoDados['numeroProcesso'] ?? 'Não informado';

        // Adiciona contexto se o documento original era uma imagem (texto extraído via OCR)
        $documentContext = $microAnalysis->isImage()
            ? "**Documento:** {$microAnalysis->descricao}\n**Índice:** {$microAnalysis->document_index}\n**Tipo original:** {$microAnalysis->mimetype} (texto extraído via OCR)"
            : "**Documento:** {$microAnalysis->descricao}\n**Índice:** {$microAnalysis->document_index}";

        $prompt = <<<PROMPT
# CONTEXTO DO PROCESSO

**Classe Processual:** {$nomeClasse}
**Assuntos:** {$assuntos}
**Número do Processo:** {$numeroProcesso}

---

# DOCUMENTO A ANALISAR

{$documentContext}

---

# TAREFA

Analise o documento acima e extraia as seguintes informações de forma estruturada:

## 1. TIPO DE MANIFESTAÇÃO
Identifique o tipo (petição inicial, contestação, decisão, despacho, sentença, recurso, parecer, documento pessoal, comprovante, etc.)

## 2. PARTES ENVOLVIDAS
Liste as partes mencionadas e seus papéis (autor, réu, terceiros, advogados, etc.)

## 3. PEDIDOS OU DECISÕES
- Se for petição/recurso: liste os pedidos formulados
- Se for decisão/sentença: liste o dispositivo (o que foi decidido)
- Se for documento/comprovante: descreva o conteúdo principal

## 4. FUNDAMENTOS
- Fundamentos legais citados (artigos de lei, jurisprudência)
- Argumentos principais utilizados

## 5. FATOS RELEVANTES
Fatos narrados que são importantes para entender a narrativa processual

## 6. DATAS E VALORES
Datas mencionadas (prazos, eventos, vencimentos) e valores monetários se houver

## 7. CONEXÕES
Referências a outros documentos ou eventos do processo

---

**FORMATO:** Responda de forma estruturada usando markdown. Seja conciso mas completo.
PROMPT;

        return $prompt;
    }

    /**
     * Verifica se todas as micro-análises MAP foram concluídas e dispara o REDUCE
     */
    private function checkAndTriggerReduce(DocumentAnalysis $documentAnalysis): void
    {
        $totalMap = $documentAnalysis->microAnalyses()->mapLevel()->count();
        $completedMap = $documentAnalysis->microAnalyses()->mapLevel()->completed()->count();
        $failedMap = $documentAnalysis->microAnalyses()->mapLevel()->failed()->count();

        Log::info('MapDocumentAnalysisJob: Verificando progresso MAP', [
            'analysis_id' => $documentAnalysis->id,
            'total' => $totalMap,
            'completed' => $completedMap,
            'failed' => $failedMap
        ]);

        // Atualiza progresso
        $documentAnalysis->update([
            'processed_documents_count' => $completedMap,
            'last_processed_at' => now(),
        ]);

        // Se todos os MAPs foram concluídos (com sucesso ou falha), dispara o REDUCE
        if (($completedMap + $failedMap) >= $totalMap) {
            if ($completedMap === 0) {
                // Todos falharam
                $documentAnalysis->update([
                    'status' => 'failed',
                    'error_message' => 'Todos os documentos falharam na fase MAP'
                ]);
                return;
            }

            Log::info('MapDocumentAnalysisJob: Fase MAP concluída, disparando REDUCE', [
                'analysis_id' => $documentAnalysis->id,
                'completed_maps' => $completedMap
            ]);

            // Dispara o job de REDUCE
            ReduceDocumentAnalysisJob::dispatch(
                $documentAnalysis->id,
                $this->aiProvider,
                $this->deepThinkingEnabled,
                $documentAnalysis->job_parameters['promptTemplate'] ?? '',
                $this->aiModelId // ID do modelo específico
            )->onQueue('analysis');
        }
    }

    /**
     * Estima contagem de tokens baseado no tamanho do texto
     */
    private function estimateTokenCount(string $text): int
    {
        // Aproximação: ~4 caracteres por token
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Formata array de assuntos para string legível
     */
    private function formatAssuntos(array $assuntos): string
    {
        if (empty($assuntos)) {
            return 'Não informados';
        }

        $nomes = array_map(function ($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }

    /**
     * Retorna o serviço de IA baseado no provider
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
