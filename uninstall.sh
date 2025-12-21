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
REMOVE_DATA=0

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
            --remove-data)
                REMOVE_DATA=1
                shift
                ;;
            --all)
                REMOVE_IMAGES=1
                REMOVE_VOLUMES=1
                REMOVE_CONFIG=1
                REMOVE_DATA=1
                shift
                ;;
            -h|--help)
                echo "Использование: $0 [опции]"
                echo ""
                echo "Опции:"
                echo "  --remove-images    Удалить Docker образы"
                echo "  --remove-volumes   Удалить Docker volumes (БД будет удалена!)"
                echo "  --remove-config    Удалить файлы конфигурации (.env, secrets)"
                echo "  --remove-data      Удалить данные (книги, SQL файлы)"
                echo "  --all              Удалить всё (опасно!)"
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

# Удаление данных
remove_data() {
    if [ $REMOVE_DATA -eq 0 ]; then
        return 0
    fi
    
    if ! confirm "Удалить данные (книги, SQL файлы)? (Необратимо!)"; then
        return 0
    fi
    
    echo -e "${BLUE}Удаление данных...${NC}"
    
    [ -d "FlibustaSQL" ] && rm -rf FlibustaSQL && echo -e "${GREEN}✓ FlibustaSQL удален${NC}"
    [ -d "Flibusta.Net" ] && rm -rf Flibusta.Net && echo -e "${GREEN}✓ Flibusta.Net удален${NC}"
    [ -d "cache" ] && rm -rf cache && echo -e "${GREEN}✓ cache удален${NC}"
}

# Главная функция
main() {
    echo -e "${BLUE}Удаление Flibusta Local Mirror${NC}"
    echo ""
    
    parse_arguments "$@"
    
    # Остановка контейнеров
    stop_containers
    
    # Удаление компонентов
    remove_images
    remove_volumes
    remove_config
    remove_data
    
    echo ""
    echo -e "${GREEN}Удаление завершено${NC}"
    echo ""
    echo "Остались файлы проекта. Для полного удаления:"
    echo "  rm -rf $(pwd)"
}

main "$@"
