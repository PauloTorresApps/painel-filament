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
     * Limites de tokens para pipeline de sumarização
     */
    protected const SINGLE_DOC_CHAR_LIMIT = 30000; // ~7.5k tokens
    protected const TOTAL_PROMPT_CHAR_LIMIT = 800000; // ~200k tokens

    /**
     * Configurações de rate limiting
     */
    protected const RATE_LIMIT_DELAY_MS = 2000; // 2 segundos entre chamadas
    protected const MAX_RETRIES_ON_RATE_LIMIT = 5;
    protected const RATE_LIMIT_BACKOFF_BASE_MS = 5000; // 5 segundos base para backoff

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

        // Tokens de raciocínio (DeepSeek, OpenAI o1, etc.)
        $reasoningTokens = $usage['completion_tokens_details']['reasoning_tokens']
            ?? $usage['reasoning_tokens']
            ?? 0;
        $this->lastAnalysisMetadata['total_reasoning_tokens'] += $reasoningTokens;

        // Registra o modelo usado (pode mudar durante a análise)
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
     * Analisa documentos do processo com contexto
     */
    public function analyzeDocuments(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled = true,
        ?DocumentAnalysis $documentAnalysis = null
    ): string {
        try {
            // Inicializa metadados da análise
            $this->resetAnalysisMetadata();

            Log::info('Iniciando análise com estratégia de Resumo Evolutivo', [
                'provider' => $this->getName(),
                'total_documentos' => \count($documentos),
                'total_chars_estimate' => array_sum(array_map(fn($d) => mb_strlen($d['texto'] ?? ''), $documentos)),
                'has_persistence' => $documentAnalysis !== null,
                'analysis_id' => $documentAnalysis?->id
            ]);

            $result = $this->applyEvolutiveSummarization($promptTemplate, $documentos, $contextoDados, $deepThinkingEnabled, $documentAnalysis);

            // Finaliza metadados
            $this->finalizeMetadata(\count($documentos));

            Log::info('Metadados da análise acumulados', [
                'provider' => $this->getName(),
                'metadata' => $this->lastAnalysisMetadata
            ]);

            return $result;

        } catch (\Exception $e) {
            // Finaliza metadados mesmo em caso de erro
            $this->finalizeMetadata(\count($documentos));
            $this->lastAnalysisMetadata['error'] = $e->getMessage();

            Log::error('Erro ao chamar ' . $this->getName() . ' API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Detecta se é uma análise de contrato ou parecer jurídico baseado no contexto
     */
    protected function isContractAnalysis(array $contextoDados): bool
    {
        if (!isset($contextoDados['tipo'])) {
            return false;
        }

        return \in_array($contextoDados['tipo'], ['Contrato', 'Parecer Jurídico']);
    }

    /**
     * Aplica estratégia de Resumo Evolutivo para manter contexto completo do processo
     *
     * Cada documento é analisado junto com o resumo acumulado dos anteriores,
     * criando uma narrativa evolutiva que preserva toda a história processual.
     */
    protected function applyEvolutiveSummarization(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled,
        ?DocumentAnalysis $documentAnalysis = null
    ): string {
        $isContract = $this->isContractAnalysis($contextoDados);

        // Extrai informações do contexto baseado no tipo
        if ($isContract) {
            $nomeClasse = 'Análise de Contrato';
            $assuntos = $contextoDados['arquivo'] ?? 'Contrato';
            $numeroProcesso = 'N/A';
            $tipoParte = $contextoDados['parte_interessada'] ?? 'Não informada';
        } else {
            $nomeClasse = $contextoDados['classeProcessualNome'] ?? $contextoDados['classeProcessual'] ?? 'Não informada';
            $assuntos = $this->formatAssuntos($contextoDados['assunto'] ?? []);
            $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';
            $tipoParte = $this->identificarTipoParte($contextoDados);
        }

        // Contexto inicial que será mantido em todas as iterações
        $contextoBase = $this->buildContextoBase($isContract, $nomeClasse, $assuntos, $numeroProcesso, $tipoParte, $contextoDados);

        $totalDocumentos = \count($documentos);

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
            // Pula documentos já processados (retomada)
            if ($index < $startIndex) {
                continue;
            }
            $documentosProcessados++;
            $docNum = $index + 1;
            $descricao = $doc['descricao'] ?? "Documento {$docNum}";
            $texto = $doc['texto'] ?? '';
            $charCount = mb_strlen($texto);

            Log::info("Processando documento {$docNum}/{$totalDocumentos}: {$descricao}", [
                'chars' => $charCount,
                'has_previous_summary' => !empty($resumoEvolutivo)
            ]);

            // Sumariza documento individualmente se muito grande
            if ($charCount > static::SINGLE_DOC_CHAR_LIMIT) {
                Log::info("Documento {$docNum} excede limite individual. Sumarizando antes de incorporar.");

                // Rate limiting
                if ($documentosProcessados > 1) {
                    usleep(static::RATE_LIMIT_DELAY_MS * 1000);
                }

                $texto = $this->summarizeDocument($texto, $descricao, $deepThinkingEnabled);
            }

            // Monta prompt evolutivo
            $promptEvolutivo = $this->buildEvolutionaryPrompt(
                $promptTemplate,
                $resumoEvolutivo,
                $texto,
                $descricao,
                $docNum,
                $totalDocumentos,
                $contextoBase,
                $isContract
            );

            // Rate limiting entre chamadas
            if ($documentosProcessados > 1) {
                usleep(static::RATE_LIMIT_DELAY_MS * 1000);
            }

            // Faz chamada à API
            try {
                $response = $this->callAPI($promptEvolutivo, $deepThinkingEnabled);

                if ($docNum < $totalDocumentos) {
                    // Atualiza resumo evolutivo para próxima iteração
                    $resumoEvolutivo = $response;

                    // PERSISTE o estado no banco de dados
                    if ($documentAnalysis) {
                        $documentAnalysis->updateEvolutionaryState($index, $resumoEvolutivo);

                        Log::info("Estado evolutivo persistido após documento {$docNum}", [
                            'analysis_id' => $documentAnalysis->id,
                            'resumo_chars' => mb_strlen($resumoEvolutivo),
                            'progresso' => $documentAnalysis->getProgressPercentage() . '%'
                        ]);
                    } else {
                        Log::info("Resumo evolutivo atualizado após documento {$docNum}", [
                            'resumo_chars' => mb_strlen($resumoEvolutivo)
                        ]);
                    }
                } else {
                    // Último documento: retorna análise final
                    Log::info('Análise final gerada com sucesso via Resumo Evolutivo', [
                        'total_documentos_processados' => $documentosProcessados,
                        'analysis_id' => $documentAnalysis?->id
                    ]);

                    return $response;
                }

            } catch (\Exception $e) {
                Log::error("Erro ao processar documento {$docNum} no Resumo Evolutivo", [
                    'descricao' => $descricao,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception("Falha no Resumo Evolutivo no documento {$docNum} ({$descricao}): " . $e->getMessage());
            }
        }

        // Fallback: se chegou aqui sem retornar, algo deu errado
        throw new \Exception('Erro inesperado no Resumo Evolutivo: nenhuma análise final foi gerada');
    }

    /**
     * Constrói o contexto base para análise
     */
    protected function buildContextoBase(
        bool $isContract,
        string $nomeClasse,
        string $assuntos,
        string $numeroProcesso,
        string $tipoParte,
        array $contextoDados
    ): string {
        if ($isContract) {
            $contextoBase = "# CONTEXTO DA ANÁLISE DE CONTRATO\n\n";
            $contextoBase .= "**Tipo:** Análise de Contrato\n";
            $contextoBase .= "**Arquivo:** {$assuntos}\n";
            if (!empty($tipoParte) && $tipoParte !== 'Não informada') {
                $contextoBase .= "**Parte Interessada:** {$tipoParte}\n";
            }
        } else {
            $contextoBase = "# CONTEXTO DO PROCESSO\n\n";
            $contextoBase .= "**Classe Processual:** {$nomeClasse}\n";
            $contextoBase .= "**Assuntos:** {$assuntos}\n";
            $contextoBase .= "**Você está analisando como:** {$tipoParte}\n";
            $contextoBase .= "**Número do Processo:** {$numeroProcesso}\n";

            if (!empty($contextoDados['valorCausa'])) {
                $contextoBase .= "**Valor da Causa:** R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
            }
        }

        $contextoBase .= "\n---\n\n";

        return $contextoBase;
    }

    /**
     * Monta o prompt evolutivo para análise de um documento
     */
    protected function buildEvolutionaryPrompt(
        string $promptTemplate,
        string $resumoAnterior,
        string $textoDocumento,
        string $descricaoDocumento,
        int $docNum,
        int $totalDocs,
        string $contextoBase,
        bool $isContract
    ): string {
        $promptEvolutivo = $contextoBase;

        // Se há resumo anterior, inclui como contexto
        if (!empty($resumoAnterior)) {
            $promptEvolutivo .= "## RESUMO DOS DOCUMENTOS ANTERIORES\n\n";
            $promptEvolutivo .= $resumoAnterior . "\n\n";
            $promptEvolutivo .= "---\n\n";
        }

        // Adiciona documento atual
        $promptEvolutivo .= "## NOVO DOCUMENTO PARA INCORPORAR\n\n";
        $promptEvolutivo .= "### DOCUMENTO {$docNum}: {$descricaoDocumento}\n\n";
        $promptEvolutivo .= $textoDocumento . "\n\n";
        $promptEvolutivo .= "---\n\n";

        // Instruções para resumo evolutivo
        if ($docNum < $totalDocs) {
            // Ainda há mais documentos: gera resumo evolutivo
            $promptEvolutivo .= "## TAREFA\n\n";
            $promptEvolutivo .= "Atualize o resumo do processo incorporando o novo documento acima.\n\n";
            $promptEvolutivo .= "**IMPORTANTE:**\n";
            $promptEvolutivo .= "1. Mantenha a ordem cronológica dos eventos\n";
            $promptEvolutivo .= "2. Destaque conexões causais (ex: 'decisão X baseada na petição Y')\n";
            $promptEvolutivo .= "3. Preserve informações relevantes dos documentos anteriores\n";
            $promptEvolutivo .= "4. Adicione informações importantes do novo documento\n";
            $promptEvolutivo .= "5. Mantenha o resumo conciso mas completo (máximo 4-5 parágrafos)\n";
            $promptEvolutivo .= "6. Use markdown para estruturar\n\n";
            $promptEvolutivo .= "**FORMATO:** Retorne apenas o resumo atualizado, sem comentários adicionais.";
        } else {
            // Último documento: gera análise final conforme solicitado pelo usuário
            $promptEvolutivo .= "## TAREFA FINAL\n\n";
            $promptEvolutivo .= $promptTemplate . "\n\n";
            $promptEvolutivo .= "**INSTRUÇÕES:**\n";
            $promptEvolutivo .= "1. Considere TODOS os documentos analisados até agora\n";
            $promptEvolutivo .= "2. Incorpore o último documento apresentado acima\n";
            $promptEvolutivo .= "3. Forneça a análise completa conforme solicitado\n";
            $promptEvolutivo .= "4. Mantenha a perspectiva cronológica e causal dos eventos\n";
        }

        return $promptEvolutivo;
    }

    /**
     * Sumariza um documento individual preservando informações jurídicas essenciais
     */
    protected function summarizeDocument(string $documentText, string $descricao, bool $deepThinkingEnabled = false): string
    {
        $promptSumarizacao = <<<PROMPT
Você é um assistente jurídico especializado. Resuma o documento abaixo em 2-3 parágrafos concisos, destacando:

1. **Tipo de manifestação** (petição inicial, contestação, decisão, despacho, sentença, recurso, etc.)
2. **Partes envolvidas** (autor, réu, terceiros)
3. **Pedidos ou decisões principais**
4. **Fundamentos legais citados** (artigos de lei, jurisprudência)
5. **Fatos relevantes** que conectem este documento aos demais do processo
6. **Datas importantes** mencionadas

**IMPORTANTE:** Preserve informações que ajudem a entender a sequência cronológica e relações com outros documentos do processo.

**Descrição do documento:** {$descricao}

**DOCUMENTO:**

{$documentText}
PROMPT;

        $response = $this->callAPI($promptSumarizacao, $deepThinkingEnabled);

        // Adiciona cabeçalho indicando que é um resumo
        return "**[RESUMO AUTOMÁTICO - Documento original: " . number_format(mb_strlen($documentText)) . " caracteres]**\n\n" . $response;
    }

    /**
     * Formata array de assuntos para string legível
     */
    protected function formatAssuntos(array $assuntos): string
    {
        if (empty($assuntos)) {
            return 'Não informados';
        }

        $nomes = array_map(function($assunto) {
            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? $assunto['codigoNacional']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }

    /**
     * Trunca documentos muito grandes para evitar exceder limite de tokens da API
     */
    protected function truncateDocument(string $texto): string
    {
        $maxChars = 15000; // ~3.750 tokens

        if (mb_strlen($texto) <= $maxChars) {
            return $texto;
        }

        // Para documentos muito grandes, pega 70% do início e 30% do fim
        $inicioChars = (int) ($maxChars * 0.7);
        $fimChars = (int) ($maxChars * 0.3);

        $inicio = mb_substr($texto, 0, $inicioChars);
        $fim = mb_substr($texto, -$fimChars);

        $caracteresOmitidos = mb_strlen($texto) - $maxChars;

        return $inicio .
               "\n\n[... DOCUMENTO TRUNCADO - {$caracteresOmitidos} caracteres omitidos da parte central ...]\n\n" .
               $fim;
    }

    /**
     * Identifica o tipo de parte do usuário que está consultando o processo
     * Retorna uma descrição amigável do polo/tipo de parte
     *
     * IMPORTANTE: Esta função é específica para processos judiciais.
     * Para contratos e pareceres jurídicos, use o campo 'parte_interessada' do contexto.
     */
    protected function identificarTipoParte(array $contextoDados): string
    {
        // Para contratos e pareceres jurídicos, usa a parte interessada informada
        if ($this->isContractAnalysis($contextoDados)) {
            return $contextoDados['parte_interessada'] ?? 'Não informada';
        }

        // Verifica se há informações de partes disponíveis (processos judiciais)
        if (empty($contextoDados['parte']) || !\is_array($contextoDados['parte'])) {
            return 'Órgão do Ministério Público';
        }

        $partes = $contextoDados['parte'];

        // Procura por partes do MP (Ministério Público)
        foreach ($partes as $parte) {
            $polo = strtoupper($parte['polo'] ?? '');
            $tipoPessoa = strtoupper($parte['tipoPessoa'] ?? '');
            $nome = strtoupper($parte['nomeCompleto'] ?? $parte['nome'] ?? '');

            // Identifica se é Ministério Público
            if (str_contains($nome, 'MINISTÉRIO PÚBLICO') ||
                str_contains($nome, 'MINISTERIO PUBLICO') ||
                str_contains($nome, 'MP') ||
                $tipoPessoa === 'MP') {

                // Determina o polo
                if ($polo === 'AT' || $polo === 'ATIVO') {
                    return 'Ministério Público (Polo Ativo - Autor)';
                } elseif ($polo === 'PA' || $polo === 'PASSIVO') {
                    return 'Ministério Público (Polo Passivo - Réu)';
                } elseif ($polo === 'TR' || $polo === 'TERCEIRO') {
                    return 'Ministério Público (Terceiro Interessado)';
                } else {
                    return 'Ministério Público';
                }
            }
        }

        // Se não encontrou MP especificamente, retorna genérico (apenas para processos judiciais)
        return 'Órgão do Ministério Público';
    }

    /**
     * Calcula delay para exponential backoff
     */
    protected function calculateBackoff(int $attempt): int
    {
        return static::RATE_LIMIT_BACKOFF_BASE_MS * (int) pow(2, $attempt - 1);
    }
}
