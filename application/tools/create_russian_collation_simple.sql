-- Простой скрипт для создания русского collation
-- Использование: docker-compose exec postgres psql -U <USER> -d flibusta -f /application/tools/create_russian_collation_simple.sql
-- 
-- ВАЖНО: Требует прав суперпользователя PostgreSQL
-- Если у пользователя нет прав, используйте существующие collations

-- Проверяем существующие collations
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname LIKE '%ru%' OR collname LIKE '%RU%'
ORDER BY collname;

-- Пытаемся создать collation
-- Если collation уже существует, команда выдаст ошибку - это нормально
CREATE COLLATION IF NOT EXISTS "ru_RU.UTF-8" (
    LOCALE = 'ru_RU.UTF-8',
    PROVIDER = 'libc'
);

-- Проверяем результат
SELECT collname, collcollate, collctype 
FROM pg_collation 
WHERE collname = 'ru_RU.UTF-8';
