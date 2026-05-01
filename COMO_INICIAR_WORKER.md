# 🚀 Como Iniciar o Queue Worker para Análises de IA

## ⚠️ IMPORTANTE

O sistema de análises de documentos **requer** que o queue worker esteja rodando para processar os documentos em segundo plano.

## 🔧 Opção 1: Worker Manual (Desenvolvimento)

### Inicie o worker em um terminal separado:

```bash
php artisan queue:work --tries=2 --timeout=600
```

**Mantenha este terminal aberto** enquanto estiver usando o sistema!

### Parâmetros:
- `--tries=2`: Tenta 2 vezes em caso de falha
- `--timeout=600`: Timeout de 10 minutos por job (análises podem demorar)

### Para parar:
Pressione `Ctrl+C` no terminal do worker.

---

## 🔁 Opção 2: Worker Automático (Produção)

### 1. Usando Supervisor (Recomendado)

Crie o arquivo `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /caminho/completo/para/painel/artisan queue:work database --sleep=3 --tries=2 --max-time=3600 --timeout=600
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

**Ajuste os caminhos:**
- `/caminho/completo/para/painel` → Caminho absoluto do projeto
- `seu_usuario` → Seu usuário Linux
- `/caminho/para/logs` → Onde salvar logs

**Depois:**

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

**Comandos úteis:**

```bash
# Ver status
sudo supervisorctl status laravel-worker:*

# Parar
sudo supervisorctl stop laravel-worker:*

# Reiniciar
sudo supervisorctl restart laravel-worker:*

# Ver logs
tail -f /caminho/para/logs/worker.log
```

---

## 📊 Como Verificar se está Funcionando

### 1. Verificar se o worker está ativo:

```bash
ps aux | grep "queue:work"
```

Deve aparecer algo como:
```
usuario  12345  php artisan queue:work database
```

### 2. Verificar jobs na fila:

```bash
php artisan queue:monitor
```

### 3. Testar o sistema:

1. Acesse um processo no sistema
2. Clique em **"Enviar todos os documentos para análise"**
3. Observe o **widget de status** no topo da página:
   - Deve mostrar "Pendentes" aumentando
   - Depois "Processando"
   - Por fim "Concluídas"

### 4. Ver logs em tempo real:

```bash
tail -f storage/logs/laravel.log
```

---

## 🐛 Troubleshooting

### ❌ "Nada acontece após enviar para análise"

**Causa:** Worker não está rodando.

**Solução:**
```bash
php artisan queue:work --tries=2 --timeout=600
```

### ❌ "Análises ficam presas em 'Pendente'"

**Causa:** Worker parou ou crashou.

**Soluções:**
1. Reinicie o worker manualmente
2. Ou configure supervisor para reiniciar automaticamente
3. Verifique logs: `tail -f storage/logs/laravel.log`

### ❌ "Erro: pdftotext não está disponível"

**Causa:** Biblioteca não instalada.

**Solução:**
```bash
sudo apt-get install poppler-utils
```

### ❌ "Erro: GEMINI_API_KEY não configurado"

**Causa:** Variável de ambiente não configurada.

**Solução:**
```bash
# Adicione no .env:
GEMINI_API_KEY=sua_chave_aqui

# Depois:
php artisan config:clear
```

### ❌ "Jobs falhados acumulando"

**Ver jobs falhados:**
```bash
php artisan queue:failed
```

**Reprocessar todos:**
```bash
php artisan queue:retry all
```

**Limpar falhados:**
```bash
php artisan queue:flush
```

---

## 📈 Monitoramento

### Dashboard de Status

O sistema mostra automaticamente na página:
- ✅ **Pendentes**: Aguardando processamento
- 🔄 **Processando**: Em análise pela IA
- ✓ **Concluídas**: Prontas para visualização
- ✗ **Falhas**: Verifique os logs

### Polling Automático

O widget **atualiza automaticamente a cada 5 segundos** quando há análises em andamento.

---

## 🎯 Fluxo Completo

```
1. Usuário clica "Enviar para Análise"
   ↓
2. Sistema cria jobs na fila
   ↓
3. Worker processa (converte PDF → envia para IA)
   ↓
4. Sistema salva análise no banco
   ↓
5. Usuário visualiza resultado no widget/lista
```

---

## 💡 Dicas

1. **Desenvolvimento**: Use `php artisan queue:work` em terminal separado
2. **Produção**: Configure supervisor para auto-restart
3. **Monitoramento**: Ative o widget de status na página
4. **Performance**: Aumente `numprocs` no supervisor para processar mais rápido
5. **Logs**: Sempre monitore `storage/logs/laravel.log` para debugar

---

## ✅ Checklist Antes de Usar

- [ ] Worker rodando (`ps aux | grep queue:work`)
- [ ] `poppler-utils` instalado (`which pdftotext`)
- [ ] `GEMINI_API_KEY` configurado no `.env`
- [ ] Prompt padrão criado no sistema
- [ ] Migrations executadas (`php artisan migrate`)

---

**Versão**: 1.0.0
**Última atualização**: Dezembro 2025
