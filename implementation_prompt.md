# Prompt de Implementação: Otimização da Arquitetura Map-Reduce

Você é um Engenheiro de Software Sênior especialista em Laravel e integrações com LLMs (Large Language Models), em especial com arquiteturas de Processamento Paralelo via Map-Reduce e Prompt Caching.

Analisei a arquitetura do meu sistema de análise de documentos jurídicos, que utiliza o `Illuminate\Support\Facades\Bus` para paralelizar a fase MAP ([MapDocumentAnalysisJob](file:///home/paulo/projetos/painel-filament/app/Jobs/MapDocumentAnalysisJob.php#31-630)) e consolidar os resultados hierarquicamente na fase REDUCE ([ReduceBatchJob](file:///home/paulo/projetos/painel-filament/app/Jobs/ReduceBatchJob.php#20-403), [ReduceDocumentAnalysisJob](file:///home/paulo/projetos/painel-filament/app/Jobs/ReduceDocumentAnalysisJob.php#32-641)).

O pipeline atual funciona de forma espetacular extraindo timelines em JSON durante o MAP e fundindo as narrativas nos reduzes, mas identifiquei duas grandes oportunidades de melhoria arquitetural (Pontos de Falha Silenciosa e Perda de Contexto):

Preciso que você implemente as seguintes melhorias técnicas baseadas nestes dois problemas mapeados:

## Problema 1: Perda de Contexto no Reduce Hierárquico (Vazamento de Entidades)

**Contexto Atual:**
No processo de redução hierárquica (ex: juntar 10 análises, depois juntar os resumos dessas 10, e assim por diante), o LLM perde detalhes críticos (como nomes de partes vitais, valores monetários cruciais e as _flags_ `[IMPORTANTE]` das timelines originais). O texto narrativo consolida, mas as "Entidades Duras" (Hard Entities) se perdem nas camadas intermediárias de "resumo do resumo".

**Requisito de Implementação:**
No [ReduceBatchJob.php](file:///home/paulo/projetos/painel-filament/app/Jobs/ReduceBatchJob.php) e [ReduceDocumentAnalysisJob.php](file:///home/paulo/projetos/painel-filament/app/Jobs/ReduceDocumentAnalysisJob.php), eu não quero depender apenas do LLM para carregar os Arrays de "Pontos Chave", "Partes Mencionadas" e "Valores Monetários".
Preciso que o **PHP atue como agregador de metadados estritos**:
1. Durante a consolidação do lote no [ReduceBatchJob](file:///home/paulo/projetos/painel-filament/app/Jobs/ReduceBatchJob.php#20-403), o PHP deve fazer um `array_merge` + `array_unique` das chaves `partes_mencionadas`, `valores_monetarios` e `pontos_chave` presentes nos JSONs de micro-análises (se presentes).
2. O resultado consolidado desses arrays estruturados deve ser trafegado pelo banco de dados (provavelmente injetado em uma coluna de metadados do modelo `DocumentMicroAnalysis` que representa o resultado do Reduce) ou repassado textualmente de forma irredutível ao próximo nível de Reduce.
3. Garanta que no *Parecer Final*, o modelo receba não apenas a narrativa mastigada pela IA, mas a lista aglutinada exata (e unificada/desduplicada pelo PHP) das entidades e pontos chave.

## Problema 2: Gerenciamento de Falhas Silenciosas em Batches (Circuit Breaker)

**Contexto Atual:**
No [DispatchMapPhaseJob.php](file:///home/paulo/projetos/painel-filament/app/Jobs/DispatchMapPhaseJob.php) (e similares de Reduce), os batches são disparados com `->allowFailures()`.
Se houver um problema isolado num documento, tudo bem, a análise continua.
Contudo, se a API da OpenRouter/Anthropic cair, e 80 de 100 jobs de MAP daquele lote retornarem falha, o `.then()` do Bus continua sendo engatilhado. O sistema pegará as 20 análises que sobreviveram e forjará um Parecer Final falso/incompleto, sem alertar o usuário do desastre oculto.

**Requisito de Implementação:**
Implemente um mecanismo de **"Circuit Breaker" ou "Gatilho de Aborto de Qualidade"** nas callbacks dos Batches de processamento paralelo suportados pelo Laravel ([DispatchMapPhaseJob.php](file:///home/paulo/projetos/painel-filament/app/Jobs/DispatchMapPhaseJob.php) e consolidações):
1. Dentro do `then()` (ou outro momento razoável), calcule a taxa de mortalidade do Batch (ex: `$batch->failedJobs / $batch->totalJobs`).
2. Defina uma constante/configuração de limite de tolerância (ex: se mais de 25% dos jobs do batch falharam, a análise integral falha e aborta a fase seguinte).
3. Se o limite de falhas for excedido, o pipeline não deve avançar para o Reduce / Final Parecer. A entidade [DocumentAnalysis](file:///home/paulo/projetos/painel-filament/app/Jobs/MapDocumentAnalysisJob.php#31-630) alvo deve ter seu status setado para `failed` com um `error_message` claro: "Análise abortada: X% dos documentos falharam ao processar".
4. Dispare uma notificação ao usuário usando o serviço existente relatando que a análise falhou por alta taxa de erros na API (com a contagem real).

---

## Saída Esperada:
Entregue as alterações exatas necessárias no código, focando na precisão técnica para Laravel 11 / PHP 8.2+.
Sua resposta deve demonstrar senioridade: não altere código desnecessário e garanta tipagem e performance adequadas para arrays grandes. Mostre-me as alterações bloco a bloco nos arquivos pertinentes.
