-- Создание collation для русской локали с правильной сортировкой
-- Использование: docker-compose exec postgres psql -U postgres -d flibusta -f /application/tools/create_russian_collation.sql
-- ИЛИ: psql -U postgres -d flibusta < create_russian_collation.sql

-- Проверяем существующие collations
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname LIKE '%ru%' OR collname LIKE '%RU%'
ORDER BY collname;

-- Создаем collation для русской локали UTF-8
-- ВАЖНО: Это требует прав суперпользователя (postgres)
-- Если collation уже существует, команда выдаст ошибку - это нормально

-- Для PostgreSQL 14+ можно использовать ICU collations (более гибкие)
-- Но стандартный способ - использовать локали системы

-- Проверяем, доступна ли локаль ru_RU.UTF-8 на системе
DO $$
BEGIN
    -- Пытаемся создать collation
    BEGIN
        CREATE COLLATION IF NOT EXISTS "ru_RU.UTF-8" (
            LOCALE = 'ru_RU.UTF-8',
            PROVIDER = 'libc'
        );
        RAISE NOTICE 'Collation ru_RU.UTF-8 успешно создан';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'Ошибка при создании collation: %', SQLERRM;
        RAISE NOTICE 'Попробуйте использовать альтернативный способ';
        
        -- Альтернативный способ: создать collation из существующего
        BEGIN
            -- Используем C collation как основу (если ru_RU.UTF-8 недоступен)
            CREATE COLLATION IF NOT EXISTS "ru_RU.UTF-8" (
                LOCALE = 'C',
                PROVIDER = 'libc'
            );
            RAISE NOTICE 'Создан fallback collation';
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'Не удалось создать collation. Используйте существующие collations.';
        END;
    END;
END $$;

-- Если ru_RU.UTF-8 недоступен, можно использовать существующие collations:
-- - C (стандартная сортировка)
-- - POSIX (стандартная сортировка)
-- - или другие доступные на системе

-- Проверяем результат
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname = 'ru_RU.UTF-8';

-- Если collation создан успешно, можно использовать его в запросах:
-- ORDER BY title COLLATE "ru_RU.UTF-8"
