#!/bin/sh
# test_db_connection.sh - Проверка подключения к базе данных PostgreSQL

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функция логирования
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

# Загрузка конфигурации из dbinit.sh если доступен
if [ -f "/application/tools/dbinit.sh" ]; then
    . /application/tools/dbinit.sh
fi

# Установка значений по умолчанию
DB_HOST="${FLIBUSTA_DBHOST:-postgres}"
DB_USER="${FLIBUSTA_DBUSER:-flibusta}"
DB_NAME="${FLIBUSTA_DBNAME:-flibusta}"
DB_PASSWORD="${PGPASSWORD:-${FLIBUSTA_DBPASSWORD:-flibusta}}"

# Определение команды docker-compose
COMPOSE_CMD="docker-compose"
if ! command -v docker-compose &> /dev/null; then
    COMPOSE_CMD="docker compose"
fi

echo ""
log_info "=== Проверка подключения к базе данных PostgreSQL ==="
echo ""

# Вывод параметров подключения (без пароля)
log_info "Параметры подключения:"
echo "  Хост: $DB_HOST"
echo "  Пользователь: $DB_USER"
echo "  База данных: $DB_NAME"
echo "  Пароль: ${DB_PASSWORD:+***установлен***}"
if [ -z "$DB_PASSWORD" ]; then
    echo "  Пароль: не установлен"
fi
echo ""

# Проверка 1: Доступность контейнера postgres
log_info "Проверка 1: Доступность контейнера postgres..."
if $COMPOSE_CMD ps postgres 2>/dev/null | grep -q "Up"; then
    log_success "Контейнер postgres запущен"
else
    log_error "Контейнер postgres не запущен"
    log_error "Запустите контейнер: $COMPOSE_CMD up -d postgres"
    exit 1
fi

# Проверка 2: Healthcheck статус
log_info "Проверка 2: Статус healthcheck контейнера..."
health_status=$($COMPOSE_CMD ps postgres 2>/dev/null | grep -o "healthy\|unhealthy" | head -1 || echo "unknown")
if [ "$health_status" = "healthy" ]; then
    log_success "Healthcheck: healthy"
elif [ "$health_status" = "unhealthy" ]; then
    log_error "Healthcheck: unhealthy"
    log_error "Проверьте логи: $COMPOSE_CMD logs postgres"
    exit 1
else
    log_warning "Healthcheck статус: $health_status (возможно, еще не готов)"
fi

# Проверка 3: pg_isready
log_info "Проверка 3: pg_isready..."
if $COMPOSE_CMD exec -T postgres pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; then
    log_success "pg_isready: сервер доступен"
else
    log_error "pg_isready: сервер недоступен"
    log_error "Подождите несколько секунд и попробуйте снова"
    exit 1
fi

# Проверка 4: Реальное подключение с паролем
log_info "Проверка 4: Подключение к базе данных с паролем..."
export PGPASSWORD="$DB_PASSWORD"

if $COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "SELECT 1;" >/dev/null 2>&1; then
    log_success "Подключение к базе данных успешно"
else
    log_error "Не удалось подключиться к базе данных"
    log_error "Возможные причины:"
    log_error "  1. Неправильный пароль"
    log_error "  2. Пользователь $DB_USER не существует"
    log_error "  3. База данных $DB_NAME не существует"
    log_error "  4. Проблемы с правами доступа"
    exit 1
fi

# Проверка 5: Версия PostgreSQL
log_info "Проверка 5: Версия PostgreSQL..."
pg_version=$($COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT version();" 2>/dev/null | head -1 | xargs)
if [ -n "$pg_version" ]; then
    log_success "Версия PostgreSQL: $pg_version"
else
    log_warning "Не удалось получить версию PostgreSQL"
fi

# Проверка 6: Список баз данных
log_info "Проверка 6: Список доступных баз данных..."
databases=$($COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT datname FROM pg_database WHERE datistemplate = false;" 2>/dev/null | xargs)
if [ -n "$databases" ]; then
    log_success "Доступные базы данных:"
    echo "$databases" | tr ' ' '\n' | while read -r db; do
        if [ -n "$db" ]; then
            echo "  - $db"
        fi
    done
else
    log_warning "Не удалось получить список баз данных"
fi

# Проверка 7: Список таблиц в базе данных
log_info "Проверка 7: Список таблиц в базе данных $DB_NAME..."
tables=$($COMPOSE_CMD exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -t -c "SELECT tablename FROM pg_tables WHERE schemaname = 'public';" 2>/dev/null | xargs)
table_count=$(echo "$tables" | wc -w)
if [ "$table_count" -gt 0 ]; then
    log_success "Найдено таблиц: $table_count"
    if [ "$table_count" -le 10 ]; then
        echo "$tables" | tr ' ' '\n' | while read -r table; do
            if [ -n "$table" ]; then
                echo "  - $table"
            fi
        done
    else
        log_info "Первые 10 таблиц:"
        echo "$tables" | tr ' ' '\n' | head -10 | while read -r table; do
            if [ -n "$table" ]; then
                echo "  - $table"
            fi
        done
        log_info "... и еще $((table_count - 10)) таблиц"
    fi
else
    log_warning "Таблицы не найдены (база данных пуста или не инициализирована)"
fi

echo ""
log_success "=== Все проверки пройдены успешно ==="
echo ""

exit 0
