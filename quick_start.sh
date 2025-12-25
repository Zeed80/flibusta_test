#!/bin/bash
# quick_start.sh - Быстрая установка Flibusta для опытных пользователей

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Парсинг аргументов
SQL_DIR=""
BOOKS_DIR=""
DB_PASSWORD=""
WEB_PORT="27100"
DB_PORT="27101"
AUTO_INIT=0
SKIP_CHECKS=0
QUIET=0

usage() {
    echo "Использование: $0 [опции]"
    echo ""
    echo "Опции:"
    echo "  --sql-dir DIR        Путь к папке с SQL файлами"
    echo "  --books-dir DIR      Путь к папке с архивами книг"
    echo "  --db-password PASS   Пароль базы данных"
    echo "  --port PORT          Порт веб-сервера (по умолчанию: 27100)"
    echo "  --db-port PORT       Порт базы данных (по умолчанию: 27101)"
    echo "  --auto-init          Автоматическая инициализация БД"
    echo "  --skip-checks        Пропустить проверки требований"
    echo "  --quiet              Тихий режим (минимум вывода)"
    echo "  -h, --help           Показать эту справку"
    echo ""
    echo "Пример:"
    echo "  $0 --sql-dir /path/to/sql --books-dir /path/to/books --db-password 'pass' --auto-init"
    exit 1
}

parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --sql-dir)
                SQL_DIR="$2"
                shift 2
                ;;
            --books-dir)
                BOOKS_DIR="$2"
                shift 2
                ;;
            --db-password)
                DB_PASSWORD="$2"
                shift 2
                ;;
            --port)
                WEB_PORT="$2"
                shift 2
                ;;
            --db-port)
                DB_PORT="$2"
                shift 2
                ;;
            --auto-init)
                AUTO_INIT=1
                shift
                ;;
            --skip-checks)
                SKIP_CHECKS=1
                shift
                ;;
            --quiet)
                QUIET=1
                shift
                ;;
            -h|--help)
                usage
                ;;
            *)
                echo "Неизвестный параметр: $1"
                usage
                ;;
        esac
    done
}

# Проверка обязательных параметров
check_required() {
    local errors=0
    
    if [ -z "$SQL_DIR" ]; then
        echo -e "${RED}Ошибка: --sql-dir обязателен${NC}"
        ((errors++))
    fi
    
    if [ -z "$BOOKS_DIR" ]; then
        echo -e "${RED}Ошибка: --books-dir обязателен${NC}"
        ((errors++))
    fi
    
    if [ -z "$DB_PASSWORD" ]; then
        echo -e "${RED}Ошибка: --db-password обязателен${NC}"
        ((errors++))
    fi
    
    if [ $errors -gt 0 ]; then
        echo ""
        usage
    fi
}

# Логирование
log() {
    if [ $QUIET -eq 0 ]; then
        echo "$1"
    fi
}

# Главная функция
main() {
    parse_arguments "$@"
    check_required
    
    echo -e "${GREEN}Быстрая установка Flibusta Local Mirror${NC}"
    echo ""
    
    # Построение команды install.sh
    local install_cmd="./install.sh"
    install_cmd+=" --db-password \"$DB_PASSWORD\""
    install_cmd+=" --port $WEB_PORT"
    install_cmd+=" --db-port $DB_PORT"
    install_cmd+=" --sql-dir \"$SQL_DIR\""
    install_cmd+=" --books-dir \"$BOOKS_DIR\""
    
    if [ $AUTO_INIT -eq 1 ]; then
        install_cmd+=" --auto-init"
    else
        install_cmd+=" --no-auto-init"
    fi
    
    if [ $SKIP_CHECKS -eq 1 ]; then
        install_cmd+=" --skip-checks"
    fi
    
    install_cmd+=" --quick"
    
    # Запуск установки
    local install_result=0
    if [ $QUIET -eq 1 ]; then
        if ! eval $install_cmd > /dev/null 2>&1; then
            install_result=$?
        fi
    else
        if ! eval $install_cmd; then
            install_result=$?
        fi
    fi
    
    if [ $install_result -eq 0 ]; then
        log ""
        log -e "${GREEN}Установка завершена успешно!${NC}"
        log ""
        log "Веб-интерфейс: http://localhost:$WEB_PORT"
        log "OPDS каталог: http://localhost:$WEB_PORT/opds/"
        return 0
    else
        log -e "${RED}Ошибка при установке (код возврата: $install_result)${NC}"
        exit 1
    fi
}

main "$@"
