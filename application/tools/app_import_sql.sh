#!/bin/sh
# app_import_sql.sh - Импорт SQL файлов с улучшенной обработкой ошибок

. /application/tools/dbinit.sh

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Переменные для отслеживания ошибок
ERROR_COUNT=0
SUCCESS_COUNT=0
FAILED_FILES=""
SQL_FILES="lib.a.annotations_pics.sql lib.b.annotations_pics.sql lib.a.annotations.sql lib.b.annotations.sql lib.libavtorname.sql lib.libavtor.sql lib.libbook.sql lib.libfilename.sql lib.libgenrelist.sql lib.libgenre.sql lib.libjoinedbooks.sql lib.librate.sql lib.librecs.sql lib.libseqname.sql lib.libseq.sql lib.libtranslator.sql lib.reviews.sql"

# Функция безопасного выполнения команды
safe_execute() {
    description="$1"
    command="$2"
    critical="${3:-0}"  # Критическая ли операция
    
    echo -e "${YELLOW}Выполняется: $description${NC}"
    
    if eval "$command"; then
        echo -e "${GREEN}✓ $description завершен успешно${NC}"
        SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
        return 0
    else
        exit_code=$?
        echo -e "${RED}✗ Ошибка при $description (код: $exit_code)${NC}" | tee -a /application/cache/sql_status
        
        if [ "$critical" -eq 1 ]; then
            FAILED_FILES="$FAILED_FILES$description (КРИТИЧЕСКАЯ ОШИБКА)\n"
            ERROR_COUNT=$((ERROR_COUNT + 1))
        else
            FAILED_FILES="$FAILED_FILES$description (код: $exit_code)\n"
        fi
        
        ERROR_COUNT=$((ERROR_COUNT + 1))
        return 1
    fi
}

mkdir -p /application/sql/psql
mkdir -p /application/cache/authors
mkdir -p /application/cache/covers
mkdir -p /application/cache/tmp

# Устанавливаем права на запись для директорий
# Важно: устанавливаем права для всей директории sql и её поддиректорий
chmod -R 777 /application/sql 2>/dev/null || true
chmod -R 777 /application/cache 2>/dev/null || true

# Дополнительно убеждаемся, что psql директория существует и имеет права
mkdir -p /application/sql/psql
chmod 777 /application/sql/psql 2>/dev/null || true

# Создаем файл статуса импорта для PHP проверки
# Используем cache директорию, так как там есть права на запись
mkdir -p /application/cache
echo "importing" > /application/cache/sql_status

# Распаковка sql.gz файлов
# Проверяем наличие .gz файлов перед распаковкой
if ls /application/sql/*.gz 1> /dev/null 2>&1; then
    echo -e "${YELLOW}Распаковка SQL.gz файлов...${NC}"
    
    # Сначала устанавливаем права на запись для всех .gz файлов
    cd /application/sql
    chmod 666 *.gz 2>/dev/null || true
    
    # Распаковываем каждый файл индивидуально с подробным выводом
    for gzfile in *.gz; do
        if [ -f "$gzfile" ]; then
            echo "Распаковка: $gzfile"
            # Используем gunzip вместо gzip -d для лучшей совместимости
            gunzip -f -k "$gzfile" 2>&1 || {
                echo -e "${RED}Ошибка при распаковке $gzfile${NC}" | tee -a /application/cache/sql_status
            }
        fi
    done
    echo -e "${GREEN}✓ Распаковка SQL.gz файлов завершен успешно${NC}" | tee -a /application/cache/sql_status
else
    echo -e "${YELLOW}⚠ Файлы .gz не найдены, пропускаем распаковку${NC}" | tee -a /application/cache/sql_status
fi

# Импорт каждого SQL файла
echo ""
echo -e "${GREEN}=== Начало импорта SQL файлов ===${NC}"
echo ""

for sql_file in $SQL_FILES; do
    if [ -f "/application/sql/$sql_file" ]; then
        safe_execute "Импорт $sql_file" "/application/tools/app_topg $sql_file" 0
    else
        echo -e "${RED}✗ Файл не найден: $sql_file${NC}" | tee -a /application/cache/sql_status
        FAILED_FILES="$FAILED_FILES$sql_file (файл не найден)\n"
        ERROR_COUNT=$((ERROR_COUNT + 1))
    fi
done

# Подчистка БД
echo ""
safe_execute "Подчистка БД" "$SQL_CMD -f /application/tools/cleanup_db.sql" 1

# Обновление полнотекстовых индексов
safe_execute "Обновление индексов" "$SQL_CMD -f /application/tools/update_vectors.sql" 1

# Создание индекса zip-файлов
safe_execute "Создание индекса zip-файлов" "php /application/tools/app_update_zip_list.php 2>&1" 0

# Итоговый отчет
echo ""
echo -e "${GREEN}=== Итоговый отчет ===${NC}"
echo -e "Успешно импортировано: $SUCCESS_COUNT файлов"
echo -e "Ошибок: $ERROR_COUNT"

if [ $ERROR_COUNT -gt 0 ]; then
    echo -e "${RED}=== Файлы с ошибками ===${NC}"
    echo -e "$FAILED_FILES"
    echo ""
    echo -e "${YELLOW}⚠ ВНИМАНИЕ: Обнаружены ошибки при импорте. Проверьте логи.${NC}"
    echo "" >> /application/cache/sql_status
    echo "=== Импорт завершен с ошибками ===" >> /application/cache/sql_status
    exit 1
else
    echo -e "${GREEN}✓ Все операции выполнены без ошибок${NC}"
    echo "" >> /application/cache/sql_status
    echo "=== Импорт завершен успешно ===" >> /application/cache/sql_status
    exit 0
fi

