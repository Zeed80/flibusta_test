#!/bin/bash
# fix_permissions.sh - Исправление прав доступа для Flibusta
# Этот скрипт устанавливает правильные права на скрипты и директории
# Может использоваться как standalone скрипт или как библиотека функций

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функция инициализации путей (можно вызывать из других скриптов)
init_paths() {
    # Загрузка переменных из .env если файл существует (только на хосте)
    if [ ! -d "/application" ] && [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
    fi

    # Определяем, запущен ли скрипт внутри Docker контейнера или на хосте
    if [ -d "/application" ]; then
        # Внутри Docker контейнера
        APPLICATION_DIR="/application"
        CACHE_DIR="/application/cache"
        SQL_DIR="/application/sql"
        BOOKS_DIR="/application/flibusta"
        TOOLS_DIR="/application/tools"
        WWW_USER="www-data"
        WWW_GROUP="www-data"
        IN_DOCKER=true
    else
        # На хосте
        APPLICATION_DIR="$(cd "$(dirname "$0")/.." && pwd)"
        CACHE_DIR="$APPLICATION_DIR/cache"
        # Используем переменные окружения или значения по умолчанию
        SQL_DIR="${FLIBUSTA_SQL_PATH:-$APPLICATION_DIR/FlibustaSQL}"
        BOOKS_DIR="${FLIBUSTA_BOOKS_PATH:-$APPLICATION_DIR/Flibusta.Net}"
        TOOLS_DIR="$APPLICATION_DIR/application/tools"
        WWW_USER=""
        WWW_GROUP=""
        IN_DOCKER=false
    fi
}

# Основная функция установки прав (можно вызывать из других скриптов)
fix_permissions_internal() {
    local quiet="${1:-false}"
    
    # Инициализация путей
    init_paths
    
    if [ "$quiet" != "true" ]; then
        echo -e "${GREEN}=== Исправление прав доступа для Flibusta ===${NC}"
        echo ""
        if [ "$IN_DOCKER" = "false" ]; then
            echo -e "${YELLOW}Обнаружена среда хоста${NC}"
            echo -e "${YELLOW}SQL_DIR: $SQL_DIR${NC}"
            echo -e "${YELLOW}BOOKS_DIR: $BOOKS_DIR${NC}"
        else
            echo -e "${YELLOW}Обнаружена среда Docker контейнера${NC}"
        fi
    fi

# Функция для установки прав
set_permissions() {
    local path="$1"
    local permissions="$2"
    local owner="${3:-}"
    
    if [ ! -e "$path" ]; then
        echo -e "${RED}✗ Путь не существует: $path${NC}"
        return 1
    fi
    
    # Устанавливаем права
    if chmod "$permissions" "$path" 2>/dev/null; then
        echo -e "${GREEN}✓ Установлены права $permissions для: $path${NC}"
    else
        echo -e "${YELLOW}⚠ Не удалось установить права для: $path (возможно, требуется sudo)${NC}"
    fi
    
    # Устанавливаем владельца (только в Docker или с sudo)
    if [ -n "$owner" ] && [ -n "$WWW_USER" ]; then
        if chown "$owner" "$path" 2>/dev/null; then
            echo -e "${GREEN}✓ Установлен владелец $owner для: $path${NC}"
        else
            echo -e "${YELLOW}⚠ Не удалось установить владельца для: $path${NC}"
        fi
    fi
}

    # Создание необходимых директорий
    if [ "$quiet" != "true" ]; then
        echo -e "${YELLOW}Создание директорий...${NC}"
    fi
    mkdir -p "$CACHE_DIR"/{authors,covers,tmp,opds} 2>/dev/null || true
    mkdir -p "$SQL_DIR"/psql 2>/dev/null || true

    # Установка прав на директории
    if [ "$quiet" != "true" ]; then
        echo ""
        echo -e "${YELLOW}Установка прав на директории...${NC}"
    fi

# Директории кэша - полные права на запись
set_permissions "$CACHE_DIR" "777" "$WWW_USER:$WWW_GROUP"
set_permissions "$CACHE_DIR/authors" "777" "$WWW_USER:$WWW_GROUP"
set_permissions "$CACHE_DIR/covers" "777" "$WWW_USER:$WWW_GROUP"
set_permissions "$CACHE_DIR/tmp" "777" "$WWW_USER:$WWW_GROUP"
set_permissions "$CACHE_DIR/opds" "777" "$WWW_USER:$WWW_GROUP"

# Директория SQL - права на чтение/запись
set_permissions "$SQL_DIR" "755" "$WWW_USER:$WWW_GROUP"
set_permissions "$SQL_DIR/psql" "777" "$WWW_USER:$WWW_GROUP"

# Директория с книгами - права на чтение
if [ -d "$BOOKS_DIR" ]; then
    set_permissions "$BOOKS_DIR" "755" "$WWW_USER:$WWW_GROUP"
else
    echo -e "${YELLOW}⚠ Директория с книгами не найдена: $BOOKS_DIR${NC}"
fi

    # Установка прав на скрипты
    if [ "$quiet" != "true" ]; then
        echo ""
        echo -e "${YELLOW}Установка прав на выполнение для скриптов...${NC}"
    fi

if [ -d "$TOOLS_DIR" ]; then
    # Shell скрипты
    for script in "$TOOLS_DIR"/*.sh; do
        if [ -f "$script" ]; then
            set_permissions "$script" "755" "$WWW_USER:$WWW_GROUP"
        fi
    done
    
    # Python скрипты
    for script in "$TOOLS_DIR"/*.py; do
        if [ -f "$script" ]; then
            set_permissions "$script" "755" "$WWW_USER:$WWW_GROUP"
        fi
    done
    
    # Бинарные скрипты
    if [ -f "$TOOLS_DIR/app_topg" ]; then
        set_permissions "$TOOLS_DIR/app_topg" "755" "$WWW_USER:$WWW_GROUP"
    fi
    
    # PHP скрипты (должны быть исполняемыми для запуска через CLI)
    if [ -f "$TOOLS_DIR/app_update_zip_list.php" ]; then
        set_permissions "$TOOLS_DIR/app_update_zip_list.php" "644" "$WWW_USER:$WWW_GROUP"
    fi
else
    echo -e "${RED}✗ Директория tools не найдена: $TOOLS_DIR${NC}"
fi

# Проверка прав на dbinit.sh (используется другими скриптами)
if [ -f "$TOOLS_DIR/dbinit.sh" ]; then
    set_permissions "$TOOLS_DIR/dbinit.sh" "755" "$WWW_USER:$WWW_GROUP"
fi

    # Установка прав на скрипты в scripts/
    if [ -d "$APPLICATION_DIR/scripts" ]; then
        if [ "$quiet" != "true" ]; then
            echo ""
            echo -e "${YELLOW}Установка прав на скрипты в scripts/...${NC}"
        fi
        for script in "$APPLICATION_DIR/scripts"/*.sh; do
            if [ -f "$script" ]; then
                set_permissions "$script" "755"
            fi
        done
    fi
}

# Если скрипт запущен напрямую (не через source), выполняем основную логику
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    set -e
    fix_permissions_internal false

    # Финальная проверка
    echo ""
    echo -e "${YELLOW}Проверка установленных прав...${NC}"

ERRORS=0

# Проверка скриптов
check_script() {
    local script="$1"
    if [ -f "$script" ]; then
        if [ ! -x "$script" ]; then
            echo -e "${RED}✗ Скрипт не исполняемый: $script${NC}"
            ERRORS=$((ERRORS + 1))
        else
            echo -e "${GREEN}✓ Скрипт исполняемый: $script${NC}"
        fi
    fi
}

check_script "$TOOLS_DIR/app_import_sql.sh"
check_script "$TOOLS_DIR/app_reindex.sh"
check_script "$TOOLS_DIR/app_topg"
check_script "$TOOLS_DIR/app_db_converter.py"
check_script "$TOOLS_DIR/dbinit.sh"

# Проверка директорий
check_dir() {
    local dir="$1"
    if [ -d "$dir" ]; then
        if [ ! -w "$dir" ]; then
            echo -e "${RED}✗ Директория не доступна для записи: $dir${NC}"
            ERRORS=$((ERRORS + 1))
        else
            echo -e "${GREEN}✓ Директория доступна для записи: $dir${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ Директория не найдена: $dir${NC}"
    fi
}

check_dir "$CACHE_DIR"
check_dir "$CACHE_DIR/tmp"
check_dir "$SQL_DIR/psql"

    echo ""
    if [ $ERRORS -eq 0 ]; then
        echo -e "${GREEN}=== Все права установлены успешно! ===${NC}"
        exit 0
    else
        echo -e "${RED}=== Обнаружены проблемы с правами доступа ($ERRORS ошибок) ===${NC}"
        echo -e "${YELLOW}Если скрипт запущен на хосте, попробуйте запустить с sudo:${NC}"
        echo -e "${YELLOW}sudo $0${NC}"
        exit 1
    fi
fi
