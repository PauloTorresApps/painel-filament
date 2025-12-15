# Sistema de Análise de Documentos com IA

Sistema integrado de análise automatizada de documentos processuais utilizando Google Gemini AI.

## 📋 Índice

- [Funcionalidades](#funcionalidades)
- [Requisitos do Sistema](#requisitos-do-sistema)
- [Instalação e Configuração](#instalação-e-configuração)
- [Como Usar](#como-usar)
- [Arquitetura e Fluxo](#arquitetura-e-fluxo)
- [Otimizações Implementadas](#otimizações-implementadas)
- [Custos e Pricing](#custos-e-pricing)
- [Troubleshooting](#troubleshooting)

## 🎯 Funcionalidades

- ✅ Análise automatizada de documentos processuais
- ✅ Conversão de PDF para texto estruturado
- ✅ Processamento assíncrono via filas
- ✅ Filtragem automática de documentos sigilosos e mídias
- ✅ Notificações em tempo real sobre progresso
- ✅ Prompts personalizáveis por usuário
- ✅ Histórico completo de análises
- ✅ Visualização formatada em Markdown
- ✅ Sistema de retry automático em caso de falhas

## 🔧 Requisitos do Sistema

### Dependências do Sistema Operacional

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install poppler-utils

# CentOS/RHEL
sudo yum install poppler-utils

# macOS
brew install poppler
```

### Dependências PHP

- PHP >= 8.2
- Extensões: fileinfo, mbstring, curl

### API Key do Google Gemini

1. Acesse https://aistudio.google.com/app/apikey
2. Crie uma nova API Key
3. Copie a chave gerada

## 📦 Instalação e Configuração

### 1. Variáveis de Ambiente

Adicione as seguintes variáveis ao seu arquivo `.env`:

```env
# Gemini AI Configuration
GEMINI_API_KEY=sua_api_key_aqui
GEMINI_API_URL=https://generativelanguage.googleapis.com/v1beta/models
GEMINI_MODEL=gemini-1.5-flash

# Opções de modelo:
# - gemini-1.5-flash: Rápido e econômico (recomendado para produção)
# - gemini-1.5-pro: Mais preciso, porém mais caro
```

### 2. Queue Worker

O sistema utiliza filas para processamento assíncrono. Configure o worker:

```bash
# Desenvolvimento (single worker)
php artisan queue:work --tries=2 --timeout=600

# Produção (com supervisor)
# Crie o arquivo /etc/supervisor/conf.d/laravel-worker.conf
```

Exemplo de configuração do Supervisor:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /caminho/para/seu/projeto/artisan queue:work database --sleep=3 --tries=2 --max-time=3600 --timeout=600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=seu_usuario
numprocs=2
redirect_stderr=true
stdout_logfile=/caminho/para/logs/worker.log
stopwaitsecs=3600
```

Depois:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

## 🚀 Como Usar

### 1. Configurar Prompt Padrão

Antes de usar o sistema, configure um prompt padrão:

1. Acesse o menu **Prompts IA** no painel
2. Clique em **Novo Prompt**
3. Preencha:
   - **Título**: Ex: "Analista de Processo"
   - **Conteúdo**: Seu prompt customizado (veja exemplo abaixo)
   - **Sistema**: Selecione o sistema apropriado
   - **Marque como Padrão**: ✅
   - **Ativo**: ✅

#### Exemplo de Prompt:

```
Você é um advogado especialista em Direito Empresarial, Tributário e Cível.

Analise profundamente cada documento anexo que faz parte de um processo de classe [nomeClasse] e assuntos [assuntos].

Para cada manifestação, identifique:
1. Tipo de manifestação (petição inicial, contestação, sentença, etc.)
2. Parte que manifestou
3. Pedidos ou alegações principais
4. Fundamentação legal utilizada
5. Documentos/provas apresentados
6. Pontos críticos e relevantes

Retorne uma análise estruturada e objetiva em formato Markdown.
```

### 2. Analisar Documentos de um Processo

1. Acesse **Consulta de Processos**
2. Busque um processo
3. Visualize os detalhes do processo
4. Clique no botão **"Enviar todos os documentos para análise"**
5. Confirme a ação no modal

### 3. Acompanhar Progresso

Durante o processamento, você receberá notificações:

- 🔵 **Análise Iniciada**: Processo começou
- 🟡 **Progresso**: A cada 5 documentos processados
- 🟢 **Análise Concluída**: Sucesso
- 🔴 **Análise Falhou**: Erro

### 4. Visualizar Resultados

1. Acesse **Análises de Documentos** no menu
2. Clique em uma análise para ver detalhes
3. A análise será exibida formatada em Markdown

## 🏗️ Arquitetura e Fluxo

### Fluxo de Processamento

```
1. Usuário → Clica "Enviar para Análise"
         ↓
2. Sistema → Filtra documentos (remove sigilosos e mídias)
         ↓
3. Sistema → Dispara Job Assíncrono
         ↓
4. Job → Para cada documento:
   4.1. Busca PDF do webservice
   4.2. Converte PDF para texto (pdftotext)
   4.3. Armazena texto no banco
   4.4. Notifica progresso
         ↓
5. Job → Envia TUDO em um único request para Gemini
         ↓
6. Job → Salva análise completa no banco
         ↓
7. Sistema → Notifica usuário (sucesso/falha)
```

### Componentes Principais

#### 1. Services

- **PdfToTextService**: Converte PDFs em texto
- **GeminiService**: Integração com API do Gemini
- **EprocService**: Busca documentos do webservice

#### 2. Jobs

- **AnalyzeProcessDocuments**: Processa documentos assincronamente

#### 3. Models

- **DocumentAnalysis**: Armazena análises
- **AiPrompt**: Gerencia prompts personalizados

## ⚡ Otimizações Implementadas

### 1. Batching Inteligente

- **Problema**: Cada documento em um request = muita latência + custo alto
- **Solução**: Todos os documentos em UM ÚNICO request
- **Benefício**: ~70% menos latência, ~50% menos custo

### 2. Processamento Assíncrono

- **Problema**: Requisições longas travam a interface
- **Solução**: Filas + Jobs + Notificações
- **Benefício**: UX fluida, sem timeouts

### 3. Filtragem Automática

- **O que é filtrado**:
  - Documentos sigilosos (nivelSigilo > 0)
  - Arquivos de mídia (imagens, vídeos)
- **Benefício**: Reduz custos e evita erros

### 4. Cache e Normalização

- Texto extraído é normalizado (remove caracteres de controle, quebras excessivas)
- Resultados são armazenados para consulta futura

### 5. Retry Automático

- Jobs tentam 2 vezes em caso de falha
- Timeout de 10 minutos por job

## 💰 Custos e Pricing

### Gemini 1.5 Flash (Recomendado)

- **Input**: $0.075 por 1M tokens
- **Output**: $0.30 por 1M tokens
- **Exemplo**: 10 documentos (~50k caracteres cada)
  - Input: ~125k tokens = $0.009
  - Output: ~2k tokens = $0.0006
  - **Total**: ~$0.01 por análise

### Gemini 1.5 Pro (Mais Preciso)

- **Input**: $3.50 por 1M tokens
- **Output**: $10.50 por 1M tokens
- **Exemplo**: Mesma análise = ~$0.50

### Dicas para Reduzir Custos

1. Use **gemini-1.5-flash** para produção
2. Filtre documentos irrelevantes antes de enviar
3. Ajuste prompts para respostas mais concisas
4. Evite reprocessar documentos já analisados

## 🐛 Troubleshooting

### Erro: "pdftotext não está disponível"

```bash
# Instale o poppler-utils
sudo apt-get install poppler-utils

# Verifique a instalação
which pdftotext
```

### Erro: "GEMINI_API_KEY não configurado"

Verifique se a chave está no `.env` e rode:

```bash
php artisan config:clear
php artisan cache:clear
```

### Jobs não estão processando

```bash
# Verifique se o worker está rodando
ps aux | grep "queue:work"

# Inicie o worker
php artisan queue:work

# Verifique jobs falhados
php artisan queue:failed

# Reprocesse jobs falhados
php artisan queue:retry all
```

### Análises ficam presas em "Processando"

```bash
# Verifique logs do Laravel
tail -f storage/logs/laravel.log

# Verifique a tabela de jobs
php artisan tinker
> DB::table('jobs')->count()

# Limpe jobs presos (cuidado!)
> DB::table('jobs')->delete()
```

### Timeout em processos grandes

Aumente o timeout no `.env`:

```env
QUEUE_CONNECTION=database
```

E no `config/queue.php`:

```php
'database' => [
    'timeout' => 900, // 15 minutos
],
```

## 📊 Monitoramento

### Métricas Importantes

Acesse **Análises de Documentos** para ver:

- Total de análises realizadas
- Taxa de sucesso/falha
- Tempo médio de processamento
- Caracteres processados

### Logs

```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Worker logs (se usando supervisor)
tail -f /var/log/supervisor/laravel-worker.log
```

## 🔐 Segurança

- ✅ Documentos sigilosos são automaticamente filtrados
- ✅ Validação de permissões de usuário
- ✅ API Key armazenada de forma segura no `.env`
- ✅ Sanitização de inputs (XSS protection)
- ✅ Isolamento por usuário (cada um vê apenas suas análises)

## 📝 Notas Finais

- O sistema foi otimizado para **minimizar custos** e **maximizar performance**
- Use **gemini-1.5-flash** em produção (rápido e barato)
- Configure **supervisor** para garantir que workers estejam sempre rodando
- Monitore custos regularmente via [Google Cloud Console](https://console.cloud.google.com)

## 🆘 Suporte

Em caso de dúvidas ou problemas:

1. Verifique este README
2. Consulte os logs do sistema
3. Entre em contato com a equipe de desenvolvimento

---

**Versão**: 1.0.0
**Última Atualização**: Dezembro 2025
