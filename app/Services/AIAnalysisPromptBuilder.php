<?php

namespace App\Services;

class AIAnalysisPromptBuilder
{
    public function buildContractAnalysisPrompt(
        string $promptTemplate,
        string $documentText,
        string $arquivo,
        string $parteInteressada = '',
        bool $isSummarized = false
    ): string {
        $header = $isSummarized
            ? '# DOCUMENTO DO CONTRATO (RESUMIDO)'
            : '# DOCUMENTO DO CONTRATO';

        $contexto = "# CONTEXTO DA ANÁLISE DE CONTRATO\n\n";
        $contexto .= "**Tipo:** Análise de Contrato\n";
        $contexto .= "**Arquivo:** {$arquivo}\n";

        if ($parteInteressada !== '') {
            $contexto .= "**Parte Interessada:** {$parteInteressada}\n";
        }

        $contexto .= "\n---\n\n";
        $contexto .= "{$header}\n\n";
        $contexto .= $documentText . "\n\n";
        $contexto .= "---\n\n";
        $contexto .= "# TAREFA\n\n";
        $contexto .= $promptTemplate;

        return $contexto;
    }

    /**
     * @param array<int,array<string,mixed>> $documentos
     * @param array<string,mixed> $contextoDados
     */
    public function buildSimpleProcessAnalysisPrompt(string $promptTemplate, array $documentos, array $contextoDados): string
    {
        $nomeClasse = $contextoDados['classeProcessualNome']
            ?? $contextoDados['classeProcessual']
            ?? 'Não informada';

        $assuntos = $this->formatAssuntos($contextoDados['assunto'] ?? []);
        $numeroProcesso = $contextoDados['numeroProcesso'] ?? 'Não informado';

        $prompt = "# CONTEXTO DO PROCESSO\n\n";
        $prompt .= "**Classe Processual:** {$nomeClasse}\n";
        $prompt .= "**Assuntos:** {$assuntos}\n";
        $prompt .= "**Número do Processo:** {$numeroProcesso}\n";

        if (!empty($contextoDados['valorCausa'])) {
            $prompt .= "**Valor da Causa:** R$ " . number_format((float) $contextoDados['valorCausa'], 2, ',', '.') . "\n";
        }

        $prompt .= "\n---\n\n";
        $prompt .= "# DOCUMENTOS DO PROCESSO\n\n";

        foreach ($documentos as $index => $doc) {
            $docNum = $index + 1;
            $descricao = $doc['descricao'] ?? "Documento {$docNum}";
            $texto = $doc['texto'] ?? '';

            $prompt .= "## DOCUMENTO {$docNum}: {$descricao}\n\n";
            $prompt .= $texto . "\n\n";
            $prompt .= "---\n\n";
        }

        $prompt .= "# TAREFA\n\n";
        $prompt .= $promptTemplate;

        return $prompt;
    }

    public function buildSummarizationPrompt(string $documentText, string $descricao): string
    {
        return <<<PROMPT
    Você é um assistente jurídico especializado. Resuma o documento abaixo em 2-3 parágrafos concisos, destacando:

    1. **Tipo de manifestação** (petição, contestação, decisão, despacho, sentença, recurso, contrato, etc.)
2. **Partes envolvidas**
    3. **Pedidos ou decisões principais**
4. **Fundamentos legais citados**
5. **Fatos relevantes**
6. **Datas importantes**

    **IMPORTANTE:** Preserve informações essenciais para compreensão do documento.

    **Descrição do documento:** {$descricao}

**DOCUMENTO:**

{$documentText}
PROMPT;
    }

    /**
     * @param array<int,mixed> $assuntos
     */
    public function formatAssuntos(array $assuntos): string
    {
        if (empty($assuntos)) {
            return 'Não informados';
        }

        $nomes = array_map(function ($assunto) {
            if (!is_array($assunto)) {
                return 'Assunto';
            }

            return $assunto['nomeAssunto']
                ?? $assunto['descricao']
                ?? $assunto['codigoAssunto']
                ?? $assunto['codigoNacional']
                ?? 'Assunto';
        }, $assuntos);

        return implode(', ', $nomes);
    }
}
