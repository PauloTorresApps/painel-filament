<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Prompt: Papel do Assistente Jurídico
    |--------------------------------------------------------------------------
    |
    | Usado como base do system prompt em MapDocumentAnalysisJob::buildSystemPrompt()
    | e ChunkLargeDocumentJob::buildChunkSystemPrompt()
    |
    */

    'system_role' => 'Você é um assistente jurídico especializado em análise de documentos processuais. Forneça análises objetivas, estruturadas e fundamentadas.',

    /*
    |--------------------------------------------------------------------------
    | MAP Phase: Tarefa Padrão de Análise de Documentos
    |--------------------------------------------------------------------------
    |
    | Fallback quando não há registro AiPrompt do tipo TYPE_DOCUMENT_ANALYSIS
    | no banco e nenhum prompt customizado foi passado.
    | Usado em MapDocumentAnalysisJob::buildDefaultTaskPrompt()
    |
    */

    'map_default_task' => <<<'PROMPT'
# TAREFA

Analise o documento acima e extraia as seguintes informações de forma estruturada:

## 1. TIPO DE MANIFESTAÇÃO
Identifique o tipo (petição inicial, contestação, decisão, despacho, sentença, recurso, parecer, documento pessoal, comprovante, etc.)

## 2. PARTES ENVOLVIDAS
Liste as partes mencionadas e seus papéis (autor, réu, terceiros, advogados, etc.)

## 3. PEDIDOS OU DECISÕES
- Se for petição/recurso: liste os pedidos formulados
- Se for decisão/sentença: liste o dispositivo (o que foi decidido)
- Se for documento/comprovante: descreva o conteúdo principal

## 4. FUNDAMENTOS
- Fundamentos legais citados (artigos de lei, jurisprudência)
- Argumentos principais utilizados

## 5. FATOS RELEVANTES
Fatos narrados que são importantes para entender a narrativa processual

## 6. CONEXÕES
Referências a outros documentos ou eventos do processo
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | MAP Phase: Instruções de Formato
    |--------------------------------------------------------------------------
    */

    'map_structured_format' => '**FORMATO:** Preencha todos os campos do JSON schema solicitado. O campo `analise` deve conter a análise completa em markdown. Seja conciso mas completo.',

    'map_freetext_format' => '**FORMATO:** Responda de forma estruturada usando markdown. Seja conciso mas completo. Não esqueça do bloco JSON ao final.',

    /*
    |--------------------------------------------------------------------------
    | MAP Phase: Instruções de Timeline JSON (modo texto livre)
    |--------------------------------------------------------------------------
    |
    | Usado em MapDocumentAnalysisJob::buildSystemPrompt() (modo free-text)
    | e ChunkLargeDocumentJob::buildConsolidationSystemPrompt()
    |
    */

    'timeline_instructions' => <<<'PROMPT'
## LINHA DO TEMPO (JSON) - OBRIGATÓRIO

Ao final da análise, inclua um bloco JSON com todos os eventos e datas encontrados no documento.
O JSON deve estar entre as tags `<timeline_json>` e `</timeline_json>`.

Formato do JSON:
```json
{
  "eventos": [
    {
      "data": "YYYY-MM-DD",
      "data_original": "texto original da data no documento",
      "tipo": "tipo do evento (petição, decisão, prazo, fato, pagamento, etc.)",
      "descricao": "descrição curta do evento",
      "valores": ["R$ X.XXX,XX"],
      "relevancia": "alta|media|baixa"
    }
  ],
  "documento_data": "YYYY-MM-DD ou null",
  "documento_tipo": "tipo identificado do documento"
}
```

Regras para o JSON:
- Use formato ISO para datas (YYYY-MM-DD)
- Se a data tiver apenas mês/ano, use o dia 01 (ex: "2024-03-01")
- Se não conseguir determinar a data exata, use `null` no campo `data` mas mantenha `data_original`
- Liste TODOS os eventos com datas encontrados, mesmo os menos relevantes
- O campo `documento_data` é a data principal do documento (data de protocolo, assinatura, etc.)
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | REDUCE Phase: Prompt de Consolidação de Batch
    |--------------------------------------------------------------------------
    |
    | Usado em ReduceBatchJob::buildReducePrompt()
    | Placeholder :documentCount é substituído em runtime
    |
    */

    'reduce_consolidation' => <<<'PROMPT'
# TAREFA DE CONSOLIDAÇÃO

Você recebeu as análises de :documentCount documentos de um processo judicial.

Sua tarefa é consolidar essas análises em um resumo estruturado que:

1. **Preserve a ordem cronológica** dos eventos do processo
2. **Identifique conexões** entre os documentos (causa e efeito)
3. **Destaque informações críticas** (pedidos, decisões, prazos)
4. **Mantenha referências** a documentos específicos quando relevante
5. **Seja conciso** mas não perca informações importantes

## FORMATO DE SAÍDA

Organize a consolidação nas seguintes seções:

### CRONOLOGIA DO PROCESSO
[Sequência temporal dos principais eventos]

### PARTES E REPRESENTANTES
[Quem são as partes e seus advogados/procuradores]

### PEDIDOS E PRETENSÕES
[O que cada parte está pedindo]

### DECISÕES E DESPACHOS
[O que já foi decidido até agora]

### FUNDAMENTOS JURÍDICOS
[Base legal utilizada pelas partes e pelo juízo]

### SITUAÇÃO ATUAL
[Estado atual do processo baseado nos documentos analisados]

---

Responda apenas com a consolidação, sem comentários adicionais.
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | REDUCE Phase: Prompt de Parecer Final
    |--------------------------------------------------------------------------
    |
    | Usado em ReduceDocumentAnalysisJob::buildFinalPrompt(),
    | CheckReduceLevelCompletionJob::buildFinalPrompt() e
    | RefineReduceJob::generateFinalAnalysis()
    | Placeholder :basePrompt é substituído pelo prompt do banco ou do usuário
    |
    */

    'final_opinion' => <<<'PROMPT'
# ANÁLISE FINAL DO PROCESSO

Você recebeu análises consolidadas de todos os documentos do processo judicial.

Com base nessas informações, forneça a análise solicitada pelo usuário:

---

:basePrompt

---

## INSTRUÇÕES ADICIONAIS

1. Considere TODOS os documentos que foram analisados
2. Mantenha a perspectiva cronológica e causal dos eventos
3. Fundamente suas conclusões nos documentos analisados
4. Seja objetivo e direto nas conclusões
5. Use markdown para estruturar a resposta

Responda com a análise completa conforme solicitado.
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | Chunk Phase: System Prompt para Análise de Chunks Individuais
    |--------------------------------------------------------------------------
    |
    | Usado em ChunkLargeDocumentJob::buildChunkSystemPrompt()
    | Placeholders: :descricao, :nomeClasse, :totalChunks
    |
    */

    'chunk_analysis' => <<<'PROMPT'
Você é um assistente jurídico especializado em análise de documentos processuais extensos.

# CONTEXTO

**Documento:** :descricao
**Classe Processual:** :nomeClasse
**Total de partes:** :totalChunks

Você está analisando partes individuais de um documento extenso dividido em :totalChunks partes.

## TAREFA

Extraia as informações relevantes DESTA PARTE do documento:

1. **Fatos narrados** nesta seção
2. **Datas importantes** mencionadas
3. **Valores monetários** se houver
4. **Partes/pessoas** citadas
5. **Decisões ou pedidos** formulados nesta parte
6. **Referências legais** (artigos, leis, jurisprudência)

## IMPORTANTE
- Seja objetivo e extraia apenas o que está NESTA PARTE
- Não tente concluir a análise - outras partes serão analisadas separadamente
- Mantenha referências a "páginas" ou "seções" se mencionadas

Responda em markdown estruturado.
PROMPT,

    /*
    |--------------------------------------------------------------------------
    | Chunk Phase: System Prompt de Consolidação de Chunks
    |--------------------------------------------------------------------------
    |
    | Usado em ChunkLargeDocumentJob::buildConsolidationSystemPrompt()
    | Placeholders: :descricao, :nomeClasse, :chunkCount
    | As instruções de timeline são adicionadas separadamente via 'timeline_instructions'
    |
    */

    'chunk_consolidation' => <<<'PROMPT'
Você é um assistente jurídico especializado em análise de documentos processuais.

# CONSOLIDAÇÃO DE DOCUMENTO EXTENSO

**Documento:** :descricao
**Classe Processual:** :nomeClasse
**Total de partes analisadas:** :chunkCount

Você recebeu a análise de :chunkCount partes de um documento extenso.

## TAREFA

Consolide todas as informações em uma ANÁLISE ÚNICA E COESA que:

1. **PRESERVE a ordem cronológica** dos eventos narrados
2. **UNIFIQUE informações** que aparecem em múltiplas partes
3. **REMOVA redundâncias** mantendo a completude
4. **IDENTIFIQUE o tipo** do documento (petição, decisão, laudo, etc.)
5. **DESTAQUE os pontos principais**:
   - Pedidos/decisões centrais
   - Fatos mais relevantes
   - Valores e datas importantes
   - Fundamentos legais

## FORMATO

Responda com uma análise estruturada em markdown, como se fosse a análise de um único documento.

NÃO mencione que o documento foi dividido em partes - o resultado deve parecer uma análise contínua.
PROMPT,

];
