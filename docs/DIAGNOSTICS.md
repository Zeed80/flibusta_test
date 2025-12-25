# Руководство по диагностике Flibusta

Это руководство поможет вам диагностировать и исправить проблемы с Flibusta Local Mirror.

## Быстрая диагностика

Для быстрой проверки всех компонентов системы используйте скрипт диагностики:

```bash
# На хосте
./scripts/diagnose.sh

# Внутри Docker контейнера
docker-compose exec php-fpm sh /application/scripts/diagnose.sh
```

## Автоматическое исправление

Для автоматического исправления большинства проблем:

```bash
# На хосте
./scripts/fix_all.sh

# Или исправление только прав доступа
./scripts/fix_permissions.sh
```

## Типичные проблемы и решения

### 1. Кнопка "Обновить базу" не работает

**Симптомы:**
- Кнопка неактивна или не реагирует на нажатие
- Появляется сообщение об ошибке на странице `/service/`

**Причины и решения:**

#### Причина 1: Скрипты не имеют прав на выполнение

**Решение:**
```bash
docker-compose exec php-fpm sh -c "cd /application/tools && chmod +x *.sh app_topg *.py"
```

#### Причина 2: Нет прав на запись в директорию cache

**Решение:**
```bash
docker-compose exec php-fpm sh -c "chmod -R 777 /application/cache"
```

#### Причина 3: Функции exec/shell_exec отключены в PHP

**Проверка:**
```bash
docker-compose exec php-fpm php -i | grep disable_functions
```

**Решение:** Убедитесь, что `exec` и `shell_exec` не в списке отключенных функций.

### 2. Проблемы с индексацией ZIP архивов

**Симптомы:**
- Книги не отображаются
- Ошибка "ZIP файл не найден"
- Таблица `book_zip` пуста

**Диагностика:**

```bash
# Проверка наличия ZIP файлов
docker-compose exec php-fpm ls -la /application/flibusta/*.zip | head -10

# Проверка таблицы book_zip
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) FROM book_zip WHERE is_valid = TRUE;"

# Проверка прав на чтение
docker-compose exec php-fpm ls -ld /application/flibusta
```

**Решения:**

1. Убедитесь, что ZIP файлы находятся в правильной директории:
   ```bash
   # На хосте
   ls -la Flibusta.Net/*.zip | head -10
   ```

2. Проверьте права на чтение:
   ```bash
   docker-compose exec php-fpm sh -c "chmod 755 /application/flibusta"
   ```

3. Запустите реиндексацию вручную:
   ```bash
   docker-compose exec php-fpm sh /application/tools/app_reindex.sh
   ```

### 3. Проблемы с импортом SQL

**Симптомы:**
- Импорт не запускается
- Импорт завершается с ошибками
- База данных пуста

**Диагностика:**

```bash
# Проверка наличия SQL файлов
docker-compose exec php-fpm ls -la /application/sql/*.sql | head -10

# Проверка подключения к БД
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) FROM libbook;"

# Просмотр логов импорта
docker-compose exec php-fpm cat /application/cache/sql_status
```

**Решения:**

1. Убедитесь, что SQL файлы находятся в правильной директории:
   ```bash
   # На хосте
   ls -la FlibustaSQL/*.sql | head -10
   ```

2. Проверьте права на запись:
   ```bash
   docker-compose exec php-fpm sh -c "chmod -R 777 /application/sql/psql"
   ```

3. Запустите импорт вручную:
   ```bash
   docker-compose exec php-fpm sh /application/tools/app_import_sql.sh
   ```

4. Проверьте логи PostgreSQL:
   ```bash
   docker-compose logs postgres | tail -50
   ```

### 4. Проблемы с веб-интерфейсом

**Симптомы:**
- Веб-интерфейс недоступен
- Ошибки 500, 502, 503
- Медленная загрузка страниц

**Диагностика:**

```bash
# Проверка статуса контейнеров
docker-compose ps

# Проверка логов Nginx
docker-compose logs webserver | tail -50

# Проверка логов PHP-FPM
docker-compose logs php-fpm | tail -50

# Проверка доступности веб-сервера
curl -I http://localhost:27100
```

**Решения:**

1. Перезапустите контейнеры:
   ```bash
   docker-compose restart
   ```

2. Проверьте конфигурацию Nginx:
   ```bash
   docker-compose exec webserver nginx -t
   ```

3. Проверьте конфигурацию PHP-FPM:
   ```bash
   docker-compose exec php-fpm php-fpm -t
   ```

### 5. Проблемы с правами доступа

**Симптомы:**
- Ошибки "Permission denied"
- Файлы не создаются
- Скрипты не выполняются

**Диагностика:**

```bash
# Проверка прав на скрипты
docker-compose exec php-fpm ls -la /application/tools/

# Проверка прав на директории
docker-compose exec php-fpm ls -ld /application/cache /application/sql/psql
```

**Решение:**

Используйте скрипт автоматического исправления:
```bash
docker-compose exec php-fpm sh /application/scripts/fix_permissions.sh
```

Или вручную:
```bash
docker-compose exec php-fpm sh -c "
  chmod +x /application/tools/*.sh /application/tools/app_topg /application/tools/*.py
  chmod -R 777 /application/cache /application/sql/psql
"
```

## Проверка компонентов системы

### Проверка базы данных

```bash
# Подключение к БД
docker-compose exec postgres psql -U flibusta -d flibusta

# Проверка таблиц
\dt

# Проверка количества книг
SELECT COUNT(*) FROM libbook WHERE deleted='0';

# Проверка индексации ZIP
SELECT COUNT(*) FROM book_zip WHERE is_valid = TRUE;

# Проверка размера БД
SELECT pg_size_pretty(pg_database_size('flibusta'));
```

### Проверка PHP расширений

```bash
docker-compose exec php-fpm php -m | grep -E "zip|pdo_pgsql|gd"
```

Должны быть установлены:
- `zip` - для работы с ZIP архивами
- `pdo_pgsql` - для подключения к PostgreSQL
- `gd` - для обработки изображений

### Проверка файловой системы

```bash
# Размер директорий
docker-compose exec php-fpm du -sh /application/cache
docker-compose exec php-fpm du -sh /application/flibusta
docker-compose exec php-fpm du -sh /application/sql

# Количество файлов
docker-compose exec php-fpm find /application/flibusta -name "*.zip" | wc -l
docker-compose exec php-fpm find /application/sql -name "*.sql" | wc -l
```

## Логи и отладка

### Просмотр логов

```bash
# Все логи
docker-compose logs

# Логи конкретного сервиса
docker-compose logs php-fpm
docker-compose logs postgres
docker-compose logs webserver

# Последние 100 строк
docker-compose logs --tail=100 php-fpm

# Логи в реальном времени
docker-compose logs -f php-fpm
```

### Файл статуса импорта

```bash
# Просмотр статуса импорта
docker-compose exec php-fpm cat /application/cache/sql_status

# Очистка статуса (если процесс завис)
docker-compose exec php-fpm rm /application/cache/sql_status
```

### Включение отладки PHP

Временно измените `phpdocker/php-fpm/php-fpm.conf`:
```ini
php_flag[display_errors] = on
php_admin_value[error_reporting] = E_ALL
```

Перезапустите контейнер:
```bash
docker-compose restart php-fpm
```

## Производительность

### Оптимизация базы данных

```bash
# Анализ таблиц
docker-compose exec postgres psql -U flibusta -d flibusta -c "ANALYZE;"

# Пересоздание индексов
docker-compose exec postgres psql -U flibusta -d flibusta -c "REINDEX DATABASE flibusta;"
```

### Очистка кэша

```bash
# Через веб-интерфейс
# Перейдите на http://localhost:27100/service/ и нажмите "Очистить кэш"

# Или вручную
docker-compose exec php-fpm rm -rf /application/cache/authors/* /application/cache/covers/*
```

## Восстановление после сбоя

### Восстановление прав доступа

```bash
./scripts/fix_all.sh
```

### Пересоздание контейнеров

```bash
docker-compose down
docker-compose up -d
```

### Полная переустановка (с сохранением данных)

```bash
# Остановка контейнеров
docker-compose down

# Удаление контейнеров (данные сохраняются в volumes)
docker-compose rm -f

# Пересборка образов
docker-compose build

# Запуск
docker-compose up -d

# Исправление прав
./scripts/fix_all.sh
```

## Получение помощи

Если проблема не решена:

1. Запустите полную диагностику:
   ```bash
   ./scripts/diagnose.sh > diagnose_output.txt 2>&1
   ```

2. Соберите логи:
   ```bash
   docker-compose logs > logs.txt 2>&1
   ```

3. Проверьте статус системы:
   ```bash
   docker-compose ps > status.txt
   ```

4. Создайте issue в репозитории с приложенными файлами диагностики.

## Полезные команды

```bash
# Проверка здоровья контейнеров
docker-compose ps

# Перезапуск всех сервисов
docker-compose restart

# Перезапуск конкретного сервиса
docker-compose restart php-fpm

# Просмотр использования ресурсов
docker stats

# Выполнение команды в контейнере
docker-compose exec php-fpm sh

# Проверка конфигурации
docker-compose config

# Просмотр переменных окружения
docker-compose exec php-fpm env | grep FLIBUSTA
```
