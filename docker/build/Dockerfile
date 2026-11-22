# Images figées par empreinte ; renouveler explicitement ces références.
FROM node:24-bookworm-slim@sha256:c86ecead9e55a5210ac1727de38a4ca840bf03dcaf34dc9749addbf383b1920c AS node
FROM composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c AS composer
FROM php:8.4-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libcurl4-openssl-dev libicu-dev libonig-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring mysqli pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/tmp/tycms-composer \
    npm_config_cache=/tmp/tycms-npm
WORKDIR /work
ENTRYPOINT ["php", "/build-tools/build.php", "--compile", "/work"]
