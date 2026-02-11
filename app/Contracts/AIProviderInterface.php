<?php

namespace App\Contracts;

interface AIProviderInterface
{
    /**
     * Analisa documentos do processo com contexto
     *
     * @param string $promptTemplate Prompt do usuário
     * @param array $documentos Array de documentos com texto extraído
     * @param array $contextoDados Dados do processo (classe, assuntos, etc)
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo (reasoning)
     * @param \App\Models\DocumentAnalysis|null $documentAnalysis Model para persistir estado evolutivo (opcional)
     * @return string Análise gerada pela IA
     */
    public function analyzeDocuments(
        string $promptTemplate,
        array $documentos,
        array $contextoDados,
        bool $deepThinkingEnabled = true,
        ?\App\Models\DocumentAnalysis $documentAnalysis = null
    ): string;

    /**
     * Analisa um único documento (usado na fase MAP do map-reduce)
     *
     * @param string $prompt Prompt de análise
     * @param string $documentText Texto do documento
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @param string|null $systemPrompt System prompt customizado (para prompt caching entre chamadas)
     * @return string Análise gerada pela IA
     */
    public function analyzeSingleDocument(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string;

    /**
     * Analisa um documento de imagem enviando diretamente ao modelo de visão
     *
     * @param string $prompt Prompt de análise
     * @param string $imageBase64 Imagem codificada em base64
     * @param string $mimetype Tipo MIME da imagem
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @param string|null $systemPrompt System prompt customizado (para prompt caching)
     * @return string Análise gerada pela IA
     */
    public function analyzeImageDocument(
        string $prompt,
        string $imageBase64,
        string $mimetype,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string;

    /**
     * Analisa um documento PDF enviando diretamente à API com plugin de parsing
     *
     * @param string $prompt Prompt de análise
     * @param string $pdfBase64 PDF codificado em base64
     * @param string $filename Nome do arquivo para contexto
     * @param bool $isScanned Se o PDF é escaneado (usa mistral-ocr ao invés de pdf-text)
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @param string|null $systemPrompt System prompt customizado (para prompt caching)
     * @return string Análise gerada pela IA
     */
    public function analyzePdfDocument(
        string $prompt,
        string $pdfBase64,
        string $filename,
        bool $isScanned = false,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string;

    /**
     * Valida se a API está acessível
     */
    public function healthCheck(): bool;

    /**
     * Retorna o nome do provider
     */
    public function getName(): string;

    /**
     * Define o modelo a ser utilizado
     */
    public function setModel(string $model): self;

    /**
     * Retorna o modelo atual
     */
    public function getModel(): string;

    /**
     * Retorna os metadados acumulados da última análise (tokens, annotations, etc.)
     */
    public function getLastAnalysisMetadata(): array;

    /**
     * Retorna o modelo ideal para uma estratégia de processamento de documento.
     * Permite roteamento de modelos por tipo (pdf_text, pdf_ocr, vision, etc.)
     */
    public function getModelForStrategy(string $strategy): string;

    /**
     * Analisa um único documento retornando resposta JSON estruturada.
     * Usado na fase MAP para obter resultados consistentes e parseáveis.
     *
     * @param string $prompt Prompt de análise
     * @param string $documentText Texto do documento
     * @param array $jsonSchema JSON Schema para validação da resposta
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @param string|null $systemPrompt System prompt customizado (para prompt caching)
     * @return string Resposta JSON gerada pela IA
     */
    public function analyzeSingleDocumentStructured(
        string $prompt,
        string $documentText,
        array $jsonSchema,
        bool $deepThinkingEnabled = false,
        ?string $systemPrompt = null
    ): string;

    /**
     * Analisa texto com web search habilitado (usado no parecer final).
     * Permite que o modelo consulte legislação e jurisprudência atualizadas.
     *
     * @param string $prompt Prompt de análise
     * @param string $documentText Texto consolidado
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo
     * @return string Análise gerada pela IA
     */
    public function analyzeWithWebSearch(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false
    ): string;
}
