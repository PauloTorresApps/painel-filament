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
     * @param bool $deepThinkingEnabled Habilita modo de pensamento profundo (DeepSeek)
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
     * @return string Análise gerada pela IA
     */
    public function analyzeSingleDocument(
        string $prompt,
        string $documentText,
        bool $deepThinkingEnabled = false
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
}
