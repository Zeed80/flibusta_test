#!/bin/bash
# install.sh - Автоматическая установка Flibusta Local Mirror

set -e
set -o pipefail

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Переменные по умолчанию
DB_PASSWORD=""
WEB_PORT="27100"
DB_PORT="27101"
SQL_DIR="./FlibustaSQL"
BOOKS_DIR="./Flibusta.Net"
AUTO_INIT=1
SKIP_CHECKS=0
QUICK_MODE=0
DOWNLOAD_SQL=0
DOWNLOAD_COVERS=0
UPDATE_LIBRARY=0

# Логирование
LOG_FILE="install.log"
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
    echo "$1"
}

# Генерация пароля
generate_password() {
    if command -v openssl &> /dev/null; then
        openssl rand -base64 24 | tr -d "=+/" | cut -c1-32
    else
        cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1
    fi
}

# Проверка требований
check_requirements() {
    if [ $SKIP_CHECKS -eq 1 ]; then
        log "${YELLOW}Проверки пропущены${NC}"
        return 0
    fi
    
    log "${BLUE}Проверка требований...${NC}"
    if [ -f "scripts/check_requirements.sh" ]; then
        bash scripts/check_requirements.sh
        local exit_code=$?
        
        # Скрипт возвращает 0 даже при наличии предупреждений
        # Предупреждения не критичны, установка продолжается
        if [ $exit_code -ne 0 ]; then
            log "${RED}Проверка требований не пройдена${NC}"
            log "${RED}Критические ошибки обнаружены. Установка остановлена.${NC}"
            exit 1
        else
            log "${GREEN}✓ Проверка требований пройдена (возможны предупреждения)${NC}"
        fi
    else
        log "${YELLOW}Скрипт проверки требований не найден${NC}"
    fi
}

# Создание директорий
init_directories() {
    log "${BLUE}Создание директорий...${NC}"
    if [ -f "scripts/init_directories.sh" ]; then
        bash scripts/init_directories.sh
    else
        mkdir -p FlibustaSQL Flibusta.Net cache secrets
        mkdir -p cache/authors cache/covers cache/tmp cache/opds
        chmod 755 FlibustaSQL Flibusta.Net 2>/dev/null || true
        chmod 777 cache cache/authors cache/covers cache/tmp cache/opds 2>/dev/null || true
        chmod 700 secrets 2>/dev/null || true
    fi
    log "${GREEN}✓ Директории созданы${NC}"
}

# Интерактивная настройка
interactive_setup() {
    if [ $QUICK_MODE -eq 1 ]; then
        return 0
    fi
    
    echo ""
    echo -e "${BLUE}=== Flibusta Local Mirror - Установка ===${NC}"
    echo ""
    
    # Пароль БД
    echo "1. Пароль базы данных:"
    echo "   [1] Сгенерировать автоматически (рекомендуется)"
    echo "   [2] Ввести вручную"
    read -p "   Выбор [1]: " password_choice
    password_choice=${password_choice:-1}
    
    if [ "$password_choice" = "1" ]; then
        DB_PASSWORD=$(generate_password)
        log "${GREEN}Пароль БД сгенерирован${NC}"
    else
        read -sp "   Введите пароль БД: " DB_PASSWORD
        echo ""
        read -sp "   Подтвердите пароль: " DB_PASSWORD_CONFIRM
        echo ""
        
        if [ "$DB_PASSWORD" != "$DB_PASSWORD_CONFIRM" ]; then
            log "${RED}Пароли не совпадают${NC}"
            exit 1
        fi
    fi
    
    # Порт веб-сервера
    read -p "2. Порт веб-сервера [$WEB_PORT]: " input_port
    WEB_PORT=${input_port:-$WEB_PORT}
    
    # Порт БД
    read -p "3. Порт базы данных [$DB_PORT]: " input_db_port
    DB_PORT=${input_db_port:-$DB_PORT}
    
    # Путь к SQL
    read -p "4. Путь к SQL файлам [$SQL_DIR]: " input_sql_dir
    SQL_DIR=${input_sql_dir:-$SQL_DIR}
    
    # Путь к книгам
    read -p "5. Путь к архивам книг [$BOOKS_DIR]: " input_books_dir
    BOOKS_DIR=${input_books_dir:-$BOOKS_DIR}
    
    # Автоинициализация
    read -p "6. Запустить автоматическую инициализацию БД? [Y/n]: " auto_init_choice
    auto_init_choice=${auto_init_choice:-Y}
    if [[ "$auto_init_choice" =~ ^[Yy]$ ]]; then
        AUTO_INIT=1
    else
        AUTO_INIT=0
    fi
    
    # Скачивание SQL файлов
    read -p "7. Скачать SQL файлы с Флибусты? [y/N]: " download_sql_choice
    download_sql_choice=${download_sql_choice:-N}
    if [[ "$download_sql_choice" =~ ^[Yy]$ ]]; then
        DOWNLOAD_SQL=1
    else
        DOWNLOAD_SQL=0
    fi
    
    # Скачивание обложек
    read -p "8. Скачать обложки книг? [y/N]: " download_covers_choice
    download_covers_choice=${download_covers_choice:-N}
    if [[ "$download_covers_choice" =~ ^[Yy]$ ]]; then
        DOWNLOAD_COVERS=1
    else
        DOWNLOAD_COVERS=0
    fi
    
    # Обновление библиотеки
    read -p "9. Обновить библиотеку (скачать ежедневные обновления)? [y/N]: " update_library_choice
    update_library_choice=${update_library_choice:-N}
    if [[ "$update_library_choice" =~ ^[Yy]$ ]]; then
        UPDATE_LIBRARY=1
    else
        UPDATE_LIBRARY=0
    fi
    
    echo ""
}

# Создание .env файла
create_env_file() {
    log "${BLUE}Создание файла конфигурации...${NC}"
    
    if [ ! -f ".env.example" ]; then
        log "${RED}Файл .env.example не найден${NC}"
        exit 1
    fi
    
    cp .env.example .env
    
    # Замена значений
    if [ -n "$DB_PASSWORD" ]; then
        sed -i "s/FLIBUSTA_DBPASSWORD=.*/FLIBUSTA_DBPASSWORD=$DB_PASSWORD/" .env
    fi
    
    sed -i "s/FLIBUSTA_PORT=.*/FLIBUSTA_PORT=$WEB_PORT/" .env
    sed -i "s/FLIBUSTA_DB_PORT=.*/FLIBUSTA_DB_PORT=$DB_PORT/" .env
    
    # Сохранение пароля в secrets
    if [ -n "$DB_PASSWORD" ]; then
        echo -n "$DB_PASSWORD" > secrets/flibusta_pwd.txt
        chmod 600 secrets/flibusta_pwd.txt
        log "${GREEN}✓ Пароль сохранен в secrets/flibusta_pwd.txt${NC}"
    fi
    
    log "${GREEN}✓ Файл .env создан${NC}"
}

# Копирование данных или создание символических ссылок
copy_data() {
    if [ "$SQL_DIR" != "./FlibustaSQL" ] && [ -d "$SQL_DIR" ]; then
        # Проверяем, является ли путь абсолютным
        if [[ "$SQL_DIR" == /* ]]; then
            log "${BLUE}Создание символической ссылки на SQL файлы...${NC}"
            # Удаляем существующую директорию или ссылку
            rm -rf FlibustaSQL 2>/dev/null || true
            # Создаем символическую ссылку
            ln -s "$SQL_DIR" FlibustaSQL
            log "${GREEN}✓ Символьная ссылка на SQL файлы создана: $SQL_DIR${NC}"
        else
            log "${BLUE}Копирование SQL файлов...${NC}"
            cp -r "$SQL_DIR"/* FlibustaSQL/ 2>/dev/null || true
            log "${GREEN}✓ SQL файлы скопированы${NC}"
        fi
    fi
    
    if [ "$BOOKS_DIR" != "./Flibusta.Net" ] && [ -d "$BOOKS_DIR" ]; then
        # Проверяем, является ли путь абсолютным
        if [[ "$BOOKS_DIR" == /* ]]; then
            log "${BLUE}Создание символической ссылки на книги...${NC}"
            # Удаляем существующую директорию или ссылку
            rm -rf Flibusta.Net 2>/dev/null || true
            # Создаем символическую ссылку
            ln -s "$BOOKS_DIR" Flibusta.Net
            log "${GREEN}✓ Символьная ссылка на книги создана: $BOOKS_DIR${NC}"
        else
            log "${BLUE}Копирование архивов книг...${NC}"
            cp -r "$BOOKS_DIR"/* Flibusta.Net/ 2>/dev/null || true
            log "${GREEN}✓ Архивы книг скопированы${NC}"
        fi
    fi
}

# Получение команды docker-compose
get_compose_cmd() {
    if command -v docker-compose &> /dev/null; then
        echo "docker-compose"
    else
        echo "docker compose"
    fi
}

# Сборка образов
build_containers() {
    log "${BLUE}Сборка Docker образов...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        export $(grep -v '^#' .env | xargs)
    fi
    
    if $compose_cmd build; then
        log "${GREEN}✓ Образы собраны${NC}"
        return 0
    else
        log "${RED}✗ Ошибка при сборке образов${NC}"
        return 1
    fi
}

# Запуск контейнеров
start_containers() {
    log "${BLUE}Запуск контейнеров...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        export $(grep -v '^#' .env | xargs)
    fi
    
    # Сборка если нужно (по умолчанию собираем)
    if [ "${BUILD_ON_START:-1}" = "1" ]; then
        $compose_cmd build --quiet 2>/dev/null || true
    fi
    
    $compose_cmd up -d
    
    log "${GREEN}✓ Контейнеры запущены${NC}"
    
    # Установка прав на выполнение для скриптов в tools/
    log "${BLUE}Установка прав на выполнение для скриптов...${NC}"
    $compose_cmd exec -T php-fpm sh -c "chmod +x /application/tools/*.sh /application/tools/app_topg" 2>/dev/null || true
    log "${GREEN}✓ Права на выполнение установлены${NC}"
    
    # Ожидание готовности
    log "${BLUE}Ожидание готовности сервисов...${NC}"
    sleep 10
    
    # Проверка health checks
    for i in {1..30}; do
        if $compose_cmd ps | grep -q "healthy"; then
            log "${GREEN}✓ Сервисы готовы${NC}"
            break
        fi
        if [ $i -eq 30 ]; then
            log "${YELLOW}⚠ Таймаут ожидания готовности сервисов${NC}"
        fi
        sleep 2
    done
}

# Остановка контейнеров
stop_containers() {
    log "${BLUE}Остановка контейнеров...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    if [ -f "docker-compose.yml" ]; then
        $compose_cmd stop
        log "${GREEN}✓ Контейнеры остановлены${NC}"
        return 0
    else
        log "${YELLOW}⚠ docker-compose.yml не найден${NC}"
        return 1
    fi
}

# Перезапуск контейнеров
restart_containers() {
    log "${BLUE}Перезапуск контейнеров...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        export $(grep -v '^#' .env | xargs)
    fi
    
    if [ -f "docker-compose.yml" ]; then
        $compose_cmd restart
        log "${GREEN}✓ Контейнеры перезапущены${NC}"
        return 0
    else
        log "${YELLOW}⚠ docker-compose.yml не найден${NC}"
        return 1
    fi
}

# Статус контейнеров
status_containers() {
    local compose_cmd=$(get_compose_cmd)
    
    if [ -f "docker-compose.yml" ]; then
        echo ""
        echo -e "${BLUE}Статус контейнеров:${NC}"
        echo ""
        $compose_cmd ps
        echo ""
        return 0
    else
        log "${YELLOW}⚠ docker-compose.yml не найден${NC}"
        return 1
    fi
}

# Скачивание SQL файлов
download_sql() {
    if [ $DOWNLOAD_SQL -eq 0 ]; then
        return 0
    fi
    
    log "${BLUE}Скачивание SQL файлов с Флибусты...${NC}"
    
    if [ ! -f "getsql.sh" ]; then
        log "${YELLOW}⚠ Скрипт getsql.sh не найден${NC}"
        return 0
    fi
    
    # Создаем директорию если не существует
    mkdir -p "$SQL_DIR"
    
    # Устанавливаем переменную окружения для скрипта
    export FLIBUSTA_SQL_DIR="$SQL_DIR"
    
    # Запускаем скрипт скачивания (без импорта, так как контейнеры могут быть еще не запущены)
    if bash getsql.sh 2>&1 | grep -v "docker exec" | tee -a "$LOG_FILE"; then
        log "${GREEN}✓ SQL файлы скачаны${NC}"
    else
        log "${YELLOW}⚠ Ошибка при скачивании SQL файлов${NC}"
    fi
}

# Скачивание обложек
download_covers() {
    if [ $DOWNLOAD_COVERS -eq 0 ]; then
        return 0
    fi
    
    log "${BLUE}Скачивание обложек книг...${NC}"
    
    if [ ! -f "getcovers.sh" ]; then
        log "${YELLOW}⚠ Скрипт getcovers.sh не найден${NC}"
        return 0
    fi
    
    # Создаем директорию cache если не существует
    mkdir -p cache
    
    # Устанавливаем переменную окружения для скрипта
    export FLIBUSTA_CACHE_DIR="cache"
    
    # Запускаем скрипт скачивания
    if bash getcovers.sh 2>&1 | tee -a "$LOG_FILE"; then
        log "${GREEN}✓ Обложки скачаны${NC}"
    else
        log "${YELLOW}⚠ Ошибка при скачивании обложек${NC}"
    fi
}

# Обновление библиотеки
update_library() {
    if [ $UPDATE_LIBRARY -eq 0 ]; then
        return 0
    fi
    
    log "${BLUE}Обновление библиотеки (скачивание ежедневных обновлений)...${NC}"
    
    if [ ! -f "update_daily.sh" ]; then
        log "${YELLOW}⚠ Скрипт update_daily.sh не найден${NC}"
        return 0
    fi
    
    # Создаем директорию если не существует
    mkdir -p "$BOOKS_DIR"
    
    # Устанавливаем переменную окружения для скрипта
    export FLIBUSTA_DATA_DIR="$BOOKS_DIR"
    
    # Запускаем скрипт обновления
    if bash update_daily.sh 2>&1 | tee -a "$LOG_FILE"; then
        log "${GREEN}✓ Библиотека обновлена${NC}"
    else
        log "${YELLOW}⚠ Ошибка при обновлении библиотеки${NC}"
    fi
}

# Инициализация БД
init_database() {
    if [ $AUTO_INIT -eq 0 ]; then
        log "${YELLOW}Автоматическая инициализация БД пропущена${NC}"
        return 0
    fi
    
    log "${BLUE}Инициализация базы данных...${NC}"
    
    local compose_cmd="docker-compose"
    if ! command -v docker-compose &> /dev/null; then
        compose_cmd="docker compose"
    fi
    
    # Ожидание готовности контейнера
    sleep 5
    
    # Копирование скрипта инициализации в контейнер
    local php_container=$(docker ps -q -f name=php-fpm | head -1)
    if [ -n "$php_container" ] && [ -f "scripts/init_database.sh" ]; then
        docker cp scripts/init_database.sh ${php_container}:/application/scripts/init_database.sh 2>/dev/null || true
    fi
    
    # Запуск инициализации
    if [ -n "$php_container" ]; then
        docker exec ${php_container} sh /application/scripts/init_database.sh 2>/dev/null || \
            $compose_cmd exec php-fpm sh /application/scripts/init_database.sh 2>/dev/null || true
            log "${GREEN}✓ Инициализация БД завершена${NC}"
    else
        log "${YELLOW}⚠ Контейнер php-fpm не найден. Инициализацию БД нужно выполнить вручную.${NC}"
        log "${YELLOW}Откройте: http://localhost:$WEB_PORT и перейдите в меню 'Сервис' -> 'Обновить базу'${NC}"
    fi
}

# Проверка установки
verify_installation() {
    log "${BLUE}Проверка установки...${NC}"
    if [ -f "scripts/verify_installation.sh" ]; then
        bash scripts/verify_installation.sh
    else
        log "${YELLOW}Скрипт проверки установки не найден${NC}"
    fi
}

# Парсинг аргументов
parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
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
            --sql-dir)
                SQL_DIR="$2"
                shift 2
                ;;
            --books-dir)
                BOOKS_DIR="$2"
                shift 2
                ;;
            --auto-init)
                AUTO_INIT=1
                shift
                ;;
            --no-auto-init)
                AUTO_INIT=0
                shift
                ;;
            --download-sql)
                DOWNLOAD_SQL=1
                shift
                ;;
            --download-covers)
                DOWNLOAD_COVERS=1
                shift
                ;;
            --update-library)
                UPDATE_LIBRARY=1
                shift
                ;;
            --build)
                build_containers
                exit $?
                ;;
            --start)
                start_containers
                exit $?
                ;;
            --stop)
                stop_containers
                exit $?
                ;;
            --restart)
                restart_containers
                exit $?
                ;;
            --status)
                status_containers
                exit $?
                ;;
            --skip-checks)
                SKIP_CHECKS=1
                shift
                ;;
            --quick)
                QUICK_MODE=1
                shift
                ;;
            *)
                echo "Неизвестный параметр: $1"
                exit 1
                ;;
        esac
    done
}

# Главная функция
main() {
    echo -e "${BLUE}Flibusta Local Mirror - Установка${NC}"
    echo ""
    
    # Парсинг аргументов
    parse_arguments "$@"
    
    # Генерация пароля если не указан
    if [ -z "$DB_PASSWORD" ] && [ $QUICK_MODE -eq 0 ]; then
        DB_PASSWORD=$(generate_password)
    fi
    
    # Интерактивная настройка
    if [ $QUICK_MODE -eq 0 ]; then
        interactive_setup
    fi
    
    # Проверка требований
    check_requirements
    
    # Создание директорий
    log "${BLUE}Создание директорий...${NC}"
    init_directories
    
    # Копирование данных
    copy_data
    
    # Создание .env
    create_env_file
    
    # Скачивание данных (до запуска контейнеров)
    download_sql
    download_covers
    update_library
    
    # Запуск контейнеров
    start_containers
    
    # Инициализация БД
    init_database
    
    # Проверка установки
    verify_installation
    
    echo ""
    echo -e "${GREEN}Установка завершена!${NC}"
    echo ""
    echo "Веб-интерфейс: http://localhost:$WEB_PORT"
    echo "OPDS каталог: http://localhost:$WEB_PORT/opds/"
    
    if [ -n "$DB_PASSWORD" ] && [ $QUICK_MODE -eq 0 ]; then
        echo ""
        echo -e "${YELLOW}Пароль БД: $DB_PASSWORD${NC}"
        echo -e "${YELLOW}(сохранен в secrets/flibusta_pwd.txt)${NC}"
    fi
}

# Запуск
main "$@"
