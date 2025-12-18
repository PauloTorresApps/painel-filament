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
    private string $fileApiUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->apiUrl = config('services.gemini.api_url');
        $this->fileApiUrl = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
        $this->model = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($this->apiKey)) {
            throw new \Exception('GEMINI_API_KEY não configurado no .env');
        }
    }

    /**
     * Analisa documentos do processo com contexto
     *
     * @param string $promptTemplate Prompt do usuário
     * @param array $documentos Array de documentos com texto extraído ou PDFs base64
     * @param array $contextoDados Dados do processo (classe, assuntos, etc)
     * @param bool $deepThinkingEnabled Ignorado no Gemini (compatibilidade com interface)
     * @return string Análise gerada pela IA
     */
    public function analyzeDocuments(string $promptTemplate, array $documentos, array $contextoDados, bool $deepThinkingEnabled = true): string
    {
        try {
            // Detecta se documentos têm PDFs (conteúdo base64)
            $usarFileAPI = $this->shouldUseFileAPI($documentos);

            if ($usarFileAPI) {
                // Usa File API para documentos com PDF
                Log::info('Usando Gemini File API para análise de documentos');
                return $this->analyzeWithFileAPI($promptTemplate, $documentos, $contextoDados);
            } else {
                // Usa método tradicional (texto extraído)
                Log::info('Usando método tradicional (texto) para análise');
                $prompt = $this->buildPrompt($promptTemplate, $documentos, $contextoDados);
                return $this->callGeminiAPI($prompt);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao chamar Gemini API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Verifica se deve usar File API (se documentos têm PDF base64)
     */
    private function shouldUseFileAPI(array $documentos): bool
    {
        foreach ($documentos as $doc) {
            if (isset($doc['pdf_base64']) && !empty($doc['pdf_base64'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Analisa documentos usando Gemini File API
     */
    private function analyzeWithFileAPI(string $promptTemplate, array $documentos, array $contextoDados): string
    {
        $uploadedFiles = [];
        $tempFiles = [];

        try {
            // 1. Upload dos PDFs para Gemini
            foreach ($documentos as $index => $doc) {
                if (isset($doc['pdf_base64'])) {
                    // Salva PDF temporariamente
                    $tempPath = sys_get_temp_dir() . '/gemini_doc_' . uniqid() . '.pdf';
                    file_put_contents($tempPath, base64_decode($doc['pdf_base64']));
                    $tempFiles[] = $tempPath;

                    // Upload para Gemini File API
                    $fileUri = $this->uploadFile($tempPath, $doc['descricao'] ?? "Documento " . ($index + 1));
                    $uploadedFiles[] = [
                        'uri' => $fileUri,
                        'descricao' => $doc['descricao'] ?? "Documento " . ($index + 1)
                    ];

                    Log::info("PDF uploaded to Gemini", [
                        'descricao' => $doc['descricao'],
                        'file_uri' => $fileUri
                    ]);
                }
            }

            // 2. Monta o prompt com contexto (sem texto dos documentos)
            $prompt = $this->buildPromptForFileAPI($promptTemplate, $uploadedFiles, $contextoDados);

            // 3. Chama API com referências aos arquivos
            $response = $this->callGeminiAPIWithFiles($prompt, $uploadedFiles);

            return $response;

        } finally {
            // Limpa arquivos temporários
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        }
    }

    /**
     * Faz upload de arquivo para Gemini File API
     * @return string URI do arquivo no Gemini
     */
    private function uploadFile(string $filePath, string $displayName): string
    {
        // Primeira requisição: inicia upload e obtém upload URL
        $response = Http::timeout(60)
            ->withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => filesize($filePath),
                'X-Goog-Upload-Header-Content-Type' => 'application/pdf',
                'Content-Type' => 'application/json',
            ])
            ->post($this->fileApiUrl . '?key=' . $this->apiKey, [
                'file' => [
                    'display_name' => $displayName
                ]
            ]);

        if (!$response->successful()) {
            throw new \Exception('Falha ao iniciar upload: ' . $response->body());
        }

        $uploadUrl = $response->header('X-Goog-Upload-URL');

        if (!$uploadUrl) {
            throw new \Exception('Upload URL não retornada pela API');
        }

        // Segunda requisição: upload do conteúdo
        $fileContent = file_get_contents($filePath);
        $uploadResponse = Http::timeout(120)
            ->withHeaders([
                'Content-Length' => strlen($fileContent),
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])
            ->withBody($fileContent, 'application/pdf')
            ->post($uploadUrl);

        if (!$uploadResponse->successful()) {
            throw new \Exception('Falha no upload do arquivo: ' . $uploadResponse->body());
        }

        $data = $uploadResponse->json();

        if (!isset($data['file']['uri'])) {
            throw new \Exception('URI do arquivo não retornada');
        }

        return $data['file']['uri'];
    }

    /**
     * Monta o prompt para File API (sem conteúdo dos documentos, apenas referências)
     */
    private function buildPromptForFileAPI(string $template, array $uploadedFiles, array $contextoDados): string
    {
        // Extrai informações do contexto
        $nomeClasse = $contextoDados['classeProcessualNome'] ?? $contextoDados['classeProcessual'] ?? 'Não informada';
        $assuntos = $this->formatAssuntos($contextoDados['assunto'] ?? []);
        $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';
        $tipoParte = $this->identificarTipoParte($contextoDados);

        // Substitui variáveis no template
        $prompt = str_replace(
            ['[nomeClasse]', '[assuntos]', '[numeroProcesso]', '[tipoParte]'],
            [$nomeClasse, $assuntos, $numeroProcesso, $tipoParte],
            $template
        );

        // CONTEXTO INICIAL
        $contextoInicial = "# CONTEXTO DO PROCESSO\n\n";
        $contextoInicial .= "**Classe Processual:** {$nomeClasse}\n";
        $contextoInicial .= "**Assuntos:** {$assuntos}\n";
        $contextoInicial .= "**Você está analisando como:** {$tipoParte}\n";
        $contextoInicial .= "**Número do Processo:** {$numeroProcesso}\n";

        if (!empty($contextoDados['valorCausa'])) {
            $contextoInicial .= "**Valor da Causa:** R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
        }

        $contextoInicial .= "\n---\n\n";

        // Adiciona o contexto inicial ANTES do prompt do usuário
        $prompt = $contextoInicial . $prompt;

        // Lista os documentos anexados
        $prompt .= "\n\n## DOCUMENTOS ANEXADOS PARA ANÁLISE\n\n";
        $prompt .= "Os seguintes documentos em PDF foram anexados para sua análise:\n\n";

        foreach ($uploadedFiles as $index => $file) {
            $docNum = $index + 1;
            $prompt .= "{$docNum}. **{$file['descricao']}**\n";
        }

        $prompt .= "\n---\n\n";

        // Instruções finais
        $prompt .= "\n## INSTRUÇÕES\n";
        $prompt .= "Por favor, analise TODOS os documentos PDF anexados acima considerando o contexto processual fornecido. ";
        $prompt .= "Retorne uma análise estruturada e objetiva, destacando pontos relevantes de cada documento. ";
        $prompt .= "Leia completamente cada PDF para fornecer uma análise precisa e detalhada.";

        return $prompt;
    }

    /**
     * Chama Gemini API com arquivos anexados via File API
     */
    private function callGeminiAPIWithFiles(string $prompt, array $uploadedFiles): string
    {
        $url = "{$this->apiUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        // Monta array de parts: primeiro o prompt, depois os arquivos
        $parts = [
            ['text' => $prompt]
        ];

        // Adiciona referências aos arquivos
        foreach ($uploadedFiles as $file) {
            $parts[] = [
                'fileData' => [
                    'fileUri' => $file['uri'],
                    'mimeType' => 'application/pdf'
                ]
            ];
        }

        $response = Http::timeout(180) // 3 minutos - File API pode demorar mais
            ->retry(3, 2000)
            ->post($url, [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'topK' => 32,
                    'topP' => 0.95,
                    'maxOutputTokens' => 8192,
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

        if (!$response->successful()) {
            $statusCode = $response->status();
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? 'Erro desconhecido';

            $friendlyMessage = $this->translateGeminiError($statusCode, $errorMessage);
            throw new \Exception($friendlyMessage);
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($text)) {
            throw new \Exception('A API retornou uma resposta vazia. Tente novamente em alguns instantes.');
        }

        return $text;
    }

    /**
     * Monta o prompt com todo o contexto necessário
     */
    private function buildPrompt(string $template, array $documentos, array $contextoDados): string
    {
        // Extrai informações do contexto
        $nomeClasse = $contextoDados['classeProcessualNome'] ?? $contextoDados['classeProcessual'] ?? 'Não informada';
        $assuntos = $this->formatAssuntos($contextoDados['assunto'] ?? []);
        $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';
        $tipoParte = $this->identificarTipoParte($contextoDados);

        // Substitui variáveis no template
        $prompt = str_replace(
            ['[nomeClasse]', '[assuntos]', '[numeroProcesso]', '[tipoParte]'],
            [$nomeClasse, $assuntos, $numeroProcesso, $tipoParte],
            $template
        );

        // CONTEXTO INICIAL - Informações essenciais para orientar a análise
        $contextoInicial = "# CONTEXTO DO PROCESSO\n\n";
        $contextoInicial .= "**Classe Processual:** {$nomeClasse}\n";
        $contextoInicial .= "**Assuntos:** {$assuntos}\n";
        $contextoInicial .= "**Você está analisando como:** {$tipoParte}\n";
        $contextoInicial .= "**Número do Processo:** {$numeroProcesso}\n";

        if (!empty($contextoDados['valorCausa'])) {
            $contextoInicial .= "**Valor da Causa:** R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
        }

        $contextoInicial .= "\n---\n\n";

        // Adiciona o contexto inicial ANTES do prompt do usuário
        $prompt = $contextoInicial . $prompt;

        // Adiciona informações complementares do processo
        $prompt .= "\n\n## INFORMAÇÕES COMPLEMENTARES DO PROCESSO\n";
        $prompt .= "Número: {$numeroProcesso}\n";
        $prompt .= "Classe: {$nomeClasse}\n";
        $prompt .= "Assuntos: {$assuntos}\n";
        $prompt .= "Perspectiva de análise: {$tipoParte}\n";

        if (!empty($contextoDados['valorCausa'])) {
            $prompt .= "Valor da Causa: R$ " . number_format($contextoDados['valorCausa'], 2, ',', '.') . "\n";
        }

        // Adiciona documentos
        $prompt .= "\n## DOCUMENTOS PARA ANÁLISE\n\n";

        foreach ($documentos as $index => $doc) {
            $docNum = $index + 1;
            $descricao = $doc['descricao'] ?? "Documento {$docNum}";
            $texto = $doc['texto'] ?? '';

            // Trunca documentos muito grandes para evitar exceder limite de tokens
            $textoTruncado = $this->truncateDocument($texto);

            $prompt .= "### DOCUMENTO {$docNum}: {$descricao}\n\n";
            $prompt .= $textoTruncado . "\n\n";
            $prompt .= "---\n\n";
        }

        // Instruções finais
        $prompt .= "\n## INSTRUÇÕES\n";
        $prompt .= "Por favor, analise cada documento acima considerando o contexto processual fornecido. ";
        $prompt .= "Retorne uma análise estruturada e objetiva, destacando pontos relevantes de cada manifestação.";

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
     * Faz a chamada HTTP para a API do Gemini
     */
    private function callGeminiAPI(string $prompt): string
    {
        $url = "{$this->apiUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout(120) // 2 minutos de timeout
            ->retry(3, 1000) // Retry 3 vezes com 1s de intervalo
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
