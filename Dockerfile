FROM php:8.3-fpm

WORKDIR /var/www/html

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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

