FROM php:8.1-fpm-alpine@sha256:a4a7d7f277f087a709822729d68140887826c0f33b3d8f3c287e735958c4e5b38d46d1f9b6634b8b3a7

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

WORKDIR "/application"

RUN apk --update add postgresql-client;\
  apk add python3;\
  apk add coreutils;\
  apk add php-fpm-healthcheck;
RUN chmod +x /usr/local/bin/install-php-extensions; install-php-extensions gd bz2 pdo_pgsql zip
