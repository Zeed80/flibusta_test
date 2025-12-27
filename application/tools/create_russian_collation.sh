#!/bin/bash
# Скрипт для создания русского collation в PostgreSQL
# Использование: ./create_russian_collation.sh

set -e

echo "=== Создание русского collation для сортировки ==="
echo ""

# Определяем пользователя БД из переменных окружения или используем flibusta по умолчанию
DB_USER="${FLIBUSTA_DBUSER:-flibusta}"
DB_NAME="${FLIBUSTA_DBNAME:-flibusta}"
DB_HOST="${FLIBUSTA_DBHOST:-postgres}"

echo "Пользователь БД: $DB_USER"
echo "База данных: $DB_NAME"
echo "Хост: $DB_HOST"
echo ""

# Проверяем, существует ли collation
echo "1. Проверка существующих collations..."
docker-compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname LIKE '%ru%' OR collname LIKE '%RU%'
ORDER BY collname;
" || echo "Ошибка при проверке collations"

echo ""
echo "2. Попытка создать collation ru_RU.UTF-8..."

# Пытаемся создать collation от имени текущего пользователя
# Если у пользователя нет прав, получим ошибку
docker-compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" <<EOF 2>&1 || true
-- Пытаемся создать collation
DO \$\$
BEGIN
    -- Проверяем, существует ли collation
    IF NOT EXISTS (SELECT 1 FROM pg_collation WHERE collname = 'ru_RU.UTF-8') THEN
        -- Пытаемся создать collation
        BEGIN
            CREATE COLLATION "ru_RU.UTF-8" (
                LOCALE = 'ru_RU.UTF-8',
                PROVIDER = 'libc'
            );
            RAISE NOTICE 'Collation ru_RU.UTF-8 успешно создан';
        EXCEPTION WHEN insufficient_privilege THEN
            RAISE NOTICE 'Ошибка: недостаточно прав для создания collation';
            RAISE NOTICE 'Необходимы права суперпользователя PostgreSQL';
            RAISE NOTICE '';
            RAISE NOTICE 'Решения:';
            RAISE NOTICE '1. Подключиться как пользователь postgres (если существует)';
            RAISE NOTICE '2. Создать collation вручную через ALTER DATABASE';
            RAISE NOTICE '3. Использовать существующие collations (C, POSIX)';
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'Ошибка при создании collation: %', SQLERRM;
            IF SQLERRM LIKE '%does not exist%' THEN
                RAISE NOTICE 'Локаль ru_RU.UTF-8 не установлена в системе';
                RAISE NOTICE 'Установите локаль на сервере или используйте существующие collations';
            END IF;
        END;
    ELSE
        RAISE NOTICE 'Collation ru_RU.UTF-8 уже существует';
    END IF;
END \$\$;
EOF

echo ""
echo "3. Проверка результата..."
docker-compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname = 'ru_RU.UTF-8';
" || echo "Collation не найден"

echo ""
echo "=== Завершено ==="
echo ""
echo "Если collation не создан из-за недостатка прав:"
echo "1. Проверьте, доступен ли пользователь postgres в контейнере"
echo "2. Или используйте существующие collations (C, POSIX) - код автоматически переключится"
