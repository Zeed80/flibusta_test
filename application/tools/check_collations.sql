-- Проверка доступных collations в PostgreSQL
-- Использование: docker-compose exec postgres psql -U flibusta -d flibusta -f /application/tools/check_collations.sql

-- Показываем все доступные collations
SELECT 
    collname AS "Collation Name",
    collcollate AS "Locale",
    collctype AS "Type",
    collprovider AS "Provider"
FROM pg_collation 
WHERE collname NOT LIKE 'pg_catalog%'
ORDER BY collname;

-- Показываем collation базы данных по умолчанию
SELECT 
    datname AS "Database",
    datcollate AS "Default Collate",
    datctype AS "Default Ctype"
FROM pg_database 
WHERE datname = current_database();

-- Проверяем, какие локали доступны на системе (требует прав суперпользователя)
-- SELECT * FROM pg_collation WHERE collname LIKE '%ru%' OR collname LIKE '%RU%';

-- Тестовый запрос для проверки сортировки
SELECT 
    'А' AS char1, 
    'Б' AS char2, 
    'A' AS char3, 
    'B' AS char4
ORDER BY char1 COLLATE "C";  -- Используем C collation для примера
