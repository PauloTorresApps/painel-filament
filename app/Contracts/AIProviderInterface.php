<?php

namespace App\Contracts;

interface AIProviderInterface extends TextAnalyzerInterface, ImageAnalyzerInterface, PdfAnalyzerInterface
{
    /**
     * Retorna o nome do provider
     */
    public function getName(): string;

    /**
     * Define o modelo a ser utilizado
     */
    public function setModel(string $model): self;

    /**
     * Define o limite de timeout para chamadas HTTP
     */
    public function setTimeout(int $seconds): self;

    /**
     * Define limite de caracteres de entrada para summarização.
     * null = sem limite (texto completo é enviado).
     */
    public function setInputCharLimit(?int $limit): self;

    /**
     * Define override de max_tokens de saída para a próxima chamada.
     * null = usa default do provider.
     */
    public function setMaxTokens(?int $maxTokens): self;

    /**
     * Define override de temperature para a próxima chamada.
     * null = usa default do provider.
     */
    public function setTemperature(?float $temperature): self;

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
}
