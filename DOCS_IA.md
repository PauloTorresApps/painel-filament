# Sistema de Analise de Documentos com IA

Sistema integrado de analise automatizada de documentos processuais utilizando **OpenRouter AI** com pipeline Map-Reduce.

## Indice

- [Funcionalidades](#funcionalidades)
- [Requisitos do Sistema](#requisitos-do-sistema)
- [Instalacao e Configuracao](#instalacao-e-configuracao)
- [Como Usar](#como-usar)
- [Arquitetura e Fluxo](#arquitetura-e-fluxo)
- [Configuracao de Analise](#configuracao-de-analise)
- [Troubleshooting](#troubleshooting)

## Funcionalidades

- Analise automatizada de documentos processuais via OpenRouter (Claude, GPT-4o, Gemini, etc.)
- Pipeline Map-Reduce para processamento paralelo de documentos
- Processamento multimodal: texto extraido, visao direta (imagens), PDF nativo
- Chunking automatico de documentos grandes (>100k caracteres)
- Duas estrategias de consolidacao: Batch Hierarquico e Refinamento Sequencial
- Prompts personalizaveis via banco de dados (tabela `ai_prompts`)
- Notificacoes em tempo real sobre progresso
- Historico completo com arquivos de debug
- Web search para fundamentacao juridica no parecer final
- Suporte a deep thinking / reasoning models

## Requisitos do Sistema

### Dependencias do Sistema Operacional

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install poppler-utils

# macOS
brew install poppler
```

### Dependencias PHP

- PHP >= 8.2
- Extensoes: fileinfo, mbstring, curl

### API Key do OpenRouter

1. Acesse https://openrouter.ai/keys
2. Crie uma nova API Key
3. Copie a chave gerada

## Instalacao e Configuracao

### 1. Variaveis de Ambiente

Adicione ao seu arquivo `.env`:

```env
# OpenRouter AI Configuration
OPENROUTER_API_KEY=sua_api_key_aqui
OPENROUTER_MODEL=anthropic/claude-sonnet-4
OPENROUTER_TIMEOUT=300

# Opcoes de modelo (via OpenRouter):
# - anthropic/claude-sonnet-4 (recomendado - equilibrio custo/qualidade)
# - openai/gpt-4o
# - google/gemini-2.5-pro-preview
# - deepseek/deepseek-r1 (reasoning)

# Configuracoes opcionais de analise
# ANALYSIS_BATCH_SIZE=10
# ANALYSIS_LARGE_DOC_THRESHOLD=100000
# ANALYSIS_MAP_TIMEOUT=300
# ANALYSIS_REDUCE_TIMEOUT=600
# OPENROUTER_STRUCTURED_MAP_ENABLED=false
# OPENROUTER_WEB_SEARCH_ENABLED=false
```

### 2. Queue Worker (Docker)

O sistema utiliza filas para processamento assincrono:

```bash
# Limpar caches apos alteracoes
docker exec painel_app php artisan optimize:clear
docker exec painel_app php artisan filament:cache-components
docker exec painel_app php artisan icons:cache
docker restart painel_app --timeout 5
docker exec painel_app php artisan queue:restart
```

## Como Usar

### 1. Configurar Prompt Padrao

1. Acesse o menu **Prompts IA** no painel
2. Clique em **Novo Prompt**
3. Preencha:
   - **Titulo**: Ex: "Analista de Processo"
   - **Tipo**: "Analise de Documentos" (MAP) ou "Parecer Final" (REDUCE)
   - **Conteudo**: Seu prompt customizado
   - **Marque como Padrao**
   - **Ativo**

### 2. Analisar Documentos

1. Acesse **Consulta de Processos**
2. Busque um processo
3. Clique em **"Enviar todos os documentos para analise"**
4. Acompanhe o progresso em tempo real

### 3. Acompanhar Progresso

- **Download**: Documentos sendo baixados do webservice
- **Fase MAP**: Analise individual de cada documento em paralelo
- **Fase REDUCE**: Consolidacao das analises em parecer final
- **Concluido**: Analise disponivel para visualizacao

## Arquitetura e Fluxo

### Pipeline Map-Reduce

```
1. AnalyzeProcessDocuments     (Orquestracao geral)
         |
2. DownloadDocumentJob         (Download paralelo dos PDFs)
         |
3. DispatchMapPhaseJob         (Decide estrategia MAP)
         |
4. FASE MAP (paralelo via Bus::batch)
   |-- MapDocumentAnalysisJob  (Documentos normais < 100k chars)
   |   |-- VisionProcessingStrategy    (Imagens)
   |   |-- PdfNativeProcessingStrategy (PDFs nativos)
   |   |-- TextProcessingStrategy      (Texto extraido)
   |
   |-- ChunkLargeDocumentJob   (Documentos grandes > 100k chars)
         |
5. FASE REDUCE (auto-selecionada)
   |-- RefineReduceJob              (<= 20 docs: sequencial evolutivo)
   |-- ReduceDocumentAnalysisJob    (> 20 docs: batch hierarquico)
       |-- ReduceBatchJob           (Consolida batches de 10)
       |-- CheckReduceLevelCompletionJob (Verifica niveis e gera parecer)
```

### Componentes Principais

#### Services

- **OpenRouterService**: Integracao com API OpenRouter (visao, PDF, texto, structured output, web search)
- **AIServiceFactory**: Factory para instanciar o provider de IA
- **AbstractAIService**: Classe base com metadata tracking e rate limiting

#### Strategies (Strategy Pattern)

- **DocumentProcessingStrategy**: Interface para estrategias de processamento
- **VisionProcessingStrategy**: Analise de imagens via visao direta
- **PdfNativeProcessingStrategy**: PDFs enviados nativamente (pdf-text ou mistral-ocr)
- **TextProcessingStrategy**: Texto extraido com suporte a structured outputs

#### Traits

- **HandlesJsonOutput**: JSON seguro, estimativa de tokens, truncamento de texto

#### Models

- **DocumentAnalysis**: Armazena analises completas
- **DocumentMicroAnalysis**: Micro-analises individuais (MAP) e consolidacoes (REDUCE)
- **AiPrompt**: Prompts personalizaveis por tipo (document_analysis, final_opinion)

## Configuracao de Analise

### config/analysis.php

Centraliza todos os parametros numericos do pipeline:

- **jobs.***: Timeouts, retries e backoff por job
- **thresholds**: Limites para documentos grandes e estrategia de reduce
- **chunking**: Tamanho dos chunks para documentos extensos
- **reduce**: Batch size e niveis maximos de reduce
- **token_estimation**: Metodo e multiplicador para estimativa de tokens

### config/prompts.php

Prompts fallback quando nao ha registro no banco de dados:

- **system_role**: Papel base do assistente juridico
- **map_default_task**: Tarefa padrao da fase MAP
- **timeline_instructions**: Instrucoes para extracao de timeline JSON
- **reduce_consolidation**: Prompt de consolidacao de batches
- **final_opinion**: Template do parecer final
- **chunk_analysis**: Prompt para analise de chunks individuais
- **chunk_consolidation**: Prompt para consolidacao de chunks

### Prioridade de Prompts

1. Prompt do banco de dados (`AiPrompt::getDefaultForSystemAndType()`)
2. Prompt customizado passado pelo usuario
3. Fallback do `config/prompts.php`

## Troubleshooting

### Erro: "pdftotext nao esta disponivel"

```bash
sudo apt-get install poppler-utils
which pdftotext
```

### Erro: "OPENROUTER_API_KEY nao configurado"

```bash
docker exec painel_app php artisan config:clear
docker exec painel_app php artisan cache:clear
```

### Jobs nao estao processando

```bash
# Verifique se o container de queue esta rodando
docker ps | grep painel_queue

# Reinicie o worker
docker exec painel_app php artisan queue:restart

# Verifique jobs falhados
docker exec painel_app php artisan queue:failed

# Reprocesse jobs falhados
docker exec painel_app php artisan queue:retry all
```

### Analises ficam presas em "Processando"

```bash
# Verifique logs
docker exec painel_app tail -f storage/logs/laravel.log

# Verifique a tabela de jobs
docker exec painel_app php artisan tinker --execute="echo DB::table('jobs')->count();"
```

### Timeout em processos grandes

Ajuste no `.env`:

```env
ANALYSIS_MAP_TIMEOUT=600
ANALYSIS_CHUNK_TIMEOUT=7200
ANALYSIS_REDUCE_TIMEOUT=1200
```

### Arquivos de Debug

Salvos em `storage/app/private/analises-debug/{processo}/analysis_{id}/`:

- `{index}_{timestamp}_{nome}.md` - Analise individual
- `{index}_{timestamp}_{nome}_CHUNKED.md` - Documento grande
- `reduces/reduce_nivel_{n}_batch_{b}_{timestamp}.md` - Consolidacoes
- `PARECER_FINAL_{timestamp}.md` - Resultado final

### Commands de Manutencao

```bash
# Limpar arquivos de contratos finalizados
docker exec painel_app php artisan contracts:cleanup

# Limpar analises falhas (com confirmacao)
docker exec painel_app php artisan analysis:cleanup

# Limpar analises falhas com mais de 30 dias (sem confirmacao)
docker exec painel_app php artisan analysis:cleanup --all --older-than=30
```

Estes commands estao registrados no scheduler (`routes/console.php`).

## Seguranca

- Documentos sigilosos sao automaticamente filtrados
- Validacao de permissoes de usuario
- API Key armazenada de forma segura no `.env`
- Isolamento por usuario (cada um ve apenas suas analises)

---

**Versao**: 2.0.0
**Ultima Atualizacao**: Fevereiro 2026
