#!/bin/bash
# fix_all.sh - Автоматическое исправление всех проблем Flibusta
# Этот скрипт запускает все скрипты исправления и проверки

set -e

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo -e "${BLUE}=== Автоматическое исправление Flibusta ===${NC}"
echo ""

# Проверка, запущен ли скрипт в правильной директории
if [ ! -f "$PROJECT_DIR/docker-compose.yml" ]; then
    echo -e "${RED}✗ Ошибка: docker-compose.yml не найден${NC}"
    echo -e "${YELLOW}Запустите скрипт из корневой директории проекта${NC}"
    exit 1
fi

# 1. Исправление прав доступа
echo -e "${BLUE}=== Шаг 1: Исправление прав доступа ===${NC}"
if [ -f "$SCRIPT_DIR/fix_permissions.sh" ]; then
    bash "$SCRIPT_DIR/fix_permissions.sh"
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Права доступа исправлены${NC}"
    else
        echo -e "${YELLOW}⚠ Некоторые права не удалось исправить (возможно, требуется запуск в Docker)${NC}"
    fi
else
    echo -e "${RED}✗ Скрипт fix_permissions.sh не найден${NC}"
fi
echo ""

# 2. Проверка директорий
echo -e "${BLUE}=== Шаг 2: Создание необходимых директорий ===${NC}"
if [ -f "$SCRIPT_DIR/init_directories.sh" ]; then
    bash "$SCRIPT_DIR/init_directories.sh"
    echo -e "${GREEN}✓ Директории проверены/созданы${NC}"
else
    echo -e "${YELLOW}⚠ Скрипт init_directories.sh не найден, создаем директории вручную...${NC}"
    mkdir -p "$PROJECT_DIR/cache"/{authors,covers,tmp,opds} 2>/dev/null || true
    mkdir -p "$PROJECT_DIR/FlibustaSQL/psql" 2>/dev/null || true
    mkdir -p "$PROJECT_DIR/Flibusta.Net" 2>/dev/null || true
    mkdir -p "$PROJECT_DIR/secrets" 2>/dev/null || true
    echo -e "${GREEN}✓ Директории созданы${NC}"
fi
echo ""

# 3. Диагностика
echo -e "${BLUE}=== Шаг 3: Диагностика системы ===${NC}"
if [ -f "$SCRIPT_DIR/diagnose.sh" ]; then
    bash "$SCRIPT_DIR/diagnose.sh"
    DIAGNOSE_EXIT=$?
    if [ $DIAGNOSE_EXIT -eq 0 ]; then
        echo -e "${GREEN}✓ Диагностика завершена${NC}"
    else
        echo -e "${YELLOW}⚠ Диагностика выявила проблемы (см. выше)${NC}"
    fi
else
    echo -e "${YELLOW}⚠ Скрипт diagnose.sh не найден${NC}"
fi
echo ""

# 4. Проверка Docker (если доступен)
echo -e "${BLUE}=== Шаг 4: Проверка Docker ===${NC}"
if command -v docker-compose >/dev/null 2>&1 || docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD="docker-compose"
    if ! command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
    fi
    
    cd "$PROJECT_DIR"
    
    # Проверка статуса контейнеров
    echo -n "Проверка контейнеров... "
    if $COMPOSE_CMD ps --services --filter "status=running" 2>/dev/null | grep -q .; then
        echo -e "${GREEN}✓ Контейнеры запущены${NC}"
        
        # Исправление прав внутри контейнера
        echo "Исправление прав внутри контейнера php-fpm..."
        if $COMPOSE_CMD exec -T php-fpm sh -c "cd /application/tools && chmod +x *.sh app_topg *.py 2>/dev/null || true" 2>/dev/null; then
            echo -e "${GREEN}✓ Права на скрипты установлены в контейнере${NC}"
        else
            echo -e "${YELLOW}⚠ Не удалось установить права в контейнере (возможно, контейнер не запущен)${NC}"
        fi
        
        # Установка прав на директории внутри контейнера
        echo "Установка прав на директории в контейнере..."
        if $COMPOSE_CMD exec -T php-fpm sh -c "chmod -R 777 /application/cache /application/sql/psql 2>/dev/null || true" 2>/dev/null; then
            echo -e "${GREEN}✓ Права на директории установлены в контейнере${NC}"
        else
            echo -e "${YELLOW}⚠ Не удалось установить права на директории в контейнере${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ Контейнеры не запущены${NC}"
        echo -e "${YELLOW}Запустите: $COMPOSE_CMD up -d${NC}"
    fi
else
    echo -e "${YELLOW}⚠ Docker Compose не найден${NC}"
fi
echo ""

# Итоговый отчет
echo -e "${BLUE}=== Итоговый отчет ===${NC}"
echo ""
echo -e "${GREEN}Автоматическое исправление завершено!${NC}"
echo ""
echo -e "${YELLOW}Рекомендации:${NC}"
echo -e "${YELLOW}1. Если контейнеры не запущены, запустите: docker-compose up -d${NC}"
echo -e "${YELLOW}2. Запустите диагностику внутри контейнера: docker-compose exec php-fpm sh /application/scripts/diagnose.sh${NC}"
echo -e "${YELLOW}3. Проверьте веб-интерфейс: http://localhost:27100${NC}"
echo -e "${YELLOW}4. Если проблемы остались, проверьте логи: docker-compose logs${NC}"
echo ""
