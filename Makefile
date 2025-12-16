# Makefile para facilitar gerenciamento do Docker

.PHONY: help build up down restart logs shell db-shell queue-logs clear-cache migrate seed install

# Cores para output
GREEN=\033[0;32m
YELLOW=\033[1;33m
NC=\033[0m # No Color

help: ## Mostra este menu de ajuda
	@echo "${GREEN}========================================${NC}"
	@echo "${GREEN}  Painel de Análise de Processos${NC}"
	@echo "${GREEN}========================================${NC}"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  ${YELLOW}%-20s${NC} %s\n", $$1, $$2}'
	@echo ""

install: ## Primeira instalação (build + up + migrate)
	@echo "${GREEN}🚀 Instalando aplicação...${NC}"
	cp .env.docker .env
	docker compose build --no-cache
	docker compose up -d
	@echo "${YELLOW}⏳ Aguardando containers ficarem prontos...${NC}"
	sleep 10
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate --seed
	@echo "${GREEN}✅ Instalação concluída!${NC}"
	@echo "${GREEN}🌐 Acesse: http://localhost:8000${NC}"

build: ## Builda as imagens Docker
	@echo "${GREEN}🔨 Buildando imagens...${NC}"
	docker compose build

up: ## Sobe os containers
	@echo "${GREEN}🚀 Subindo containers...${NC}"
	docker compose up -d
	@echo "${GREEN}✅ Containers iniciados!${NC}"

down: ## Para os containers
	@echo "${YELLOW}🛑 Parando containers...${NC}"
	docker compose down

restart: ## Reinicia os containers
	@echo "${YELLOW}🔄 Reiniciando containers...${NC}"
	docker compose restart

logs: ## Mostra logs de todos os containers
	docker compose logs -f

logs-app: ## Mostra logs do container da aplicação
	docker compose logs -f app

logs-nginx: ## Mostra logs do Nginx
	docker compose logs -f nginx

logs-queue: ## Mostra logs do worker de filas
	docker compose logs -f queue

shell: ## Abre shell no container da aplicação
	docker compose exec app bash

db-shell: ## Abre shell no PostgreSQL
	docker compose exec postgres psql -U painel_user -d painel

redis-cli: ## Abre Redis CLI
	docker compose exec redis redis-cli

clear-cache: ## Limpa todos os caches
	@echo "${YELLOW}🧹 Limpando caches...${NC}"
	docker compose exec app php artisan cache:clear
	docker compose exec app php artisan config:clear
	docker compose exec app php artisan route:clear
	docker compose exec app php artisan view:clear
	@echo "${GREEN}✅ Caches limpos!${NC}"

migrate: ## Executa migrations
	@echo "${GREEN}📊 Executando migrations...${NC}"
	docker compose exec app php artisan migrate

migrate-fresh: ## Recria banco de dados (CUIDADO!)
	@echo "${YELLOW}⚠️  ATENÇÃO: Isso irá apagar todos os dados!${NC}"
	@read -p "Tem certeza? [y/N] " -n 1 -r; \
	echo; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		docker compose exec app php artisan migrate:fresh --seed; \
	fi

seed: ## Executa seeders
	docker compose exec app php artisan db:seed

tinker: ## Abre o Tinker (REPL do Laravel)
	docker compose exec app php artisan tinker

test: ## Executa testes
	docker compose exec app php artisan test

optimize: ## Otimiza a aplicação (caches)
	@echo "${GREEN}⚡ Otimizando aplicação...${NC}"
	docker compose exec app php artisan config:cache
	docker compose exec app php artisan route:cache
	docker compose exec app php artisan view:cache
	docker compose exec app php artisan event:cache
	docker compose exec app php artisan filament:cache-components
	@echo "${GREEN}✅ Otimização concluída!${NC}"

queue-restart: ## Reinicia workers da fila
	docker compose exec app php artisan queue:restart

composer-install: ## Instala dependências do Composer
	docker compose exec app composer install

composer-update: ## Atualiza dependências do Composer
	docker compose exec app composer update

npm-install: ## Instala dependências do NPM
	docker compose exec app npm install

npm-build: ## Builda assets do frontend
	docker compose exec app npm run build

clean: ## Remove containers, volumes e imagens
	@echo "${YELLOW}🗑️  Removendo tudo (containers, volumes, imagens)...${NC}"
	docker compose down -v --rmi all
	@echo "${GREEN}✅ Limpeza concluída!${NC}"

status: ## Mostra status dos containers
	docker compose ps

stats: ## Mostra estatísticas de uso dos containers
	docker stats
