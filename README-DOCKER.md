# 🐳 Painel de Análise de Processos - Docker

Documentação completa para executar o sistema com Docker.

## 📋 Pré-requisitos

- **Docker** >= 24.0
- **Docker Compose** >= 2.20
- **Make** (opcional, mas recomendado)
- Mínimo 4GB RAM disponível
- 10GB de espaço em disco

## 🏗️ Arquitetura

O ambiente Docker é composto por 5 containers:

```
┌─────────────────────────────────────────────────────┐
│                    Nginx (Port 8000)                │
│              Servidor Web / Reverse Proxy            │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────┐
│               PHP-FPM 8.4 (app)                     │
│          Aplicação Laravel + Filament                │
└─────┬──────────────┬─────────────────┬──────────────┘
      │              │                 │
      ▼              ▼                 ▼
┌──────────┐   ┌──────────┐    ┌─────────────┐
│PostgreSQL│   │  Redis   │    │   Queue     │
│  (DB)    │   │ (Cache)  │    │  (Worker)   │
└──────────┘   └──────────┘    └─────────────┘
```

### Containers:

1. **app** - PHP 8.4-FPM com Laravel
2. **nginx** - Servidor web
3. **postgres** - Banco de dados PostgreSQL 16
4. **redis** - Cache e gerenciamento de filas
5. **queue** - Worker para processar análises de IA

## 🚀 Instalação Rápida

### Opção 1: Usando Make (Recomendado)

```bash
# 1. Clone o repositório
git clone <url-do-repo>
cd painel

# 2. Instale tudo automaticamente
make install
```

Isso irá:
- Copiar `.env.docker` para `.env`
- Buildar as imagens
- Subir os containers
- Gerar APP_KEY
- Executar migrations
- Criar usuário admin padrão

### Opção 2: Manual

```bash
# 1. Copie o arquivo de ambiente
cp .env.docker .env

# 2. Edite as variáveis (especialmente as senhas e API keys)
nano .env

# 3. Build das imagens
docker compose build

# 4. Suba os containers
docker compose up -d

# 5. Gere a chave da aplicação
docker compose exec app php artisan key:generate

# 6. Execute as migrations
docker compose exec app php artisan migrate --seed
```

## 🔧 Configuração

### 1. Arquivo .env

Edite o `.env` e configure:

#### Obrigatório:
```env
# Mude para uma senha forte
DB_PASSWORD=sua_senha_forte_aqui

# Configure suas API Keys
GEMINI_API_KEY=sua_chave_gemini
DEEPSEEK_API_KEY=sua_chave_deepseek
```

#### Opcional:
```env
# Porta da aplicação (padrão: 8000)
APP_PORT=8000

# URL da aplicação
APP_URL=http://localhost:8000

# Webservice do e-Proc (se diferente)
URL_EPROC_WEBSERVICE=https://projudi.tjms.jus.br/projudi/intercomunicacao
```

### 2. Primeiro Acesso

Após a instalação, acesse:

```
http://localhost:8000
```

**Credenciais padrão** (se executou seeders):
- Email: `admin@painel.local`
- Senha: `password`

⚠️ **IMPORTANTE**: Troque a senha padrão imediatamente!

## 📚 Comandos Úteis (Make)

O Makefile fornece atalhos convenientes:

```bash
make help              # Mostra todos os comandos disponíveis
make up                # Inicia os containers
make down              # Para os containers
make restart           # Reinicia os containers
make logs              # Visualiza logs de todos os containers
make logs-app          # Logs apenas da aplicação
make logs-queue        # Logs do worker de filas
make shell             # Abre shell no container da aplicação
make db-shell          # Abre shell no PostgreSQL
make redis-cli         # Abre Redis CLI
make migrate           # Executa migrations
make seed              # Executa seeders
make tinker            # Abre Laravel Tinker
make clear-cache       # Limpa todos os caches
make optimize          # Otimiza para produção
make queue-restart     # Reinicia workers da fila
make composer-install  # Instala dependências PHP
make npm-build         # Builda assets do frontend
make status            # Mostra status dos containers
make stats             # Mostra estatísticas de uso
```

## 🔍 Comandos Docker Compose (Manual)

Se preferir usar docker compose diretamente:

```bash
# Subir containers
docker compose up -d

# Parar containers
docker compose down

# Ver logs
docker compose logs -f app

# Executar comandos dentro do container
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker

# Acessar shell
docker compose exec app bash

# Ver status
docker compose ps
```

## 🗄️ Banco de Dados

### Conexão ao PostgreSQL

**Do host:**
```bash
make db-shell

# Ou manualmente:
docker compose exec postgres psql -U painel_user -d painel
```

**De outro cliente (DBeaver, pgAdmin, etc):**
```
Host: localhost
Port: 5432
Database: painel
Username: painel_user
Password: (definido no .env)
```

### Backup

```bash
# Exportar backup
docker compose exec postgres pg_dump -U painel_user painel > backup.sql

# Restaurar backup
docker compose exec -T postgres psql -U painel_user painel < backup.sql
```

## 🔴 Redis

### Acessar Redis CLI

```bash
make redis-cli

# Ver todas as chaves
KEYS *

# Limpar cache
FLUSHDB

# Ver estatísticas
INFO
```

## 📊 Filas (Queue)

### Monitorar Workers

```bash
# Ver logs em tempo real
make logs-queue

# Reiniciar workers
make queue-restart
```

### Processar jobs manualmente

```bash
docker compose exec app php artisan queue:work --once
```

## 🐛 Troubleshooting

### Containers não sobem

```bash
# Verifica logs
make logs

# Verifica se portas estão em uso
lsof -i :8000
lsof -i :5432
lsof -i :6379

# Recria tudo do zero
docker compose down -v
make install
```

### Erro de permissão

```bash
# Ajusta permissões
docker compose exec app chmod -R 775 storage bootstrap/cache
docker compose exec app chown -R appuser:appuser storage bootstrap/cache
```

### Cache de configuração desatualizado

```bash
make clear-cache
# Ou
docker compose exec app php artisan config:clear
```

### Worker não processa jobs

```bash
# Verifica logs do worker
make logs-queue

# Reinicia worker
make queue-restart

# Testa processamento manual
docker compose exec app php artisan queue:work --once --verbose
```

### Migrations falham

```bash
# Verifica conexão com banco
docker compose exec app php artisan db:show

# Recria banco (APAGA TUDO!)
make migrate-fresh
```

## 🔐 Segurança

### Para Produção:

1. **Mude todas as senhas padrão:**
   ```env
   DB_PASSWORD=senha_forte_complexa
   ```

2. **Configure APP_KEY único:**
   ```bash
   docker compose exec app php artisan key:generate
   ```

3. **Desabilite debug:**
   ```env
   APP_DEBUG=false
   APP_ENV=production
   ```

4. **Use HTTPS:**
   - Configure certificado SSL no Nginx
   - Atualize `APP_URL` para `https://`

5. **Limite recursos:**
   ```yaml
   # No docker compose.yml, adicione:
   services:
     app:
       deploy:
         resources:
           limits:
             cpus: '2'
             memory: 2G
   ```

## 📈 Performance

### Otimizar para produção:

```bash
make optimize
```

Isso executa:
- `config:cache`
- `route:cache`
- `view:cache`
- `event:cache`
- `filament:cache-components`

### Monitorar recursos:

```bash
make stats
```

## 🔄 Atualizações

```bash
# 1. Para os containers
make down

# 2. Atualiza código
git pull origin main

# 3. Rebuilda imagens
make build

# 4. Sobe containers
make up

# 5. Atualiza dependências e banco
make composer-install
make migrate
make optimize
```

## 📝 Logs

Logs são armazenados em `storage/logs/`:

- `laravel.log` - Log principal da aplicação
- `worker.log` - Logs do queue worker
- `supervisord.log` - Logs do Supervisor
- `php-errors.log` - Erros do PHP
- `php-fpm-access.log` - Acessos PHP-FPM
- `php-fpm-slow.log` - Queries lentas

```bash
# Ver logs da aplicação
tail -f storage/logs/laravel.log

# Ver logs do worker
tail -f storage/logs/worker.log
```

## 🧹 Limpeza

### Remover containers e dados:

```bash
# Remove containers mas mantém volumes (dados)
docker compose down

# Remove containers E volumes (APAGA DADOS!)
docker compose down -v

# Remove tudo (containers, volumes, imagens)
make clean
```

## 🆘 Suporte

### Informações úteis para debug:

```bash
# Status dos containers
make status

# Logs completos
make logs

# Versões instaladas
docker compose exec app php -v
docker compose exec app php artisan --version
docker compose exec postgres psql --version
```

### Recursos:

- Documentação Laravel: https://laravel.com/docs
- Documentação Filament: https://filamentphp.com/docs
- Issues do projeto: [URL do repositório]

## 📦 Estrutura de Diretórios Docker

```
.
├── Dockerfile                    # Imagem principal PHP 8.4
├── docker compose.yml           # Orquestração de containers
├── docker-entrypoint.sh         # Script de inicialização
├── Makefile                     # Comandos facilitadores
├── .env.docker                  # Template de configuração
└── docker/
    ├── nginx/
    │   ├── nginx.conf          # Configuração global Nginx
    │   └── conf.d/
    │       └── app.conf        # Virtual host da aplicação
    ├── php/
    │   ├── php.ini             # Configurações PHP
    │   └── www.conf            # Configurações PHP-FPM
    └── supervisor/
        └── supervisord.conf    # Configuração do Supervisor
```

## ✅ Checklist Pós-Instalação

- [ ] Alterar senha padrão do admin
- [ ] Configurar API keys (Gemini e/ou DeepSeek)
- [ ] Testar consulta de processo
- [ ] Testar envio de documento para análise
- [ ] Verificar processamento da fila
- [ ] Configurar backup automático
- [ ] Revisar logs de erro
- [ ] Configurar monitoramento (se produção)

---

**Desenvolvido com ❤️ usando Docker, Laravel e Filament**
