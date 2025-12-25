#!/bin/bash
# fix_php_fpm_db_connection.sh - Исправление подключения php-fpm к БД

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

log_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

log_error() {
    echo -e "${RED}✗ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Определение команды docker-compose
COMPOSE_CMD="docker-compose"
if ! command -v docker-compose &> /dev/null; then
    COMPOSE_CMD="docker compose"
fi

echo ""
log_info "=== Исправление подключения php-fpm к базе данных ==="
echo ""

# Шаг 1: Синхронизация пароля БД
log_info "Шаг 1: Синхронизация пароля БД..."
if [ -f "scripts/sync_db_password.sh" ]; then
    if bash scripts/sync_db_password.sh; then
        log_success "Пароль БД синхронизирован"
    else
        log_error "Не удалось синхронизировать пароль БД"
        exit 1
    fi
else
    log_warning "Скрипт sync_db_password.sh не найден, используем fix_db_password.sh..."
    if [ -f "scripts/fix_db_password.sh" ]; then
        if bash scripts/fix_db_password.sh; then
            log_success "Пароль БД исправлен"
        else
            log_error "Не удалось исправить пароль БД"
            exit 1
        fi
    else
        log_error "Скрипты исправления пароля не найдены"
        exit 1
    fi
fi

# Шаг 2: Проверка секрета в контейнере php-fpm
log_info "Шаг 2: Проверка секрета в контейнере php-fpm..."
if $COMPOSE_CMD exec -T php-fpm sh -c "test -f /run/secrets/FLIBUSTA_PWD" > /dev/null 2>&1; then
    secret_password=$(docker-compose exec -T php-fpm cat /run/secrets/FLIBUSTA_PWD 2>/dev/null | tr -d '\n\r' || echo "")
    if [ -n "$secret_password" ]; then
        log_success "Секрет найден в контейнере php-fpm"
        log_info "Пароль из секрета: ${secret_password:0:3}***"
    else
        log_warning "Секрет найден, но пуст"
    fi
else
    log_error "Секрет /run/secrets/FLIBUSTA_PWD не найден в контейнере php-fpm"
    log_error "Проверьте конфигурацию docker-compose.yml"
    exit 1
fi

# Шаг 3: Проверка пароля в secrets на хосте
log_info "Шаг 3: Проверка пароля в secrets на хосте..."
if [ -f "secrets/flibusta_pwd.txt" ]; then
    host_password=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
    if [ -n "$host_password" ]; then
        log_success "Пароль найден в secrets/flibusta_pwd.txt"
        if [ "$host_password" = "$secret_password" ]; then
            log_success "Пароли в secrets и контейнере совпадают"
        else
            log_warning "Пароли в secrets и контейнере не совпадают"
            log_info "Перезапускаем контейнер php-fpm для обновления секрета..."
        fi
    else
        log_error "Файл secrets/flibusta_pwd.txt пуст"
        exit 1
    fi
else
    log_error "Файл secrets/flibusta_pwd.txt не найден"
    exit 1
fi

# Шаг 4: Пересоздание контейнера php-fpm (секреты монтируются только при создании)
log_info "Шаг 4: Пересоздание контейнера php-fpm для обновления секрета..."
log_warning "ВНИМАНИЕ: Это пересоздаст контейнер php-fpm"
if $COMPOSE_CMD up -d --force-recreate --no-deps php-fpm; then
    log_success "Контейнер php-fpm пересоздан"
else
    log_error "Не удалось пересоздать контейнер php-fpm"
    exit 1
fi

# Шаг 5: Ожидание готовности php-fpm
log_info "Шаг 5: Ожидание готовности php-fpm..."
for i in {1..30}; do
    if $COMPOSE_CMD exec -T php-fpm php -v > /dev/null 2>&1; then
        log_success "php-fpm готов"
        break
    fi
    if [ $i -eq 30 ]; then
        log_warning "php-fpm не готов после 30 попыток"
    fi
    sleep 2
done

# Шаг 6: Проверка подключения через PHP
log_info "Шаг 6: Проверка подключения к БД через PHP..."
# Проверяем, что секрет скопирован в доступное место
if $COMPOSE_CMD exec -T php-fpm sh -c "test -f /tmp/flibusta_pwd.txt" > /dev/null 2>&1; then
    log_success "Секрет скопирован в /tmp/flibusta_pwd.txt"
else
    log_warning "Секрет не найден в /tmp/flibusta_pwd.txt (возможно, entrypoint еще не выполнился)"
fi

if $COMPOSE_CMD exec -T php-fpm php -r "
\$dbname = getenv('FLIBUSTA_DBNAME') ?: 'flibusta';
\$dbhost = getenv('FLIBUSTA_DBHOST') ?: 'postgres';
\$dbuser = getenv('FLIBUSTA_DBUSER') ?: 'flibusta';
\$dbpasswd = '';
// Пробуем прочитать из скопированного файла (доступен для www-data)
\$secretFiles = ['/tmp/flibusta_pwd.txt'];
if (getenv('FLIBUSTA_DBPASSWORD_FILE')) {
    \$secretFiles[] = getenv('FLIBUSTA_DBPASSWORD_FILE');
}
foreach (\$secretFiles as \$passwordFile) {
    if (file_exists(\$passwordFile) && is_readable(\$passwordFile)) {
        \$dbpasswd = trim(file_get_contents(\$passwordFile));
        if (!empty(\$dbpasswd)) {
            break;
        }
    }
}
if (empty(\$dbpasswd)) {
    \$dbpasswd = getenv('FLIBUSTA_DBPASSWORD') ?: 'flibusta';
}
try {
    \$dbh = new PDO(\"pgsql:host=\$dbhost;dbname=\$dbname\", \$dbuser, \$dbpasswd);
    echo 'SUCCESS';
} catch(Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage();
}
" 2>&1 | grep -q "SUCCESS"; then
    log_success "Подключение к БД через PHP успешно"
    echo ""
    log_success "=== Все исправлено! ==="
    log_info "Проверьте веб-интерфейс: http://localhost:${FLIBUSTA_PORT:-27100}"
    exit 0
else
    log_error "Подключение к БД через PHP не удалось"
    log_info "Попробуйте проверить вручную:"
    log_info "  docker-compose exec php-fpm php -r \"...код проверки...\""
    exit 1
fi
