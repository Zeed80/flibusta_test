#!/bin/bash
# diagnose.sh - Диагностика проблем Flibusta
# Этот скрипт проверяет все аспекты системы и выявляет проблемы

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

ERRORS=0
WARNINGS=0

# Определяем, запущен ли скрипт внутри Docker контейнера или на хосте
if [ -d "/application" ]; then
    # Внутри Docker контейнера
    APPLICATION_DIR="/application"
    CACHE_DIR="/application/cache"
    SQL_DIR="/application/sql"
    BOOKS_DIR="/application/flibusta"
    TOOLS_DIR="/application/tools"
    IN_DOCKER=true
else
    # На хосте
    APPLICATION_DIR="$(cd "$(dirname "$0")/.." && pwd)"
    CACHE_DIR="$APPLICATION_DIR/cache"
    SQL_DIR="$APPLICATION_DIR/FlibustaSQL"
    BOOKS_DIR="$APPLICATION_DIR/Flibusta.Net"
    TOOLS_DIR="$APPLICATION_DIR/application/tools"
    IN_DOCKER=false
fi

echo -e "${BLUE}=== Диагностика Flibusta ===${NC}"
echo ""
echo -e "${BLUE}Рабочая директория: $APPLICATION_DIR${NC}"
echo -e "${BLUE}Среда: $([ "$IN_DOCKER" = "true" ] && echo "Docker контейнер" || echo "Хост")${NC}"
echo ""

# Функция проверки
check() {
    local name="$1"
    local test_cmd="$2"
    local error_msg="$3"
    
    echo -n "Проверка: $name... "
    if eval "$test_cmd" >/dev/null 2>&1; then
        echo -e "${GREEN}✓ OK${NC}"
        return 0
    else
        echo -e "${RED}✗ ОШИБКА${NC}"
        if [ -n "$error_msg" ]; then
            echo -e "  ${RED}→ $error_msg${NC}"
        fi
        ERRORS=$((ERRORS + 1))
        return 1
    fi
}

# Функция предупреждения
warn() {
    local name="$1"
    local test_cmd="$2"
    local warning_msg="$3"
    
    echo -n "Проверка: $name... "
    if eval "$test_cmd" >/dev/null 2>&1; then
        echo -e "${GREEN}✓ OK${NC}"
        return 0
    else
        echo -e "${YELLOW}⚠ ПРЕДУПРЕЖДЕНИЕ${NC}"
        if [ -n "$warning_msg" ]; then
            echo -e "  ${YELLOW}→ $warning_msg${NC}"
        fi
        WARNINGS=$((WARNINGS + 1))
        return 1
    fi
}

# 1. Проверка структуры директорий
echo -e "${BLUE}=== 1. Структура директорий ===${NC}"

check "Директория application" "[ -d '$APPLICATION_DIR' ]" \
    "Директория application не найдена"

check "Директория cache" "[ -d '$CACHE_DIR' ]" \
    "Директория cache не найдена. Создайте: mkdir -p $CACHE_DIR"

check "Директория cache/authors" "[ -d '$CACHE_DIR/authors' ]" \
    "Директория cache/authors не найдена"

check "Директория cache/covers" "[ -d '$CACHE_DIR/covers' ]" \
    "Директория cache/covers не найдена"

check "Директория cache/tmp" "[ -d '$CACHE_DIR/tmp' ]" \
    "Директория cache/tmp не найдена"

warn "Директория SQL" "[ -d '$SQL_DIR' ]" \
    "Директория SQL не найдена: $SQL_DIR"

warn "Директория SQL/psql" "[ -d '$SQL_DIR/psql' ]" \
    "Директория SQL/psql не найдена"

warn "Директория books" "[ -d '$BOOKS_DIR' ]" \
    "Директория с книгами не найдена: $BOOKS_DIR"

echo ""

# 2. Проверка прав доступа
echo -e "${BLUE}=== 2. Права доступа ===${NC}"

check "Права на запись в cache" "[ -w '$CACHE_DIR' ]" \
    "Нет прав на запись в $CACHE_DIR. Выполните: chmod 777 $CACHE_DIR"

check "Права на запись в cache/tmp" "[ -w '$CACHE_DIR/tmp' ]" \
    "Нет прав на запись в $CACHE_DIR/tmp. Выполните: chmod 777 $CACHE_DIR/tmp"

if [ -d "$SQL_DIR/psql" ]; then
    check "Права на запись в sql/psql" "[ -w '$SQL_DIR/psql' ]" \
        "Нет прав на запись в $SQL_DIR/psql. Выполните: chmod 777 $SQL_DIR/psql"
fi

if [ -d "$BOOKS_DIR" ]; then
    check "Права на чтение books" "[ -r '$BOOKS_DIR' ]" \
        "Нет прав на чтение $BOOKS_DIR. Выполните: chmod 755 $BOOKS_DIR"
fi

echo ""

# 3. Проверка скриптов
echo -e "${BLUE}=== 3. Скрипты ===${NC}"

check "Скрипт app_import_sql.sh" "[ -f '$TOOLS_DIR/app_import_sql.sh' ]" \
    "Скрипт app_import_sql.sh не найден"

if [ -f "$TOOLS_DIR/app_import_sql.sh" ]; then
    check "Права на выполнение app_import_sql.sh" "[ -x '$TOOLS_DIR/app_import_sql.sh' ]" \
        "Скрипт app_import_sql.sh не исполняемый. Выполните: chmod +x $TOOLS_DIR/app_import_sql.sh"
fi

check "Скрипт app_reindex.sh" "[ -f '$TOOLS_DIR/app_reindex.sh' ]" \
    "Скрипт app_reindex.sh не найден"

if [ -f "$TOOLS_DIR/app_reindex.sh" ]; then
    check "Права на выполнение app_reindex.sh" "[ -x '$TOOLS_DIR/app_reindex.sh' ]" \
        "Скрипт app_reindex.sh не исполняемый. Выполните: chmod +x $TOOLS_DIR/app_reindex.sh"
fi

check "Скрипт app_topg" "[ -f '$TOOLS_DIR/app_topg' ]" \
    "Скрипт app_topg не найден"

if [ -f "$TOOLS_DIR/app_topg" ]; then
    check "Права на выполнение app_topg" "[ -x '$TOOLS_DIR/app_topg' ]" \
        "Скрипт app_topg не исполняемый. Выполните: chmod +x $TOOLS_DIR/app_topg"
fi

check "Скрипт app_db_converter.py" "[ -f '$TOOLS_DIR/app_db_converter.py' ]" \
    "Скрипт app_db_converter.py не найден"

if [ -f "$TOOLS_DIR/app_db_converter.py" ]; then
    check "Права на выполнение app_db_converter.py" "[ -x '$TOOLS_DIR/app_db_converter.py' ]" \
        "Скрипт app_db_converter.py не исполняемый. Выполните: chmod +x $TOOLS_DIR/app_db_converter.py"
fi

check "Скрипт dbinit.sh" "[ -f '$TOOLS_DIR/dbinit.sh' ]" \
    "Скрипт dbinit.sh не найден"

if [ -f "$TOOLS_DIR/dbinit.sh" ]; then
    check "Права на выполнение dbinit.sh" "[ -x '$TOOLS_DIR/dbinit.sh' ]" \
        "Скрипт dbinit.sh не исполняемый. Выполните: chmod +x $TOOLS_DIR/dbinit.sh"
fi

check "Скрипт app_update_zip_list.php" "[ -f '$TOOLS_DIR/app_update_zip_list.php' ]" \
    "Скрипт app_update_zip_list.php не найден"

echo ""

# 4. Проверка файлов данных
echo -e "${BLUE}=== 4. Файлы данных ===${NC}"

if [ -d "$SQL_DIR" ]; then
    SQL_COUNT=$(find "$SQL_DIR" -maxdepth 1 -name "*.sql" -o -name "*.sql.gz" 2>/dev/null | wc -l)
    if [ "$SQL_COUNT" -gt 0 ]; then
        echo -e "${GREEN}✓ Найдено SQL файлов: $SQL_COUNT${NC}"
    else
        echo -e "${YELLOW}⚠ SQL файлы не найдены в $SQL_DIR${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    echo -e "${YELLOW}⚠ Директория SQL не найдена${NC}"
    WARNINGS=$((WARNINGS + 1))
fi

if [ -d "$BOOKS_DIR" ]; then
    ZIP_COUNT=$(find "$BOOKS_DIR" -maxdepth 1 -name "*.zip" -type f 2>/dev/null | wc -l)
    if [ "$ZIP_COUNT" -gt 0 ]; then
        echo -e "${GREEN}✓ Найдено ZIP файлов: $ZIP_COUNT${NC}"
    else
        echo -e "${YELLOW}⚠ ZIP файлы не найдены в $BOOKS_DIR${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    echo -e "${YELLOW}⚠ Директория с книгами не найдена${NC}"
    WARNINGS=$((WARNINGS + 1))
fi

echo ""

# 5. Проверка базы данных (только в Docker)
if [ "$IN_DOCKER" = "true" ]; then
    echo -e "${BLUE}=== 5. База данных ===${NC}"
    
    # Проверка подключения к БД
    if command -v psql >/dev/null 2>&1; then
        if psql -h "${FLIBUSTA_DBHOST:-postgres}" -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" -c "SELECT 1;" >/dev/null 2>&1; then
            echo -e "${GREEN}✓ Подключение к базе данных успешно${NC}"
            
            # Проверка таблицы book_zip
            if psql -h "${FLIBUSTA_DBHOST:-postgres}" -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" -t -c "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'book_zip');" 2>/dev/null | grep -q "t"; then
                echo -e "${GREEN}✓ Таблица book_zip существует${NC}"
                
                # Количество записей в book_zip
                ZIP_RECORDS=$(psql -h "${FLIBUSTA_DBHOST:-postgres}" -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" -t -c "SELECT COUNT(*) FROM book_zip WHERE is_valid = TRUE;" 2>/dev/null | tr -d ' ')
                if [ -n "$ZIP_RECORDS" ] && [ "$ZIP_RECORDS" -gt 0 ]; then
                    echo -e "${GREEN}✓ Записей в book_zip: $ZIP_RECORDS${NC}"
                else
                    echo -e "${YELLOW}⚠ Таблица book_zip пуста или не проиндексирована${NC}"
                    WARNINGS=$((WARNINGS + 1))
                fi
            else
                echo -e "${YELLOW}⚠ Таблица book_zip не существует${NC}"
                WARNINGS=$((WARNINGS + 1))
            fi
            
            # Проверка таблицы libbook
            if psql -h "${FLIBUSTA_DBHOST:-postgres}" -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" -t -c "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'libbook');" 2>/dev/null | grep -q "t"; then
                echo -e "${GREEN}✓ Таблица libbook существует${NC}"
                
                BOOK_COUNT=$(psql -h "${FLIBUSTA_DBHOST:-postgres}" -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" -t -c "SELECT COUNT(*) FROM libbook WHERE deleted='0';" 2>/dev/null | tr -d ' ')
                if [ -n "$BOOK_COUNT" ] && [ "$BOOK_COUNT" -gt 0 ]; then
                    echo -e "${GREEN}✓ Книг в базе данных: $BOOK_COUNT${NC}"
                else
                    echo -e "${YELLOW}⚠ База данных пуста или не импортирована${NC}"
                    WARNINGS=$((WARNINGS + 1))
                fi
            else
                echo -e "${YELLOW}⚠ Таблица libbook не существует (база данных не импортирована)${NC}"
                WARNINGS=$((WARNINGS + 1))
            fi
        else
            echo -e "${RED}✗ Не удалось подключиться к базе данных${NC}"
            ERRORS=$((ERRORS + 1))
        fi
    else
        echo -e "${YELLOW}⚠ psql не найден (проверка БД пропущена)${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
    echo ""
fi

# 6. Проверка PHP расширений (только в Docker)
if [ "$IN_DOCKER" = "true" ]; then
    echo -e "${BLUE}=== 6. PHP расширения ===${NC}"
    
    if command -v php >/dev/null 2>&1; then
        php -m | grep -q "zip" && echo -e "${GREEN}✓ Расширение zip установлено${NC}" || echo -e "${RED}✗ Расширение zip не установлено${NC}" && ERRORS=$((ERRORS + 1))
        php -m | grep -q "pdo_pgsql" && echo -e "${GREEN}✓ Расширение pdo_pgsql установлено${NC}" || echo -e "${RED}✗ Расширение pdo_pgsql не установлено${NC}" && ERRORS=$((ERRORS + 1))
        php -m | grep -q "gd" && echo -e "${GREEN}✓ Расширение gd установлено${NC}" || echo -e "${RED}✗ Расширение gd не установлено${NC}" && ERRORS=$((ERRORS + 1))
    else
        echo -e "${YELLOW}⚠ PHP не найден${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
    echo ""
fi

# Итоговый отчет
echo -e "${BLUE}=== Итоговый отчет ===${NC}"
echo ""

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}✓ Все проверки пройдены успешно!${NC}"
    exit 0
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}⚠ Обнаружено предупреждений: $WARNINGS${NC}"
    echo -e "${YELLOW}Система может работать, но рекомендуется исправить предупреждения${NC}"
    exit 0
else
    echo -e "${RED}✗ Обнаружено ошибок: $ERRORS${NC}"
    if [ $WARNINGS -gt 0 ]; then
        echo -e "${YELLOW}⚠ Обнаружено предупреждений: $WARNINGS${NC}"
    fi
    echo ""
    echo -e "${YELLOW}Рекомендации:${NC}"
    echo -e "${YELLOW}1. Запустите скрипт исправления прав: ./scripts/fix_permissions.sh${NC}"
    echo -e "${YELLOW}2. Проверьте конфигурацию Docker и переменные окружения${NC}"
    echo -e "${YELLOW}3. Убедитесь, что все необходимые файлы на месте${NC}"
    exit 1
fi
