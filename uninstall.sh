#!/bin/bash
# uninstall.sh - Удаление установки Flibusta

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

REMOVE_IMAGES=0
REMOVE_VOLUMES=0
REMOVE_CONFIG=0
REMOVE_CACHE=0
REMOVE_SQL=0

# Парсинг аргументов
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --remove-images)
                REMOVE_IMAGES=1
                shift
                ;;
            --remove-volumes)
                REMOVE_VOLUMES=1
                shift
                ;;
            --remove-config)
                REMOVE_CONFIG=1
                shift
                ;;
            --remove-cache)
                REMOVE_CACHE=1
                shift
                ;;
            --remove-sql)
                REMOVE_SQL=1
                shift
                ;;
            --remove-data)
                echo -e "${RED}⚠️ ВНИМАНИЕ: Опция --remove-data больше не поддерживается.${NC}"
                echo -e "${YELLOW}Книги (Flibusta.Net) больше не удаляются автоматически.${NC}"
                echo -e "${YELLOW}Используйте --remove-sql для удаления SQL файлов.${NC}"
                echo -e "${YELLOW}Книги должны быть удалены вручную пользователем.${NC}"
                shift
                ;;
            --all)
                REMOVE_IMAGES=1
                REMOVE_VOLUMES=1
                REMOVE_CONFIG=1
                REMOVE_CACHE=1
                REMOVE_SQL=1
                shift
                ;;
            -h|--help)
                echo "Использование: $0 [опции]"
                echo ""
                echo "Опции:"
                echo "  --remove-images    Удалить Docker образы"
                echo "  --remove-volumes   Удалить Docker volumes (БД будет удалена!)"
                echo "  --remove-config    Удалить файлы конфигурации (.env, secrets)"
                echo "  --remove-cache     Удалить кэш (cache/)"
                echo "  --remove-sql      Удалить SQL файлы (FlibustaSQL/)"
                echo "  --all              Удалить всё кроме книг (опасно!)"
                echo ""
                echo "⚠️  ВАЖНО:"
                echo "  • Книги (Flibusta.Net) НИКОГДА не удаляются автоматически"
                echo "  • SQL файлы (FlibustaSQL) удаляются только с опцией --remove-sql"
                echo "  • Книги часто находятся на других дисках и должны быть удалены вручную"
                echo "  • Для полного удаления: ./uninstall.sh --all"
                echo "  • Удалите книги вручную, если это необходимо: rm -rf Flibusta.Net"
                echo ""
                echo "  -h, --help         Показать эту справку"
                exit 0
                ;;
            *)
                echo "Неизвестный параметр: $1"
                exit 1
                ;;
        esac
    done
}

# Подтверждение
confirm() {
    local message=$1
    read -p "$message (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        return 1
    fi
    return 0
}

# Остановка контейнеров
stop_containers() {
    echo -e "${BLUE}Остановка контейнеров...${NC}"
    
    local compose_cmd="docker-compose"
    if ! command -v docker-compose &> /dev/null; then
        compose_cmd="docker compose"
    fi
    
    if [ -f "docker-compose.yml" ]; then
        $compose_cmd down 2>/dev/null || true
        echo -e "${GREEN}✓ Контейнеры остановлены${NC}"
    else
        echo -e "${YELLOW}⚠ docker-compose.yml не найден${NC}"
    fi
}

# Удаление образов
remove_images() {
    if [ $REMOVE_IMAGES -eq 0 ]; then
        return 0
    fi
    
    if ! confirm "Удалить Docker образы?"; then
        return 0
    fi
    
    echo -e "${BLUE}Удаление образов...${NC}"
    
    # Поиск образов проекта
    local images=$(docker images | grep -E "(flibusta|php-fpm|postgres)" | awk '{print $3}' | sort -u)
    
    if [ -n "$images" ]; then
        echo "$images" | xargs docker rmi -f 2>/dev/null || true
        echo -e "${GREEN}✓ Образы удалены${NC}"
    else
        echo -e "${YELLOW}⚠ Образы не найдены${NC}"
    fi
}

# Удаление volumes
remove_volumes() {
    if [ $REMOVE_VOLUMES -eq 0 ]; then
        return 0
    fi
    
    if ! confirm "Удалить Docker volumes? (БД будет удалена!)"; then
        return 0
    fi
    
    echo -e "${BLUE}Удаление volumes...${NC}"
    
    local compose_cmd="docker-compose"
    if ! command -v docker-compose &> /dev/null; then
        compose_cmd="docker compose"
    fi
    
    if [ -f "docker-compose.yml" ]; then
        $compose_cmd down -v 2>/dev/null || true
        echo -e "${GREEN}✓ Volumes удалены${NC}"
    fi
}

# Удаление конфигурации
remove_config() {
    if [ $REMOVE_CONFIG -eq 0 ]; then
        return 0
    fi
    
    if ! confirm "Удалить файлы конфигурации (.env, secrets)?"; then
        return 0
    fi
    
    echo -e "${BLUE}Удаление конфигурации...${NC}"
    
    [ -f ".env" ] && rm -f .env && echo -e "${GREEN}✓ .env удален${NC}"
    [ -d "secrets" ] && rm -rf secrets && echo -e "${GREEN}✓ secrets удалены${NC}"
}

# Удаление кэша
remove_cache() {
    if [ $REMOVE_CACHE -eq 0 ]; then
        return 0
    fi

    if ! confirm "Удалить кэш (cache/)?"; then
        return 0
    fi

    echo -e "${BLUE}Удаление кэша...${NC}"

    [ -d "cache" ] && rm -rf cache && echo -e "${GREEN}✓ cache удален${NC}"

    echo -e "${YELLOW}⚠️  Книги (Flibusta.Net) и SQL файлы (FlibustaSQL) НЕ удалены${NC}"
    echo -e "${YELLOW}⚠️  Они должны быть удалены вручную пользователем:${NC}"
    echo -e "${YELLOW}     rm -rf Flibusta.Net FlibustaSQL${NC}"
}

# Удаление SQL файлов
remove_sql() {
    if [ $REMOVE_SQL -eq 0 ]; then
        return 0
    fi

    if ! confirm "Удалить SQL файлы (FlibustaSQL/)?"; then
        return 0
    fi

    echo -e "${BLUE}Удаление SQL файлов...${NC}"

    [ -d "FlibustaSQL" ] && rm -rf FlibustaSQL && echo -e "${GREEN}✓ FlibustaSQL удален${NC}"

    echo -e "${YELLOW}⚠️  Книги (Flibusta.Net) НЕ удалены${NC}"
    echo -e "${YELLOW}⚠️  Они должны быть удалены вручную пользователем:${NC}"
    echo -e "${YELLOW}     rm -rf Flibusta.Net${NC}"
}

# Главная функция
main() {
    echo -e "${BLUE}Удаление Flibusta Local Mirror${NC}"
    echo ""
    echo -e "${YELLOW}⚠️  ВНИМАНИЕ:${NC}"
    echo -e "${YELLOW}  Книги (Flibusta.Net) НИКОГДА не будут удалены автоматически${NC}"
    echo -e "${YELLOW}  SQL файлы (FlibustaSQL) удаляются только с опцией --remove-sql${NC}"
    echo -e "${YELLOW}  Книги часто находятся на других дисках и должны быть удалены вручную${NC}"
    echo ""

    parse_arguments "$@"

    # Остановка контейнеров
    stop_containers

    # Удаление компонентов
    remove_images
    remove_volumes
    remove_config
    remove_cache
    remove_sql

    echo ""
    echo -e "${GREEN}Удаление завершено${NC}"
    echo ""
    echo -e "${YELLOW}⚠️  Для ручного удаления книг:${NC}"
    echo -e "  ${BLUE}rm -rf Flibusta.Net${NC}"
    echo ""
    echo -e "${YELLOW}Для удаления оставшихся файлов проекта:${NC}"
    echo -e "  ${BLUE}rm -rf $(pwd)${NC}"
}

main "$@"
