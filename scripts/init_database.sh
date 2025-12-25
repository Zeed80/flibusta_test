#!/bin/sh
# init_database.sh - Автоматическая инициализация базы данных

set -e

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Переменные для отслеживания ошибок
ERRORS_COUNT=0
IMPORTED_FILES=0
FAILED_FILES=""

# Функция логирования ошибок
log_error() {
    message=$1
    echo -e "${RED}✗ $message${NC}" | tee -a /var/log/flibusta_init.log 2>/dev/null || echo "$message"
    ERRORS_COUNT=$((ERRORS_COUNT + 1))
}

# Функция логирования успеха
log_success() {
    message=$1
    echo -e "${GREEN}✓ $message${NC}" | tee -a /var/log/flibusta_init.log 2>/dev/null || echo "$message"
}

# Функция логирования предупреждений
log_warning() {
    message=$1
    echo -e "${YELLOW}⚠ $message${NC}" | tee -a /var/log/flibusta_init.log 2>/dev/null || echo "$message"
}

# Определение команды docker-compose
COMPOSE_CMD="docker-compose"
if ! command -v docker-compose &> /dev/null; then
    COMPOSE_CMD="docker compose"
fi

# Проверка наличия SQL файлов
if [ ! -d "/application/sql" ] || [ -z "$(find /application/sql -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null)" ]; then
    echo -e "${YELLOW}⚠ SQL файлы не найдены. Пропуск инициализации БД.${NC}"
    exit 0
fi

# Ожидание готовности PostgreSQL
log_success "Ожидание готовности PostgreSQL..."
i=1
postgres_ready=0
while [ $i -le 60 ]; do
    if $COMPOSE_CMD exec -T postgres pg_isready -U flibusta -d flibusta > /dev/null 2>&1; then
        postgres_ready=1
        log_success "PostgreSQL готов"
        break
    fi
    if [ $i -eq 60 ]; then
        log_error "PostgreSQL не готов после 60 попыток"
        exit 1
    fi
    sleep 2
    i=$((i + 1))
done

# Дополнительная проверка: убеждаемся, что база данных доступна
if [ $postgres_ready -eq 1 ]; then
    log_success "Проверка доступности базы данных..."
    if $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -c "SELECT 1;" > /dev/null 2>&1; then
        log_success "База данных доступна"
    else
        log_error "База данных недоступна"
        exit 1
    fi
fi

# Создание необходимых директорий
mkdir -p /application/sql/psql
mkdir -p /application/cache/authors
mkdir -p /application/cache/covers
mkdir -p /application/cache/tmp
mkdir -p /application/cache/opds

# Распаковка sql.gz файлов
echo -e "${GREEN}Распаковка SQL файлов...${NC}"
if ls /application/sql/*.gz 1> /dev/null 2>&1; then
    gzip -f -d /application/sql/*.gz 2>/dev/null || true
fi

# Импорт SQL файлов с детальной обработкой ошибок
echo -e "${GREEN}Импорт SQL файлов...${NC}"

# Список файлов для импорта в правильном порядке
SQL_FILES="lib.a.annotations_pics.sql lib.b.annotations_pics.sql lib.a.annotations.sql lib.b.annotations.sql lib.libavtorname.sql lib.libavtor.sql lib.libbook.sql lib.libfilename.sql lib.libgenrelist.sql lib.libgenre.sql lib.libjoinedbooks.sql lib.librate.sql lib.librecs.sql lib.libseq.sql lib.libtranslator.sql lib.reviews.sql"

for sql_file in $SQL_FILES; do
    if [ -f "/application/sql/$sql_file" ]; then
        log_success "Начало импорта $sql_file"
        
        # Попытка импорта с использованием app_topg
        if [ -x "/application/tools/app_topg" ]; then
            if /application/tools/app_topg "$sql_file" 2>/dev/null; then
                log_success "Импорт $sql_file завершен"
                IMPORTED_FILES=$((IMPORTED_FILES + 1))
            else
                FAILED_FILES="$FAILED_FILES$sql_file "
                log_error "Ошибка импорта $sql_file (через app_topg)"
            fi
        else
            # Альтернативный способ импорта через psql
            if $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/sql/$sql_file > /dev/null 2>&1; then
                log_success "Импорт $sql_file завершен (через psql)"
                IMPORTED_FILES=$((IMPORTED_FILES + 1))
            else
                FAILED_FILES="$FAILED_FILES$sql_file "
                log_error "Ошибка импорта $sql_file (через psql)"
            fi
        fi
done

# Подчистка БД
echo -e "${GREEN}Очистка базы данных...${NC}"
if [ -f "/application/tools/cleanup_db.sql" ]; then
    # Использование SQL_CMD из dbinit.sh если доступен
    if [ -f "/application/tools/dbinit.sh" ]; then
        . /application/tools/dbinit.sh
        $SQL_CMD -f /application/tools/cleanup_db.sql > /dev/null 2>&1 || true
    else
        $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/tools/cleanup_db.sql > /dev/null 2>&1 || true
    fi
fi

# Обновление полнотекстовых индексов
echo -e "${GREEN}Обновление индексов...${NC}"
if [ -f "/application/tools/update_vectors.sql" ]; then
    if [ -f "/application/tools/dbinit.sh" ]; then
        . /application/tools/dbinit.sh
        $SQL_CMD -f /application/tools/update_vectors.sql > /dev/null 2>&1 || true
    else
        $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/tools/update_vectors.sql > /dev/null 2>&1 || true
    fi
fi

# Создание индекса zip-файлов
log_success "Создание индекса zip-файлов..."
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
        if $COMPOSE_CMD exec -T php-fpm php /application/tools/app_update_zip_list.php > /dev/null 2>&1; then
            log_success "Индекс zip-файлов создан"
        else
            log_warning "Не удалось создать индекс zip-файлов через php-fpm"
            # Попытка выполнить локально, если мы в контейнере
            if php /application/tools/app_update_zip_list.php > /dev/null 2>&1; then
                log_success "Индекс zip-файлов создан (локально)"
            else
                log_warning "Не удалось создать индекс zip-файлов"
            fi
        fi
    else
        log_warning "php-fpm не готов, пропуск создания индекса zip-файлов"
    fi
fi

# Итоговый отчет об импорте
echo ""
echo -e "${GREEN}=== Итоговая статистика импорта ===${NC}"
echo -e "Импортировано файлов: $IMPORTED_FILES"

if [ -n "$FAILED_FILES" ]; then
    echo -e "${RED}Файлы с ошибками:${NC}"
    echo "$FAILED_FILES"
    ERRORS_COUNT=$((ERRORS_COUNT + 1))
else
    echo -e "${GREEN}✓ Все файлы импортированы без ошибок${NC}"
fi

echo ""
echo -e "${GREEN}✓ Инициализация базы данных завершена${NC}"

# Выход с кодом ошибки, если были проблемы
if [ $ERRORS_COUNT -gt 0 ]; then
    echo -e "${YELLOW}⚠ ВНИМАНИЕ: Обнаружены ошибки при импорте. Проверьте логи.${NC}"
    exit 1
fi
