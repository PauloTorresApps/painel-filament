Visão Geral do Projeto: OLHADINHA - Análises Jurídicas
Uma aplicação Laravel 11 + Filament v3 para análise jurídica com IA, integrando o sistema judiciário brasileiro (e-Proc/CNJ) e processamento de documentos via OpenRouter.

Arquitetura Geral
Camada	Tecnologia
Backend	Laravel 11, Filament v3
Frontend	Livewire 3, Flux, Tailwind CSS v4
IA	OpenRouter (agregador multi-modelo)
Integração	e-Proc (SOAP), CNJ (SOAP)
Roles/Permissions	Spatie Laravel Permission
Filas	Laravel Queue com Bus::batch()
Dois Painéis Filament
Admin (/admin) — Gestão de usuários, roles, permissões, sistemas, modelos de IA e prompts. Acesso: Admin/Manager.
Analises (/analises) — Operacional. Análise de contratos e processos judiciais. Acesso por role: Admin, Manager, Analista de Contrato, Analista de Processo.
Models Principais
Model	Função
User	Usuários com roles Spatie, 2FA, preferências de notificação
AiModel	Modelos de IA com roteamento por propósito (vision, pdf_text, pdf_ocr, large_context)
AiPrompt	Prompts configuráveis por sistema e tipo, com suporte a deep thinking
ContractAnalysis	Análise de contratos com 3 fases: análise → parecer jurídico → infográfico
DocumentAnalysis	Análise de processos judiciais (orquestrador do pipeline map-reduce)
DocumentMicroAnalysis	Micro-análise individual de cada documento do processo
System / JudicialUser	Sistemas judiciais e credenciais de acesso
Setting	Configurações globais com cache
Pipeline Map-Reduce (Processos)
O coração do sistema é um pipeline distribuído:

Download — AnalyzeProcessDocuments → batch de DownloadDocumentJob (paralelo, 3s entre cada)
Map — DispatchMapPhaseJob → batch de MapDocumentAnalysisJob (análise individual com strategy pattern)
Reduce — ReduceDocumentAnalysisJob → batches hierárquicos de ReduceBatchJob até consolidação final
Strategies (padrão Strategy para o Map):

VisionProcessingStrategy — documentos como imagem (multimodal)
PdfNativeProcessingStrategy — PDF nativo com plugins pdf-text/mistral-ocr
TextProcessingStrategy — texto extraído (fallback)
Services
Service	Função
OpenRouterService	Provider de IA: text, vision, PDF nativo, web search, prompt caching
EprocService	Cliente SOAP do e-Proc (consulta processos e download de documentos via MTOM)
CnjService	Resolução de códigos de classe/assunto processual (cache 30 dias)
PdfService	Geração de PDFs (DomPDF) para relatórios e pareceres
PdfToTextService / OcrService / HtmlToTextService	Extração de texto de documentos
RateLimiterService	Rate limiting Redis para API OpenRouter
NotificationService	Notificações centralizadas via Filament
Frontend (resources/)
CSS — Tailwind v4 + Flux, tema customizado com loading overlay animado
JS — LoadingManager global que intercepta fetch, XHR, Livewire e navegação
Views principais:
Login com SVG animado (olho escaneando)
Dashboard com abas por tipo de análise
Upload de contratos via FilePond (chunked, max 100MB)
Consulta de processos com máscara CNJ
Progress tracker ABNT-style com wire:poll.5s
3 templates PDF (análise, parecer jurídico ABNT, análise processual)
2 emails HTML transacionais com anexo PDF
Padrões Notáveis
Prompt Caching — System prompts separados dos document prompts para aproveitamento do cache da Anthropic
Model Purpose Routing — Cada propósito (vision, pdf_text, etc.) atribuído a um único modelo ativo
Chunking — Documentos grandes (>50k chars) são divididos e analisados por partes
Debug System — Flag global para salvar prompts/respostas em disco como Markdown
Multi-panel auth — Redirecionamento automático para painel correto conforme role do usuário
