-- Попытка создать русский collation
-- Использование: docker-compose exec -T postgres psql -U flibusta -d flibusta -f /application/tools/try_create_collation.sql

-- Пытаемся создать collation
CREATE COLLATION IF NOT EXISTS "ru_RU.UTF-8" (
    LOCALE = 'ru_RU.UTF-8',
    PROVIDER = 'libc'
);

-- Проверяем результат
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname = 'ru_RU.UTF-8';
