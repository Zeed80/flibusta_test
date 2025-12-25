#!/bin/bash
# validate_config.sh - Валидация конфигурации установки

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

ERRORS=0
WARNINGS=0

# Загрузка переменных из .env если файл существует
if [ -f ".env" ]; then
    set -a
    source .env 2>/dev/null || true
    set +a
fi

# Проверка .env файла
check_env_file() {
    if [ ! -f ".env" ]; then
        echo -e "${YELLOW}⚠ Файл .env не найден (будет создан из .env.example)${NC}"
        ((WARNINGS++))
        return
    fi
    
    # Проверка обязательных полей
    local required_vars=("FLIBUSTA_DBUSER" "FLIBUSTA_DBNAME" "FLIBUSTA_DBHOST" "FLIBUSTA_DBPASSWORD")
    
    for var in "${required_vars[@]}"; do
        if ! grep -q "^${var}=" .env; then
            echo -e "${RED}✗ Отсутствует переменная $var в .env${NC}"
            ((ERRORS++))
        fi
    done
    
    # Проверка всех переменных окружения из docker-compose.yml
    local all_vars=("FLIBUSTA_DBUSER" "FLIBUSTA_DBNAME" "FLIBUSTA_DBTYPE" "FLIBUSTA_DBHOST" 
                    "FLIBUSTA_DBPASSWORD" "FLIBUSTA_WEBROOT" "FLIBUSTA_PORT" "FLIBUSTA_DB_PORT" 
                    "FLIBUSTA_PHP_VERSION" "FLIBUSTA_BOOKS_PATH" "FLIBUSTA_SQL_PATH" "FLIBUSTA_PROMETHEUS_PORT")
    
    echo -e "${BLUE}Проверка переменных окружения...${NC}"
    for var in "${all_vars[@]}"; do
        if grep -q "^${var}=" .env; then
            local value=$(grep "^${var}=" .env | cut -d'=' -f2-)
            if [ -n "$value" ]; then
                echo -e "${GREEN}✓ $var установлена${NC}"
            else
                echo -e "${YELLOW}⚠ $var пуста${NC}"
                ((WARNINGS++))
            fi
        else
            echo -e "${YELLOW}⚠ $var не установлена (будет использовано значение по умолчанию)${NC}"
            ((WARNINGS++))
        fi
    done
    
    # Проверка пароля
    local password=$(grep "^FLIBUSTA_DBPASSWORD=" .env | cut -d'=' -f2-)
    if [ -z "$password" ] || [ "$password" = "your_secure_password_here" ] || [ "$password" = "flibusta" ]; then
        echo -e "${RED}✗ Пароль БД не настроен или использует небезопасное значение по умолчанию${NC}"
        ((ERRORS++))
    fi
    
    if [ ${#password} -lt 8 ]; then
        echo -e "${YELLOW}⚠ Пароль БД слишком короткий (рекомендуется минимум 8 символов)${NC}"
        ((WARNINGS++))
    fi
    
    # Проверка портов (должны быть числами)
    local web_port=$(grep "^FLIBUSTA_PORT=" .env | cut -d'=' -f2- || echo "27100")
    local db_port=$(grep "^FLIBUSTA_DB_PORT=" .env | cut -d'=' -f2- || echo "27101")
    local prometheus_port=$(grep "^FLIBUSTA_PROMETHEUS_PORT=" .env | cut -d'=' -f2- || echo "9090")
    
    if ! [[ "$web_port" =~ ^[0-9]+$ ]]; then
        echo -e "${RED}✗ FLIBUSTA_PORT должен быть числом: $web_port${NC}"
        ((ERRORS++))
    fi
    
    if ! [[ "$db_port" =~ ^[0-9]+$ ]]; then
        echo -e "${RED}✗ FLIBUSTA_DB_PORT должен быть числом: $db_port${NC}"
        ((ERRORS++))
    fi
    
    if ! [[ "$prometheus_port" =~ ^[0-9]+$ ]]; then
        echo -e "${RED}✗ FLIBUSTA_PROMETHEUS_PORT должен быть числом: $prometheus_port${NC}"
        ((ERRORS++))
    fi
}

# Проверка SQL файлов
check_sql_files() {
    # Используем переменную из .env или значение по умолчанию
    local sql_dir="${FLIBUSTA_SQL_PATH:-./FlibustaSQL}"
    
    # Нормализация пути (преобразование относительного в абсолютный если нужно)
    if [[ "$sql_dir" != /* ]]; then
        sql_dir="$(cd "$(dirname "$0")/.." && pwd)/$sql_dir"
    fi
    
    echo -e "${BLUE}Проверка SQL файлов в: $sql_dir${NC}"
    
    if [ ! -d "$sql_dir" ]; then
        echo -e "${YELLOW}⚠ Директория SQL не найдена: $sql_dir (будет создана)${NC}"
        ((WARNINGS++))
        return
    fi
    
    if [ ! -r "$sql_dir" ]; then
        echo -e "${RED}✗ Нет прав на чтение директории SQL: $sql_dir${NC}"
        ((ERRORS++))
        return
    fi
    
    local sql_count=$(find "$sql_dir" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
    
    if [ $sql_count -eq 0 ]; then
        echo -e "${YELLOW}⚠ SQL файлы не найдены в $sql_dir${NC}"
        echo -e "${YELLOW}  (не критично - можно будет скачать автоматически)${NC}"
        ((WARNINGS++))
    else
        echo -e "${GREEN}✓ Найдено SQL файлов: $sql_count${NC}"
        
        # Проверка формата
        local invalid_files=$(find "$sql_dir" -maxdepth 1 -type f ! \( -name "*.sql" -o -name "*.sql.gz" -o -name "*.zip" -o -name ".gitkeep" \) 2>/dev/null | wc -l)
        if [ $invalid_files -gt 0 ]; then
            echo -e "${YELLOW}⚠ Найдено файлов неверного формата в SQL директории: $invalid_files${NC}"
            ((WARNINGS++))
        fi
    fi
}

# Проверка архивов книг
check_books_files() {
    # Используем переменную из .env или значение по умолчанию
    local books_dir="${FLIBUSTA_BOOKS_PATH:-./Flibusta.Net}"
    
    # Нормализация пути (преобразование относительного в абсолютный если нужно)
    if [[ "$books_dir" != /* ]]; then
        books_dir="$(cd "$(dirname "$0")/.." && pwd)/$books_dir"
    fi
    
    echo -e "${BLUE}Проверка архивов книг в: $books_dir${NC}"
    
    if [ ! -d "$books_dir" ]; then
        echo -e "${YELLOW}⚠ Директория с книгами не найдена: $books_dir (будет создана)${NC}"
        ((WARNINGS++))
        return
    fi
    
    if [ ! -r "$books_dir" ]; then
        echo -e "${RED}✗ Нет прав на чтение директории с книгами: $books_dir${NC}"
        ((ERRORS++))
        return
    fi
    
    local books_count=$(find "$books_dir" -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l)
    
    if [ $books_count -eq 0 ]; then
        echo -e "${YELLOW}⚠ Архивы книг не найдены в $books_dir${NC}"
        echo -e "${YELLOW}  (не критично - можно будет скачать автоматически)${NC}"
        ((WARNINGS++))
    else
        echo -e "${GREEN}✓ Найдено архивов книг: $books_count${NC}"
    fi
}

# Проверка директорий и прав доступа
check_directories() {
    local dirs=("cache" "secrets")
    
    for dir in "${dirs[@]}"; do
        if [ ! -d "$dir" ]; then
            echo -e "${YELLOW}⚠ Директория $dir не найдена (будет создана)${NC}"
        else
            if [ ! -w "$dir" ]; then
                echo -e "${RED}✗ Нет прав на запись в директорию: $dir${NC}"
                ((ERRORS++))
            else
                echo -e "${GREEN}✓ Директория $dir доступна для записи${NC}"
            fi
        fi
    done
}

# Проверка портов
check_ports() {
    local web_port="${FLIBUSTA_PORT:-27100}"
    local db_port="${FLIBUSTA_DB_PORT:-27101}"
    
    if command -v ss &> /dev/null; then
        if ss -tuln | grep -q ":$web_port "; then
            echo -e "${RED}✗ Порт веб-сервера $web_port занят${NC}"
            ((ERRORS++))
        fi
        
        if ss -tuln | grep -q ":$db_port "; then
            echo -e "${RED}✗ Порт базы данных $db_port занят${NC}"
            ((ERRORS++))
        fi
    fi
}

# Выполнение проверок
echo "=== Валидация конфигурации ==="
echo ""

check_env_file
check_sql_files
check_books_files
check_directories
check_ports

echo ""
echo -e "${BLUE}=== Итоги валидации ===${NC}"
echo -e "Ошибки: $ERRORS"
echo -e "Предупреждения: $WARNINGS"
echo ""

if [ $ERRORS -eq 0 ]; then
    if [ $WARNINGS -eq 0 ]; then
        echo -e "${GREEN}Валидация пройдена успешно!${NC}"
        exit 0
    else
        echo -e "${YELLOW}Валидация пройдена с предупреждениями${NC}"
        echo -e "${YELLOW}Установка может продолжиться, но рекомендуется исправить предупреждения${NC}"
        exit 0
    fi
else
    echo -e "${RED}Обнаружены ошибки конфигурации${NC}"
    echo -e "${RED}Исправьте ошибки перед продолжением установки${NC}"
    exit 1
fi
