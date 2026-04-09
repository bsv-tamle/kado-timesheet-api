ARG DOCKER_REGISTRY=docker.io

# -----------------------------
# Stage 1: Composer
# -----------------------------
FROM ${DOCKER_REGISTRY}/composer:2.9.3 AS composer

FROM ${DOCKER_REGISTRY}/php:8.5-fpm AS base

ENV TZ=Asia/Tokyo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
      git \
      unzip \
      curl \
      libpq-dev \
      libzip-dev \
      libicu-dev \
      libonig-dev \
    && docker-php-ext-install \
      pdo \
      pdo_pgsql \
      pgsql \
      bcmath \
      intl \
      mbstring \
      zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

FROM base AS app

WORKDIR /var/www/html

# Use the default production configuration for PHP-FPM ($PHP_INI_DIR variable already set by the default image)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy files from current folder to container current folder (set in workdir).
COPY ./src .

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Install dependencies
RUN composer install --no-ansi --no-dev --no-interaction --no-progress --optimize-autoloader && composer clear-cache

# Adjust user permission & group.
RUN usermod --uid 1000 www-data
RUN groupmod --gid 1000 www-data

RUN chgrp -HR www-data storage bootstrap/cache
RUN chmod -Rf ug+rwx storage/ bootstrap/

COPY scripts/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Run the entrypoint file.
ENTRYPOINT [ "/bin/bash", "/usr/local/bin/entrypoint.sh" ]
