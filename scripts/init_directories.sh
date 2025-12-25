#!/bin/bash
# init_directories.sh - Создание необходимых директорий для Flibusta

# Не используем set -e, чтобы иметь контроль над обработкой ошибок

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Инициализация счетчика ошибок
ERRORS=0

# Загрузка переменных из .env если файл существует
if [ -f ".env" ]; then
    set -a
    source .env 2>/dev/null || true
    set +a
fi

# Определение путей из переменных окружения или значений по умолчанию
SQL_DIR="${FLIBUSTA_SQL_PATH:-./FlibustaSQL}"
BOOKS_DIR="${FLIBUSTA_BOOKS_PATH:-./Flibusta.Net}"

echo -e "${GREEN}Создание директорий...${NC}"

# Основные директории
mkdir -p "$SQL_DIR"
mkdir -p "$BOOKS_DIR"
mkdir -p cache
mkdir -p secrets

# Поддиректории кэша с проверкой
for subdir in cache/authors cache/covers cache/tmp cache/opds; do
    if ! mkdir -p "$subdir" 2>/dev/null; then
        echo -e "${RED}✗ Ошибка при создании директории: $subdir${NC}"
        ERRORS=$((ERRORS + 1))
    fi
done

# Установка прав доступа с проверкой существования директорий
if [ -d "$SQL_DIR" ] && [ -d "$BOOKS_DIR" ]; then
    chmod 755 "$SQL_DIR" "$BOOKS_DIR" 2>/dev/null || echo -e "${YELLOW}⚠ Не удалось установить права на $SQL_DIR или $BOOKS_DIR${NC}"
else
    echo -e "${YELLOW}⚠ Некоторые директории не существуют для установки прав${NC}"
fi

if [ -d "cache" ]; then
    chmod 777 cache cache/authors cache/covers cache/tmp cache/opds 2>/dev/null || echo -e "${YELLOW}⚠ Не удалось установить права на cache${NC}"
else
    echo -e "${RED}✗ Директория cache не существует${NC}"
    ERRORS=$((ERRORS + 1))
fi

if [ -d "secrets" ]; then
    chmod 700 secrets 2>/dev/null || echo -e "${YELLOW}⚠ Не удалось установить права на secrets${NC}"
else
    echo -e "${RED}✗ Директория secrets не существует${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Создание .gitkeep файлов для пустых директорий (только если директории существуют)
[ -d "$SQL_DIR" ] && touch "$SQL_DIR/.gitkeep" 2>/dev/null || true
[ -d "$BOOKS_DIR" ] && touch "$BOOKS_DIR/.gitkeep" 2>/dev/null || true
[ -d "cache/authors" ] && touch cache/authors/.gitkeep 2>/dev/null || true
[ -d "cache/covers" ] && touch cache/covers/.gitkeep 2>/dev/null || true
[ -d "cache/tmp" ] && touch cache/tmp/.gitkeep 2>/dev/null || true
[ -d "cache/opds" ] && touch cache/opds/.gitkeep 2>/dev/null || true

# Убеждаемся, что ERRORS - это число
if ! [[ "$ERRORS" =~ ^[0-9]+$ ]]; then
    ERRORS=0
fi

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}✓ Директории созданы${NC}"
    exit 0
else
    echo -e "${RED}✗ Обнаружены ошибки при создании директорий ($ERRORS ошибок)${NC}"
    exit 1
fi
