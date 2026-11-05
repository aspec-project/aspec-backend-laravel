# Imagem do backend para o Render (runtime Docker, Root Directory Aspec_Backend).
# PHP 8.5: o composer.lock exige PHP >= 8.4.1 e é a versão usada localmente e nos testes.
FROM php:8.5.12-apache

# Extensões em falta na imagem oficial: bcmath, intl (Collator, Normalizer), gd com JPEG/PNG/WebP/FreeType
# (dompdf e validação de imagens), pdo_mysql (TiDB) e zip. fileinfo, mbstring e openssl já vêm na imagem.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        unzip \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libfreetype-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" bcmath intl gd pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-aspec.ini"

# O Listen passa para o vhost, com a porta que o Render injeta em PORT.
RUN a2enmod rewrite headers \
    && echo "# Listen definido em sites-available/000-default.conf (variável PORT)." > /etc/apache2/ports.conf
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
ENV PORT=10000

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Dependências primeiro: esta camada só se refaz quando o composer.json ou o composer.lock mudam.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .

# As caches do Laravel (config, rotas, vistas) são criadas no arranque (docker/start.sh), porque as
# variáveis de ambiente do Render só existem em runtime.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/private \
        bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && composer check-platform-reqs --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/start.sh

EXPOSE 10000

CMD ["/var/www/html/docker/start.sh"]
