#!/bin/bash
# fix_db_password.sh - Исправление пароля базы данных PostgreSQL

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
log_info "=== Исправление пароля базы данных PostgreSQL ==="
echo ""

# Проверка наличия .env
if [ ! -f ".env" ]; then
    log_error "Файл .env не найден"
    log_error "Создайте файл .env с настройками БД"
    exit 1
fi

# Загрузка переменных из .env
set -a
source .env 2>/dev/null || true
set +a

# Получение пароля
DB_PASSWORD=""
if [ -f "secrets/flibusta_pwd.txt" ]; then
    DB_PASSWORD=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
fi

if [ -z "$DB_PASSWORD" ] && [ -n "${FLIBUSTA_DBPASSWORD:-}" ]; then
    DB_PASSWORD="${FLIBUSTA_DBPASSWORD}"
fi

if [ -z "$DB_PASSWORD" ]; then
    log_error "Пароль БД не найден"
    log_error "Проверьте файл secrets/flibusta_pwd.txt или переменную FLIBUSTA_DBPASSWORD в .env"
    exit 1
fi

log_success "Пароль найден в secrets/flibusta_pwd.txt"

# Параметры подключения
DB_USER="${FLIBUSTA_DBUSER:-flibusta}"
DB_NAME="${FLIBUSTA_DBNAME:-flibusta}"
DB_HOST="${FLIBUSTA_DBHOST:-postgres}"

log_info "Параметры подключения:"
echo "  Пользователь: $DB_USER"
echo "  База данных: $DB_NAME"
echo "  Хост: $DB_HOST"
echo ""

# Проверка доступности контейнера postgres
log_info "Проверка доступности контейнера postgres..."
if ! $COMPOSE_CMD ps postgres 2>/dev/null | grep -q "Up"; then
    log_error "Контейнер postgres не запущен"
    log_error "Запустите контейнер: $COMPOSE_CMD up -d postgres"
    exit 1
fi

log_success "Контейнер postgres запущен"

# Ожидание готовности PostgreSQL
log_info "Ожидание готовности PostgreSQL..."
for i in {1..30}; do
    if $COMPOSE_CMD exec -T postgres pg_isready -U "$DB_USER" >/dev/null 2>&1; then
        log_success "PostgreSQL готов"
        break
    fi
    if [ $i -eq 30 ]; then
        log_error "PostgreSQL не готов после 30 попыток"
        exit 1
    fi
    sleep 2
done

# Попытка подключения с новым паролем
log_info "Проверка подключения с новым паролем..."
export PGPASSWORD="$DB_PASSWORD"
if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" >/dev/null 2>&1; then
    log_success "Пароль БД уже правильный, обновление не требуется"
    exit 0
fi

log_warning "Подключение с новым паролем не удалось, пробуем обновить пароль..."

# Пробуем подключиться с разными паролями
PASSWORDS_TO_TRY=("flibusta" "${FLIBUSTA_DBPASSWORD:-flibusta}")

working_password=""
for test_password in "${PASSWORDS_TO_TRY[@]}"; do
    export PGPASSWORD="$test_password"
    if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" >/dev/null 2>&1; then
        working_password="$test_password"
        log_success "Найден рабочий пароль для подключения"
        break
    fi
done

if [ -z "$working_password" ]; then
    log_error "Не удалось подключиться к БД ни с одним из известных паролей"
    log_error "Возможные решения:"
    log_error "  1. Пересоздайте volume БД: $COMPOSE_CMD down -v && $COMPOSE_CMD up -d"
    log_error "  2. Проверьте логи: $COMPOSE_CMD logs postgres"
    exit 1
fi

# Обновление пароля
log_info "Обновление пароля пользователя $DB_USER..."
export PGPASSWORD="$working_password"

# Экранируем специальные символы в пароле для SQL
escaped_password=$(echo "$DB_PASSWORD" | sed "s/'/''/g")

if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "postgres" -c "ALTER USER $DB_USER WITH PASSWORD '$escaped_password';" >/dev/null 2>&1; then
    log_success "Пароль обновлен в базе данных"
else
    log_error "Не удалось обновить пароль"
    exit 1
fi

# Проверка нового пароля
log_info "Проверка нового пароля..."
sleep 1
export PGPASSWORD="$DB_PASSWORD"
if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" >/dev/null 2>&1; then
    log_success "Подключение с новым паролем успешно"
    log_success "Пароль БД исправлен"
    echo ""
    log_info "Рекомендуется перезапустить контейнеры для применения изменений:"
    log_info "  $COMPOSE_CMD restart"
    exit 0
else
    log_warning "Пароль обновлен, но подключение с новым паролем не работает"
    log_warning "Попробуйте перезапустить контейнеры: $COMPOSE_CMD restart"
    exit 1
fi
