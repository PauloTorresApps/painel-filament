<?php

namespace App\Contracts;

interface PdfAnalyzerInterface
{
    /**
     * Analisa um documento PDF enviando diretamente à API com plugin de parsing.
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
}
