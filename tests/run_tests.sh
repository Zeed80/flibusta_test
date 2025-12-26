#!/bin/bash
# Скрипт для запуска тестов OPDS
# Использование: ./run_tests.sh

CONTAINER_NAME="flibusta_test-php-fpm-1"

echo "Запуск тестов OPDS..."
echo "Контейнер: $CONTAINER_NAME"
echo ""

# Проверяем, что контейнер запущен
if ! docker ps | grep -q "$CONTAINER_NAME"; then
    echo "❌ Ошибка: Контейнер $CONTAINER_NAME не запущен!"
    echo "Запустите: docker-compose up -d"
    exit 1
fi

# Запускаем тесты
docker exec -it "$CONTAINER_NAME" php /application/tests/test_opds.php

exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo ""
    echo "✅ Тесты завершены успешно!"
else
    echo ""
    echo "❌ Тесты завершились с ошибками (код: $exit_code)"
fi

exit $exit_code
