#!/bin/sh
#
# Скрипт для автоматического бэкапа базы данных PostgreSQL и конфигурации
# Предназначен для работы в Docker контейнере
#

# Загрузка переменных окружения
. /application/tools/dbinit.sh

# Конфигурация
BACKUP_DIR="${FLIBUSTA_BACKUP_DIR:-/application/backups}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=${FLIBUSTA_BACKUP_RETENTION:-30}

# Создание директории для бэкапов
mkdir -p "$BACKUP_DIR"
mkdir -p "$BACKUP_DIR/postgres"
mkdir -p "$BACKUP_DIR/config"

echo "=========================================="
echo "Запуск бэкапа: $TIMESTAMP"
echo "=========================================="

# Ведение логов
LOG_FILE="$BACKUP_DIR/backup_$TIMESTAMP.log"
exec > "$LOG_FILE" 2>&1

# Функция логирования
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Функция проверки результата команды
check_result() {
    if [ $? -eq 0 ]; then
        log_message "УСПЕХ: $1"
        return 0
    else
        log_message "ОШИБКА: $1"
        return 1
    fi
}

# 1. Бэкап конфигурации
log_message "Начало бэкапа конфигурации..."

# Бэкап .env файла (если он существует вне контейнера)
if [ -f "/application/.env" ]; then
    cp /application/.env "$BACKUP_DIR/config/.env_$TIMESTAMP.bak"
    check_result "Бэкап .env"
fi

# Бэкап secrets
if [ -d "/application/secrets" ]; then
    tar -czf "$BACKUP_DIR/config/secrets_$TIMESTAMP.tar.gz" -C /application secrets 2>/dev/null
    check_result "Бэкап secrets"
fi

# Бэкап docker-compose.yml
if [ -f "/docker-compose.yml" ]; then
    cp /docker-compose.yml "$BACKUP_DIR/config/docker-compose.yml_$TIMESTAMP.bak"
    check_result "Бэкап docker-compose.yml"
fi

# Бэкап nginx конфигурации
if [ -f "/application/phpdocker/nginx/nginx.conf" ]; then
    cp /application/phpdocker/nginx/nginx.conf "$BACKUP_DIR/config/nginx.conf_$TIMESTAMP.bak"
    check_result "Бэкап nginx.conf"
fi

# 2. Бэкап PostgreSQL
log_message "Начало бэкапа PostgreSQL..."

# Полный бэкап базы данных
BACKUP_FILE="$BACKUP_DIR/postgres/flibusta_full_$TIMESTAMP.sql.gz"

log_message "Создание полного дампа базы данных..."
$SQL_CMD -f /dev/stdout | gzip > "$BACKUP_FILE"
if [ $? -eq 0 ]; then
    BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    log_message "Бэкап базы данных создан: $BACKUP_FILE ($BACKUP_SIZE)"
    check_result "Полный бэкап PostgreSQL"
else
    log_message "Критическая ошибка: не удалось создать бэкап PostgreSQL"
    exit 1
fi

# Создание файла контрольной суммы
log_message "Создание контрольной суммы..."
MD5SUM=$(md5sum "$BACKUP_FILE" | awk '{print $1}')
echo "$BACKUP_FILE|$MD5SUM" > "$BACKUP_FILE.md5"
check_result "Контрольная сумма"

# 3. Проверка целостности бэкапа
log_message "Проверка целостности бэкапа..."

# Проверяем, что файл не пустой
if [ -s "$BACKUP_FILE" ]; then
    BACKUP_SIZE_BYTES=$(stat -c%s "$BACKUP_FILE")
    if [ "$BACKUP_SIZE_BYTES" -gt 1000000 ]; then # Больше 1MB
        log_message "Бэкап прошел проверку целостности ($BACKUP_SIZE_BYTES байт)"
    else
        log_message "ПРЕДУПРЕЖДЕНИЕ: Бэкап слишком маленький ($BACKUP_SIZE_BYTES байт)"
    fi
else
    log_message "Критическая ошибка: Бэкап пустой"
    exit 1
fi

# 4. Тестовое восстановление (опционально)
# Для больших баз данных это может занимать много времени
if [ "${FLIBUSTA_TEST_RESTORE:-false}" = "true" ]; then
    log_message "Тестовое восстановление бэкапа..."
    TEST_DB="flibusta_test_$TIMESTAMP"
    
    # Создаем тестовую базу
    $SQL_CMD -c "CREATE DATABASE $TEST_DB;" 2>/dev/null
    
    # Восстанавливаем в тестовую базу
    gunzip -c "$BACKUP_FILE" | $SQL_CMD -d "$TEST_DB" 2>/dev/null
    
    if [ $? -eq 0 ]; then
        log_message "Тестовое восстановление успешно"
        # Удаляем тестовую базу
        $SQL_CMD -c "DROP DATABASE $TEST_DB;" 2>/dev/null
        check_result "Тестовое восстановление"
    else
        log_message "ОШИБКА: Тестовое восстановление не удалось"
        $SQL_CMD -c "DROP DATABASE IF EXISTS $TEST_DB;" 2>/dev/null
    fi
fi

# 5. Очистка старых бэкапов
log_message "Очистка бэкапов старше $RETENTION_DAYS дней..."

# Удаляем файлы старше RETENTION_DAYS
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +$RETENTION_DAYS -delete 2>/dev/null
find "$BACKUP_DIR" -name "*.bak" -mtime +$RETENTION_DAYS -delete 2>/dev/null
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +$RETENTION_DAYS -delete 2>/dev/null
find "$BACKUP_DIR/postgres" -name "*.md5" -mtime +$RETENTION_DAYS -delete 2>/dev/null

check_result "Очистка старых бэкапов"

# 6. Создание файла статуса бэкапа
STATUS_FILE="$BACKUP_DIR/backup_status.json"
STATUS_JSON=$(cat <<EOF
{
    "timestamp": "$TIMESTAMP",
    "backup_file": "$BACKUP_FILE",
    "backup_size": "$BACKUP_SIZE",
    "md5_checksum": "$MD5SUM",
    "config_backups": {
        "env": "config/.env_$TIMESTAMP.bak",
        "secrets": "config/secrets_$TIMESTAMP.tar.gz",
        "docker_compose": "config/docker-compose.yml_$TIMESTAMP.bak",
        "nginx": "config/nginx.conf_$TIMESTAMP.bak"
    },
    "status": "completed",
    "log_file": "$LOG_FILE"
}
EOF
)

echo "$STATUS_JSON" > "$STATUS_FILE"
log_message "Файл статуса создан: $STATUS_FILE"

# 7. Итоговая статистика
log_message "=========================================="
log_message "Бэкап завершен успешно"
log_message "=========================================="
log_message "Файл бэкапа: $BACKUP_FILE"
log_message "Размер: $BACKUP_SIZE"
log_message "MD5: $MD5SUM"
log_message "Лог: $LOG_FILE"
log_message "Статус: $STATUS_FILE"
log_message "Хранение бэкапов: $RETENTION_DAYS дней"
log_message "=========================================="

# Вывод краткой статистики для Docker healthcheck
echo "{\"status\":\"success\",\"timestamp\":\"$TIMESTAMP\",\"backup_file\":\"$BACKUP_FILE\",\"backup_size\":\"$BACKUP_SIZE\"}"
