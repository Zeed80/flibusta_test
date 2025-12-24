#!/bin/sh
#
# Скрипт мониторинга здоровья системы Flibusta
# Выводит метрики в формате JSON для экспортеров Prometheus
#

# Загрузка переменных окружения
. /application/tools/dbinit.sh

# Временная метка
TIMESTAMP=$(date +%s)
DATETIME=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# Инициализация JSON
echo "{"
echo "  \"timestamp\": \"$DATETIME\","
echo "  \"metrics\": {"

# Функция для вывода метрики в JSON
print_metric() {
    echo "    \"$1\": \"$2\","
}

# Функция для выполнения SQL запроса и получения метрики
get_sql_metric() {
    local query="$1"
    local metric_name="$2"
    
    result=$($SQL_CMD -t -c "$query" 2>/dev/null)
    if [ $? -eq 0 ]; then
        print_metric "$metric_name" "$result"
    else
        print_metric "$metric_name" "null"
    fi
}

# 1. Метрики базы данных PostgreSQL
echo "  \"postgres\": {"
echo "    \"is_running\": true,"

# Проверка соединения с PostgreSQL
PG_IS_READY=$($SQL_CMD -c "SELECT 1" 2>/dev/null && echo "true" || echo "false")
print_metric "is_connected" "$PG_IS_READY"

# Количество книг
get_sql_metric "SELECT COUNT(*) FROM libbook WHERE deleted='0'" "total_books"

# Количество активных авторов
get_sql_metric "SELECT COUNT(DISTINCT AvtorId) FROM libavtor" "active_authors"

# Количество жанров
get_sql_metric "SELECT COUNT(*) FROM libgenrelist" "total_genres"

# Количество серий
get_sql_metric "SELECT COUNT(*) FROM libseqname" "total_sequences"

# Количество ZIP архивов
get_sql_metric "SELECT COUNT(*) FROM book_zip WHERE is_valid = TRUE" "zip_files_count"

# Количество недействительных ZIP архивов
get_sql_metric "SELECT COUNT(*) FROM book_zip WHERE is_valid = FALSE" "invalid_zip_files"

# Размер базы данных
get_sql_metric "SELECT pg_size_pretty(pg_database_size('$FLIBUSTA_DBNAME'))" "database_size"

# Размер таблицы libbook (в MB)
get_sql_metric "SELECT ROUND(pg_total_relation_size('libbook')::numeric / 1024 / 1024, 2)" "libbook_size_mb"

# Последнее обновление базы данных
get_sql_metric "SELECT MAX(time)::text FROM libbook" "last_update"

# Время последнего VACUUM для таблицы libbook
get_sql_metric "SELECT COALESCE(last_vacuum::text, 'never')" "last_vacuum"

# Количество активных соединений
get_sql_metric "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = '$FLIBUSTA_DBNAME'" "active_connections"

echo "  },"

# 2. Метрики файловой системы
echo "  \"filesystem\": {"

# Размер ZIP архивов
if [ -d "/application/flibusta" ]; then
    ZIP_SIZE=$(du -sm /application/flibusta | cut -f1)
    print_metric "zip_archives_size" "$ZIP_SIZE"
    
    ZIP_COUNT=$(find /application/flibusta -name "*.zip" -type f 2>/dev/null | wc -l)
    print_metric "zip_archives_count" "$ZIP_COUNT"
else
    print_metric "zip_archives_size" "null"
    print_metric "zip_archives_count" "null"
fi

# Размер кэша авторов
if [ -d "/application/cache/authors" ]; then
    AUTHORS_CACHE_SIZE=$(du -sm /application/cache/authors | cut -f1)
    AUTHORS_CACHE_COUNT=$(find /application/cache/authors -type f 2>/dev/null | wc -l)
    print_metric "authors_cache_size" "$AUTHORS_CACHE_SIZE"
    print_metric "authors_cache_count" "$AUTHORS_CACHE_COUNT"
else
    print_metric "authors_cache_size" "null"
    print_metric "authors_cache_count" "null"
fi

# Размер кэша обложек
if [ -d "/application/cache/covers" ]; then
    COVERS_CACHE_SIZE=$(du -sm /application/cache/covers | cut -f1)
    COVERS_CACHE_COUNT=$(find /application/cache/covers -type f 2>/dev/null | wc -l)
    print_metric "covers_cache_size" "$COVERS_CACHE_SIZE"
    print_metric "covers_cache_count" "$COVERS_CACHE_COUNT"
else
    print_metric "covers_cache_size" "null"
    print_metric "covers_cache_count" "null"
fi

# Свободное место на диске (для директории /application)
DISK_FREE=$(df -BM /application | tail -1 | awk '{print $4}')
DISK_USED=$(df -BM /application | tail -1 | awk '{print $3}')
DISK_TOTAL=$(df -BM /application | tail -1 | awk '{print $2}')

print_metric "disk_free_mb" "$DISK_FREE"
print_metric "disk_used_mb" "$DISK_USED"
print_metric "disk_total_mb" "$DISK_TOTAL"

echo "  },"

# 3. Метрики OPDS API
echo "  \"opds\": {"

# Размер кэша OPDS
if [ -d "/application/cache/opds" ]; then
    OPDS_CACHE_SIZE=$(du -sm /application/cache/opds | cut -f1)
    OPDS_CACHE_COUNT=$(find /application/cache/opds -name "*.xml" -type f 2>/dev/null | wc -l)
    print_metric "cache_size" "$OPDS_CACHE_SIZE"
    print_metric "cache_files_count" "$OPDS_CACHE_COUNT"
else
    print_metric "cache_size" "null"
    print_metric "cache_files_count" "0"
fi

# Проверка последнего времени обновления OPDS кэша
if [ -f "/application/cache/opds/.metadata" ]; then
    OPDS_LAST_UPDATE=$(stat -c %Y /application/cache/opds/.metadata)
    print_metric "last_cache_update" "$OPDS_LAST_UPDATE"
else
    print_metric "last_cache_update" "null"
fi

echo "  },"

# 4. Метрики системы
echo "  \"system\": {"

# Загрузка CPU (для контейнера)
if [ -f "/proc/loadavg" ]; then
    LOAD_1MIN=$(awk '{print $1}' /proc/loadavg)
    LOAD_5MIN=$(awk '{print $2}' /proc/loadavg)
    LOAD_15MIN=$(awk '{print $3}' /proc/loadavg)
    print_metric "load_1min" "$LOAD_1MIN"
    print_metric "load_5min" "$LOAD_5MIN"
    print_metric "load_15min" "$LOAD_15MIN"
else
    print_metric "load_1min" "null"
    print_metric "load_5min" "null"
    print_metric "load_15min" "null"
fi

# Использование памяти (в MB)
if [ -f "/proc/meminfo" ]; then
    MEM_TOTAL=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
    MEM_AVAILABLE=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
    MEM_USED=$((MEM_TOTAL - MEM_AVAILABLE))
    print_metric "memory_total_mb" "$MEM_TOTAL"
    print_metric "memory_available_mb" "$MEM_AVAILABLE"
    print_metric "memory_used_mb" "$MEM_USED"
else
    print_metric "memory_total_mb" "null"
    print_metric "memory_available_mb" "null"
    print_metric "memory_used_mb" "null"
fi

# Uptime системы
if [ -f "/proc/uptime" ]; then
    UPTIME_SECONDS=$(cat /proc/uptime | awk '{print int($1)}')
    UPTIME_DAYS=$((UPTIME_SECONDS / 86400))
    print_metric "uptime_seconds" "$UPTIME_SECONDS"
    print_metric "uptime_days" "$UPTIME_DAYS"
else
    print_metric "uptime_seconds" "null"
    print_metric "uptime_days" "null"
fi

echo "  },"

# 5. Пользовательские метрики (избранные книги, авторы)
echo "  \"user_data\": {"

# Количество избранных книг
get_sql_metric "SELECT COUNT(DISTINCT bookid) FROM fav" "total_favorites_books"

# Количество избранных авторов
get_sql_metric "SELECT COUNT(DISTINCT avtorid) FROM fav WHERE avtorid IS NOT NULL" "total_favorites_authors"

# Количество избранных серий
get_sql_metric "SELECT COUNT(DISTINCT seqid) FROM fav WHERE seqid IS NOT NULL" "total_favorites_sequences"

# Количество пользователей (по user_uuid)
get_sql_metric "SELECT COUNT(DISTINCT user_uuid) FROM fav_users" "total_users"

echo "  },"

# 6. Статус импорта (из файла статуса)
echo "  \"import_status\": {"

IMPORT_STATUS_FILE="/application/cache/sql_status"
if [ -f "$IMPORT_STATUS_FILE" ]; then
    IMPORT_FILE_SIZE=$(stat -c%s "$IMPORT_STATUS_FILE" 2>/dev/null || echo "0")
    IMPORT_FILE_MTIME=$(stat -c %Y "$IMPORT_STATUS_FILE" 2>/dev/null || echo "0")
    
    # Проверяем, что процесс импорта не "завис"
    CURRENT_TIME=$(date +%s)
    TIME_DIFF=$((CURRENT_TIME - IMPORT_FILE_MTIME))
    
    if [ $TIME_DIFF -gt 300 ]; then # 5 минут
        print_metric "is_importing" "false"
        print_metric "import_status" "stale"
    elif grep -q "=== Импорт завершен" "$IMPORT_STATUS_FILE" 2>/dev/null; then
        print_metric "is_importing" "false"
        print_metric "import_status" "completed"
    elif grep -q "importing" "$IMPORT_STATUS_FILE" 2>/dev/null; then
        print_metric "is_importing" "true"
        print_metric "import_status" "in_progress"
    else
        print_metric "is_importing" "false"
        print_metric "import_status" "unknown"
    fi
    
    print_metric "status_file_size" "$IMPORT_FILE_SIZE"
    print_metric "status_file_age_seconds" "$TIME_DIFF"
else
    print_metric "is_importing" "false"
    print_metric "import_status" "no_status_file"
    print_metric "status_file_size" "0"
    print_metric "status_file_age_seconds" "0"
fi

echo "  }"

echo "  }"
echo "}"
