<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;
use App\Models\DocumentAnalysis;
use Illuminate\Support\Facades\Log;

abstract class AbstractAIService implements AIProviderInterface
{
    protected string $apiKey;
    protected string $apiUrl;
    protected string $model;

    /**
     * Metadados acumulados da última análise
     */
    protected array $lastAnalysisMetadata = [];

    /**
     * Override de max_tokens de saída (null = usa default do provider)
     */
    protected ?int $maxTokensOverride = null;

    /**
     * Override de limite de caracteres de entrada (null = sem limite)
     */
    protected ?int $inputCharLimit = null;

    /**
     * Override de temperature para a próxima chamada (null = usa default do provider)
     */
    protected ?float $temperatureOverride = null;

    /**
     * Timeout da requisição HTTP em segundos
     */
    protected int $timeout = 300;

    protected ?AIRetryPolicy $retryPolicy = null;

    protected ?AIAnalysisPromptBuilder $promptBuilder = null;

    protected ?AIAnalysisFlowResolver $flowResolver = null;

    protected ?AIAnalysisExecutionPipeline $analysisPipeline = null;

    /**
     * Contexto de observabilidade propagado pelos jobs/chamadores.
     */
    protected array $analysisContext = [];

    /**
     * Define o timeout para a chamada HTTP.
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Define override de max_tokens de saída para a próxima chamada.
     * Resetado automaticamente no início de cada análise (resetAnalysisMetadata).
     */
    public function setMaxTokens(?int $maxTokens): self
    {
        $this->maxTokensOverride = $maxTokens;
        return $this;
    }

    /**
     * Define limite de caracteres de entrada para summarização.
     * null = sem limite (texto completo é enviado).
     * Resetado automaticamente no início de cada análise (resetAnalysisMetadata).
     */
    public function setInputCharLimit(?int $limit): self
    {
        $this->inputCharLimit = $limit;
        return $this;
    }

    /**
     * Define override de temperature para a próxima chamada.
     * null = usa default do config (services.openrouter.temperature).
     */
    public function setTemperature(?float $temperature): self
    {
        $this->temperatureOverride = $temperature;
        return $this;
    }

    /**
     * Define o modelo a ser utilizado
     */
    public function setModel(string $model): self
    {
        if (!empty($model)) {
            $this->model = $model;
        }
        return $this;
    }

    /**
     * Retorna o modelo atual
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Define contexto de observabilidade da análise (Langfuse/OTEL).
     */
    public function setAnalysisContext(array $context): self
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
            }
        }

        $this->analysisContext = $normalized;

        return $this;
    }

    /**
     * Retorna o contexto de observabilidade atual.
     */
    public function getAnalysisContext(): array
    {
        return $this->analysisContext;
    }

    /**
     * Limites de tokens para processamento
     */
    protected const SINGLE_DOC_CHAR_LIMIT = 120000; // ~30k tokens - modelos modernos suportam 200k+

    /**
     * Configurações de rate limiting
     */
    protected const RATE_LIMIT_DELAY_MS = 2000;
    protected const MAX_RETRIES_ON_RATE_LIMIT = 5;
    protected const RATE_LIMIT_BACKOFF_BASE_MS = 5000;

    /**
     * Faz a chamada HTTP para a API do provider
     *
     * @param string $prompt Prompt do usuário
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @param string|null $systemPrompt System prompt customizado (para prompt caching)
     */
    abstract protected function callAPI(string $prompt, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string;

    /**
     * Traduz erros técnicos da API para mensagens amigáveis
     */
    abstract protected function translateError(int $statusCode, string $technicalMessage): string;

    /**
     * Retorna o nome do rate limiter para este provider
     */
    abstract protected function getRateLimiterKey(): string;

    /**
     * Retorna os metadados acumulados da última análise
     */
    public function getLastAnalysisMetadata(): array
    {
        return $this->lastAnalysisMetadata;
    }

    /**
     * Retorna o modelo ideal para uma estratégia de processamento.
     * Implementação padrão retorna o modelo atual (sem roteamento).
     * Providers específicos podem sobrescrever para rotear por tipo de documento.
     */
    public function getModelForStrategy(string $strategy): string
    {
        return $this->model;
    }

    /**
     * Limpa os metadados da análise
     */
    protected function resetAnalysisMetadata(): void
    {
        $this->lastAnalysisMetadata = [
            'provider' => $this->getName(),
            'model' => $this->model,
            'total_prompt_tokens' => 0,
            'total_completion_tokens' => 0,
            'total_tokens' => 0,
            'total_reasoning_tokens' => 0,
            'api_calls_count' => 0,
            'documents_processed' => 0,
            'started_at' => now()->toISOString(),
            'finished_at' => null,
            'max_tokens_override' => $this->maxTokensOverride,
            'input_char_limit' => $this->inputCharLimit,
            'temperature_override' => $this->temperatureOverride,
            'analysis_context' => $this->analysisContext,
        ];
    }

    /**
     * Acumula metadados de uma chamada à API
     */
    protected function accumulateMetadata(array $usage, ?string $model = null): void
    {
        $this->lastAnalysisMetadata['api_calls_count']++;
        $this->lastAnalysisMetadata['total_prompt_tokens'] += $usage['prompt_tokens'] ?? 0;
        $this->lastAnalysisMetadata['total_completion_tokens'] += $usage['completion_tokens'] ?? 0;
        $this->lastAnalysisMetadata['total_tokens'] += $usage['total_tokens'] ?? 0;

        $reasoningTokens = $usage['completion_tokens_details']['reasoning_tokens']
            ?? $usage['reasoning_tokens']
            ?? 0;
        $this->lastAnalysisMetadata['total_reasoning_tokens'] += $reasoningTokens;

        if ($model) {
            $this->lastAnalysisMetadata['model'] = $model;
        }
    }

    /**
     * Finaliza os metadados da análise
     */
    protected function finalizeMetadata(int $documentsProcessed): void
    {
        $this->lastAnalysisMetadata['documents_processed'] = $documentsProcessed;
        $this->lastAnalysisMetadata['finished_at'] = now()->toISOString();
    }

    /**
     * Analisa um único documento (usado na fase MAP do map-reduce)
     *
     * @param string $prompt Prompt de análise (conteúdo variável por documento)
     * @param string $documentText Texto do documento
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @param string|null $systemPrompt System prompt customizado com contexto fixo (para prompt caching entre chamadas)
     */
    public function analyzeSingleDocument(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string {
        $this->resetAnalysisMetadata();

        $fullPrompt = $prompt . "\n\n---\n\n# DOCUMENTO\n\n" . $documentText;

        // Se o documento for muito grande, sumariza primeiro
        // inputCharLimit = null desativa a sumarização (usado em REDUCE/FINAL)
        $charLimit = $this->inputCharLimit ?? static::SINGLE_DOC_CHAR_LIMIT;
        if ($charLimit > 0 && mb_strlen($documentText) > $charLimit) {
            Log::info('AbstractAIService: Documento muito grande, sumarizando', [
                'original_chars' => mb_strlen($documentText),
                'limit' => $charLimit,
            ]);

            $documentText = $this->summarizeDocument($documentText, 'Documento', $deepThinkingEnabled);
            $fullPrompt = $prompt . "\n\n---\n\n# DOCUMENTO (RESUMIDO)\n\n" . $documentText;
        }

        $result = $this->callAPI($fullPrompt, $deepThinkingEnabled, $systemPrompt);

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Analisa uma imagem enviando diretamente ao modelo de visão
     */
    public function analyzeImageDocument(
        string $prompt,
        string $imageBase64,
        string $mimetype,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string {
        $this->resetAnalysisMetadata();

        Log::info('AbstractAIService: Analisando imagem via visão', [
            'provider' => $this->getName(),
            'mimetype' => $mimetype,
            'base64_length' => strlen($imageBase64),
        ]);

        $result = $this->callAPIWithImage($prompt, $imageBase64, $mimetype, $deepThinkingEnabled, $systemPrompt);

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Analisa um PDF enviando diretamente à API com plugin de parsing
     */
    public function analyzePdfDocument(
        string $prompt,
        string $pdfBase64,
        string $filename,
        bool $isScanned = false,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string {
        $this->resetAnalysisMetadata();

        Log::info('AbstractAIService: Analisando PDF nativo', [
            'provider' => $this->getName(),
            'filename' => $filename,
            'is_scanned' => $isScanned,
            'base64_length' => strlen($pdfBase64),
        ]);

        $result = $this->callAPIWithPdf($prompt, $pdfBase64, $filename, $isScanned, $deepThinkingEnabled, $systemPrompt);

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Faz chamada à API com uma imagem (multimodal)
     * Os providers que suportam devem sobrescrever este método
     */
    protected function callAPIWithImage(string $prompt, string $imageBase64, string $mimetype, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        Log::warning('AbstractAIService: Provider não suporta análise de imagem, retornando descrição genérica', [
            'provider' => $this->getName(),
        ]);

        return "**[IMAGEM - análise visual não disponível para este provider]**\n\n" .
            "O provider {$this->getName()} não suporta análise visual de imagens. " .
            "Este documento é uma imagem do tipo {$mimetype}.";
    }

    /**
     * Faz chamada à API com um PDF nativo
     * Os providers que suportam devem sobrescrever este método
     */
    protected function callAPIWithPdf(string $prompt, string $pdfBase64, string $filename, bool $isScanned = false, bool $deepThinkingEnabled = false, ?string $systemPrompt = null): string
    {
        Log::warning('AbstractAIService: Provider não suporta envio nativo de PDF, retornando descrição genérica', [
            'provider' => $this->getName(),
        ]);

        return "**[PDF - envio nativo não disponível para este provider]**\n\n" .
            "O provider {$this->getName()} não suporta envio nativo de PDFs. " .
            "O documento '{$filename}' precisa ser processado via extração de texto.";
    }

    /**
     * Analisa um único documento retornando resposta JSON estruturada.
     * Implementação padrão: delega para analyzeSingleDocument (sem structured output).
     * OpenRouterService sobrescreve para usar response_format com json_schema.
     */
    public function analyzeSingleDocumentStructured(
        string $prompt,
        string $documentText,
        array $jsonSchema,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string {
        return $this->analyzeSingleDocument($prompt, $documentText, $deepThinkingEnabled, $systemPrompt);
    }

    /**
     * Analisa texto com web search habilitado (usado no parecer final).
     * Implementação padrão: delega para analyzeSingleDocument (sem web search).
     * OpenRouterService sobrescreve para ativar o plugin de web search.
     */
    public function analyzeWithWebSearch(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false
    ): string {
        return $this->analyzeSingleDocument($prompt, $documentText, $deepThinkingEnabled);
    }

    /**
     * Analisa documentos do processo com contexto
     *
     * NOTA: Este método é mantido para compatibilidade com análise de CONTRATOS.
     * Para processos judiciais com múltiplos documentos, use o sistema map-reduce
     * (MapDocumentAnalysisJob + ReduceDocumentAnalysisJob).
     */
    public function analyzeDocuments(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled = true,
        ?DocumentAnalysis $documentAnalysis = null
    ): string {
        $flow = $this->getFlowResolver()->resolveFlow($contextoDados);
        $isContract = $flow === AIAnalysisFlowResolver::FLOW_CONTRACT;
        $totalDocuments = \count($documentos);

        return $this->getAnalysisPipeline()->execute(
            provider: $this->getName(),
            totalDocuments: $totalDocuments,
            isContract: $isContract,
            onStart: fn () => $this->resetAnalysisMetadata(),
            runAnalysis: function () use ($flow, $promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled): string {
                return match ($flow) {
                    AIAnalysisFlowResolver::FLOW_CONTRACT => $this->analyzeContract($promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled),
                    AIAnalysisFlowResolver::FLOW_SIMPLE => $this->analyzeSimple($promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled),
                    default => $this->analyzeSimple($promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled),
                };
            },
            onSuccess: fn () => $this->finalizeMetadata($totalDocuments),
            onError: function (\Exception $e) use ($totalDocuments): void {
                $this->finalizeMetadata($totalDocuments);
                $this->lastAnalysisMetadata['error'] = $e->getMessage();
            },
            metadataProvider: fn () => $this->lastAnalysisMetadata,
        );
    }

    /**
     * Detecta se é uma análise de contrato
     */
    protected function isContractAnalysis(array $contextoDados): bool
    {
        return $this->getFlowResolver()->isContractAnalysis($contextoDados);
    }

    /**
     * Análise de contrato (documento único, fluxo simples)
     */
    protected function analyzeContract(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled
    ): string {
        $documento = $documentos[0] ?? null;

        if (!$documento) {
            throw new \Exception('Nenhum documento fornecido para análise de contrato');
        }

        $texto = $documento['texto'] ?? '';
        $arquivo = $contextoDados['arquivo'] ?? 'Contrato';
        $parteInteressada = $contextoDados['parte_interessada'] ?? '';

        $contexto = $this->getPromptBuilder()->buildContractAnalysisPrompt(
            promptTemplate: $promptTemplate,
            documentText: $texto,
            arquivo: $arquivo,
            parteInteressada: $parteInteressada,
            isSummarized: false,
        );

        // Se muito grande, sumariza
        if (mb_strlen($texto) > static::SINGLE_DOC_CHAR_LIMIT) {
            $texto = $this->summarizeDocument($texto, $arquivo, $deepThinkingEnabled);
            $contexto = $this->getPromptBuilder()->buildContractAnalysisPrompt(
                promptTemplate: $promptTemplate,
                documentText: $texto,
                arquivo: $arquivo,
                parteInteressada: $parteInteressada,
                isSummarized: true,
            );
        }

        return $this->callAPI($contexto, $deepThinkingEnabled);
    }

    /**
     * Análise simples (fallback para poucos documentos)
     * Concatena todos os documentos e envia em uma única chamada
     */
    protected function analyzeSimple(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled
    ): string {
        $prompt = $this->getPromptBuilder()->buildSimpleProcessAnalysisPrompt($promptTemplate, $documentos, $contextoDados);

        return $this->callAPI($prompt, $deepThinkingEnabled);
    }

    /**
     * Sumariza um documento individual
     */
    protected function summarizeDocument(string $documentText, string $descricao, bool $deepThinkingEnabled = false): string
    {
        $promptSumarizacao = $this->getPromptBuilder()->buildSummarizationPrompt($documentText, $descricao);

        $response = $this->callAPI($promptSumarizacao, $deepThinkingEnabled);

        return "**[RESUMO AUTOMÁTICO - Original: " . number_format(mb_strlen($documentText)) . " caracteres]**\n\n" . $response;
    }

    /**
     * Formata array de assuntos para string legível
     */
    protected function formatAssuntos(array $assuntos): string
    {
        return $this->getPromptBuilder()->formatAssuntos($assuntos);
    }

    /**
     * Helper para executar chamadas à API com lógica de retry e backoff
     */
    protected function withRetry(callable $apiCall, int $maxRetries = self::MAX_RETRIES_ON_RATE_LIMIT): string
    {
        return $this->getRetryPolicy()->execute($apiCall, $this->getName(), $maxRetries);
    }

    /**
     * Detecta se o erro é de rate limit (429)
     */
    protected function isRateLimitError(\Exception $e): bool
    {
        return $this->getRetryPolicy()->isRateLimitError($e);
    }

    /**
     * Detecta se o erro é de conexão ou timeout
     */
    protected function isConnectionError(\Exception $e): bool
    {
        return $this->getRetryPolicy()->isConnectionError($e);
    }

    /**
     * Calcula delay para exponential backoff
     */
    protected function calculateBackoff(int $attempt): int
    {
        return $this->getRetryPolicy()->calculateBackoff($attempt);
    }

    protected function getRetryPolicy(): AIRetryPolicy
    {
        if ($this->retryPolicy !== null) {
            return $this->retryPolicy;
        }

        try {
            $this->retryPolicy = app(AIRetryPolicy::class);
        } catch (\Throwable) {
            $this->retryPolicy = new AIRetryPolicy(static::RATE_LIMIT_BACKOFF_BASE_MS);
        }

        return $this->retryPolicy;
    }

    protected function getPromptBuilder(): AIAnalysisPromptBuilder
    {
        if ($this->promptBuilder !== null) {
            return $this->promptBuilder;
        }

        try {
            $this->promptBuilder = app(AIAnalysisPromptBuilder::class);
        } catch (\Throwable) {
            $this->promptBuilder = new AIAnalysisPromptBuilder();
        }

        return $this->promptBuilder;
    }

    protected function getFlowResolver(): AIAnalysisFlowResolver
    {
        if ($this->flowResolver !== null) {
            return $this->flowResolver;
        }

        try {
            $this->flowResolver = app(AIAnalysisFlowResolver::class);
        } catch (\Throwable) {
            $this->flowResolver = new AIAnalysisFlowResolver();
        }

        return $this->flowResolver;
    }

    protected function getAnalysisPipeline(): AIAnalysisExecutionPipeline
    {
        if ($this->analysisPipeline !== null) {
            return $this->analysisPipeline;
        }

        try {
            $this->analysisPipeline = app(AIAnalysisExecutionPipeline::class);
        } catch (\Throwable) {
            $this->analysisPipeline = new AIAnalysisExecutionPipeline();
        }

        return $this->analysisPipeline;
    }
}
