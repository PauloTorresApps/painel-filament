<?php

namespace App\Contracts;

interface ImageAnalyzerInterface
{
    /**
     * Analisa um documento de imagem enviando diretamente ao modelo de visão.
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
}
