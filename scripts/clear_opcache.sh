#!/bin/bash
# Скрипт для очистки opcache в PHP-FPM контейнере

CONTAINER_NAME=$(docker ps --format "{{.Names}}" | grep -E "php-fpm|php" | head -n 1)

if [ -z "$CONTAINER_NAME" ]; then
    echo "Ошибка: не найден контейнер PHP-FPM"
    exit 1
fi

echo "Очистка opcache в контейнере: $CONTAINER_NAME"

# Очищаем opcache через PHP скрипт
docker exec -it "$CONTAINER_NAME" php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'opcache очищен'; } else { echo 'opcache не доступен'; }"

# Также очищаем кэш OPDS
docker exec -it "$CONTAINER_NAME" rm -rf /application/cache/opds/*

echo "Готово!"
