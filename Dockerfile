FROM php:8.4-fpm

# Argumentos de build
ARG USER_ID=1000
ARG GROUP_ID=1000

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    zip \
    unzip \
    supervisor \
    nginx \
    poppler-utils \
    ghostscript \
    && rm -rf /var/lib/apt/lists/*

# Verificar instalação do pdftotext (necessário para spatie/pdf-to-text)
RUN which pdftotext && pdftotext -v

# Configurar extensão GD com suporte a JPEG e FreeType
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Instalar extensões PHP
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    soap \
    sockets \
    intl

# Instalar Redis
RUN pecl install redis && docker-php-ext-enable redis

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Criar usuário não-root
RUN groupadd -g ${GROUP_ID} appuser && \
    useradd -u ${USER_ID} -g appuser -m -s /bin/bash appuser

# Configurar diretório de trabalho
WORKDIR /var/www/html

# Copiar configurações customizadas do PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
RUN chmod 644 /usr/local/etc/php-fpm.d/www.conf

# Copiar composer files primeiro para cache de layers
COPY composer.json composer.lock /var/www/html/

# Instalar dependências do Composer (como root para criar vendor)
# Isso inclui spatie/pdf-to-text que depende do poppler-utils (pdftotext) instalado acima
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copiar resto dos arquivos do projeto
COPY --chown=appuser:appuser . /var/www/html

# Executar scripts do composer e ajustar permissões
RUN composer dump-autoload --optimize && \
    mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache database && \
    chown -R appuser:appuser /var/www/html && \
    chmod -R 775 storage bootstrap/cache database

# Copiar e tornar executável o entrypoint
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expor porta do PHP-FPM
EXPOSE 9000

# Definir entrypoint e comando padrão
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]
