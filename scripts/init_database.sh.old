#!/bin/bash
# init_database.sh - Автоматическая инициализация базы данных

set -e
set -o pipefail

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
    local message=$1
    echo -e "${RED}✗ $message${NC}" | tee -a /var/log/flibusta_init.log 2>/dev/null || echo "$message"
    ERRORS_COUNT=$((ERRORS_COUNT + 1))
}

# Функция логирования успеха
log_success() {
    local message=$1
    echo -e "${GREEN}✓ $message${NC}" | tee -a /var/log/flibusta_init.log 2>/dev/null || echo "$message"
}

# Функция логирования предупреждений
log_warning() {
    local message=$1
    echo -e "${YELLOW}⚠ $message${NC}" | tee -a /var/log/flibusta_init.log 2>/dev/null || echo "$message"
}

echo -e "${GREEN}Инициализация базы данных...${NC}"

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
echo -e "${GREEN}Ожидание готовности PostgreSQL...${NC}"
for i in {1..30}; do
    if $COMPOSE_CMD exec -T postgres pg_isready -U flibusta -d flibusta > /dev/null 2>&1; then
        echo -e "${GREEN}✓ PostgreSQL готов${NC}"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${RED}✗ PostgreSQL не готов после 30 попыток${NC}"
        exit 1
    fi
    sleep 2
done

# Создание необходимых директорий
mkdir -p /application/sql/psql
mkdir -p /application/cache/authors
mkdir -p /application/cache/covers
mkdir -p /application/cache/tmp

# Распаковка sql.gz файлов
echo -e "${GREEN}Распаковка SQL файлов...${NC}"
if ls /application/sql/*.gz 1> /dev/null 2>&1; then
    gzip -f -d /application/sql/*.gz 2>/dev/null || true
fi

# Импорт SQL файлов с детальной обработкой ошибок
echo -e "${GREEN}Импорт SQL файлов...${NC}"

# Список файлов для импорта в правильном порядке
SQL_FILES=(
    "lib.a.annotations_pics.sql"
    "lib.b.annotations_pics.sql"
    "lib.a.annotations.sql"
    "lib.b.annotations.sql"
    "lib.libavtorname.sql"
    "lib.libavtor.sql"
    "lib.libbook.sql"
    "lib.libfilename.sql"
    "lib.libgenrelist.sql"
    "lib.libgenre.sql"
    "lib.libjoinedbooks.sql"
    "lib.librate.sql"
    "lib.librecs.sql"
    "lib.libseqname.sql"
    "lib.libseq.sql"
    "lib.libtranslator.sql"
    "lib.reviews.sql"
)

for sql_file in "${SQL_FILES[@]}"; do
    if [ -f "/application/sql/$sql_file" ]; then
        log_success "Начало импорта $sql_file"
        
        # Попытка импорта с использованием app_topg
        if [ -x "/application/tools/app_topg" ]; then
            if /application/tools/app_topg "$sql_file" 2>/dev/null; then
                log_success "Импорт $sql_file завершен"
                IMPORTED_FILES=$((IMPORTED_FILES + 1))
            else
                FAILED_FILES+="$sql_file "
                log_error "Ошибка импорта $sql_file (через app_topg)"
            fi
        else
            # Альтернативный способ импорта через psql
            if $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/sql/$sql_file > /dev/null 2>&1; then
                log_success "Импорт $sql_file завершен (через psql)"
                IMPORTED_FILES=$((IMPORTED_FILES + 1))
            else
                FAILED_FILES+="$sql_file "
                log_error "Ошибка импорта $sql_file (через psql)"
            fi
        fi
    else
        log_warning "Файл не найден: $sql_file"
        FAILED_FILES+="$sql_file (отсутствует) "
    fi
done

# Подчистка БД
echo -e "${GREEN}Очистка базы данных...${NC}"
if [ -f "/application/tools/cleanup_db.sql" ]; then
    # Использование SQL_CMD из dbinit.sh если доступен
    if [ -f "/application/tools/dbinit.sh" ]; then
        source /application/tools/dbinit.sh
        $SQL_CMD -f /application/tools/cleanup_db.sql > /dev/null 2>&1 || true
    else
        $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/tools/cleanup_db.sql > /dev/null 2>&1 || true
    fi
fi

# Обновление полнотекстовых индексов
echo -e "${GREEN}Обновление индексов...${NC}"
if [ -f "/application/tools/update_vectors.sql" ]; then
    if [ -f "/application/tools/dbinit.sh" ]; then
        source /application/tools/dbinit.sh
        $SQL_CMD -f /application/tools/update_vectors.sql > /dev/null 2>&1 || true
    else
        $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/tools/update_vectors.sql > /dev/null 2>&1 || true
    fi
fi

# Создание индекса zip-файлов
echo -e "${GREEN}Создание индекса zip-файлов...${NC}"
if [ -f "/application/tools/app_update_zip_list.php" ]; then
    php /application/tools/app_update_zip_list.php > /dev/null 2>&1 || \
    $COMPOSE_CMD exec -T php-fpm php /application/tools/app_update_zip_list.php > /dev/null 2>&1 || true
fi

# Итоговый отчет об импорте
echo ""
echo -e "${GREEN}=== Итоговая статистика импорта ===${NC}"
echo -e "Импортировано файлов: $IMPORTED_FILES"

if [ -n "$FAILED_FILES" ]; then
    echo -e "${RED}Файлы с ошибками:${NC}"
    echo "$FAILED_FILES"
    ERROR_S_COUNT=$((ERRORS_COUNT + 1))
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
