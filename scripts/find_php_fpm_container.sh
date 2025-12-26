#!/bin/bash
# Скрипт для поиска правильного имени контейнера PHP-FPM

echo "Поиск контейнера PHP-FPM..."
echo ""

# Вариант 1: Поиск через docker ps
echo "=== Вариант 1: Поиск через docker ps ==="
docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}" | grep -i php

echo ""
echo "=== Вариант 2: Поиск через docker-compose ==="
# Попробуем найти через docker-compose
cd "$(dirname "$0")/.." 2>/dev/null || cd /flibusta_test 2>/dev/null || cd /flibusta 2>/dev/null

if [ -f "docker-compose.yml" ]; then
    echo "Найден docker-compose.yml"
    echo "Имя проекта: $(docker-compose config --services | head -1)"
    
    # Получаем имя контейнера через docker-compose
    CONTAINER_NAME=$(docker-compose ps php-fpm --format json 2>/dev/null | grep -o '"Name":"[^"]*"' | head -1 | cut -d'"' -f4)
    
    if [ -n "$CONTAINER_NAME" ]; then
        echo "Найден контейнер: $CONTAINER_NAME"
        echo ""
        echo "Команда для запуска тестов:"
        echo "docker exec -it $CONTAINER_NAME php /application/tests/test_opds.php"
    else
        echo "Контейнер не найден через docker-compose"
    fi
else
    echo "docker-compose.yml не найден в текущей директории"
fi

echo ""
echo "=== Вариант 3: Все контейнеры с 'php' в имени ==="
docker ps --format "{{.Names}}" | grep -i php

echo ""
echo "=== Рекомендация ==="
echo "Используйте команду:"
echo "docker ps | grep php-fpm"
echo ""
echo "Или попробуйте:"
echo "docker exec -it \$(docker ps | grep php-fpm | awk '{print \$1}') php /application/tests/test_opds.php"
