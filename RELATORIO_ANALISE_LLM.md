# Relatório: Análise de Chamadas LLM por Processo (17 arquivos)

## Resumo Executivo

Para um processo com **17 arquivos**, o pipeline atual dispara **no mínimo 18 chamadas** à OpenRouter (caminho feliz, sem documentos grandes, sem fallback):
- **17 chamadas MAP** (1 por arquivo, fase de análise individual)
- **1 chamada REDUCE** (consolidação direta em `RefineReduceJob::directConsolidation()` — [app/Jobs/ProcessAnalysis/RefineReduceJob.php#L257](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L257))

Esse total cresce **rápido** quando os seguintes gatilhos são acionados (todos coexistem no fluxo atual):

| Gatilho | Multiplicador típico para 17 arquivos |
|---|---|
| Documento individual > 100.000 chars → `ChunkLargeDocumentJob` | +(N_chunks + 1) **por** arquivo grande. Ex.: PDF 250k → 5+1 = 6 calls (vs 1) |
| Documento > 120.000 chars passando por `analyzeSingleDocument` sem `setInputCharLimit(null)` | +1 sumarização escondida por chamada — [app/Services/AbstractAIService.php#L251](app/Services/AbstractAIService.php#L251) |
| Total de micro-análises > 200.000 chars no REDUCE | RefineReduce cai para sequencial: **17 calls** ao invés de 1 ([app/Jobs/ProcessAnalysis/RefineReduceJob.php#L386](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L386)) |
| Structured output habilitado e falhando → fallback texto livre | +1 call/arquivo afetado ([app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L97](app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L97)) |
| Retries por rate-limit / connection error (`MAX_RETRIES_ON_RATE_LIMIT = 5`) | Até **6×** por call ([app/Services/AbstractAIService.php#L151](app/Services/AbstractAIService.php#L151)) |
| `tries = 3` em `MapDocumentAnalysisJob` (job-level retry) | Até **3×** o job inteiro por documento que falha ([config/analysis.php#L29](config/analysis.php#L29)) |
| Self-healing `ensureMapToReduceTransition` em race / falha de callback | Pode disparar Reduce/Refine duplicado, mas mitigado por `ShouldBeUnique` |

**Conclusão**: 18 calls é o piso. Em produção, com PDFs escaneados longos + retries + sumarização escondida, é comum observar **40–80+** chamadas LLM para 17 arquivos.

---

## Diagrama do Fluxo

```
AnalyzeProcessDocumentsJob  (sem chamada LLM — só baixa do e-Proc)
    │
    ▼
DispatchMapPhaseJob  (sem chamada LLM — classifica regular vs large)
    │
    ├── docs ≤ 100k chars → MapDocumentAnalysisJob   ──► 1 LLM call cada
    │       │                                              (Vision | PdfNative | Text)
    │       └─ se Text + structured falhar → +1 fallback LLM call
    │
    └── docs > 100k chars → ChunkLargeDocumentJob    ──► N chunks LLM calls
                                                        + 1 consolidação LLM call
    │
    ▼  (callback Bus::batch().then())
[Escolha por reduceStrategy / docCount]
    │
    ├── ≤20 docs → RefineReduceJob
    │       ├── totalChars ≤ 200k → directConsolidation: 1 LLM call
    │       └── totalChars > 200k → sequentialRefinement: N LLM calls
    │
    └── >20 docs → ReduceDocumentAnalysisJob
            └── ReduceBatchJob × ceil(N/10): 1 LLM call por batch
                    │
                    ▼
            CheckReduceLevelCompletionJob
                    ├── ainda > 10 → próximo nível REDUCE (recursivo)
                    └── final → analyzeWithWebSearch: 1 LLM call
```

Para 17 arquivos a estratégia padrão é **RefineReduce** (limite `refine_max_documents = 20` em [config/analysis.php#L73](config/analysis.php#L73)).

---

## Tabela de Pontos de Chamada LLM

Todos os pontos terminam em `OpenRouterService::executeAPICall` ([app/Services/OpenRouterService.php#L322](app/Services/OpenRouterService.php#L322)), que aplica `withRetry` (até 5 retries em 429/conexão) + `RateLimiterService`.

| # | Arquivo:Linha | Método | Etapa | Granularidade | Estimativa p/ 17 arquivos (caminho feliz) |
|---|---|---|---|---|---|
| 1 | [TextProcessingStrategy.php#L98](app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L98) | `analyzeSingleDocumentStructured` | MAP (texto, structured) | por arquivo | até 17 |
| 2 | [TextProcessingStrategy.php#L113](app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L113) | `analyzeSingleDocument` | MAP (fallback texto livre) | por arquivo (se #1 falhar) | 0–17 extras |
| 3 | [PdfNativeProcessingStrategy.php#L40](app/Strategies/ProcessAnalysis/PdfNativeProcessingStrategy.php#L40) | `analyzePdfDocument` (file-parser pdf-text/mistral-ocr) | MAP (PDF nativo) | por arquivo PDF | parte dos 17 |
| 4 | [VisionProcessingStrategy.php#L37](app/Strategies/ProcessAnalysis/VisionProcessingStrategy.php#L37) | `analyzeImageDocument` | MAP (imagem) | por arquivo de imagem | parte dos 17 |
| 5 | [ChunkLargeDocumentJob.php#L170](app/Jobs/ProcessAnalysis/ChunkLargeDocumentJob.php#L170) | `analyzeSingleDocument` (chunk) | MAP (sub-pipeline para >100k chars) | por chunk de doc grande | 0 (se nenhum grande) ou +N por doc |
| 6 | [ChunkLargeDocumentJob.php#L196](app/Jobs/ProcessAnalysis/ChunkLargeDocumentJob.php#L196) | `analyzeSingleDocument` (consolidação chunks) | MAP (sub-reduce do doc grande) | por doc grande | +1 por doc grande |
| 7 | [RefineReduceJob.php#L313](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L313) | `analyzeSingleDocument` (direct, prompt JSON c/ upstream_inputs) | REDUCE (caminho ≤200k chars) | por análise | 1 |
| 8 | [RefineReduceJob.php#L362](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L362) | `analyzeSingleDocument` (direct, prompt texto puro) | REDUCE (caminho ≤200k chars, prompt texto) | por análise | 1 (alt. de #7) |
| 9 | [RefineReduceJob.php#L448](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L448) | `analyzeSingleDocument` (sequencial JSON) | REDUCE (último doc, prompt JSON) | por doc | 0 ou 1 (modo sequencial) |
| 10 | [RefineReduceJob.php#L474](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L474) | `analyzeSingleDocument` (sequencial texto) | REDUCE (refinamento doc-a-doc) | por doc | 0 ou 17 (se totalChars>200k) |
| 11 | [ReduceBatchJob.php#L203](app/Jobs/ProcessAnalysis/ReduceBatchJob.php#L203) | `analyzeSingleDocument` | REDUCE batch (somente >20 docs) | por batch de até 10 | 0 (não acionado p/ 17 docs) |
| 12 | [ReduceDocumentAnalysisJob.php#L413](app/Jobs/ProcessAnalysis/ReduceDocumentAnalysisJob.php#L413) | `analyzeSingleDocument` | REDUCE final (caminho batch) | por análise | 0 |
| 13 | [CheckReduceLevelCompletionJob.php#L212](app/Jobs/ProcessAnalysis/CheckReduceLevelCompletionJob.php#L212) | `analyzeWithWebSearch` | REDUCE final (caminho batch hierárquico) | por análise | 0 |
| 14 | [AbstractAIService.php#L270](app/Services/AbstractAIService.php#L270) | `summarizeDocument` (interno em `analyzeSingleDocument` quando chars>120k) | MAP/REDUCE hidden summarize | por chamada com texto longo sem `setInputCharLimit(null)` | até +1 por call afetada |
| 15 | [AbstractAIService.php#L467](app/Services/AbstractAIService.php#L467) | `summarizeDocument` (interno em `analyzeContract`) | Fluxo de contratos | por arquivo (não usado em ProcessAnalysis) | 0 |

**Observações do AbstractAIService**:
- Cada `analyzeSingleDocument` com texto > 120.000 chars (`SINGLE_DOC_CHAR_LIMIT`) gera **1 sumarização extra** ([app/Services/AbstractAIService.php#L262](app/Services/AbstractAIService.php#L262)) **a menos** que o caller chame `setInputCharLimit(null)`.
  - `RefineReduceJob` desativa: [app/Jobs/ProcessAnalysis/RefineReduceJob.php#L150](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L150) ✅
  - `ReduceDocumentAnalysisJob` desativa: [app/Jobs/ProcessAnalysis/ReduceDocumentAnalysisJob.php#L386](app/Jobs/ProcessAnalysis/ReduceDocumentAnalysisJob.php#L386) ✅
  - `ReduceBatchJob` desativa: [app/Jobs/ProcessAnalysis/ReduceBatchJob.php#L188](app/Jobs/ProcessAnalysis/ReduceBatchJob.php#L188) ✅
  - `MapDocumentAnalysisJob` **NÃO desativa** → docs com texto > 120k chars sofrem sumarização interna **antes** de chegar no Chunking (que só dispara > 100k em [app/Jobs/ProcessAnalysis/DispatchMapPhaseJob.php#L168](app/Jobs/ProcessAnalysis/DispatchMapPhaseJob.php#L168)). Como o threshold do Chunk (100k) < limite da sumarização (120k), na prática isso é raro, mas existe a janela 100k–120k onde o `ChunkLargeDocumentJob` também não desativa o limite ([app/Jobs/ProcessAnalysis/ChunkLargeDocumentJob.php#L170](app/Jobs/ProcessAnalysis/ChunkLargeDocumentJob.php#L170) chama `analyzeSingleDocument` sem `setInputCharLimit(null)`). **Risco**: se um chunk ultrapassar 120k, ocorre sumarização escondida adicionalmente ao chunking.

---

## Causas Raiz Priorizadas (alto impacto → baixo)

### 1. 🔴 Cada arquivo gera N+1 calls quando passa pelo `ChunkLargeDocumentJob`
- Threshold = 100.000 chars ([config/analysis.php#L70](config/analysis.php#L70))
- Chunk size = 50.000 chars ([config/analysis.php#L83](config/analysis.php#L83))
- Para um PDF transcrito de 300k chars → **6 chunks + 1 consolidação = 7 calls** apenas para esse arquivo.
- Em processos jurídicos com sentenças/petições longas, isso explica facilmente +30 calls.
- **Pior**: a consolidação dos chunks duplica trabalho que o REDUCE já faz globalmente.

### 2. 🔴 Sumarização escondida no `AbstractAIService::analyzeSingleDocument`
- Linha [app/Services/AbstractAIService.php#L263](app/Services/AbstractAIService.php#L263): se `inputCharLimit` ≠ null e texto > limit, faz **uma chamada adicional** de sumarização antes da análise real.
- Ainda existe lógica de sumarização do velho pipeline (`summarizeDocument` em [app/Services/AbstractAIService.php#L498](app/Services/AbstractAIService.php#L498)) que poderia ser deletada já que o chunking moderno cobre o caso.

### 3. 🟠 Falha de structured output dispara fallback texto livre (2 calls/arquivo)
- [app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L96](app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L96): `try { structured } catch { freeform }`. Se o modelo não respeitar o schema (comum em modelos não-OpenAI/Anthropic), gera 2 calls por arquivo.

### 4. 🟠 RefineReduce sequencial = 17 calls quando totalChars > 200k
- [app/Jobs/ProcessAnalysis/RefineReduceJob.php#L172](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L172): se a soma das micro-análises ultrapassa 200.000 chars (o que é trivial — 17 análises × ~12k chars cada), o job cai em modo sequencial e roda **1 chamada por documento** para "refinar" cumulativamente.
- Isso torna o REDUCE potencialmente **17×** mais caro do que a consolidação direta.

### 5. 🟠 Retries multiplicam tudo
- `tries = 3` no `MapDocumentAnalysisJob` ([app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L48](app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L48)) → o job inteiro é re-executado em falha (cada execução = 1 LLM call).
- `MAX_RETRIES_ON_RATE_LIMIT = 5` no `AbstractAIService` ([app/Services/AbstractAIService.php#L151](app/Services/AbstractAIService.php#L151)) → cada call pode ser repetida até 5× em 429.
- Em pico de rate-limit, **um único arquivo** pode gerar até 3 × 5 = **15 calls** (embora apenas a última conte como sucesso).
- O `withRetry` envolve `summarizeDocument` também, então retries de sumarização também acontecem.

### 6. 🟡 Múltiplas etapas extras no MAP
A fase MAP por arquivo pode incluir:
1. Sumarização interna (se aplicável) → 1 call
2. Análise principal estruturada → 1 call
3. Fallback freeform → 1 call (em caso de falha do schema)
4. Em caso de doc grande: + N chunks + 1 consolidação

Sem chunking nem fallback: **1 call/arquivo**. Pior caso por arquivo: **5+ calls**.

### 7. 🟡 `analyzeWithWebSearch` no caminho hierárquico (não acionado para 17 arquivos)
- Apenas relevante se `> 20` arquivos. Não afeta seu cenário, mas é uma chamada final adicional sobre o batch reduce.

### 8. 🟡 Self-healing pode duplicar dispatch do Reduce
- `MapDocumentAnalysisJob::ensureMapToReduceTransition` ([app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L335](app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L335)): em caso de race com callback do batch, re-dispatcha `RefineReduceJob`/`ReduceDocumentAnalysisJob`. Mitigado por `ShouldBeUnique` com TTL 1800s, mas em janela de race é possível dupla execução parcial.

### 9. 🟢 Outros loops (não impactam)
- Não há loop `por processo × por arquivo` aninhado fora do map-reduce.
- Não há geração de embeddings por arquivo no pipeline atual.

---

## Estimativas Concretas para 17 Arquivos

| Cenário | Calls MAP | Calls REDUCE | Total |
|---|---:|---:|---:|
| **Tudo verde** (PDFs ≤100k, totalChars REDUCE ≤200k, structured ok, sem retry) | 17 | 1 | **18** |
| 3 PDFs grandes (~250k chars cada → 6 calls/doc) + restante OK | 14 + 3×6 = 32 | 1 | **33** |
| Estruturado quebrando em 50% dos docs | 17 + 8 = 25 | 1 | **26** |
| RefineReduce sequencial (totalChars > 200k) | 17 | 17 | **34** |
| 3 grandes + structured 50% falha + sequencial reduce | 32 + 7 = ~39 | 17 | **~56** |
| Pico de 429 (retry médio 2× em 30% dos calls) | ~22 | ~2 | **~24** sucessos + retries (logados como calls reais) |

Em produção, é plausível que o processo de 17 arquivos esteja gerando **50–80 calls LLM** se houver PDFs longos e RefineReduce sequencial.

---

## Recomendações de Otimização (em ordem de ROI)

### Quick wins (baixo risco)

1. **Aumentar `directConsolidationLimit` no `RefineReduceJob`**
   - Atualmente fixo em 200.000 chars ([app/Jobs/ProcessAnalysis/RefineReduceJob.php#L177](app/Jobs/ProcessAnalysis/RefineReduceJob.php#L177)). Modelos modernos (Gemini 1.5/2.5, Claude 3.5, GPT-4.1) suportam 200k–2M tokens. Subir para **800.000 chars** elimina o caminho sequencial em quase todos os casos → economiza até **16 calls/processo**.
   - Tornar configurável via `config/analysis.php`.

2. **Remover sumarização escondida do `AbstractAIService::analyzeSingleDocument`** (linhas [app/Services/AbstractAIService.php#L262](app/Services/AbstractAIService.php#L262)–[L272](app/Services/AbstractAIService.php#L272))
   - Hoje é redundante: o `ChunkLargeDocumentJob` já cuida de docs grandes. A sumarização interna apenas encobre o problema com 1 call extra silencioso.
   - Alternativa: emitir **erro/log** quando texto excede limite, em vez de chamar LLM novamente.

3. **Aumentar threshold do `large_document_chars`**
   - 100.000 chars → 250.000 chars. PDFs jurídicos médios cabem em 1 call sem chunking. Economia: 5–7 calls por doc grande.
   - Hoje: [config/analysis.php#L70](config/analysis.php#L70).

4. **Reduzir `tries` do `MapDocumentAnalysisJob` de 3 → 1**
   - O `withRetry` interno já tenta 5× para 429/conexão. O `tries=3` do job duplica trabalho em erros não-recuperáveis (texto inválido, schema rejeitado) e causa "fantasmagoria" de calls.
   - [config/analysis.php#L29](config/analysis.php#L29).

5. **Desativar `setInputCharLimit` no `ChunkLargeDocumentJob`**
   - Adicionar `$aiService->setInputCharLimit(null)` antes do loop de chunks ([app/Jobs/ProcessAnalysis/ChunkLargeDocumentJob.php#L120](app/Jobs/ProcessAnalysis/ChunkLargeDocumentJob.php#L120)) para evitar sumarização escondida sobre chunks que casualmente ultrapassem 120k.

### Médio prazo

6. **Cache de resultado MAP por `file_annotation_hash`**
   - O modelo já captura o hash em [app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L242](app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L242)–[L249](app/Jobs/ProcessAnalysis/MapDocumentAnalysisJob.php#L249). Antes de disparar o job de MAP, **lookup em `DocumentMicroAnalysis` existentes** com mesmo hash + mesmo prompt + mesmo modelo → reutiliza resultado. Re-análises do mesmo processo = 0 calls MAP.

7. **Modelo menor para MAP, modelo maior só para REDUCE final**
   - O sistema **já permite** isso (`mapModelId` vs `aiModelId`), mas verifique que está configurado: usar `gemini-2.5-flash` ou `claude-haiku` no MAP (17 calls) e reservar `gemini-2.5-pro` / `claude-sonnet` para o 1 call do REDUCE. Reduz custo em ~5–10×, não o número de calls.

8. **Eliminar fallback duplicado do structured output**
   - Em [app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L96](app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L96)–[L113](app/Strategies/ProcessAnalysis/TextProcessingStrategy.php#L113), trocar fallback por `response-healing` plugin (já presente em `callAPIStructured`). Se mesmo assim falhar, falhar o job (com retry policy do `withRetry`) em vez de fazer 2 calls.

9. **Trocar `analyzeWithWebSearch` por `analyzeSingleDocument`** no caminho final do `CheckReduceLevelCompletionJob` ([app/Jobs/ProcessAnalysis/CheckReduceLevelCompletionJob.php#L212](app/Jobs/ProcessAnalysis/CheckReduceLevelCompletionJob.php#L212))
   - Web search dobra latência e custo. Habilitar somente sob flag explícita do usuário. (Hoje já condicionado a `web_search_enabled`, mas verificar default.)

10. **Dedupe via `ShouldBeUnique` em `MapDocumentAnalysisJob`**
    - Atualmente só `Reduce/Refine` tem `ShouldBeUnique`. O Map não — em race conditions (worker restart) o mesmo doc pode rodar 2×. Adicionar `uniqueId() = "map_micro_{microAnalysisId}"`.

### Estrutural

11. **Substituir Refine sequencial por hierárquico unificado**
    - O `RefineReduceJob` em modo sequencial gera 17 calls. Para >200k chars, usar `ReduceDocumentAnalysisJob` (batch de 10): para 17 docs = 2 batches (2 calls) + 1 final = **3 calls** vs 17.
    - Isso já existe — basta forçar `reduceStrategy = 'batch'` quando totalChars > 200k.

12. **Consolidar timeline localmente, sem LLM**
    - Hoje a timeline é montada em PHP no `ReduceBatchJob::buildOrderedTimeline()` ([app/Jobs/ProcessAnalysis/ReduceBatchJob.php#L266](app/Jobs/ProcessAnalysis/ReduceBatchJob.php#L266)) — bom. Garantir que o REDUCE final só receba **a timeline já consolidada + entidades agregadas**, não o texto bruto de todas as micro-análises (encolher input → pode reduzir necessidade de sequential refinement).

13. **Telemetria de calls LLM**
    - Há OTEL spans em `OpenRouterService::executeAPICall` (`ai.api.call` span). Verifique no Tempo/Loki o **número de spans `ai.api.call` por `entity_id` (DocumentAnalysis)** — métrica direta do problema.
    - Sugestão: criar contador OTEL `ai.calls.per_analysis` agrupado por `entity_id` → dashboard Grafana mostrando p50/p99 calls por análise.

---

## Validação Rápida no Banco

Para o processo de 17 arquivos que está com problema, rode no banco:

```sql
SELECT id, total_documents, total_characters,
       (SELECT COUNT(*) FROM document_micro_analyses WHERE document_analysis_id = da.id AND reduce_level = 0) AS map_count,
       (SELECT COUNT(*) FROM document_micro_analyses WHERE document_analysis_id = da.id AND reduce_level > 0) AS reduce_count,
       (SELECT SUM(LENGTH(micro_analysis)) FROM document_micro_analyses WHERE document_analysis_id = da.id AND reduce_level = 0) AS map_total_chars,
       (SELECT MAX(LENGTH(extracted_text)) FROM document_micro_analyses WHERE document_analysis_id = da.id AND reduce_level = 0) AS max_doc_chars
FROM document_analyses da WHERE id = <ID>;
```

- `map_total_chars > 200000` → confirma sequential refine
- `max_doc_chars > 100000` → confirma chunking de doc grande (somar `parent_ids->chunk_count` em micros)
- Buscar spans `ai.api.call` filtrando por `analysis.id` no Tempo confirma o número exato de chamadas reais.
