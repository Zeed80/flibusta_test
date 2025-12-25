#!/bin/bash
# check_requirements.sh - Проверка требований для установки Flibusta

# Не используем set -e, чтобы иметь контроль над обработкой ошибок

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

ERRORS=0
WARNINGS=0

echo "=== Проверка требований ==="
echo ""

# Проверка ОС
check_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        VER=$VERSION_ID
        
        if [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
            echo -e "${GREEN}✓${NC} $OS $VER обнаружена"
        else
            echo -e "${YELLOW}⚠${NC} ОС не Ubuntu/Debian. Установка может не работать."
            WARNINGS=$((WARNINGS + 1))
        fi
    else
        echo -e "${YELLOW}⚠${NC} Не удалось определить версию ОС"
        WARNINGS=$((WARNINGS + 1))
    fi
}

# Проверка Docker
check_docker() {
    if command -v docker &> /dev/null; then
        DOCKER_VERSION=$(docker --version | grep -oP '\d+\.\d+' | head -1)
        DOCKER_MAJOR=$(echo $DOCKER_VERSION | cut -d. -f1)
        DOCKER_MINOR=$(echo $DOCKER_VERSION | cut -d. -f2)
        
        if [ "$DOCKER_MAJOR" -gt 20 ] 2>/dev/null || ([ "$DOCKER_MAJOR" -eq 20 ] 2>/dev/null && [ "$DOCKER_MINOR" -ge 10 ] 2>/dev/null); then
            echo -e "${GREEN}✓${NC} Docker версии $DOCKER_VERSION установлен"
        else
            echo -e "${RED}✗${NC} Docker версии $DOCKER_VERSION установлен (требуется >= 20.10)"
            ERRORS=$((ERRORS + 1))
        fi
    else
        echo -e "${RED}✗${NC} Docker не установлен"
        echo "   Установите Docker: curl -fsSL https://get.docker.com -o get-docker.sh && sudo sh get-docker.sh"
        ERRORS=$((ERRORS + 1))
    fi
}

# Проверка Docker Compose
check_docker_compose() {
    if command -v docker-compose &> /dev/null; then
        COMPOSE_VERSION=$(docker-compose --version | grep -oP '\d+\.\d+' | head -1)
        COMPOSE_MAJOR=$(echo $COMPOSE_VERSION | cut -d. -f1)
        
        if [ "$COMPOSE_MAJOR" -ge 2 ] 2>/dev/null; then
            echo -e "${GREEN}✓${NC} Docker Compose версии $COMPOSE_VERSION установлен"
        else
            echo -e "${RED}✗${NC} Docker Compose версии $COMPOSE_VERSION установлен (требуется >= 2.0)"
            ERRORS=$((ERRORS + 1))
        fi
    elif docker compose version &> /dev/null; then
        COMPOSE_VERSION=$(docker compose version 2>/dev/null | grep -oP '\d+\.\d+' | head -1 || echo "0.0")
        COMPOSE_MAJOR=$(echo $COMPOSE_VERSION | cut -d. -f1)
        
        if [ "$COMPOSE_MAJOR" -ge 2 ] 2>/dev/null; then
            echo -e "${GREEN}✓${NC} Docker Compose (плагин) версии $COMPOSE_VERSION установлен"
        else
            echo -e "${RED}✗${NC} Docker Compose версии $COMPOSE_VERSION установлен (требуется >= 2.0)"
            ERRORS=$((ERRORS + 1))
        fi
    else
        echo -e "${RED}✗${NC} Docker Compose не установлен"
        echo "   Установите Docker Compose: https://docs.docker.com/compose/install/"
        ERRORS=$((ERRORS + 1))
    fi
}

# Проверка порта
check_port() {
    local port=$1
    local name=$2
    
    if command -v ss &> /dev/null; then
        if ss -tuln 2>/dev/null | grep -q ":$port "; then
            echo -e "${RED}✗${NC} Порт $port ($name) занят"
            ERRORS=$((ERRORS + 1))
        else
            echo -e "${GREEN}✓${NC} Порт $port ($name) свободен"
        fi
    elif command -v netstat &> /dev/null; then
        if netstat -tuln 2>/dev/null | grep -q ":$port "; then
            echo -e "${RED}✗${NC} Порт $port ($name) занят"
            ERRORS=$((ERRORS + 1))
        else
            echo -e "${GREEN}✓${NC} Порт $port ($name) свободен"
        fi
    else
        echo -e "${YELLOW}⚠${NC} Не удалось проверить порт $port (ss/netstat не найдены)"
        WARNINGS=$((WARNINGS + 1))
    fi
}

# Проверка свободного места
check_disk_space() {
    local required_gb=10
    local available_kb=$(df -BG . 2>/dev/null | tail -1 | awk '{print $4}' | sed 's/G//' || echo "0")
    local available_gb=${available_kb%.*}
    
    # Проверка, что available_gb - это число
    if ! [[ "$available_gb" =~ ^[0-9]+$ ]]; then
        available_gb=0
    fi
    
    if [ "$available_gb" -ge "$required_gb" ] 2>/dev/null; then
        echo -e "${GREEN}✓${NC} Достаточно места на диске (${available_gb}GB свободно)"
    else
        echo -e "${YELLOW}⚠${NC} Мало места на диске (${available_gb}GB свободно, требуется минимум ${required_gb}GB)"
        WARNINGS=$((WARNINGS + 1))
    fi
}

# Проверка необходимых пакетов
check_packages() {
    local packages=("curl" "wget" "unzip")
    local missing=()
    
    for package in "${packages[@]}"; do
        if ! command -v $package &> /dev/null; then
            missing+=("$package")
        fi
    done
    
    if [ ${#missing[@]} -eq 0 ]; then
        echo -e "${GREEN}✓${NC} Необходимые пакеты установлены"
    else
        echo -e "${YELLOW}⚠${NC} Отсутствуют пакеты: ${missing[*]}"
        echo "   Установите: sudo apt-get install ${missing[*]}"
        WARNINGS=$((WARNINGS + 1))
    fi
}

# Проверка TUI библиотек
check_tui() {
    if command -v dialog &> /dev/null; then
        echo -e "${GREEN}✓${NC} dialog установлен (для TUI установки)"
    elif command -v whiptail &> /dev/null; then
        echo -e "${GREEN}✓${NC} whiptail установлен (для TUI установки)"
    else
        echo -e "${YELLOW}⚠${NC} dialog/whiptail не установлены (опционально для TUI)"
        echo "   Установите: sudo apt-get install dialog"
        WARNINGS=$((WARNINGS + 1))
    fi
}

# Проверка файлов данных
check_data_files() {
    local sql_count=0
    local books_count=0
    
    if [ -d "FlibustaSQL" ]; then
        sql_count=$(find FlibustaSQL -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l || echo "0")
    fi
    
    if [ -d "Flibusta.Net" ]; then
        books_count=$(find Flibusta.Net -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l || echo "0")
    fi
    
    # Убеждаемся, что значения - это числа
    if ! [[ "$sql_count" =~ ^[0-9]+$ ]]; then
        sql_count=0
    fi
    if ! [[ "$books_count" =~ ^[0-9]+$ ]]; then
        books_count=0
    fi
    
    if [ $sql_count -gt 0 ] 2>/dev/null; then
        echo -e "${GREEN}✓${NC} Найдено SQL файлов: $sql_count"
    else
        echo -e "${YELLOW}⚠${NC} Файлы SQL не найдены в FlibustaSQL/"
        echo -e "   ${YELLOW}ℹ${NC} Не проблема - можно будет скачать автоматически в процессе установки"
        WARNINGS=$((WARNINGS + 1))
    fi
    
    if [ $books_count -gt 0 ] 2>/dev/null; then
        echo -e "${GREEN}✓${NC} Найдено архивов книг: $books_count"
    else
        echo -e "${YELLOW}⚠${NC} Архивы книг не найдены в Flibusta.Net/"
        echo -e "   ${YELLOW}ℹ${NC} Не проблема - можно будет скачать автоматически в процессе установки"
        WARNINGS=$((WARNINGS + 1))
    fi
}

# Выполнение проверок
check_os
check_docker
check_docker_compose
check_port 27100 "веб-сервер"
check_port 27101 "база данных"
check_disk_space
check_packages
check_tui
check_data_files

echo ""
# Убеждаемся, что ERRORS и WARNINGS - это числа
if ! [[ "$ERRORS" =~ ^[0-9]+$ ]]; then
    ERRORS=0
fi
if ! [[ "$WARNINGS" =~ ^[0-9]+$ ]]; then
    WARNINGS=0
fi

if [ $ERRORS -eq 0 ]; then
    if [ $WARNINGS -eq 0 ]; then
        echo -e "${GREEN}Все проверки пройдены успешно!${NC}"
        exit 0
    else
        echo -e "${YELLOW}Проверки завершены с предупреждениями${NC}"
        exit 0
    fi
else
    echo -e "${RED}Обнаружены ошибки. Исправьте их перед установкой.${NC}"
    exit 1
fi
