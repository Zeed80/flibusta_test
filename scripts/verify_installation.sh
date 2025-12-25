#!/bin/bash
# verify_installation.sh - Проверка установки Flibusta

# Не используем set -e, чтобы иметь контроль над обработкой ошибок

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

ERRORS=0

# Загрузка .env если существует
if [ -f ".env" ]; then
    set -a
    source .env 2>/dev/null || true
    set +a
fi

WEB_PORT="${FLIBUSTA_PORT:-27100}"
WEB_URL="http://localhost:${WEB_PORT}"

echo "=== Проверка установки ==="
echo ""

# Проверка статуса контейнеров
check_containers() {
    echo -n "Проверка контейнеров... "
    
    if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
        echo -e "${RED}✗ Docker Compose не найден${NC}"
        ERRORS=$((ERRORS + 1))
        return
    fi
    
    local compose_cmd="docker-compose"
    if ! command -v docker-compose &> /dev/null; then
        compose_cmd="docker compose"
    fi
    
    local containers=$($compose_cmd ps --services --filter "status=running" 2>/dev/null | wc -l || echo "0")
    
    # Убеждаемся, что containers - это число
    if ! [[ "$containers" =~ ^[0-9]+$ ]]; then
        containers=0
    fi
    
    if [ $containers -ge 3 ] 2>/dev/null; then
        echo -e "${GREEN}✓ Все контейнеры работают ($containers)${NC}"
    else
        echo -e "${RED}✗ Не все контейнеры работают ($containers/3)${NC}"
        ERRORS=$((ERRORS + 1))
    fi
}

# Проверка веб-интерфейса
check_web_interface() {
    echo -n "Проверка веб-интерфейса... "
    
    if command -v curl &> /dev/null; then
        local status=$(curl -s -o /dev/null -w "%{http_code}" "$WEB_URL" 2>/dev/null || echo "000")
        
        if [ "$status" = "200" ] || [ "$status" = "301" ] || [ "$status" = "302" ]; then
            echo -e "${GREEN}✓ Веб-интерфейс доступен: $WEB_URL${NC}"
        else
            echo -e "${RED}✗ Веб-интерфейс недоступен (HTTP $status)${NC}"
            ERRORS=$((ERRORS + 1))
            
            # Дополнительная диагностика для HTTP 500
            if [ "$status" = "500" ]; then
                echo -e "${YELLOW}  Диагностика HTTP 500 ошибки...${NC}"
                
                local compose_cmd="docker-compose"
                if ! command -v docker-compose &> /dev/null; then
                    compose_cmd="docker compose"
                fi
                
                # Проверка логов PHP-FPM
                if $compose_cmd exec -T php-fpm sh -c "test -f /var/log/nginx/application_php_errors.log" > /dev/null 2>&1; then
                    echo -e "${YELLOW}  Последние ошибки PHP:${NC}"
                    $compose_cmd exec -T php-fpm tail -n 10 /var/log/nginx/application_php_errors.log 2>/dev/null | sed 's/^/    /' || echo "    Не удалось прочитать логи"
                fi
                
                # Проверка подключения к БД через PHP
                echo -e "${YELLOW}  Проверка подключения к БД через PHP...${NC}"
                if $compose_cmd exec -T php-fpm php -r "require '/application/dbinit.php'; exit(isset(\$dbh) && \$dbh !== null ? 0 : 1);" > /dev/null 2>&1; then
                    echo -e "${GREEN}    ✓ Подключение к БД через PHP работает${NC}"
                else
                    echo -e "${RED}    ✗ Не удалось подключиться к БД через PHP${NC}"
                    echo -e "${YELLOW}    Проверьте:${NC}"
                    echo -e "${YELLOW}      - Пароль БД в secrets/flibusta_pwd.txt${NC}"
                    echo -e "${YELLOW}      - Переменные окружения в .env${NC}"
                    echo -e "${YELLOW}      - Логи: $compose_cmd logs php-fpm${NC}"
                fi
                
                # Проверка логов nginx
                echo -e "${YELLOW}  Проверка логов nginx...${NC}"
                if $compose_cmd exec -T webserver sh -c "test -f /var/log/nginx/error.log" > /dev/null 2>&1; then
                    echo -e "${YELLOW}  Последние ошибки nginx:${NC}"
                    $compose_cmd exec -T webserver tail -n 5 /var/log/nginx/error.log 2>/dev/null | sed 's/^/    /' || echo "    Не удалось прочитать логи"
                fi
            fi
        fi
    else
        echo -e "${YELLOW}⚠ curl не найден, пропуск проверки${NC}"
    fi
}

# Проверка OPDS каталога
check_opds() {
    echo -n "Проверка OPDS каталога... "
    
    if command -v curl &> /dev/null; then
        local status=$(curl -s -o /dev/null -w "%{http_code}" "$WEB_URL/opds/" 2>/dev/null || echo "000")
        
        if [ "$status" = "200" ]; then
            echo -e "${GREEN}✓ OPDS каталог доступен: $WEB_URL/opds/${NC}"
        else
            echo -e "${RED}✗ OPDS каталог недоступен (HTTP $status)${NC}"
            ERRORS=$((ERRORS + 1))
            
            # Дополнительная диагностика для HTTP 500
            if [ "$status" = "500" ]; then
                echo -e "${YELLOW}  OPDS каталог возвращает HTTP 500${NC}"
                echo -e "${YELLOW}  Проверьте логи PHP-FPM: docker-compose logs php-fpm${NC}"
            fi
        fi
    else
        echo -e "${YELLOW}⚠ curl не найден, пропуск проверки${NC}"
    fi
}

# Проверка подключения к БД
check_database() {
    echo -n "Проверка подключения к БД... "
    
    local compose_cmd="docker-compose"
    if ! command -v docker-compose &> /dev/null; then
        compose_cmd="docker compose"
    fi
    
    if $compose_cmd exec -T postgres pg_isready -U flibusta -d flibusta > /dev/null 2>&1; then
        echo -e "${GREEN}✓ База данных подключена${NC}"
        
        # Проверка наличия данных
        local book_count=$($compose_cmd exec -T postgres psql -U flibusta -d flibusta -t -c "SELECT COUNT(*) FROM libbook WHERE deleted='0';" 2>/dev/null | tr -d ' ' || echo "0")
        
        # Убеждаемся, что book_count - это число
        if ! [[ "$book_count" =~ ^[0-9]+$ ]]; then
            book_count=0
        fi
        
        if [ -n "$book_count" ] && [ "$book_count" -gt 0 ] 2>/dev/null; then
            echo -e "${GREEN}✓ Найдено книг в БД: $book_count${NC}"
        else
            echo -e "${YELLOW}⚠ Книги в БД не найдены (возможно, БД еще не инициализирована)${NC}"
        fi
    else
        echo -e "${RED}✗ Не удалось подключиться к базе данных${NC}"
        ERRORS=$((ERRORS + 1))
    fi
}

# Проверка поиска
check_search() {
    echo -n "Проверка поиска... "
    
    if command -v curl &> /dev/null; then
        local status=$(curl -s -o /dev/null -w "%{http_code}" "$WEB_URL/opds/search?q=test" 2>/dev/null || echo "000")
        
        if [ "$status" = "200" ]; then
            echo -e "${GREEN}✓ Поиск работает корректно${NC}"
        else
            echo -e "${YELLOW}⚠ Поиск недоступен (HTTP $status)${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ curl не найден, пропуск проверки${NC}"
    fi
}

# Выполнение проверок
check_containers
check_web_interface
check_opds
check_database
check_search

echo ""

# Убеждаемся, что ERRORS - это число
if ! [[ "$ERRORS" =~ ^[0-9]+$ ]]; then
    ERRORS=0
fi

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}Установка завершена успешно!${NC}"
    echo ""
    echo "Веб-интерфейс: $WEB_URL"
    echo "OPDS каталог: $WEB_URL/opds/"
    exit 0
else
    echo -e "${RED}Обнаружены проблемы при проверке установки${NC}"
    echo ""
    echo -e "${YELLOW}Рекомендации по устранению проблем:${NC}"
    echo ""
    echo "1. Проверьте статус контейнеров:"
    echo "   docker-compose ps"
    echo ""
    echo "2. Проверьте логи PHP-FPM:"
    echo "   docker-compose logs php-fpm | tail -n 50"
    echo ""
    echo "3. Проверьте логи nginx:"
    echo "   docker-compose logs webserver | tail -n 50"
    echo ""
    echo "4. Проверьте подключение к БД:"
    echo "   docker-compose exec postgres psql -U flibusta -d flibusta -c 'SELECT 1;'"
    echo ""
    echo "5. Проверьте пароль БД:"
    echo "   cat secrets/flibusta_pwd.txt"
    echo "   grep FLIBUSTA_DBPASSWORD .env"
    echo ""
    exit 1
fi
