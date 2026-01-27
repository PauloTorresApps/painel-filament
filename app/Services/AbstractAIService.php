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
     * Limites de tokens para processamento
     */
    protected const SINGLE_DOC_CHAR_LIMIT = 30000; // ~7.5k tokens

    /**
     * Configurações de rate limiting
     */
    protected const RATE_LIMIT_DELAY_MS = 2000;
    protected const MAX_RETRIES_ON_RATE_LIMIT = 5;
    protected const RATE_LIMIT_BACKOFF_BASE_MS = 5000;

    /**
     * Faz a chamada HTTP para a API do provider
     */
    abstract protected function callAPI(string $prompt, bool $deepThinkingEnabled = false): string;

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
     */
    public function analyzeSingleDocument(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false
    ): string {
        $this->resetAnalysisMetadata();

        $fullPrompt = $prompt . "\n\n---\n\n# DOCUMENTO\n\n" . $documentText;

        // Se o documento for muito grande, sumariza primeiro
        if (mb_strlen($documentText) > static::SINGLE_DOC_CHAR_LIMIT) {
            Log::info('AbstractAIService: Documento muito grande, sumarizando', [
                'original_chars' => mb_strlen($documentText),
                'limit' => static::SINGLE_DOC_CHAR_LIMIT
            ]);

            $documentText = $this->summarizeDocument($documentText, 'Documento', $deepThinkingEnabled);
            $fullPrompt = $prompt . "\n\n---\n\n# DOCUMENTO (RESUMIDO)\n\n" . $documentText;
        }

        $result = $this->callAPI($fullPrompt, $deepThinkingEnabled);

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Analisa uma imagem (usado para documentos de imagem no map-reduce)
     */
    public function analyzeImageDocument(
        string $prompt,
        string $imageBase64,
        string $mimetype,
        bool $deepThinkingEnabled = false
    ): string {
        $this->resetAnalysisMetadata();

        Log::info('AbstractAIService: Analisando imagem', [
            'provider' => $this->getName(),
            'mimetype' => $mimetype,
            'base64_length' => strlen($imageBase64)
        ]);

        // Por padrão, retorna mensagem de que análise de imagem não é suportada
        // Os providers que suportam devem sobrescrever este método
        $result = $this->callAPIWithImage($prompt, $imageBase64, $mimetype, $deepThinkingEnabled);

        $this->finalizeMetadata(1);

        return $result;
    }

    /**
     * Faz chamada à API com uma imagem
     * Os providers que suportam imagens devem sobrescrever este método
     */
    protected function callAPIWithImage(string $prompt, string $imageBase64, string $mimetype, bool $deepThinkingEnabled = false): string
    {
        // Fallback: analisa a descrição se não suportar imagens
        Log::warning('AbstractAIService: Provider não suporta análise de imagem, retornando descrição genérica', [
            'provider' => $this->getName()
        ]);

        return "**[IMAGEM - análise visual não disponível para este provider]**\n\n" .
            "O provider {$this->getName()} não suporta análise visual de imagens. " .
            "Este documento é uma imagem do tipo {$mimetype}.";
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
        try {
            $this->resetAnalysisMetadata();

            $isContract = $this->isContractAnalysis($contextoDados);

            Log::info('AbstractAIService: Iniciando análise', [
                'provider' => $this->getName(),
                'total_documentos' => \count($documentos),
                'is_contract' => $isContract,
            ]);

            if ($isContract) {
                // Análise de contrato: fluxo simples (documento único)
                $result = $this->analyzeContract($promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled);
            } else {
                // Processos judiciais com múltiplos documentos devem usar map-reduce
                // Este fallback existe apenas para compatibilidade
                $result = $this->analyzeSimple($promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled);
            }

            $this->finalizeMetadata(\count($documentos));

            Log::info('AbstractAIService: Análise concluída', [
                'provider' => $this->getName(),
                'metadata' => $this->lastAnalysisMetadata
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->finalizeMetadata(\count($documentos));
            $this->lastAnalysisMetadata['error'] = $e->getMessage();

            Log::error('AbstractAIService: Erro na análise', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Detecta se é uma análise de contrato
     */
    protected function isContractAnalysis(array $contextoDados): bool
    {
        if (!isset($contextoDados['tipo'])) {
            return false;
        }

        return \in_array($contextoDados['tipo'], ['Contrato', 'Parecer Jurídico']);
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

        // Monta contexto
        $contexto = "# CONTEXTO DA ANÁLISE DE CONTRATO\n\n";
        $contexto .= "**Tipo:** Análise de Contrato\n";
        $contexto .= "**Arquivo:** {$arquivo}\n";

        if (!empty($parteInteressada)) {
            $contexto .= "**Parte Interessada:** {$parteInteressada}\n";
        }

        $contexto .= "\n---\n\n";
        $contexto .= "# DOCUMENTO DO CONTRATO\n\n";
        $contexto .= $texto . "\n\n";
        $contexto .= "---\n\n";
        $contexto .= "# TAREFA\n\n";
        $contexto .= $promptTemplate;

        // Se muito grande, sumariza
        if (mb_strlen($texto) > static::SINGLE_DOC_CHAR_LIMIT) {
            $texto = $this->summarizeDocument($texto, $arquivo, $deepThinkingEnabled);

            $contexto = "# CONTEXTO DA ANÁLISE DE CONTRATO\n\n";
            $contexto .= "**Tipo:** Análise de Contrato\n";
            $contexto .= "**Arquivo:** {$arquivo}\n";

            if (!empty($parteInteressada)) {
                $contexto .= "**Parte Interessada:** {$parteInteressada}\n";
            }

            $contexto .= "\n---\n\n";
            $contexto .= "# DOCUMENTO DO CONTRATO (RESUMIDO)\n\n";
            $contexto .= $texto . "\n\n";
            $contexto .= "---\n\n";
            $contexto .= "# TAREFA\n\n";
            $contexto .= $promptTemplate;
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
        $nomeClasse = $contextoDados['classeProcessualNome']
            ?? $contextoDados['classeProcessual']
            ?? 'Não informada';

        $assuntos = $this->formatAssuntos($contextoDados['assunto'] ?? []);
        $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';

        // Monta contexto
        $prompt = "# CONTEXTO DO PROCESSO\n\n";
        $prompt .= "**Classe Processual:** {$nomeClasse}\n";
        $prompt .= "**Assuntos:** {$assuntos}\n";
        $prompt .= "**Número do Processo:** {$numeroProcesso}\n";

        if (!empty($contextoDados['valorCausa'])) {
            $prompt .= "**Valor da Causa:** R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
        }

        $prompt .= "\n---\n\n";
        $prompt .= "# DOCUMENTOS DO PROCESSO\n\n";

        foreach ($documentos as $index => $doc) {
            $docNum = $index + 1;
            $descricao = $doc['descricao'] ?? "Documento {$docNum}";
            $texto = $doc['texto'] ?? '';

            $prompt .= "## DOCUMENTO {$docNum}: {$descricao}\n\n";
            $prompt .= $texto . "\n\n";
            $prompt .= "---\n\n";
        }

        $prompt .= "# TAREFA\n\n";
        $prompt .= $promptTemplate;

        return $this->callAPI($prompt, $deepThinkingEnabled);
    }

    /**
     * Sumariza um documento individual
     */
    protected function summarizeDocument(string $documentText, string $descricao, bool $deepThinkingEnabled = false): string
    {
        $promptSumarizacao = <<<PROMPT
Você é um assistente jurídico especializado. Resuma o documento abaixo em 2-3 parágrafos concisos, destacando:

1. **Tipo de manifestação** (petição, contestação, decisão, despacho, sentença, recurso, contrato, etc.)
2. **Partes envolvidas**
3. **Pedidos ou decisões principais**
4. **Fundamentos legais citados**
5. **Fatos relevantes**
6. **Datas importantes**

**IMPORTANTE:** Preserve informações essenciais para compreensão do documento.

**Descrição do documento:** {$descricao}

**DOCUMENTO:**

{$documentText}
PROMPT;

        $response = $this->callAPI($promptSumarizacao, $deepThinkingEnabled);

        return "**[RESUMO AUTOMÁTICO - Original: " . number_format(mb_strlen($documentText)) . " caracteres]**\n\n" . $response;
    }

    /**
     * Formata array de assuntos para string legível
     */
    protected function formatAssuntos(array $assuntos): string
    {
        if (empty($assuntos)) {
            return 'Não informados';
        }

        $nomes = array_map(function ($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? $assunto['codigoNacional']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }

    /**
     * Helper para executar chamadas à API com lógica de retry e backoff
     */
    protected function withRetry(callable $apiCall, int $maxRetries = self::MAX_RETRIES_ON_RATE_LIMIT): string
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                return $apiCall($attempt);
            } catch (\Exception $e) {
                $lastException = $e;

                if ($this->isRateLimitError($e)) {
                    if ($attempt < $maxRetries) {
                        $backoffMs = $this->calculateBackoff($attempt);
                        Log::warning("Rate limit atingido no " . $this->getName() . ". Tentativa {$attempt}/{$maxRetries}. Aguardando {$backoffMs}ms");
                        usleep($backoffMs * 1000);
                        continue;
                    }
                }

                if ($attempt < 3 && $this->isConnectionError($e)) {
                    $retryDelay = 2000 * $attempt;
                    Log::warning("Erro de conexão no " . $this->getName() . ". Tentativa {$attempt}/3. Aguardando {$retryDelay}ms");
                    usleep($retryDelay * 1000);
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException ?? new \Exception("Falha ao chamar API " . $this->getName() . " após múltiplas tentativas");
    }

    /**
     * Detecta se o erro é de rate limit (429)
     */
    protected function isRateLimitError(\Exception $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, '429') ||
            str_contains($msg, 'rate limit') ||
            str_contains($msg, 'too many requests');
    }

    /**
     * Detecta se o erro é de conexão ou timeout
     */
    protected function isConnectionError(\Exception $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'timeout') ||
            str_contains($msg, 'connection') ||
            str_contains($msg, 'curl error') ||
            str_contains($msg, '504');
    }

    /**
     * Calcula delay para exponential backoff
     */
    protected function calculateBackoff(int $attempt): int
    {
        return static::RATE_LIMIT_BACKOFF_BASE_MS * (int) pow(2, $attempt - 1);
    }
}
