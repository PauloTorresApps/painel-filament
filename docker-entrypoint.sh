#!/bin/bash
set -e

echo "🚀 Iniciando Painel de Análise de Processos..."

# Aguarda banco de dados estar pronto
echo "⏳ Aguardando PostgreSQL..."
until pg_isready -h postgres -U ${DB_USERNAME:-painel_user} -d ${DB_DATABASE:-painel} > /dev/null 2>&1; do
    echo "  Aguardando PostgreSQL ficar disponível..."
    sleep 2
done
echo "✅ PostgreSQL está pronto!"

# Aguarda Redis estar pronto
echo "⏳ Aguardando Redis..."
until redis-cli -h redis ping > /dev/null 2>&1; do
    echo "  Aguardando Redis ficar disponível..."
    sleep 2
done
echo "✅ Redis está pronto!"

# Gera APP_KEY se não existir ou estiver vazia
if [ -f .env ]; then
    # Lê o valor de APP_KEY do arquivo .env
    ENV_APP_KEY=$(grep "^APP_KEY=" .env | cut -d '=' -f2)

    # Verifica se está vazia, não definida ou com valor padrão
    if [ -z "$ENV_APP_KEY" ] || [ "$ENV_APP_KEY" = "base64:CHANGE_THIS_KEY" ] || [ "$ENV_APP_KEY" = "base64:temp" ]; then
        echo "🔑 APP_KEY não encontrada ou inválida. Gerando nova chave..."
        php artisan key:generate --force --ansi
        echo "✅ APP_KEY gerada com sucesso!"
    else
        echo "✅ APP_KEY já está configurada"
    fi
else
    echo "⚠️  Arquivo .env não encontrado. Pulando geração de APP_KEY."
fi

# Cria link simbólico do storage
echo "🔗 Criando link simbólico do storage..."
php artisan storage:link || true

# Executa migrations
echo "📊 Executando migrations do banco de dados..."
php artisan migrate --force

# Otimiza aplicação para produção
if [ "$APP_ENV" = "production" ]; then
    echo "⚡ Otimizando para produção..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan filament:cache-components
fi

# Limpa caches antigos
echo "🧹 Limpando caches..."
php artisan cache:clear
php artisan config:clear

echo "✅ Inicialização concluída!"
echo "🌐 Aplicação disponível em: ${APP_URL}"

# Executa o comando passado
exec "$@"
