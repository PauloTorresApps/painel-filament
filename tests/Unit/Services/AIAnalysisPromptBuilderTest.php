<?php

use App\Services\AIAnalysisPromptBuilder;

it('builds contract prompt with interested party', function () {
    $builder = new AIAnalysisPromptBuilder();

    $prompt = $builder->buildContractAnalysisPrompt(
        promptTemplate: 'Analise as clausulas.',
        documentText: 'Conteudo do contrato',
        arquivo: 'contrato.pdf',
        parteInteressada: 'Empresa X',
        isSummarized: false,
    );

    expect($prompt)->toContain('# CONTEXTO DA ANÁLISE DE CONTRATO')
        ->toContain('**Tipo:** Análise de Contrato')
        ->toContain('**Arquivo:** contrato.pdf')
        ->toContain('**Parte Interessada:** Empresa X')
        ->toContain('# DOCUMENTO DO CONTRATO')
        ->toContain('Conteudo do contrato')
        ->toContain('# TAREFA')
        ->toContain('Analise as clausulas.');
});

it('builds summarized contract prompt header when flagged', function () {
    $builder = new AIAnalysisPromptBuilder();

    $prompt = $builder->buildContractAnalysisPrompt(
        promptTemplate: 'Tarefa',
        documentText: 'Resumo',
        arquivo: 'arquivo.pdf',
        parteInteressada: '',
        isSummarized: true,
    );

    expect($prompt)->toContain('# DOCUMENTO DO CONTRATO (RESUMIDO)')
        ->not->toContain('**Parte Interessada:**');
});

it('builds simple process prompt with documents and context', function () {
    $builder = new AIAnalysisPromptBuilder();

    $prompt = $builder->buildSimpleProcessAnalysisPrompt(
        'Forneca parecer.',
        [
            ['descricao' => 'Inicial', 'texto' => 'Texto A'],
            ['descricao' => 'Contestacao', 'texto' => 'Texto B'],
        ],
        [
            'classeProcessualNome' => 'Acao Civil',
            'assunto' => [['nomeAssunto' => 'Danos Morais']],
            'numeroProcesso' => '0001234-00.2024.8.00.0001',
            'valorCausa' => 12345.67,
        ]
    );

    expect($prompt)->toContain('**Classe Processual:** Acao Civil')
        ->toContain('**Assuntos:** Danos Morais')
        ->toContain('**Número do Processo:** 0001234-00.2024.8.00.0001')
        ->toContain('**Valor da Causa:** R$ 12.345,67')
        ->toContain('## DOCUMENTO 1: Inicial')
        ->toContain('## DOCUMENTO 2: Contestacao')
        ->toContain('# TAREFA')
        ->toContain('Forneca parecer.');
});

it('builds summarization prompt with description and body', function () {
    $builder = new AIAnalysisPromptBuilder();

    $prompt = $builder->buildSummarizationPrompt('Corpo do documento', 'Peticao inicial');

    expect($prompt)->toContain('**Descrição do documento:** Peticao inicial')
        ->toContain('**DOCUMENTO:**')
        ->toContain('Corpo do documento');
});

it('formats assuntos list from multiple fallback keys', function () {
    $builder = new AIAnalysisPromptBuilder();

    $result = $builder->formatAssuntos([
        ['nomeAssunto' => 'Assunto 1'],
        ['descricao' => 'Assunto 2'],
        ['codigoAssunto' => '123'],
        ['codigoNacional' => 'ABC'],
        [],
    ]);

    expect($result)->toBe('Assunto 1, Assunto 2, 123, ABC, Assunto')
        ->and($builder->formatAssuntos([]))->toBe('Não informados');
});
