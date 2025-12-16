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
     * @return string Análise gerada pela IA
     */
    public function analyzeDocuments(string $promptTemplate, array $documentos, array $contextoDados): string;

    /**
     * Valida se a API está acessível
     */
    public function healthCheck(): bool;

    /**
     * Retorna o nome do provider
     */
    public function getName(): string;
}
