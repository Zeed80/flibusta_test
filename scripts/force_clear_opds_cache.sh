#!/bin/sh
# Скрипт для принудительной очистки всех кэшей OPDS
# Используется sh вместо bash для совместимости с Alpine контейнерами

CONTAINER_NAME=$(docker ps --format "{{.Names}}" | grep -E "php-fpm|php" | head -n 1)

if [ -z "$CONTAINER_NAME" ]; then
    echo "Ошибка: не найден контейнер PHP-FPM"
    exit 1
fi

echo "Принудительная очистка всех кэшей OPDS в контейнере: $CONTAINER_NAME"

# 1. Очищаем opcache
echo "1. Очистка opcache..."
docker exec "$CONTAINER_NAME" php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'opcache очищен'; } else { echo 'opcache не доступен'; }"

# 2. Инвалидируем все файлы OPDS
echo "2. Инвалидация opcache для OPDS файлов..."
docker exec "$CONTAINER_NAME" php -r "\$files = ['/application/opds/main.php', '/application/opds/list.php', '/application/opds/search_book.php', '/application/functions.php', '/application/opds/core/OPDSEntry.php', '/application/opds/core/OPDSFeed.php']; foreach (\$files as \$file) { if (function_exists('opcache_invalidate') && file_exists(\$file)) { opcache_invalidate(\$file, true); } } echo 'Файлы инвалидированы';"

# 3. Очищаем файловый кэш OPDS
echo "3. Очистка файлового кэша OPDS..."
docker exec "$CONTAINER_NAME" sh -c "rm -rf /application/cache/opds/*"

# 4. Проверяем результат
echo "4. Проверка..."
CACHE_COUNT=$(docker exec "$CONTAINER_NAME" sh -c "find /application/cache/opds -type f 2>/dev/null | wc -l" | tr -d ' ')
echo "Осталось файлов в кэше: $CACHE_COUNT"

echo "Готово! Все кэши очищены."
