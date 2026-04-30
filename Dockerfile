FROM php:8.3-fpm-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install intl pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --gid ${GID} pflegedex \
    && useradd --uid ${UID} --gid ${GID} --create-home --shell /bin/bash pflegedex

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/pflegedex.ini
COPY docker/php/entrypoint.sh /usr/local/bin/pflegedex-entrypoint
RUN chmod +x /usr/local/bin/pflegedex-entrypoint

USER pflegedex

ENTRYPOINT ["pflegedex-entrypoint"]
CMD ["php-fpm"]
