# 🤖 Integração com DeepSeek AI

## 📋 Visão Geral

O sistema agora suporta **dois provedores de IA** para análise de documentos processuais:

1. **Google Gemini** (padrão) - Modelo generativo do Google
2. **DeepSeek** (novo) - Modelo de IA chinês com excelente custo-benefício

Você pode escolher qual IA usar ao criar ou editar um **Prompt de IA** no sistema.

---

## 🔧 Configuração do DeepSeek

### 1. Obter API Key do DeepSeek

1. Acesse: https://platform.deepseek.com/
2. Crie uma conta ou faça login
3. Navegue até **API Keys**
4. Clique em **"Create API Key"**
5. Copie a chave gerada (formato: `sk-...`)

### 2. Configurar no Sistema

Adicione as seguintes variáveis no arquivo `.env`:

```bash
# DeepSeek AI Configuration
DEEPSEEK_API_KEY=sk-sua-chave-aqui
DEEPSEEK_API_URL=https://api.deepseek.com/v1
DEEPSEEK_MODEL=deepseek-chat
```

**Modelos disponíveis:**
- `deepseek-chat` (padrão) - Modelo de chat geral
- `deepseek-coder` - Especializado em código (não recomendado para jurídico)

### 3. Limpar Cache de Configuração

Após adicionar as variáveis no `.env`:

```bash
php artisan config:clear
php artisan cache:clear
```

### 4. Reiniciar o Queue Worker

**IMPORTANTE:** Reinicie o worker para carregar as novas configurações:

```bash
# Pare o worker atual (Ctrl+C no terminal)
# Depois inicie novamente:
php artisan queue:work --tries=2 --timeout=600
```

---

## 🎯 Como Usar

### Criando um Prompt com DeepSeek

1. Acesse **Prompts de IA** no menu lateral
2. Clique em **"Criar Novo Prompt"**
3. Preencha os campos:
   - **Usuário**: Selecione o usuário (Admin/Manager)
   - **Sistema**: Escolha o sistema judicial
   - **Título**: Nome descritivo (ex: "Análise de Petições - DeepSeek")
   - **Provedor de IA**: Selecione **"DeepSeek"** ⬅️ **NOVO!**
   - **Conteúdo do Prompt**: Digite as instruções para a IA
   - **Ativo**: Marque como ativo
   - **Prompt Padrão**: Marque se quiser usar como padrão

4. Clique em **"Salvar"**

### Diferença entre Gemini e DeepSeek

| Característica | Google Gemini | DeepSeek |
|---------------|---------------|----------|
| **Custo** | $$$ | $ (mais barato) |
| **Velocidade** | Rápido | Muito rápido |
| **Contexto** | 8K tokens | 4K tokens |
| **Idioma** | Excelente PT-BR | Bom PT-BR |
| **Especialização** | Geral | Conversação |
| **Disponibilidade** | Global | China + Global |

### Quando Usar Cada Um?

**Use Gemini quando:**
- Precisar de análises complexas e detalhadas
- Trabalhar com documentos muito longos
- Necessitar máxima precisão jurídica
- Tiver orçamento disponível

**Use DeepSeek quando:**
- Quiser reduzir custos de API
- Precisar de respostas rápidas
- Trabalhar com documentos menores/médios
- Estiver em fase de testes

---

## 📊 Testando a Integração

### 1. Verificar Configuração

```bash
# Ver se as variáveis estão carregadas
php artisan tinker

>>> config('services.deepseek.api_key')
=> "sk-..."

>>> config('services.deepseek.model')
=> "deepseek-chat"
```

### 2. Testar Health Check

Crie um arquivo de teste `test-deepseek.php`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = new \App\Services\DeepSeekService();

if ($service->healthCheck()) {
    echo "✅ DeepSeek está funcionando!\n";
    echo "Nome do provider: " . $service->getName() . "\n";
} else {
    echo "❌ DeepSeek não está acessível\n";
}
```

Execute:
```bash
php test-deepseek.php
```

### 3. Testar Análise Real

1. Crie um prompt com DeepSeek como provider
2. Marque como padrão
3. Acesse um processo
4. Clique em **"Enviar todos os documentos para análise"**
5. Observe os logs:

```bash
tail -f storage/logs/laravel.log | grep -i deepseek
```

Você deve ver:
```
[INFO] Enviando para análise via DeepSeek
```

---

## 🐛 Troubleshooting

### ❌ "DEEPSEEK_API_KEY não configurado no .env"

**Causa:** Variável de ambiente não encontrada.

**Solução:**
```bash
# Adicione no .env:
DEEPSEEK_API_KEY=sk-sua-chave-aqui

# Limpe o cache:
php artisan config:clear
```

### ❌ "Chave de API DeepSeek inválida ou sem permissões"

**Causa:** API key incorreta ou expirada.

**Solução:**
1. Acesse https://platform.deepseek.com/
2. Gere uma nova API key
3. Atualize no `.env`
4. Limpe cache: `php artisan config:clear`

### ❌ "Limite de uso da API DeepSeek excedido"

**Causa:** Quota mensal/diária atingida.

**Solução:**
1. Aguarde reset da quota (geralmente diário)
2. Ou faça upgrade do plano em https://platform.deepseek.com/
3. Ou alterne temporariamente para Gemini

### ❌ "Provider de IA 'deepseek' não suportado"

**Causa:** Worker não foi reiniciado após mudanças no código.

**Solução:**
```bash
# Pare o worker (Ctrl+C)
# Reinicie:
php artisan queue:work --tries=2 --timeout=600
```

### ❌ "Erro 429: Too Many Requests"

**Causa:** Muitas requisições simultâneas.

**Solução:**
- Aguarde 1-2 minutos
- Evite enviar múltiplos processos ao mesmo tempo
- Configure rate limiting no código se necessário

---

## 💰 Estimativa de Custos

### DeepSeek Pricing (aproximado)

- **Input**: $0.14 por 1M tokens
- **Output**: $0.28 por 1M tokens

### Exemplo Prático

Análise de 10 documentos (média 500 palavras cada):
- Tokens de entrada: ~6,500 tokens
- Tokens de saída: ~2,000 tokens
- **Custo total**: ~$0.0015 (menos de 1 centavo de dólar)

### Comparação com Gemini

| Análises/Mês | DeepSeek | Gemini Flash | Economia |
|-------------|----------|--------------|----------|
| 100 | $0.15 | $0.75 | 80% |
| 1,000 | $1.50 | $7.50 | 80% |
| 10,000 | $15.00 | $75.00 | 80% |

---

## 📝 Boas Práticas

### 1. Use Prompts Específicos

Crie prompts diferentes para cada tipo de análise:
- `Análise de Petições Iniciais - DeepSeek`
- `Extração de Dados - DeepSeek`
- `Resumo de Sentença - DeepSeek`

### 2. Monitore o Uso

Acompanhe no dashboard do DeepSeek:
- Total de tokens consumidos
- Custo acumulado
- Quota restante

### 3. Teste Antes de Produção

Sempre teste com documentos reais antes de marcar como padrão:
1. Crie prompt de teste
2. Envie 2-3 documentos
3. Valide a qualidade da resposta
4. Ajuste o prompt se necessário
5. Só então marque como padrão

### 4. Combine Gemini e DeepSeek

Estratégia híbrida:
- **DeepSeek**: Análises diárias, triagens, resumos rápidos
- **Gemini**: Análises complexas, pareceres detalhados, casos críticos

---

## 🔄 Migração de Gemini para DeepSeek

Se você já tem prompts configurados com Gemini e quer migrar:

1. **Não delete os prompts antigos**
2. **Duplique** o prompt existente
3. Altere apenas o campo **"Provedor de IA"** para DeepSeek
4. Ajuste o título (adicione "- DeepSeek" no final)
5. Teste com documentos reais
6. Se satisfeito, marque o novo como padrão
7. Desmarque o antigo (mas mantenha ativo para fallback)

---

## 📈 Monitoramento

### Logs do Sistema

```bash
# Ver todas análises DeepSeek
tail -f storage/logs/laravel.log | grep -i "deepseek"

# Ver erros
tail -f storage/logs/laravel.log | grep -i "erro.*deepseek"

# Ver tempo de processamento
tail -f storage/logs/laravel.log | grep "processing_time"
```

### Widget de Status

O widget **"Status das Análises de IA"** mostra em tempo real:
- ✅ Concluídas (verde)
- 🔄 Processando (amarelo)
- ❌ Falhas (vermelho)

Clique em **"Ver Análise"** para ver qual IA foi usada.

---

## 🆘 Suporte

### Links Úteis

- **DeepSeek Platform**: https://platform.deepseek.com/
- **Documentação API**: https://platform.deepseek.com/docs
- **Pricing**: https://platform.deepseek.com/pricing
- **Status**: https://status.deepseek.com/

### Em Caso de Problemas

1. Verifique os logs: `tail -f storage/logs/laravel.log`
2. Confirme API key válida
3. Teste health check
4. Reinicie o worker
5. Se persistir, alterne para Gemini temporariamente

---

**Versão**: 1.0.0
**Última atualização**: Dezembro 2025
**Autor**: Sistema de Análise de Processos
