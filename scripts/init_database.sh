#!/bin/sh
# init_database.sh - Автоматическая инициализация базы данных

# Не используем set -e, чтобы иметь контроль над обработкой ошибок

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Переменные для отслеживания ошибок
ERRORS_COUNT=0
IMPORTED_FILES=0
FAILED_FILES=""
LOG_FILE="/var/log/flibusta_init.log"

# Функция логирования ошибок
log_error() {
    message=$1
    details=$2
    echo -e "${RED}✗ $message${NC}" | tee -a "$LOG_FILE" 2>/dev/null || echo "$message"
    if [ -n "$details" ]; then
        echo "$details" | tee -a "$LOG_FILE" 2>/dev/null || echo "$details"
    fi
    ERRORS_COUNT=$((ERRORS_COUNT + 1))
}

# Функция логирования успеха
log_success() {
    message=$1
    echo -e "${GREEN}✓ $message${NC}" | tee -a "$LOG_FILE" 2>/dev/null || echo "$message"
}

# Функция логирования предупреждений
log_warning() {
    message=$1
    echo -e "${YELLOW}⚠ $message${NC}" | tee -a "$LOG_FILE" 2>/dev/null || echo "$message"
}

# Функция логирования информации
log_info() {
    message=$1
    echo -e "${BLUE}ℹ $message${NC}" | tee -a "$LOG_FILE" 2>/dev/null || echo "$message"
}

# Загрузка переменных окружения и пароля из dbinit.sh
log_info "Загрузка конфигурации базы данных..."
if [ -f "/application/tools/dbinit.sh" ]; then
    . /application/tools/dbinit.sh
    if [ -n "$SQL_CMD" ] && [ -n "$PGPASSWORD" ]; then
        log_success "Конфигурация БД загружена (хост: ${FLIBUSTA_DBHOST:-postgres}, БД: ${FLIBUSTA_DBNAME:-flibusta}, пользователь: ${FLIBUSTA_DBUSER:-flibusta})"
    else
        log_warning "Не удалось загрузить полную конфигурацию из dbinit.sh, используются значения по умолчанию"
        # Установка значений по умолчанию
        export FLIBUSTA_DBNAME=${FLIBUSTA_DBNAME:-flibusta}
        export FLIBUSTA_DBHOST=${FLIBUSTA_DBHOST:-postgres}
        export FLIBUSTA_DBUSER=${FLIBUSTA_DBUSER:-flibusta}
        export PGPASSWORD=${FLIBUSTA_DBPASSWORD:-flibusta}
    fi
else
    log_warning "Файл /application/tools/dbinit.sh не найден, используются значения по умолчанию"
    # Установка значений по умолчанию
    export FLIBUSTA_DBNAME=${FLIBUSTA_DBNAME:-flibusta}
    export FLIBUSTA_DBHOST=${FLIBUSTA_DBHOST:-postgres}
    export FLIBUSTA_DBUSER=${FLIBUSTA_DBUSER:-flibusta}
    export PGPASSWORD=${FLIBUSTA_DBPASSWORD:-flibusta}
fi

# Проверка наличия пароля
if [ -z "$PGPASSWORD" ]; then
    log_error "Пароль базы данных не установлен"
    log_error "Установите переменную окружения FLIBUSTA_DBPASSWORD или создайте файл secrets/flibusta_pwd.txt"
    exit 1
fi

# Определение команды docker-compose
COMPOSE_CMD="docker-compose"
if ! command -v docker-compose &> /dev/null; then
    COMPOSE_CMD="docker compose"
fi

# Проверка монтирования volume с SQL файлами
log_info "Проверка монтирования volume с SQL файлами..."
if [ ! -d "/application/sql" ]; then
    log_error "Директория /application/sql не найдена"
    log_error "Убедитесь, что volume с SQL файлами правильно смонтирован в docker-compose.yml"
    exit 1
fi

# Проверка прав доступа к директории
if [ ! -r "/application/sql" ]; then
    log_error "Нет прав на чтение директории /application/sql"
    exit 1
fi

# Проверка наличия SQL файлов
log_info "Поиск SQL файлов в /application/sql..."
SQL_FILES_COUNT=$(find /application/sql -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l || echo "0")
if [ "$SQL_FILES_COUNT" -eq 0 ]; then
    log_warning "SQL файлы не найдены в /application/sql"
    log_warning "Пропуск инициализации БД. Импортируйте SQL файлы вручную позже."
    exit 0
fi
log_success "Найдено SQL файлов: $SQL_FILES_COUNT"

# Ожидание готовности PostgreSQL с проверкой подключения
log_info "Ожидание готовности PostgreSQL..."
i=1
postgres_ready=0
max_attempts=60

# Сохраняем основной пароль для последующего сравнения
MAIN_PASSWORD="$PGPASSWORD"

# Подготовка списка паролей для попыток подключения
# При первом запуске postgres может быть создан с паролем из POSTGRES_PASSWORD
# который берется из FLIBUSTA_DBPASSWORD или дефолтного 'flibusta'
# Используем строку вместо массива для совместимости с sh
PASSWORD_1="$PGPASSWORD"
PASSWORD_2="${FLIBUSTA_DBPASSWORD:-flibusta}"
PASSWORD_3="flibusta"

# Формируем список уникальных паролей
UNIQUE_PASSWORDS=""
for pwd in "$PASSWORD_1" "$PASSWORD_2" "$PASSWORD_3"; do
    if [ -n "$pwd" ]; then
        found=0
        for existing in $UNIQUE_PASSWORDS; do
            if [ "$existing" = "$pwd" ]; then
                found=1
                break
            fi
        done
        if [ $found -eq 0 ]; then
            if [ -z "$UNIQUE_PASSWORDS" ]; then
                UNIQUE_PASSWORDS="$pwd"
            else
                UNIQUE_PASSWORDS="$UNIQUE_PASSWORDS $pwd"
            fi
        fi
    fi
done

while [ $i -le $max_attempts ]; do
    # Сначала проверяем доступность сервера
    if $COMPOSE_CMD exec -T postgres pg_isready -U "$FLIBUSTA_DBUSER" -d "$FLIBUSTA_DBNAME" > /dev/null 2>&1; then
        # Пробуем подключиться с разными паролями
        connected=0
        working_password=""
        
        for test_password in $UNIQUE_PASSWORDS; do
            export PGPASSWORD="$test_password"
            if $COMPOSE_CMD exec -T postgres psql -U "$FLIBUSTA_DBUSER" -d "$FLIBUSTA_DBNAME" -c "SELECT 1;" > /dev/null 2>&1; then
                connected=1
                working_password="$test_password"
                # Обновляем PGPASSWORD на рабочий пароль
                export PGPASSWORD="$working_password"
                break
            fi
        done
        
        if [ $connected -eq 1 ]; then
            postgres_ready=1
            log_success "PostgreSQL готов и доступен"
            
            # Если рабочий пароль отличается от основного, обновляем его в БД
            if [ "$working_password" != "$MAIN_PASSWORD" ] && [ -n "$MAIN_PASSWORD" ] && [ "$MAIN_PASSWORD" != "flibusta" ]; then
                log_info "Пароль в БД отличается от пароля в secrets, обновляем..."
                escaped_password=$(echo "$MAIN_PASSWORD" | sed "s/'/''/g")
                if $COMPOSE_CMD exec -T postgres psql -U "$FLIBUSTA_DBUSER" -d "postgres" -c "ALTER USER $FLIBUSTA_DBUSER WITH PASSWORD '$escaped_password';" > /dev/null 2>&1; then
                    log_success "Пароль в БД обновлен"
                    # Проверяем новое подключение
                    sleep 1
                    export PGPASSWORD="$MAIN_PASSWORD"
                    if $COMPOSE_CMD exec -T postgres psql -U "$FLIBUSTA_DBUSER" -d "$FLIBUSTA_DBNAME" -c "SELECT 1;" > /dev/null 2>&1; then
                        log_success "Подключение с обновленным паролем успешно"
                    else
                        log_warning "Пароль обновлен, но подключение с новым паролем не работает, используем рабочий пароль"
                        export PGPASSWORD="$working_password"
                    fi
                else
                    log_warning "Не удалось обновить пароль в БД, продолжаем с текущим паролем"
                fi
            fi
            
            break
        else
            if [ $((i % 10)) -eq 0 ]; then
                log_warning "PostgreSQL доступен, но подключение не удалось ни с одним из паролей (попытка $i/$max_attempts)"
            fi
        fi
    else
        if [ $((i % 10)) -eq 0 ]; then
            log_info "Ожидание готовности PostgreSQL... (попытка $i/$max_attempts)"
        fi
    fi
    
    if [ $i -eq $max_attempts ]; then
        log_error "PostgreSQL не готов после $max_attempts попыток"
        log_error "Проверьте:"
        log_error "  1. Контейнер postgres запущен: $COMPOSE_CMD ps"
        log_error "  2. Пароль БД правильный: проверьте secrets/flibusta_pwd.txt и .env (FLIBUSTA_DBPASSWORD)"
        log_error "  3. Логи контейнера: $COMPOSE_CMD logs postgres"
        log_error "  4. Попробуйте выполнить: bash scripts/fix_db_password.sh"
        exit 1
    fi
    sleep 2
    i=$((i + 1))
done

# Дополнительная проверка: убеждаемся, что база данных доступна
if [ $postgres_ready -eq 1 ]; then
    log_info "Финальная проверка доступности базы данных..."
    export PGPASSWORD="$PGPASSWORD"
    if $COMPOSE_CMD exec -T postgres psql -U "$FLIBUSTA_DBUSER" -d "$FLIBUSTA_DBNAME" -c "SELECT version();" > /dev/null 2>&1; then
        log_success "База данных доступна и работает"
    else
        log_error "База данных недоступна после проверки готовности"
        exit 1
    fi
fi

# Создание необходимых директорий
log_info "Создание необходимых директорий..."
mkdir -p /application/sql/psql
mkdir -p /application/cache/authors
mkdir -p /application/cache/covers
mkdir -p /application/cache/tmp
mkdir -p /application/cache/opds

# Распаковка sql.gz файлов
log_info "Распаковка сжатых SQL файлов..."
if ls /application/sql/*.gz 1> /dev/null 2>&1; then
    gzip_count=$(ls /application/sql/*.gz 2>/dev/null | wc -l || echo "0")
    if [ "$gzip_count" -gt 0 ]; then
        log_info "Найдено сжатых файлов: $gzip_count"
        for gz_file in /application/sql/*.gz; do
            if [ -f "$gz_file" ]; then
                log_info "Распаковка $(basename "$gz_file")..."
                if gzip -f -d "$gz_file" 2>>"$LOG_FILE"; then
                    log_success "Файл $(basename "$gz_file") распакован"
                else
                    log_warning "Не удалось распаковать $(basename "$gz_file")"
                fi
            fi
        done
    fi
else
    log_info "Сжатые SQL файлы не найдены"
fi

# Импорт SQL файлов с детальной обработкой ошибок
log_info "Начало импорта SQL файлов..."

# Список файлов для импорта в правильном порядке (используем массив)
SQL_FILES="
lib.a.annotations_pics.sql
lib.b.annotations_pics.sql
lib.a.annotations.sql
lib.b.annotations.sql
lib.libavtorname.sql
lib.libavtor.sql
lib.libbook.sql
lib.libfilename.sql
lib.libgenrelist.sql
lib.libgenre.sql
lib.libjoinedbooks.sql
lib.librate.sql
lib.librecs.sql
lib.libseq.sql
lib.libtranslator.sql
lib.reviews.sql
"

# Подготовка переменных для импорта
export PGPASSWORD="$PGPASSWORD"
DB_HOST="${FLIBUSTA_DBHOST:-postgres}"
DB_USER="${FLIBUSTA_DBUSER:-flibusta}"
DB_NAME="${FLIBUSTA_DBNAME:-flibusta}"

for sql_file in $SQL_FILES; do
    sql_file=$(echo "$sql_file" | tr -d '\n\r' | xargs)  # Убираем пробелы и переводы строк
    if [ -z "$sql_file" ]; then
        continue
    fi
    
    sql_path="/application/sql/$sql_file"
    
    # Явная проверка существования файла
    if [ ! -f "$sql_path" ]; then
        log_warning "Файл не найден: $sql_file (пропуск)"
        FAILED_FILES="$FAILED_FILES$sql_file (отсутствует) "
        continue
    fi
    
    # Проверка прав на чтение
    if [ ! -r "$sql_path" ]; then
        log_error "Нет прав на чтение файла: $sql_file"
        FAILED_FILES="$FAILED_FILES$sql_file (нет прав) "
        continue
    fi
    
    log_info "Начало импорта $sql_file..."
    file_size=$(stat -f%z "$sql_path" 2>/dev/null || stat -c%s "$sql_path" 2>/dev/null || echo "0")
    log_info "Размер файла: $file_size байт"
    
    import_success=0
    
    # Попытка импорта с использованием app_topg
    if [ -x "/application/tools/app_topg" ]; then
        log_info "Попытка импорта через app_topg..."
        if /application/tools/app_topg "$sql_file" >>"$LOG_FILE" 2>&1; then
            log_success "Импорт $sql_file завершен (через app_topg)"
            IMPORTED_FILES=$((IMPORTED_FILES + 1))
            import_success=1
        else
            log_warning "Ошибка импорта $sql_file через app_topg, пробуем через psql..."
            # Сохраняем ошибку в лог
            echo "--- Ошибка app_topg для $sql_file ---" >> "$LOG_FILE"
        fi
    fi
    
    # Альтернативный способ импорта через psql, если app_topg не сработал
    if [ $import_success -eq 0 ]; then
        log_info "Попытка импорта через psql..."
        # Используем PGPASSWORD для передачи пароля
        export PGPASSWORD="$PGPASSWORD"
        error_output=$(mktemp /tmp/psql_error_XXXXXX 2>/dev/null || echo "/tmp/psql_error_$$")
        
        if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -f "/application/sql/$sql_file" > "$error_output" 2>&1; then
            log_success "Импорт $sql_file завершен (через psql)"
            IMPORTED_FILES=$((IMPORTED_FILES + 1))
            import_success=1
            rm -f "$error_output"
        else
            error_details=$(cat "$error_output" 2>/dev/null || echo "Не удалось прочитать детали ошибки")
            log_error "Ошибка импорта $sql_file (через psql)" "$error_details"
            FAILED_FILES="$FAILED_FILES$sql_file "
            rm -f "$error_output"
        fi
    fi
done

# Подчистка БД
log_info "Очистка базы данных..."
if [ -f "/application/tools/cleanup_db.sql" ]; then
    export PGPASSWORD="$PGPASSWORD"
    if [ -f "/application/tools/dbinit.sh" ] && [ -n "$SQL_CMD" ]; then
        log_info "Использование SQL_CMD из dbinit.sh для cleanup_db.sql"
        if $SQL_CMD -f /application/tools/cleanup_db.sql >>"$LOG_FILE" 2>&1; then
            log_success "Очистка БД завершена"
        else
            log_warning "Ошибка при выполнении cleanup_db.sql (не критично)"
        fi
    else
        log_info "Использование psql через docker-compose для cleanup_db.sql"
        if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -f /application/tools/cleanup_db.sql >>"$LOG_FILE" 2>&1; then
            log_success "Очистка БД завершена"
        else
            log_warning "Ошибка при выполнении cleanup_db.sql (не критично)"
        fi
    fi
else
    log_warning "Файл cleanup_db.sql не найден, пропуск очистки"
fi

# Обновление полнотекстовых индексов
log_info "Обновление полнотекстовых индексов..."
if [ -f "/application/tools/update_vectors.sql" ]; then
    export PGPASSWORD="$PGPASSWORD"
    if [ -f "/application/tools/dbinit.sh" ] && [ -n "$SQL_CMD" ]; then
        log_info "Использование SQL_CMD из dbinit.sh для update_vectors.sql"
        if $SQL_CMD -f /application/tools/update_vectors.sql >>"$LOG_FILE" 2>&1; then
            log_success "Обновление индексов завершено"
        else
            log_warning "Ошибка при обновлении индексов (не критично)"
        fi
    else
        log_info "Использование psql через docker-compose для update_vectors.sql"
        if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -f /application/tools/update_vectors.sql >>"$LOG_FILE" 2>&1; then
            log_success "Обновление индексов завершено"
        else
            log_warning "Ошибка при обновлении индексов (не критично)"
        fi
    fi
else
    log_warning "Файл update_vectors.sql не найден, пропуск обновления индексов"
fi

# Создание индекса zip-файлов
log_info "Создание индекса zip-файлов..."
if [ -f "/application/tools/app_update_zip_list.php" ]; then
    # Проверка готовности php-fpm перед использованием
    php_fpm_ready=0
    for i in 1 2 3 4 5; do
        if $COMPOSE_CMD exec -T php-fpm sh -c "test -f /application/tools/app_update_zip_list.php" > /dev/null 2>&1; then
            php_fpm_ready=1
            break
        fi
        sleep 1
    done
    
    if [ $php_fpm_ready -eq 1 ]; then
        log_info "Попытка создания индекса через php-fpm..."
        if $COMPOSE_CMD exec -T php-fpm php /application/tools/app_update_zip_list.php >>"$LOG_FILE" 2>&1; then
            log_success "Индекс zip-файлов создан"
        else
            log_warning "Не удалось создать индекс zip-файлов через php-fpm"
            # Попытка выполнить локально, если мы в контейнере
            if command -v php > /dev/null 2>&1; then
                log_info "Попытка создания индекса локально..."
                if php /application/tools/app_update_zip_list.php >>"$LOG_FILE" 2>&1; then
                    log_success "Индекс zip-файлов создан (локально)"
                else
                    log_warning "Не удалось создать индекс zip-файлов локально"
                fi
            fi
        fi
    else
        log_warning "php-fpm не готов, пропуск создания индекса zip-файлов"
    fi
else
    log_warning "Файл app_update_zip_list.php не найден, пропуск создания индекса zip-файлов"
fi

# Итоговый отчет об импорте
echo ""
log_info "=== Итоговая статистика импорта ==="
log_info "Импортировано файлов: $IMPORTED_FILES"

if [ -n "$FAILED_FILES" ]; then
    log_error "Файлы с ошибками:"
    echo "$FAILED_FILES" | while read -r failed_file; do
        if [ -n "$failed_file" ]; then
            echo "  - $failed_file"
        fi
    done
    ERRORS_COUNT=$((ERRORS_COUNT + 1))
else
    log_success "Все файлы импортированы без ошибок"
fi

echo ""
# Убеждаемся, что ERRORS_COUNT - это число
if ! echo "$ERRORS_COUNT" | grep -qE '^[0-9]+$'; then
    ERRORS_COUNT=0
fi

# Выход с кодом ошибки, если были проблемы
if [ $ERRORS_COUNT -gt 0 ] 2>/dev/null; then
    log_warning "ВНИМАНИЕ: Обнаружены ошибки при импорте"
    log_info "Подробные логи сохранены в: $LOG_FILE"
    log_info "Для просмотра логов выполните: cat $LOG_FILE"
    exit 1
else
    log_success "Инициализация базы данных завершена успешно"
    exit 0
fi
