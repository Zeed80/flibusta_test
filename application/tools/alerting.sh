#!/bin/sh
#
# Скрипт для проверки системы и отправки алертов
# Предназначен для работы в cron или как health check
#

# Настройки (можно переопределить через переменные окружения)
ALERT_EMAIL="${FLIBUSTA_ALERT_EMAIL:-alerts@flibusta.local}"
DISK_USAGE_THRESHOLD="${FLIBUSTA_DISK_THRESHOLD:-80}"  # Проценты
MEMORY_USAGE_THRESHOLD="${FLIBUSTA_MEMORY_THRESHOLD:-90}"  # Проценты
LOAD_THRESHOLD="${FLIBUSTA_LOAD_THRESHOLD:-5}"  # Load average
DB_CONNECTION_THRESHOLD="${FLIBUSTA_DB_CONN_THRESHOLD:-10}"  # Количество активных соединений

# Загрузка переменных окружения
. /application/tools/dbinit.sh

# Функция логирования
log_alert() {
    local level="$1"
    local message="$2"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    echo "[$timestamp] [ALERT] [$level] $message"
    
    # Если настроен email отправлятор, можно отправить
    # mail -s "[Flibusta Alert] $level" "$ALERT_EMAIL" <<< "$message"
}

# Функция отправки критического алерта
critical_alert() {
    log_alert "CRITICAL" "$1"
}

# Функция отправки предупреждения
warning_alert() {
    log_alert "WARNING" "$1"
}

# Функция отправки информационного сообщения
info_alert() {
    log_alert "INFO" "$1"
}

# Проверка дискового пространства
check_disk_usage() {
    local disk_usage=$(df -BM /application | tail -1 | awk '{print $5}')
    local usage_percent=$(echo "$disk_usage" | cut -d'%' -f1)
    
    if [ "$usage_percent" -ge "$DISK_USAGE_THRESHOLD" ]; then
        critical_alert "Дисковое пространство: использовано ${usage_percent}% (порог: ${DISK_USAGE_THRESHOLD}%)"
        return 1
    elif [ "$usage_percent" -ge $((DISK_USAGE_THRESHOLD - 10)) ]; then
        warning_alert "Дисковое пространство: использовано ${usage_percent}%"
        return 1
    fi
    
    return 0
}

# Проверка использования памяти
check_memory_usage() {
    if [ -f "/proc/meminfo" ]; then
        local mem_total=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
        local mem_available=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
        
        if [ "$mem_total" -gt 0 ] && [ "$mem_available" -gt 0 ]; then
            local used=$((mem_total - mem_available))
            local usage_percent=$((used * 100 / mem_total))
            
            if [ "$usage_percent" -ge "$MEMORY_USAGE_THRESHOLD" ]; then
                critical_alert "Память: использовано ${usage_percent}% (порог: ${MEMORY_USAGE_THRESHOLD}%)"
                return 1
            elif [ "$usage_percent" -ge $((MEMORY_USAGE_THRESHOLD - 20)) ]; then
                warning_alert "Память: использовано ${usage_percent}%"
                return 1
            fi
        fi
    fi
    
    return 0
}

# Проверка нагрузки системы (load average)
check_system_load() {
    if [ -f "/proc/loadavg" ]; then
        local load_1min=$(awk '{print $1}' /proc/loadavg)
        local load_5min=$(awk '{print $2}' /proc/loadavg)
        
        # Сравниваем с количеством CPU ядер (предполагаем 4)
        local load_threshold=$(echo "$LOAD_THRESHOLD * 4" | bc)
        
        if [ "$(echo "$load_1min > $load_threshold" | bc)" -eq 1 ]; then
            critical_alert "Нагрузка системы: ${load_1min} (1 мин), ${load_5min} (5 мин)"
            return 1
        elif [ "$(echo "$load_1min > $(echo "$load_threshold * 0.7" | bc)" | bc)" -eq 1 ]; then
            warning_alert "Высокая нагрузка: ${load_1min} (1 мин)"
            return 1
        fi
    fi
    
    return 0
}

# Проверка PostgreSQL соединений
check_postgres_connections() {
    local active_connections=$($SQL_CMD -t -c "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = '$FLIBUSTA_DBNAME'" 2>/dev/null)
    
    if [ -n "$active_connections" ] && [ "$active_connections" -ge "$DB_CONNECTION_THRESHOLD" ]; then
        critical_alert "PostgreSQL соединений: $active_connections (порог: $DB_CONNECTION_THRESHOLD)"
        return 1
    elif [ -n "$active_connections" ] && [ "$active_connections" -ge $((DB_CONNECTION_THRESHOLD * 0.7)) ]; then
        warning_alert "Высокое количество PostgreSQL соединений: $active_connections"
        return 1
    fi
    
    return 0
}

# Проверка ZIP файлов индексации
check_zip_indexing() {
    if [ -f "/application/cache/sql_status" ]; then
        local status_file_mtime=$(stat -c %Y /application/cache/sql_status)
        local current_time=$(date +%s)
        local time_diff=$((current_time - status_file_mtime))
        
        # Проверяем, не завис ли процесс индексации (>30 минут без обновления)
        if [ "$time_diff" -gt 1800 ]; then
            # Проверяем, есть ли ключевое слово о процессе
            if grep -q "importing\|Создание индекса\|Обрабатывается" /application/cache/sql_status; then
                critical_alert "Процесс индексации завис (>30 минут без обновления статуса)"
                return 1
            fi
        fi
    fi
    
    return 0
}

# Проверка доступности ZIP архивов
check_zip_files_accessible() {
    if [ -d "/application/flibusta" ]; then
        local zip_count=$(find /application/flibusta -name "*.zip" -type f 2>/dev/null | wc -l)
        
        if [ "$zip_count" -eq 0 ]; then
            critical_alert "ZIP архивы книг не найдены в /application/flibusta"
            return 1
        fi
    fi
    
    return 0
}

# Проверка ошибок в логах
check_log_errors() {
    # Проверяем последние 1000 строк лога PHP-FPM на наличие ошибок
    if [ -f "/var/log/nginx/application_php_errors.log" ]; then
        local error_count=$(tail -n 1000 /var/log/nginx/application_php_errors.log | grep -c "Fatal error\|SQLSTATE\|PDOException" 2>/dev/null)
        
        if [ "$error_count" -gt 10 ]; then
            critical_alert "Обнаружено $error_count критических ошибок в PHP логах"
            return 1
        elif [ "$error_count" -gt 0 ]; then
            warning_alert "Обнаружены критические ошибки в PHP логах"
            return 1
        fi
    fi
    fi
    
    return 0
}

# Проверка процессов
check_processes() {
    # Проверяем, не висят ли процессы импорта/индексации слишком долго
    local import_count=$(ps aux | grep -E 'app_import_sql|app_update_zip_list' | grep -v grep | wc -l)
    
    if [ "$import_count" -gt 0 ]; then
        local import_pids=$(ps aux | grep -E 'app_import_sql|app_update_zip_list' | grep -v grep | awk '{print $2}')
        
        for pid in $import_pids; do
            local elapsed=$(ps -p "$pid" -o etimes= 2>/dev/null || echo "0")
            local elapsed_hours=$((elapsed / 3600))
            
            if [ "$elapsed_hours" -gt 4 ]; then
                warning_alert "Процесс импорта/индексации PID $pid работает уже $elapsed_hours часов"
            fi
        done
    fi
    
    return 0
}

# Главная проверка
main() {
    local alert_count=0
    
    echo "Запуск проверок здоровья системы..."
    
    # Выполняем все проверки
    check_disk_usage || alert_count=$((alert_count + 1))
    check_memory_usage || alert_count=$((alert_count + 1))
    check_system_load || alert_count=$((alert_count + 1))
    check_postgres_connections || alert_count=$((alert_count + 1))
    check_zip_indexing || alert_count=$((alert_count + 1))
    check_zip_files_accessible || alert_count=$((alert_count + 1))
    check_log_errors || alert_count=$((alert_count + 1))
    check_processes || alert_count=$((alert_count + 1))
    
    # Вывод результатов
    echo "Проверки завершены. Обнаружено проблем: $alert_count"
    
    if [ $alert_count -eq 0 ]; then
        echo "Статус: OK"
    else
        echo "Статус: $alert_count проблем(а) обнаружено"
    fi
    
    # Возвращаем код возврата для monitoring
    if [ $alert_count -gt 0 ]; then
        exit 1
    else
        exit 0
    fi
}

# Запуск
main
