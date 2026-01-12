<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AIProviderInterface
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->apiUrl = config('services.openai.api_url', 'https://api.openai.com/v1');
        $this->model = config('services.openai.model', 'gpt-4o');

        if (empty($this->apiKey)) {
            throw new \Exception('OPENAI_API_KEY não configurado no .env');
        }
    }

    /**
     * Limites de tokens e caracteres
     */
    private const SINGLE_DOC_CHAR_LIMIT = 30000; // ~7.5k tokens
    private const TOTAL_PROMPT_CHAR_LIMIT = 500000; // ~125k tokens (GPT-4 tem 128k context window)

    /**
     * Configurações de rate limiting
     */
    private const RATE_LIMIT_DELAY_MS = 1000; // 1 segundo entre chamadas
    private const MAX_RETRIES_ON_RATE_LIMIT = 5;
    private const RATE_LIMIT_BACKOFF_BASE_MS = 5000; // 5 segundos base para backoff

    /**
     * Analisa documentos com estratégia de Resumo Evolutivo
     */
    public function analyzeDocuments(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled = true,
        ?\App\Models\DocumentAnalysis $documentAnalysis = null
    ): string {
        try {
            Log::info('Iniciando análise OpenAI com estratégia de Resumo Evolutivo', [
                'total_documentos' => count($documentos),
                'total_chars_estimate' => array_sum(array_map(fn($d) => mb_strlen($d['texto'] ?? ''), $documentos)),
                'has_persistence' => $documentAnalysis !== null,
                'analysis_id' => $documentAnalysis?->id,
                'model' => $this->model
            ]);

            return $this->applyEvolutiveSummarization($promptTemplate, $documentos, $contextoDados, $documentAnalysis);
        } catch (\Exception $e) {
            Log::error('Erro ao chamar OpenAI API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Aplica estratégia de Resumo Evolutivo (idêntica ao Gemini)
     */
    private function applyEvolutiveSummarization(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        ?\App\Models\DocumentAnalysis $documentAnalysis
    ): string {
        $totalDocumentos = count($documentos);
        $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';

        // Verifica se pode retomar de onde parou
        $startIndex = 0;
        $resumoEvolutivo = '';

        if ($documentAnalysis && $documentAnalysis->canBeResumed()) {
            $startIndex = $documentAnalysis->getNextDocumentIndex();
            $resumoEvolutivo = $documentAnalysis->getEvolutionarySummary();

            Log::info('Retomando Resumo Evolutivo de onde parou', [
                'analysis_id' => $documentAnalysis->id,
                'start_index' => $startIndex,
                'total_documentos' => $totalDocumentos,
                'progresso' => $documentAnalysis->getProgressPercentage() . '%'
            ]);
        } else {
            // Inicializa novo processamento
            if ($documentAnalysis) {
                $documentAnalysis->initializeEvolutionaryAnalysis($totalDocumentos);
            }

            Log::info('Iniciando Resumo Evolutivo', [
                'total_documentos' => $totalDocumentos,
                'processo' => $numeroProcesso,
                'analysis_id' => $documentAnalysis?->id
            ]);
        }

        $documentosProcessados = $startIndex;

        foreach ($documentos as $index => $doc) {
            // Pula documentos já processados
            if ($index < $startIndex) {
                continue;
            }

            $docNum = $index + 1;
            $descricao = $doc['descricao'] ?? "Documento {$docNum}";
            $texto = $doc['texto'] ?? '';
            $charCount = mb_strlen($texto);

            Log::info("Processando documento {$docNum}/{$totalDocumentos}: {$descricao}", [
                'char_count' => $charCount,
                'index' => $index
            ]);

            // Se documento é muito grande, sumariza primeiro
            if ($charCount > self::SINGLE_DOC_CHAR_LIMIT) {
                Log::info("Documento {$docNum} excede limite. Sumarizando...", [
                    'char_count' => $charCount,
                    'limit' => self::SINGLE_DOC_CHAR_LIMIT
                ]);

                $texto = $this->summarizeDocument($texto, $descricao);
            }

            // Monta prompt evolutivo
            $promptEvolutivo = $this->buildEvolutionaryPrompt(
                $promptTemplate,
                $resumoEvolutivo,
                $texto,
                $descricao,
                $docNum,
                $totalDocumentos,
                $contextoDados
            );

            // Chama API OpenAI
            try {
                $response = $this->callOpenAIAPI($promptEvolutivo);
                $resumoEvolutivo = $response;
                $documentosProcessados++;

                // Persiste estado após cada documento
                if ($documentAnalysis) {
                    $documentAnalysis->updateEvolutionaryState($index, $resumoEvolutivo);
                }

                Log::info("Documento {$docNum} processado com sucesso", [
                    'resumo_length' => mb_strlen($resumoEvolutivo)
                ]);

                // Rate limiting
                if ($docNum < $totalDocumentos) {
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                }
            } catch (\Exception $e) {
                Log::error("Erro ao processar documento {$docNum}", [
                    'error' => $e->getMessage(),
                    'index' => $index
                ]);

                // Salva erro e permite retomada
                if ($documentAnalysis) {
                    $documentAnalysis->update([
                        'error_message' => "Falha no Resumo Evolutivo no documento {$docNum} ({$descricao}): {$e->getMessage()}"
                    ]);
                }

                throw $e;
            }
        }

        // Retorna análise final
        return $resumoEvolutivo;
    }

    /**
     * Monta prompt evolutivo com contexto acumulado
     */
    private function buildEvolutionaryPrompt(
        string $promptTemplate,
        string $resumoAnterior,
        string $textoDocumento,
        string $descricaoDocumento,
        int $docNum,
        int $totalDocs,
        array $contextoDados
    ): string {
        $nomeClasse = $contextoDados['classeProcessualNome'] ?? $contextoDados['classeProcessual'] ?? 'Não informada';
        $assuntos = $this->formatAssuntos($contextoDados['assunto'] ?? []);
        $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';

        $prompt = "# ANÁLISE EVOLUTIVA DE PROCESSO JUDICIAL\n\n";
        $prompt .= "**Processo:** {$numeroProcesso}\n";
        $prompt .= "**Classe:** {$nomeClasse}\n";
        $prompt .= "**Assuntos:** {$assuntos}\n";
        $prompt .= "**Documento atual:** {$docNum}/{$totalDocs}\n\n";

        // Adiciona resumo dos documentos anteriores
        if (!empty($resumoAnterior)) {
            $prompt .= "## RESUMO DOS DOCUMENTOS ANTERIORES\n\n";
            $prompt .= $resumoAnterior . "\n\n";
            $prompt .= "---\n\n";
        }

        // Adiciona documento atual
        $prompt .= "## DOCUMENTO {$docNum}: {$descricaoDocumento}\n\n";
        $prompt .= $textoDocumento . "\n\n";
        $prompt .= "---\n\n";

        // Adiciona instruções do usuário
        $prompt .= "## INSTRUÇÕES\n\n";
        $prompt .= $promptTemplate . "\n\n";

        // Instrução para manter evolução
        if ($docNum < $totalDocs) {
            $prompt .= "\n**IMPORTANTE:** Sua resposta será usada como contexto para analisar os próximos documentos. ";
            $prompt .= "Inclua informações relevantes que conectem este documento aos anteriores e que sejam úteis para entender os próximos documentos do processo.";
        } else {
            $prompt .= "\n**IMPORTANTE:** Este é o último documento. Forneça uma análise final completa do processo, ";
            $prompt .= "consolidando todas as informações dos documentos anteriores.";
        }

        return $prompt;
    }

    /**
     * Sumariza documento individual muito grande
     */
    private function summarizeDocument(string $texto, string $descricao): string
    {
        $promptSumarizacao = <<<PROMPT
Você é um assistente jurídico especializado. Resuma o documento abaixo em 2-3 parágrafos concisos, destacando:

1. **Tipo de manifestação** (petição inicial, contestação, decisão, despacho, sentença, recurso, etc.)
2. **Partes envolvidas**
3. **Pedidos ou decisões principais**
4. **Fundamentos legais citados**
5. **Fatos relevantes** que conectem este documento aos demais do processo
6. **Datas importantes**

**Descrição:** {$descricao}

**DOCUMENTO:**

{$texto}
PROMPT;

        $response = $this->callOpenAIAPI($promptSumarizacao);

        return "**[RESUMO AUTOMÁTICO - Documento original: " . number_format(mb_strlen($texto)) . " caracteres]**\n\n" . $response;
    }

    /**
     * Chama API OpenAI com retry exponencial
     */
    private function callOpenAIAPI(string $prompt, array $options = []): string
    {
        $url = "{$this->apiUrl}/chat/completions";
        $attempt = 0;
        $lastException = null;

        $requestBody = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Você é um assistente jurídico especializado em análise de processos judiciais. Forneça análises objetivas, estruturadas e fundamentadas em linguagem clara.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 4096,
        ];

        // Merge com opções adicionais
        $requestBody = array_merge($requestBody, $options);

        while ($attempt < self::MAX_RETRIES_ON_RATE_LIMIT) {
            $attempt++;

            try {
                $response = Http::timeout(180)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $requestBody);

                // Rate limit (429)
                if ($response->status() === 429) {
                    if ($attempt < self::MAX_RETRIES_ON_RATE_LIMIT) {
                        $backoffMs = self::RATE_LIMIT_BACKOFF_BASE_MS * pow(2, $attempt - 1);

                        Log::warning("Rate limit atingido (429). Tentativa {$attempt}/" . self::MAX_RETRIES_ON_RATE_LIMIT . ". Aguardando {$backoffMs}ms", [
                            'attempt' => $attempt,
                            'backoff_ms' => $backoffMs
                        ]);

                        usleep($backoffMs * 1000);
                        continue;
                    }

                    throw new \Exception('Rate limit da API OpenAI excedido após ' . self::MAX_RETRIES_ON_RATE_LIMIT . ' tentativas. Aguarde alguns minutos e tente novamente.');
                }

                if (!$response->successful()) {
                    $statusCode = $response->status();
                    $errorBody = $response->json();
                    $errorMessage = $errorBody['error']['message'] ?? $errorBody['message'] ?? 'Erro desconhecido';

                    throw new \Exception($this->translateOpenAIError($statusCode, $errorMessage));
                }

                $data = $response->json();
                $text = $data['choices'][0]['message']['content'] ?? null;

                if (empty($text)) {
                    throw new \Exception('A API retornou uma resposta vazia.');
                }

                return $text;

            } catch (\Exception $e) {
                $lastException = $e;

                // Retry em erros de conexão
                if ($attempt < 3 && (
                    str_contains($e->getMessage(), 'timeout') ||
                    str_contains($e->getMessage(), 'Connection') ||
                    str_contains($e->getMessage(), 'cURL error')
                )) {
                    $retryDelay = 2000 * $attempt;
                    Log::warning("Erro de conexão. Tentativa {$attempt}/3. Aguardando {$retryDelay}ms", [
                        'error' => $e->getMessage()
                    ]);
                    usleep($retryDelay * 1000);
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException ?? new \Exception('Falha ao chamar API OpenAI após múltiplas tentativas');
    }

    /**
     * Traduz erros da API para mensagens amigáveis
     */
    private function translateOpenAIError(int $statusCode, string $technicalMessage): string
    {
        // Erro de saldo/quota
        if ($statusCode === 429) {
            if (str_contains(strtolower($technicalMessage), 'quota')) {
                return 'Limite de uso da API OpenAI excedido. Verifique seu plano ou aguarde o reset mensal.';
            }
            return 'Muitas requisições simultâneas. Aguarde alguns segundos e tente novamente.';
        }

        // Erros de autenticação
        if ($statusCode === 401) {
            return 'Chave de API OpenAI inválida. Verifique OPENAI_API_KEY no .env';
        }

        // Erro de conteúdo muito grande
        if ($statusCode === 400 && str_contains(strtolower($technicalMessage), 'maximum context length')) {
            return 'Documento muito grande para o modelo OpenAI. O sistema tentará sumarizar automaticamente.';
        }

        // Erro de servidor
        if ($statusCode >= 500) {
            return "Erro temporário no servidor OpenAI (código {$statusCode}). Tente novamente em alguns minutos.";
        }

        return "Erro na API OpenAI: " . substr($technicalMessage, 0, 150);
    }

    /**
     * Formata assuntos
     */
    private function formatAssuntos(array $assuntos): string
    {
        if (empty($assuntos)) {
            return 'Não informados';
        }

        $nomes = array_map(function($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }

    /**
     * Health check da API
     */
    public function healthCheck(): bool
    {
        try {
            $url = "{$this->apiUrl}/models";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->get($url);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Retorna nome do provider
     */
    public function getName(): string
    {
        return 'OpenAI';
    }
}
