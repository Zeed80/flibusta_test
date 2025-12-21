#!/bin/bash
# init_database.sh - Автоматическая инициализация базы данных

set -e

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

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

# Импорт SQL файлов
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
        echo -e "${GREEN}  Импорт $sql_file...${NC}"
        if [ -x "/application/tools/app_topg" ]; then
            /application/tools/app_topg "$sql_file" 2>/dev/null || true
        else
            # Альтернативный способ импорта
            $COMPOSE_CMD exec -T postgres psql -U flibusta -d flibusta -f /application/sql/$sql_file > /dev/null 2>&1 || true
        fi
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

echo -e "${GREEN}✓ Инициализация базы данных завершена${NC}"
