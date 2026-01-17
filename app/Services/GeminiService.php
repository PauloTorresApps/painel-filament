<?php

namespace App\Services;

use App\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements AIProviderInterface
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->apiUrl = config('services.gemini.api_url');
        $this->model = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($this->apiKey)) {
            throw new \Exception('GEMINI_API_KEY não configurado no .env');
        }
    }

    /**
     * Limites de tokens para pipeline de sumarização
     */
    private const SINGLE_DOC_CHAR_LIMIT = 30000; // ~7.5k tokens
    private const TOTAL_PROMPT_CHAR_LIMIT = 800000; // ~200k tokens (80% do limite do Gemini 1.5)

    /**
     * Configurações de rate limiting
     */
    private const RATE_LIMIT_DELAY_MS = 2000; // 2 segundos entre chamadas de sumarização
    private const MAX_RETRIES_ON_RATE_LIMIT = 5; // Máximo de tentativas em caso de 429
    private const RATE_LIMIT_BACKOFF_BASE_MS = 5000; // Base para exponential backoff (5s)

    /**
     * Analisa documentos do processo com contexto
     *
     * @param string $promptTemplate Prompt do usuário
     * @param array $documentos Array de documentos com texto extraído
     * @param array $contextoDados Dados do processo (classe, assuntos, etc)
     * @param bool $deepThinkingEnabled Ignorado no Gemini (compatibilidade com interface)
     * @param \App\Models\DocumentAnalysis|null $documentAnalysis Model para persistir estado (opcional)
     * @return string Análise gerada pela IA
     */
    public function analyzeDocuments(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled = true,
        ?\App\Models\DocumentAnalysis $documentAnalysis = null
    ): string {
        try {
            // SEMPRE usa estratégia de Resumo Evolutivo para manter contexto completo
            // independente do tamanho total dos documentos
            Log::info('Iniciando análise com estratégia de Resumo Evolutivo', [
                'total_documentos' => count($documentos),
                'total_chars_estimate' => array_sum(array_map(fn($d) => mb_strlen($d['texto'] ?? ''), $documentos)),
                'has_persistence' => $documentAnalysis !== null,
                'analysis_id' => $documentAnalysis?->id
            ]);

            return $this->applyEvolutiveSummarization($promptTemplate, $documentos, $contextoDados, $documentAnalysis);

        } catch (\Exception $e) {
            Log::error('Erro ao chamar Gemini API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Detecta se é uma análise de contrato baseado no contexto
     */
    private function isContractAnalysis(array $contextoDados): bool
    {
        return isset($contextoDados['tipo']) && $contextoDados['tipo'] === 'Contrato';
    }

    /**
     * Aplica estratégia de Resumo Evolutivo para manter contexto completo do processo
     *
     * Cada documento é analisado junto com o resumo acumulado dos anteriores,
     * criando uma narrativa evolutiva que preserva toda a história processual.
     *
     * @param string $promptTemplate Template do prompt do usuário
     * @param array $documentos Array de documentos originais
     * @param array $contextoDados Contexto do processo
     * @param \App\Models\DocumentAnalysis|null $documentAnalysis Model para persistir estado (opcional)
     * @return string Análise final completa
     */
    private function applyEvolutiveSummarization(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        ?\App\Models\DocumentAnalysis $documentAnalysis = null
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
            if ($charCount > self::SINGLE_DOC_CHAR_LIMIT) {
                Log::info("Documento {$docNum} excede limite individual. Sumarizando antes de incorporar.");

                // Rate limiting
                if ($documentosProcessados > 1) {
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                }

                $texto = $this->summarizeDocument($texto, $descricao);
            }

            // Monta prompt evolutivo
            $promptEvolutivo = $contextoBase;

            // Se há resumo anterior, inclui como contexto
            if (!empty($resumoEvolutivo)) {
                $promptEvolutivo .= "## RESUMO DOS DOCUMENTOS ANTERIORES\n\n";
                $promptEvolutivo .= $resumoEvolutivo . "\n\n";
                $promptEvolutivo .= "---\n\n";
            }

            // Adiciona documento atual
            $promptEvolutivo .= "## NOVO DOCUMENTO PARA INCORPORAR\n\n";
            $promptEvolutivo .= "### DOCUMENTO {$docNum}: {$descricao}\n\n";
            $promptEvolutivo .= $texto . "\n\n";
            $promptEvolutivo .= "---\n\n";

            // Instruções para resumo evolutivo
            if ($docNum < $totalDocumentos) {
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

            // Rate limiting entre chamadas
            if ($documentosProcessados > 1) {
                usleep(self::RATE_LIMIT_DELAY_MS * 1000);
            }

            // Faz chamada à API
            try {
                $response = $this->callGeminiAPI($promptEvolutivo);

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
     * Aplica pipeline de sumarização hierárquica para documentos muito grandes
     * Mantém contexto sequencial preservando todos os docs na mesma chamada
     *
     * @param array $documentos Array de documentos originais
     * @return array Array de documentos processados (alguns podem estar sumarizados)
     */
    private function applyHierarchicalSummarization(array $documentos): array
    {
        $processedDocs = [];
        $summarizationCount = 0;

        foreach ($documentos as $index => $doc) {
            $texto = $doc['texto'] ?? '';
            $charCount = mb_strlen($texto);

            // Se o documento excede o limite individual, sumariza
            if ($charCount > self::SINGLE_DOC_CHAR_LIMIT) {
                Log::info("Documento {$index} muito grande ({$charCount} caracteres). Aplicando sumarização.", [
                    'descricao' => $doc['descricao'] ?? "Documento " . ($index + 1)
                ]);

                // Rate limiting: aguarda entre chamadas de sumarização
                if ($summarizationCount > 0) {
                    $delayMs = self::RATE_LIMIT_DELAY_MS;
                    Log::debug("Aguardando {$delayMs}ms antes da próxima sumarização (rate limiting)");
                    usleep($delayMs * 1000); // usleep usa microssegundos
                }

                try {
                    $summary = $this->summarizeDocument($texto, $doc['descricao'] ?? "Documento " . ($index + 1));
                    $summarizationCount++;

                    // Marca o documento como sumarizado
                    $processedDocs[] = [
                        'descricao' => $doc['descricao'],
                        'texto' => $summary,
                        'is_summarized' => true,
                        'original_char_count' => $charCount
                    ];

                    Log::info("Documento {$index} sumarizado com sucesso.", [
                        'chars_original' => $charCount,
                        'chars_resumo' => mb_strlen($summary),
                        'reducao_percentual' => round((1 - mb_strlen($summary) / $charCount) * 100, 2) . '%'
                    ]);

                } catch (\Exception $e) {
                    Log::warning("Falha ao sumarizar documento {$index}. Usando truncamento tradicional.", [
                        'error' => $e->getMessage()
                    ]);

                    // Fallback: usa truncamento tradicional
                    $processedDocs[] = [
                        'descricao' => $doc['descricao'],
                        'texto' => $this->truncateDocument($texto),
                        'is_summarized' => false
                    ];
                }
            } else {
                // Documento dentro do limite: mantém original
                $processedDocs[] = $doc;
            }
        }

        return $processedDocs;
    }

    /**
     * Sumariza um documento individual preservando informações jurídicas essenciais
     *
     * @param string $documentText Texto completo do documento
     * @param string $descricao Descrição do documento para contexto
     * @return string Resumo estruturado do documento
     */
    private function summarizeDocument(string $documentText, string $descricao): string
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

        $response = $this->callGeminiAPI($promptSumarizacao);

        // Adiciona cabeçalho indicando que é um resumo
        return "**[RESUMO AUTOMÁTICO - Documento original: " . number_format(mb_strlen($documentText)) . " caracteres]**\n\n" . $response;
    }

    /**
     * Estratégia de fallback: divide documentos em lotes sequenciais quando prompt total é muito grande
     * Cada lote é analisado separadamente e depois sintetizado mantendo contexto cronológico
     *
     * @param string $promptTemplate Template do prompt do usuário
     * @param array $documentos Array de documentos (já sumarizados se necessário)
     * @param array $contextoDados Contexto do processo
     * @return string Análise sintetizada de todos os lotes
     */
    private function analyzeBatches(string $promptTemplate, array $documentos, array $contextoDados): string
    {
        $batchSize = 5; // Processa 5 documentos por vez
        $batches = array_chunk($documentos, $batchSize, true);
        $batchAnalyses = [];

        Log::info('Iniciando análise em lotes', [
            'total_documentos' => \count($documentos),
            'num_lotes' => \count($batches),
            'docs_por_lote' => $batchSize
        ]);

        // Analisa cada lote separadamente
        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            $firstDocNum = $batchIndex * $batchSize + 1;
            $lastDocNum = $firstDocNum + \count($batch) - 1;

            Log::info("Processando lote {$batchNum}/" . \count($batches), [
                'documentos' => "{$firstDocNum}-{$lastDocNum}"
            ]);

            // Adiciona contexto do lote anterior (se existir)
            $promptComContexto = $promptTemplate;
            if (!empty($batchAnalyses)) {
                $resumoAnterior = end($batchAnalyses);
                $promptComContexto = "**CONTEXTO DOS DOCUMENTOS ANTERIORES:**\n\n{$resumoAnterior}\n\n---\n\n" . $promptTemplate;
            }

            // Monta prompt para este lote
            $batchPrompt = $this->buildPrompt($promptComContexto, $batch, $contextoDados);

            // Analisa o lote
            try {
                $batchAnalysis = $this->callGeminiAPI($batchPrompt);
                $batchAnalyses[] = $batchAnalysis;

                Log::info("Lote {$batchNum} processado com sucesso");
            } catch (\Exception $e) {
                Log::error("Erro ao processar lote {$batchNum}", [
                    'error' => $e->getMessage()
                ]);
                throw new \Exception("Falha ao processar lote {$batchNum} de documentos: " . $e->getMessage());
            }
        }

        // Sintetiza todas as análises mantendo cronologia
        if (\count($batchAnalyses) === 1) {
            return $batchAnalyses[0];
        }

        return $this->synthesizeBatchAnalyses($batchAnalyses, $contextoDados);
    }

    /**
     * Sintetiza múltiplas análises de lotes em uma narrativa coerente
     *
     * @param array $batchAnalyses Array de análises de cada lote
     * @param array $contextoDados Contexto do processo
     * @return string Análise final sintetizada
     */
    private function synthesizeBatchAnalyses(array $batchAnalyses, array $contextoDados): string
    {
        $isContract = $this->isContractAnalysis($contextoDados);

        if ($isContract) {
            $nomeClasse = 'Análise de Contrato';
            $numeroProcesso = $contextoDados['arquivo'] ?? 'Contrato';

            $promptSintese = <<<PROMPT
Você é um assistente jurídico especializado. Você recebeu análises parciais de um contrato que foi dividido em lotes devido ao volume do documento.

**ARQUIVO:** {$numeroProcesso}
**TIPO:** {$nomeClasse}

**SUA TAREFA:** Sintetize as análises abaixo em UMA ÚNICA narrativa coerente que conte a análise completa do contrato, preservando:
- Todas as cláusulas importantes identificadas
- Os riscos e pontos de atenção
- As recomendações de cada lote
- A perspectiva da parte interessada

**ANÁLISES PARCIAIS:**

PROMPT;
        } else {
            $nomeClasse = $contextoDados['classeProcessualNome'] ?? $contextoDados['classeProcessual'] ?? 'Não informada';
            $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';

            $promptSintese = <<<PROMPT
Você é um assistente jurídico especializado. Você recebeu análises parciais de um processo judicial que foi dividido em lotes devido ao volume de documentos.

**PROCESSO:** {$numeroProcesso}
**CLASSE:** {$nomeClasse}

**SUA TAREFA:** Sintetize as análises abaixo em UMA ÚNICA narrativa coerente que conte a história completa do processo, preservando:
- A ordem cronológica dos eventos
- Conexões entre os documentos (petição → decisão → recurso)
- Todos os pontos relevantes de cada lote
- A perspectiva solicitada pelo usuário

**ANÁLISES PARCIAIS (EM ORDEM CRONOLÓGICA):**

PROMPT;
        }

        foreach ($batchAnalyses as $index => $analysis) {
            $loteNum = $index + 1;
            $promptSintese .= "\n\n### LOTE {$loteNum}:\n\n{$analysis}\n\n---";
        }

        $promptSintese .= <<<PROMPT


**INSTRUÇÕES:**
1. Crie uma narrativa única e fluida (não liste lote por lote)
2. Mantenha a ordem cronológica dos eventos
3. Destaque as conexões causais entre documentos
4. Preserve informações importantes de todos os lotes
5. Use markdown para estruturar a análise final

PROMPT;

        Log::info('Sintetizando análises de lotes', [
            'num_lotes' => \count($batchAnalyses)
        ]);

        $synthesis = $this->callGeminiAPI($promptSintese);

        // Adiciona nota sobre processamento em lotes
        $nota = "\n\n---\n\n*Nota: Devido ao grande volume de documentos, esta análise foi processada em " . \count($batchAnalyses) . " lotes sequenciais para preservar todas as informações.*";

        return $synthesis . $nota;
    }

    /**
     * Monta o prompt com todo o contexto necessário
     */
    private function buildPrompt(string $template, array $documentos, array $contextoDados): string
    {
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

        // Substitui variáveis no template (se existirem)
        $prompt = str_replace(
            ['[nomeClasse]', '[assuntos]', '[numeroProcesso]', '[tipoParte]', '[parteInteressada]'],
            [$nomeClasse, $assuntos, $numeroProcesso, $tipoParte, $tipoParte],
            $template
        );

        // CONTEXTO INICIAL - Informações essenciais para orientar a análise
        if ($isContract) {
            $contextoInicial = "# CONTEXTO DA ANÁLISE DE CONTRATO\n\n";
            $contextoInicial .= "**Tipo:** Análise de Contrato\n";
            $contextoInicial .= "**Arquivo:** {$assuntos}\n";
            if (!empty($tipoParte) && $tipoParte !== 'Não informada') {
                $contextoInicial .= "**Parte Interessada:** {$tipoParte}\n";
            }
        } else {
            $contextoInicial = "# CONTEXTO DO PROCESSO\n\n";
            $contextoInicial .= "**Classe Processual:** {$nomeClasse}\n";
            $contextoInicial .= "**Assuntos:** {$assuntos}\n";
            $contextoInicial .= "**Você está analisando como:** {$tipoParte}\n";
            $contextoInicial .= "**Número do Processo:** {$numeroProcesso}\n";

            if (!empty($contextoDados['valorCausa'])) {
                $contextoInicial .= "**Valor da Causa:** R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
            }
        }

        $contextoInicial .= "\n---\n\n";

        // Adiciona o contexto inicial ANTES do prompt do usuário
        $prompt = $contextoInicial . $prompt;

        // Adiciona informações complementares (apenas para processos)
        if (!$isContract) {
            $prompt .= "\n\n## INFORMAÇÕES COMPLEMENTARES DO PROCESSO\n";
            $prompt .= "Número: {$numeroProcesso}\n";
            $prompt .= "Classe: {$nomeClasse}\n";
            $prompt .= "Assuntos: {$assuntos}\n";
            $prompt .= "Perspectiva de análise: {$tipoParte}\n";

            if (!empty($contextoDados['valorCausa'])) {
                $prompt .= "Valor da Causa: R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
            }
        }

        // Adiciona documentos
        $prompt .= "\n## " . ($isContract ? "CONTRATO" : "DOCUMENTOS") . " PARA ANÁLISE\n\n";

        foreach ($documentos as $index => $doc) {
            $docNum = $index + 1;
            $descricao = $doc['descricao'] ?? "Documento {$docNum}";
            $texto = $doc['texto'] ?? '';
            $isSummarized = $doc['is_summarized'] ?? false;

            // Se já foi sumarizado, usa direto; senão, aplica truncamento tradicional
            $textoFinal = $isSummarized ? $texto : $this->truncateDocument($texto);

            $prompt .= "### DOCUMENTO {$docNum}: {$descricao}\n\n";
            $prompt .= $textoFinal . "\n\n";
            $prompt .= "---\n\n";
        }

        // Instruções finais (apenas se não for contrato - contrato já tem instruções no template)
        if (!$isContract) {
            $prompt .= "\n## INSTRUÇÕES\n";
            $prompt .= "Por favor, analise cada documento acima considerando o contexto processual fornecido. ";
            $prompt .= "Retorne uma análise estruturada e objetiva, destacando pontos relevantes de cada manifestação.";
        }

        return $prompt;
    }

    /**
     * Formata array de assuntos para string legível
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
                ?? $assunto['codigoNacional']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }

    /**
     * Trunca documentos muito grandes para evitar exceder limite de tokens da API
     * Limita cada documento a aproximadamente 15.000 caracteres (~3.750 tokens)
     * mantendo início e fim do documento para contexto
     */
    private function truncateDocument(string $texto): string
    {
        $maxChars = 15000; // ~3.750 tokens (assumindo ~4 chars por token)

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
     */
    private function identificarTipoParte(array $contextoDados): string
    {
        // Verifica se há informações de partes disponíveis
        if (empty($contextoDados['parte']) || !is_array($contextoDados['parte'])) {
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

        // Se não encontrou MP especificamente, retorna genérico
        return 'Órgão do Ministério Público';
    }

    /**
     * Faz a chamada HTTP para a API do Gemini com exponential backoff para rate limiting
     */
    private function callGeminiAPI(string $prompt): string
    {
        // Aplica rate limiting antes da chamada
        RateLimiterService::apply('gemini');

        $url = "{$this->apiUrl}/{$this->model}:generateContent?key={$this->apiKey}";
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES_ON_RATE_LIMIT) {
            $attempt++;

            try {
                $response = Http::timeout(300) // 5 minutos de timeout para análises jurídicas longas
                    ->post($url, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt]
                                ]
                            ]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4, // Mais determinístico para análises jurídicas
                            'topK' => 32,
                            'topP' => 0.95,
                            'maxOutputTokens' => 8192, // Permite respostas longas
                        ],
                        'safetySettings' => [
                            [
                                'category' => 'HARM_CATEGORY_HARASSMENT',
                                'threshold' => 'BLOCK_NONE'
                            ],
                            [
                                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                                'threshold' => 'BLOCK_NONE'
                            ],
                            [
                                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                                'threshold' => 'BLOCK_NONE'
                            ],
                            [
                                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                                'threshold' => 'BLOCK_NONE'
                            ]
                        ]
                    ]);

                // Se recebeu 429 (rate limit), aplica exponential backoff
                if ($response->status() === 429) {
                    if ($attempt < self::MAX_RETRIES_ON_RATE_LIMIT) {
                        // Calcula delay exponencial: base * 2^(attempt-1)
                        $backoffMs = self::RATE_LIMIT_BACKOFF_BASE_MS * pow(2, $attempt - 1);

                        Log::warning("Rate limit atingido (429). Tentativa {$attempt}/" . self::MAX_RETRIES_ON_RATE_LIMIT . ". Aguardando {$backoffMs}ms", [
                            'attempt' => $attempt,
                            'backoff_ms' => $backoffMs
                        ]);

                        usleep($backoffMs * 1000); // Converte para microssegundos
                        continue; // Tenta novamente
                    }

                    // Esgotou tentativas
                    throw new \Exception('Rate limit da API Gemini excedido após ' . self::MAX_RETRIES_ON_RATE_LIMIT . ' tentativas. Aguarde alguns minutos e tente novamente.');
                }

                if (!$response->successful()) {
                    $statusCode = $response->status();
                    $errorBody = $response->json();
                    $errorMessage = $errorBody['error']['message'] ?? 'Erro desconhecido';

                    // Traduz erros comuns da API para mensagens amigáveis
                    $friendlyMessage = $this->translateGeminiError($statusCode, $errorMessage);

                    throw new \Exception($friendlyMessage);
                }

                $data = $response->json();

                // Extrai o texto da resposta
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if (empty($text)) {
                    throw new \Exception('A API retornou uma resposta vazia. Tente novamente em alguns instantes.');
                }

                return $text;

            } catch (\Exception $e) {
                $lastException = $e;

                // Se for erro de conexão ou timeout, tenta novamente com backoff menor
                if ($attempt < 3 && (
                    str_contains($e->getMessage(), 'timeout') ||
                    str_contains($e->getMessage(), 'Connection') ||
                    str_contains($e->getMessage(), 'cURL error')
                )) {
                    $retryDelay = 2000 * $attempt; // 2s, 4s, 6s
                    Log::warning("Erro de conexão. Tentativa {$attempt}/3. Aguardando {$retryDelay}ms", [
                        'error' => $e->getMessage()
                    ]);
                    usleep($retryDelay * 1000);
                    continue;
                }

                // Outros erros: não tenta novamente
                throw $e;
            }
        }

        // Se chegou aqui, esgotou todas as tentativas
        throw $lastException ?? new \Exception('Falha ao chamar API Gemini após múltiplas tentativas');
    }

    /**
     * Traduz erros técnicos da API Gemini para mensagens amigáveis
     */
    private function translateGeminiError(int $statusCode, string $technicalMessage): string
    {
        // Erros de quota/rate limit
        if ($statusCode === 429) {
            if (str_contains(strtolower($technicalMessage), 'quota')) {
                return 'Limite de uso da API de IA excedido. Por favor, verifique seu plano no Google AI Studio ou aguarde até amanhã para novas análises.';
            }
            return 'Muitas requisições simultâneas. Por favor, aguarde alguns segundos e tente novamente.';
        }

        // Erros de autenticação
        if ($statusCode === 401 || $statusCode === 403) {
            return 'Chave de API inválida ou sem permissões. Verifique a configuração GEMINI_API_KEY no arquivo .env';
        }

        // Erros de tamanho de conteúdo
        if ($statusCode === 413 || str_contains(strtolower($technicalMessage), 'too large')) {
            return 'O documento é muito grande para ser processado. Tente enviar menos documentos por vez.';
        }

        // Timeout
        if ($statusCode === 504 || str_contains(strtolower($technicalMessage), 'timeout')) {
            return 'A análise demorou muito tempo. Tente novamente com documentos menores.';
        }

        // Erro de conteúdo bloqueado por safety
        if (str_contains(strtolower($technicalMessage), 'safety') || str_contains(strtolower($technicalMessage), 'blocked')) {
            return 'O conteúdo foi bloqueado pelos filtros de segurança da API. Tente com outros documentos.';
        }

        // Erro genérico do servidor
        if ($statusCode >= 500) {
            return "Erro temporário no servidor da Google AI (código {$statusCode}). Tente novamente em alguns minutos.";
        }

        // Erro não mapeado - retorna mensagem técnica simplificada
        return "Erro na API de IA: " . substr($technicalMessage, 0, 150);
    }

    /**
     * Calcula custo estimado de uma requisição
     * Baseado no pricing do Gemini (valores aproximados)
     */
    public function estimateCost(int $inputTokens, int $outputTokens = 2000): float
    {
        // Preços aproximados (USD por 1M tokens) - atualizar conforme pricing oficial
        $pricePerMillionInput = $this->model === 'gemini-1.5-pro' ? 3.50 : 0.075; // Flash é bem mais barato
        $pricePerMillionOutput = $this->model === 'gemini-1.5-pro' ? 10.50 : 0.30;

        $inputCost = ($inputTokens / 1000000) * $pricePerMillionInput;
        $outputCost = ($outputTokens / 1000000) * $pricePerMillionOutput;

        return $inputCost + $outputCost;
    }

    /**
     * Valida se a API está acessível
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->callGeminiAPI('Hello');
            return !empty($response);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Retorna o nome do provider
     */
    public function getName(): string
    {
        return 'Google Gemini';
    }
}
