<?php

namespace App\Contracts;

interface TextAnalyzerInterface
{
    /**
     * Analisa documentos do processo com contexto.
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
     * Analisa um único documento em texto (fase MAP do map-reduce).
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
     * Analisa um único documento retornando resposta JSON estruturada.
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
