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
ENABLE_HTTPS=0
DOMAIN=""
SSL_EMAIL=""

# Логирование (определяем первым, так как используется другими функциями)
LOG_FILE="install.log"
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
    echo "$1"
}

# Инициализация путей из переменных окружения или .env
init_paths_from_env() {
    # Загружаем .env если существует
    if [ -f ".env" ]; then
        # Используем source для загрузки переменных (совместимо с bash)
        set -a
        source .env 2>/dev/null || true
        set +a
    fi
    
    # Используем переменные окружения если они установлены
    if [ -n "${FLIBUSTA_BOOKS_PATH:-}" ]; then
        BOOKS_DIR="$FLIBUSTA_BOOKS_PATH"
        log "${BLUE}Путь к книгам из переменной окружения: $BOOKS_DIR${NC}"
    fi
    
    if [ -n "${FLIBUSTA_SQL_PATH:-}" ]; then
        SQL_DIR="$FLIBUSTA_SQL_PATH"
        log "${BLUE}Путь к SQL файлам из переменной окружения: $SQL_DIR${NC}"
    fi
    
    if [ -n "${FLIBUSTA_PORT:-}" ]; then
        WEB_PORT="$FLIBUSTA_PORT"
        log "${BLUE}Порт веб-сервера из переменной окружения: $WEB_PORT${NC}"
    fi
    
    if [ -n "${FLIBUSTA_DB_PORT:-}" ]; then
        DB_PORT="$FLIBUSTA_DB_PORT"
        log "${BLUE}Порт БД из переменной окружения: $DB_PORT${NC}"
    fi
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
        # Запускаем проверку требований и перехватываем код выхода
        set +e  # Временно отключаем set -e для обработки кода выхода
        bash scripts/check_requirements.sh 2>&1
        local exit_code=$?
        set -e  # Включаем обратно
        
        # Скрипт возвращает 0 даже при наличии предупреждений
        # Предупреждения не критичны, установка продолжается
        if [ $exit_code -ne 0 ]; then
            log "${RED}Проверка требований не пройдена (код выхода: $exit_code)${NC}"
            log "${RED}Критические ошибки обнаружены. Установка остановлена.${NC}"
            exit 1
        else
            log "${GREEN}✓ Проверка требований пройдена (возможны предупреждения)${NC}"
        fi
    else
        log "${YELLOW}Скрипт проверки требований не найден${NC}"
    fi
    
    # Функция завершена успешно
    return 0
}

# Создание директорий
init_directories() {
    log "${BLUE}Создание директорий...${NC}"
    local errors=0
    
    # Проверка наличия скрипта
    if [ -f "scripts/init_directories.sh" ]; then
        if ! bash scripts/init_directories.sh; then
            log "${RED}✗ Ошибка при выполнении scripts/init_directories.sh${NC}"
            errors=1
        fi
    else
        log "${YELLOW}⚠ Скрипт scripts/init_directories.sh не найден, создаем директории вручную${NC}"
        
        # Создание основных директорий с проверкой
        local dirs=("FlibustaSQL" "Flibusta.Net" "cache" "secrets")
        for dir in "${dirs[@]}"; do
            if ! mkdir -p "$dir" 2>/dev/null; then
                log "${RED}✗ Ошибка при создании директории: $dir${NC}"
                errors=1
            fi
        done
        
        # Создание поддиректорий кэша с проверкой
        local cache_dirs=("cache/authors" "cache/covers" "cache/tmp" "cache/opds")
        for dir in "${cache_dirs[@]}"; do
            if ! mkdir -p "$dir" 2>/dev/null; then
                log "${RED}✗ Ошибка при создании директории: $dir${NC}"
                errors=1
            fi
        done
        
        # Установка прав доступа с проверкой
        if [ -d "FlibustaSQL" ] && [ -d "Flibusta.Net" ]; then
            chmod 755 FlibustaSQL Flibusta.Net 2>/dev/null || log "${YELLOW}⚠ Не удалось установить права на FlibustaSQL/Flibusta.Net${NC}"
        else
            log "${YELLOW}⚠ Директории FlibustaSQL или Flibusta.Net не существуют для установки прав${NC}"
        fi
        
        if [ -d "cache" ]; then
            chmod 777 cache cache/authors cache/covers cache/tmp cache/opds 2>/dev/null || log "${YELLOW}⚠ Не удалось установить права на cache${NC}"
        else
            log "${RED}✗ Директория cache не существует${NC}"
            errors=1
        fi
        
        if [ -d "secrets" ]; then
            chmod 700 secrets 2>/dev/null || log "${YELLOW}⚠ Не удалось установить права на secrets${NC}"
        else
            log "${RED}✗ Директория secrets не существует${NC}"
            errors=1
        fi
    fi
    
    # Финальная проверка успешности создания директорий
    if [ $errors -eq 0 ]; then
        local required_dirs=("cache" "secrets")
        local missing_dirs=()
        for dir in "${required_dirs[@]}"; do
            if [ ! -d "$dir" ]; then
                missing_dirs+=("$dir")
            fi
        done
        
        if [ ${#missing_dirs[@]} -eq 0 ]; then
            log "${GREEN}✓ Директории созданы${NC}"
            return 0
        else
            log "${RED}✗ Некоторые директории не были созданы: ${missing_dirs[*]}${NC}"
            return 1
        fi
    else
        log "${RED}✗ Ошибки при создании директорий${NC}"
        return 1
    fi
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
    
    # Настройка HTTPS
    echo ""
    echo "10. Настройка HTTPS (SSL):"
    read -p "   Включить HTTPS с Let's Encrypt? [y/N]: " enable_https_choice
    enable_https_choice=${enable_https_choice:-N}
    if [[ "$enable_https_choice" =~ ^[Yy]$ ]]; then
        ENABLE_HTTPS=1
        
        read -p "   Введите доменное имя (например: books.example.com): " domain_input
        if [ -n "$domain_input" ]; then
            DOMAIN="$domain_input"
        fi
        
        read -p "   Введите email для Let's Encrypt (для уведомлений): " email_input
        if [ -n "$email_input" ]; then
            SSL_EMAIL="$email_input"
        else
            SSL_EMAIL=""
        fi
    else
        ENABLE_HTTPS=0
    fi
    
    echo ""
}

# Создание .env файла
create_env_file() {
    log "${BLUE}Создание файла конфигурации...${NC}"
    
    # Создаем .env из примера или с нуля
    if [ -f ".env.example" ]; then
        cp .env.example .env
        log "${GREEN}✓ Файл .env создан из .env.example${NC}"
    else
        log "${YELLOW}⚠ Файл .env.example не найден, создаем .env с нуля${NC}"
        # Создаем базовый .env файл
        cat > .env << EOF
# Flibusta Local Mirror - Конфигурация
FLIBUSTA_DBUSER=flibusta
FLIBUSTA_DBNAME=flibusta
FLIBUSTA_DBTYPE=postgres
FLIBUSTA_DBHOST=postgres
FLIBUSTA_DBPASSWORD=flibusta
FLIBUSTA_WEBROOT=
FLIBUSTA_PORT=27100
FLIBUSTA_DB_PORT=27101
FLIBUSTA_PHP_VERSION=8.2
FLIBUSTA_PROMETHEUS_PORT=9090
EOF
        log "${GREEN}✓ Базовый файл .env создан${NC}"
    fi
    
    # Замена значений пароля
    if [ -n "$DB_PASSWORD" ]; then
        # Экранируем специальные символы в пароле для sed
        DB_PASSWORD_ESCAPED=$(echo "$DB_PASSWORD" | sed 's/[[\.*^$()+?{|]/\\&/g')
        
        # Проверяем, есть ли уже строка FLIBUSTA_DBPASSWORD в .env
        if grep -q "^FLIBUSTA_DBPASSWORD=" .env; then
            # Заменяем существующую строку
            sed -i "s|^FLIBUSTA_DBPASSWORD=.*|FLIBUSTA_DBPASSWORD=$DB_PASSWORD_ESCAPED|" .env
            log "${GREEN}✓ Пароль обновлен в .env${NC}"
        else
            # Добавляем новую строку
            echo "FLIBUSTA_DBPASSWORD=$DB_PASSWORD" >> .env
            log "${GREEN}✓ Пароль добавлен в .env${NC}"
        fi
    fi
    
    sed -i "s/FLIBUSTA_PORT=.*/FLIBUSTA_PORT=$WEB_PORT/" .env
    
    # Добавляем HTTPS настройки в .env
    if [ $ENABLE_HTTPS -eq 1 ] && [ -n "$DOMAIN" ]; then
        if grep -q "^FLIBUSTA_DOMAIN=" .env; then
            sed -i "s|^FLIBUSTA_DOMAIN=.*|FLIBUSTA_DOMAIN=$DOMAIN|" .env
        else
            echo "FLIBUSTA_DOMAIN=$DOMAIN" >> .env
        fi
        
        if [ -n "$SSL_EMAIL" ]; then
            if grep -q "^FLIBUSTA_SSL_EMAIL=" .env; then
                sed -i "s|^FLIBUSTA_SSL_EMAIL=.*|FLIBUSTA_SSL_EMAIL=$SSL_EMAIL|" .env
            else
                echo "FLIBUSTA_SSL_EMAIL=$SSL_EMAIL" >> .env
            fi
        fi
    fi
    
    sed -i "s/FLIBUSTA_DB_PORT=.*/FLIBUSTA_DB_PORT=$DB_PORT/" .env
    
    # Сохранение путей если они были изменены
    if [ "$SQL_DIR" != "./FlibustaSQL" ]; then
        # Экранируем слэши для sed
        SQL_DIR_ESCAPED=$(echo "$SQL_DIR" | sed 's/\//\\\//g')
        if grep -q "^FLIBUSTA_SQL_PATH=" .env; then
            sed -i "s|^FLIBUSTA_SQL_PATH=.*|FLIBUSTA_SQL_PATH=$SQL_DIR_ESCAPED|" .env
        else
            echo "FLIBUSTA_SQL_PATH=$SQL_DIR" >> .env
        fi
    fi
    
    if [ "$BOOKS_DIR" != "./Flibusta.Net" ]; then
        # Экранируем слэши для sed
        BOOKS_DIR_ESCAPED=$(echo "$BOOKS_DIR" | sed 's/\//\\\//g')
        if grep -q "^FLIBUSTA_BOOKS_PATH=" .env; then
            sed -i "s|^FLIBUSTA_BOOKS_PATH=.*|FLIBUSTA_BOOKS_PATH=$BOOKS_DIR_ESCAPED|" .env
        else
            echo "FLIBUSTA_BOOKS_PATH=$BOOKS_DIR" >> .env
        fi
    fi
    
    # Сохранение пароля в secrets
    if [ -n "$DB_PASSWORD" ]; then
        # Убеждаемся, что директория secrets существует
        if [ ! -d "secrets" ]; then
            mkdir -p secrets
            chmod 700 secrets
            log "${GREEN}✓ Директория secrets создана${NC}"
        fi
        
        # Создаем файл с паролем
        if echo -n "$DB_PASSWORD" > secrets/flibusta_pwd.txt; then
            # Устанавливаем правильные права доступа (только для владельца)
            if chmod 600 secrets/flibusta_pwd.txt; then
                log "${GREEN}✓ Пароль сохранен в secrets/flibusta_pwd.txt с правами 600${NC}"
            else
                log "${RED}✗ Ошибка при установке прав на secrets/flibusta_pwd.txt${NC}"
                return 1
            fi
        else
            log "${RED}✗ Ошибка при создании secrets/flibusta_pwd.txt${NC}"
            return 1
        fi
        
        # Проверка, что пароль действительно записан в .env
        local env_password_check=$(grep "^FLIBUSTA_DBPASSWORD=" .env | cut -d'=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || echo "")
        if [ -z "$env_password_check" ] || [ "$env_password_check" != "$DB_PASSWORD" ]; then
            log "${RED}✗ Ошибка: пароль не записан в .env или не совпадает${NC}"
            log "${YELLOW}Попытка исправления...${NC}"
            # Пробуем еще раз записать пароль
            if grep -q "^FLIBUSTA_DBPASSWORD=" .env; then
                DB_PASSWORD_ESCAPED=$(echo "$DB_PASSWORD" | sed 's/[[\.*^$()+?{|]/\\&/g')
                sed -i "s|^FLIBUSTA_DBPASSWORD=.*|FLIBUSTA_DBPASSWORD=$DB_PASSWORD_ESCAPED|" .env
            else
                echo "FLIBUSTA_DBPASSWORD=$DB_PASSWORD" >> .env
            fi
            # Проверяем еще раз
            env_password_check=$(grep "^FLIBUSTA_DBPASSWORD=" .env | cut -d'=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || echo "")
            if [ "$env_password_check" = "$DB_PASSWORD" ]; then
                log "${GREEN}✓ Пароль успешно записан в .env${NC}"
            else
                log "${RED}✗ Критическая ошибка: не удалось записать пароль в .env${NC}"
                log "${RED}Проверьте файл .env вручную${NC}"
                return 1
            fi
        else
            log "${GREEN}✓ Пароль подтвержден в .env${NC}"
        fi
    else
        log "${YELLOW}⚠ Пароль БД не указан, файл secrets/flibusta_pwd.txt не создан${NC}"
        log "${YELLOW}Убедитесь, что файл создан вручную перед запуском контейнеров${NC}"
    fi
    
    log "${GREEN}✓ Файл .env создан${NC}"
}

# Валидация пути
validate_path() {
    local path=$1
    local path_type=$2
    
    # Проверка на пустой путь
    if [ -z "$path" ]; then
        log "${RED}✗ Путь к $path_type не указан${NC}"
        return 1
    fi
    
    # Нормализация пути (убираем лишние слэши)
    path=$(echo "$path" | sed 's|//*|/|g')
    
    # Проверка существования пути
    if [ ! -e "$path" ]; then
        log "${YELLOW}⚠ Путь $path_type не существует: $path${NC}"
        log "${BLUE}Создание директории...${NC}"
        if mkdir -p "$path" 2>/dev/null; then
            log "${GREEN}✓ Директория создана: $path${NC}"
        else
            log "${RED}✗ Не удалось создать директорию: $path${NC}"
            return 1
        fi
    fi
    
    # Проверка, что это директория
    if [ ! -d "$path" ]; then
        log "${RED}✗ Путь $path_type не является директорией: $path${NC}"
        return 1
    fi
    
    # Проверка прав на чтение
    if [ ! -r "$path" ]; then
        log "${RED}✗ Нет прав на чтение директории $path_type: $path${NC}"
        return 1
    fi
    
    return 0
}

# Преобразование относительного пути в абсолютный
normalize_path() {
    local path=$1
    
    # Если путь уже абсолютный, возвращаем как есть
    if [[ "$path" == /* ]]; then
        echo "$path"
        return
    fi
    
    # Если путь начинается с ./, убираем это
    if [[ "$path" == ./* ]]; then
        path="${path#./}"
    fi
    
    # Преобразуем в абсолютный путь относительно текущей директории
    local abs_path="$(cd "$(dirname "$path")" 2>/dev/null && pwd)/$(basename "$path")"
    
    # Если путь не существует, создаем его
    if [ ! -e "$abs_path" ]; then
        mkdir -p "$abs_path" 2>/dev/null || true
    fi
    
    echo "$abs_path"
}

# Копирование данных или создание символических ссылок
copy_data() {
    log "${BLUE}Обработка путей к данным...${NC}"
    log "${BLUE}SQL_DIR: $SQL_DIR${NC}"
    log "${BLUE}BOOKS_DIR: $BOOKS_DIR${NC}"
    
    # Валидация и нормализация пути к SQL файлам
    if [ "$SQL_DIR" != "./FlibustaSQL" ]; then
        if ! validate_path "$SQL_DIR" "SQL файлам"; then
            log "${YELLOW}⚠ Используется путь по умолчанию: ./FlibustaSQL${NC}"
            SQL_DIR="./FlibustaSQL"
        else
            local sql_abs=$(normalize_path "$SQL_DIR")
            log "${GREEN}✓ Путь к SQL файлам валиден: $sql_abs${NC}"
            
            # Проверяем, является ли путь абсолютным
            if [[ "$SQL_DIR" == /* ]]; then
                log "${BLUE}Создание символической ссылки на SQL файлы...${NC}"
                # Удаляем существующую директорию или ссылку
                rm -rf FlibustaSQL 2>/dev/null || true
                # Создаем символическую ссылку
                if ln -s "$SQL_DIR" FlibustaSQL 2>/dev/null; then
                    log "${GREEN}✓ Символьная ссылка на SQL файлы создана: $SQL_DIR${NC}"
                else
                    log "${YELLOW}⚠ Не удалось создать символическую ссылку, используем путь напрямую${NC}"
                    # Не возвращаем ошибку, просто используем путь напрямую
                fi
            else
                log "${BLUE}Копирование SQL файлов...${NC}"
                if cp -r "$SQL_DIR"/* FlibustaSQL/ 2>/dev/null; then
                    log "${GREEN}✓ SQL файлы скопированы${NC}"
                else
                    log "${YELLOW}⚠ Не удалось скопировать SQL файлы (возможно, директория пуста)${NC}"
                fi
            fi
        fi
    fi
    
    # Валидация и нормализация пути к книгам
    if [ "$BOOKS_DIR" != "./Flibusta.Net" ]; then
        if ! validate_path "$BOOKS_DIR" "архивам книг"; then
            log "${YELLOW}⚠ Не удалось валидировать путь к книгам: $BOOKS_DIR${NC}"
            log "${YELLOW}⚠ Используется путь по умолчанию: ./Flibusta.Net${NC}"
            BOOKS_DIR="./Flibusta.Net"
        else
            local books_abs=$(normalize_path "$BOOKS_DIR")
            log "${GREEN}✓ Путь к архивам книг валиден: $books_abs${NC}"
            
            # Проверяем, является ли путь абсолютным
            if [[ "$BOOKS_DIR" == /* ]]; then
                log "${BLUE}Создание символической ссылки на книги...${NC}"
                # Удаляем существующую директорию или ссылку
                rm -rf Flibusta.Net 2>/dev/null || true
                # Создаем символическую ссылку
                if ln -s "$BOOKS_DIR" Flibusta.Net 2>/dev/null; then
                    log "${GREEN}✓ Символьная ссылка на книги создана: $BOOKS_DIR${NC}"
                else
                    log "${YELLOW}⚠ Не удалось создать символическую ссылку, используем путь напрямую${NC}"
                    # Не возвращаем ошибку, путь будет использован напрямую через переменную окружения
                fi
            else
                log "${BLUE}Копирование архивов книг...${NC}"
                if cp -r "$BOOKS_DIR"/* Flibusta.Net/ 2>/dev/null; then
                    log "${GREEN}✓ Архивы книг скопированы${NC}"
                else
                    log "${YELLOW}⚠ Не удалось скопировать архивы книг (возможно, директория пуста)${NC}"
                fi
            fi
        fi
    fi
    
    log "${GREEN}✓ Обработка путей к данным завершена${NC}"
    return 0
}

# Получение команды docker-compose
get_compose_cmd() {
    if command -v docker-compose &> /dev/null; then
        echo "docker-compose"
    else
        echo "docker compose"
    fi
}

# Нормализация и проверка портов из .env
normalize_ports() {
    # Нормализация FLIBUSTA_PROMETHEUS_PORT
    if [ -n "$FLIBUSTA_PROMETHEUS_PORT" ]; then
        FLIBUSTA_PROMETHEUS_PORT=$(echo "$FLIBUSTA_PROMETHEUS_PORT" | tr -d '[:space:]')
        if ! [[ "$FLIBUSTA_PROMETHEUS_PORT" =~ ^[0-9]+$ ]]; then
            log "${YELLOW}⚠ FLIBUSTA_PROMETHEUS_PORT имеет недопустимое значение: '$FLIBUSTA_PROMETHEUS_PORT', используем значение по умолчанию: 9090${NC}"
            FLIBUSTA_PROMETHEUS_PORT=9090
            # Обновляем в .env файле
            if [ -f ".env" ]; then
                if grep -q "^FLIBUSTA_PROMETHEUS_PORT=" .env; then
                    sed -i "s|^FLIBUSTA_PROMETHEUS_PORT=.*|FLIBUSTA_PROMETHEUS_PORT=9090|" .env
                else
                    echo "FLIBUSTA_PROMETHEUS_PORT=9090" >> .env
                fi
            fi
        fi
    else
        FLIBUSTA_PROMETHEUS_PORT=9090
    fi
    
    # Экспортируем переменную для docker-compose
    export FLIBUSTA_PROMETHEUS_PORT
}

# Сборка образов
build_containers() {
    log "${BLUE}Сборка Docker образов...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
        
        # Нормализация и проверка портов
        normalize_ports
    fi
    
    if $compose_cmd build; then
        log "${GREEN}✓ Образы собраны успешно${NC}"
        return 0
    else
        log "${RED}✗ Ошибка при сборке образов${NC}"
        log "${RED}Проверьте логи выше для деталей${NC}"
        return 1
    fi
}

# Обновление пароля в существующей БД
update_db_password() {
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
    fi
    
    # Получаем пароль из разных источников
    local new_password=""
    if [ -n "$DB_PASSWORD" ]; then
        new_password="$DB_PASSWORD"
    elif [ -f "secrets/flibusta_pwd.txt" ]; then
        new_password=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
    elif [ -n "${FLIBUSTA_DBPASSWORD:-}" ]; then
        new_password="${FLIBUSTA_DBPASSWORD}"
    fi
    
    if [ -z "$new_password" ]; then
        log "${YELLOW}⚠ Пароль БД не указан, пропуск обновления${NC}"
        return 0
    fi
    
    log "${BLUE}Проверка и обновление пароля БД...${NC}"
    
    local db_user="${FLIBUSTA_DBUSER:-flibusta}"
    local db_name="${FLIBUSTA_DBNAME:-flibusta}"
    
    # Ожидание готовности PostgreSQL с проверкой подключения
    local postgres_ready=0
    for i in {1..60}; do
        if $compose_cmd exec -T postgres pg_isready -U "$db_user" >/dev/null 2>&1; then
            # Проверяем реальное подключение с новым паролем
            export PGPASSWORD="$new_password"
            if $compose_cmd exec -T postgres psql -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
                postgres_ready=1
                log "${GREEN}✓ PostgreSQL готов и пароль правильный${NC}"
                return 0
            fi
            # Если подключение не удалось, продолжаем попытки
        fi
        if [ $i -eq 60 ]; then
            log "${YELLOW}⚠ PostgreSQL не готов после 60 попыток, продолжаем попытки обновления пароля${NC}"
            break
        fi
        sleep 2
    done
    
    # В этом контейнере POSTGRES_USER=flibusta, значит flibusta - это суперпользователь
    # Пробуем подключиться как flibusta с разными паролями для обновления пароля
    # Пробуем несколько вариантов пароля:
    # 1. Новый пароль (из secrets или .env)
    # 2. Пароль из .env (если отличается)
    # 3. Дефолтный пароль 'flibusta' (если volume был создан с дефолтным паролем)
    local admin_passwords=("$new_password" "${FLIBUSTA_DBPASSWORD:-flibusta}" "flibusta")
    
    # Убираем пустые значения и дубликаты
    local filtered_passwords=()
    for pwd in "${admin_passwords[@]}"; do
        if [ -n "$pwd" ]; then
            # Проверяем, нет ли уже такого пароля в массиве
            local found=0
            for existing in "${filtered_passwords[@]}"; do
                if [ "$existing" = "$pwd" ]; then
                    found=1
                    break
                fi
            done
            if [ $found -eq 0 ]; then
                filtered_passwords+=("$pwd")
            fi
        fi
    done
    admin_passwords=("${filtered_passwords[@]}")
    
    local connected=0
    local working_password=""
    for admin_pass in "${admin_passwords[@]}"; do
        export PGPASSWORD="$admin_pass"
        # Пробуем подключиться как flibusta (который является суперпользователем)
        if $compose_cmd exec -T postgres psql -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
            connected=1
            working_password="$admin_pass"
            break
        fi
    done
    
    if [ $connected -eq 0 ]; then
        log "${YELLOW}⚠ Не удалось подключиться к PostgreSQL для обновления пароля${NC}"
        log "${YELLOW}Возможно, volume БД существует со старым паролем. Удалите volume:${NC}"
        log "${YELLOW}  $compose_cmd down -v${NC}"
        log "${YELLOW}  (ВНИМАНИЕ: это удалит все данные базы!)${NC}"
        return 0
    fi
    
    # Если текущий пароль уже правильный, не нужно обновлять
    if [ "$working_password" = "$new_password" ]; then
        log "${GREEN}✓ Пароль БД уже правильный${NC}"
        return 0
    fi
    
    # Обновляем пароль пользователя flibusta
    # В этом контейнере flibusta является суперпользователем (POSTGRES_USER=flibusta)
    log "${BLUE}Обновление пароля пользователя $db_user...${NC}"
    
    # Экранируем специальные символы в пароле для SQL
    local escaped_password=$(echo "$new_password" | sed "s/'/''/g")
    
    # Используем рабочий пароль для подключения и обновляем на новый
    # Подключаемся к системной базе postgres для выполнения ALTER USER
    # В этом контейнере flibusta является суперпользователем (POSTGRES_USER=flibusta)
    export PGPASSWORD="$working_password"
    # Пробуем подключиться к базе postgres или flibusta
    local alter_result=0
    if $compose_cmd exec -T postgres psql -U "$db_user" -d "postgres" -c "ALTER USER $db_user WITH PASSWORD '$escaped_password';" >/dev/null 2>&1; then
        alter_result=1
    elif $compose_cmd exec -T postgres psql -U "$db_user" -d "$db_name" -c "ALTER USER $db_user WITH PASSWORD '$escaped_password';" >/dev/null 2>&1; then
        alter_result=1
    fi
    
    if [ $alter_result -eq 1 ]; then
        log "${GREEN}✓ Пароль пользователя $db_user обновлен${NC}"
        
        # Небольшая задержка для применения изменений
        sleep 1
        
        # Проверяем, что новый пароль работает
        export PGPASSWORD="$new_password"
        local verify_attempts=0
        local verify_success=0
        while [ $verify_attempts -lt 5 ]; do
            if $compose_cmd exec -T postgres psql -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
                verify_success=1
                break
            fi
            verify_attempts=$((verify_attempts + 1))
            sleep 1
        done
        
        if [ $verify_success -eq 1 ]; then
            log "${GREEN}✓ Подключение с новым паролем успешно${NC}"
            log "${GREEN}✓ Пароль БД синхронизирован${NC}"
            return 0
        else
            log "${YELLOW}⚠ Пароль обновлен, но подключение с новым паролем не работает${NC}"
            log "${YELLOW}Попробуйте перезапустить контейнеры: $compose_cmd restart${NC}"
            return 1
        fi
    else
        log "${YELLOW}⚠ Не удалось обновить пароль пользователя $db_user${NC}"
        log "${YELLOW}Возможно, пользователь не существует или нет прав${NC}"
        log "${YELLOW}Попробуйте пересоздать volume БД: $compose_cmd down -v && $compose_cmd up -d${NC}"
        return 1
    fi
}

# Запуск контейнеров
start_containers() {
    log "${BLUE}Запуск контейнеров...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
        
        # Нормализация и проверка портов
        normalize_ports
    fi
    
    # Запуск контейнеров (образы должны быть собраны заранее)
    if ! $compose_cmd up -d; then
        log "${RED}✗ Ошибка при запуске контейнеров${NC}"
        return 1
    fi
    
    log "${GREEN}✓ Контейнеры запущены${NC}"
    
    # Ожидание готовности контейнеров перед установкой прав
    log "${BLUE}Ожидание готовности сервисов...${NC}"
    sleep 10
    
    # Проверка готовности контейнера php-fpm
    local php_ready=0
    for i in {1..30}; do
        if $compose_cmd exec -T php-fpm sh -c "test -d /application" >/dev/null 2>&1; then
            php_ready=1
            log "${GREEN}✓ Контейнер php-fpm готов${NC}"
            break
        fi
        if [ $i -eq 30 ]; then
            log "${YELLOW}⚠ Таймаут ожидания готовности php-fpm${NC}"
        fi
        sleep 2
    done
    
    # Проверка health checks для всех сервисов
    log "${BLUE}Проверка health checks сервисов...${NC}"
    local services_ready=0
    local max_attempts=60  # Увеличено до 60 попыток (2 минуты)
    
    for i in $(seq 1 $max_attempts); do
        # Проверяем статус всех сервисов через docker-compose ps
        local ps_output=$($compose_cmd ps 2>/dev/null || echo "")
        
        # Проверяем наличие healthy статуса
        if echo "$ps_output" | grep -qE "(healthy|Up)"; then
            services_ready=1
        fi
        
        # Дополнительная проверка: контейнеры запущены и работают
        local running_count=$(echo "$ps_output" | grep -c "Up" || echo "0")
        if [ $running_count -ge 2 ]; then  # Минимум postgres и php-fpm
            services_ready=1
        fi
        
        if [ $services_ready -eq 1 ]; then
            log "${GREEN}✓ Сервисы готовы${NC}"
            break
        fi
        
        if [ $i -eq $max_attempts ]; then
            log "${YELLOW}⚠ Таймаут ожидания готовности сервисов (попыток: $max_attempts)${NC}"
            log "${YELLOW}Проверьте статус вручную: $compose_cmd ps${NC}"
        else
            # Показываем прогресс каждые 10 попыток
            if [ $((i % 10)) -eq 0 ]; then
                log "${BLUE}Ожидание готовности сервисов... (попытка $i/$max_attempts)${NC}"
            fi
        fi
        sleep 2
    done
    
    # Установка прав на выполнение для скриптов в tools/ (только если контейнер готов)
    if [ $php_ready -eq 1 ]; then
        log "${BLUE}Установка прав на выполнение для скриптов...${NC}"
        # Используем скрипт fix_permissions.sh для установки прав
        if $compose_cmd exec -T php-fpm sh -c "sh /application/scripts/fix_permissions.sh >/dev/null 2>&1" 2>/dev/null; then
            log "${GREEN}✓ Права на выполнение установлены${NC}"
        else
            # Fallback на старый метод если скрипт недоступен
            if $compose_cmd exec -T php-fpm sh -c "cd /application/tools && chmod +x *.sh app_topg *.py 2>/dev/null && chmod -R 777 /application/cache /application/sql/psql 2>/dev/null" 2>/dev/null; then
                log "${GREEN}✓ Права на выполнение установлены (fallback метод)${NC}"
            else
                log "${YELLOW}⚠ Не удалось установить права на выполнение скриптов${NC}"
            fi
        fi
    else
        log "${YELLOW}⚠ Контейнер php-fpm не готов, пропуск установки прав в контейнере${NC}"
    fi
    
    # Убеждаемся, что права на хосте установлены (на случай, если они не были установлены ранее)
    if [ -d "cache" ]; then
        if chmod -R 777 cache 2>/dev/null; then
            log "${GREEN}✓ Права на cache на хосте установлены${NC}"
        else
            log "${YELLOW}⚠ Не удалось установить права на cache на хосте${NC}"
        fi
    fi
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
        set -a
        source .env 2>/dev/null || true
        set +a
        
        # Нормализация и проверка портов
        normalize_ports
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
    local download_result=0
    if bash getsql.sh 2>&1 | grep -v "docker exec" | tee -a "$LOG_FILE"; then
        download_result=$?
    else
        download_result=$?
    fi
    
    if [ $download_result -eq 0 ]; then
        # Проверяем, что файлы действительно скачались
        local sql_count=$(find "$SQL_DIR" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
        if [ $sql_count -gt 0 ]; then
            log "${GREEN}✓ SQL файлы скачаны ($sql_count файлов)${NC}"
        else
            log "${YELLOW}⚠ Скрипт выполнен, но SQL файлы не найдены${NC}"
        fi
    else
        log "${YELLOW}⚠ Ошибка при скачивании SQL файлов (код возврата: $download_result)${NC}"
        return 1
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
    local download_result=0
    if bash getcovers.sh 2>&1 | tee -a "$LOG_FILE"; then
        download_result=$?
    else
        download_result=$?
    fi
    
    if [ $download_result -eq 0 ]; then
        log "${GREEN}✓ Обложки скачаны${NC}"
    else
        log "${YELLOW}⚠ Ошибка при скачивании обложек (код возврата: $download_result)${NC}"
        return 1
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
    local update_result=0
    if bash update_daily.sh 2>&1 | tee -a "$LOG_FILE"; then
        update_result=$?
    else
        update_result=$?
    fi
    
    if [ $update_result -eq 0 ]; then
        log "${GREEN}✓ Библиотека обновлена${NC}"
    else
        log "${YELLOW}⚠ Ошибка при обновлении библиотеки (код возврата: $update_result)${NC}"
        return 1
    fi
}

# Инициализация БД
init_database() {
    if [ $AUTO_INIT -eq 0 ]; then
        log "${YELLOW}Автоматическая инициализация БД пропущена${NC}"
        return 0
    fi
    
    log "${BLUE}Инициализация базы данных...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка переменных окружения из .env
    if [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
    fi
    
    # Проверка наличия скрипта инициализации
    if [ ! -f "scripts/init_database.sh" ]; then
        log "${RED}✗ Скрипт scripts/init_database.sh не найден${NC}"
        log "${YELLOW}Инициализацию БД нужно выполнить вручную позже${NC}"
        return 1
    fi
    
    # Проверка наличия SQL файлов на хосте (если путь указан)
    local sql_path="${FLIBUSTA_SQL_PATH:-./FlibustaSQL}"
    if [ -d "$sql_path" ]; then
        local sql_count=$(find "$sql_path" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
        if [ "$sql_count" -eq 0 ]; then
            log "${YELLOW}⚠ SQL файлы не найдены в $sql_path${NC}"
            log "${YELLOW}Инициализация БД будет пропущена. Импортируйте SQL файлы вручную позже.${NC}"
            return 0
        else
            log "${GREEN}✓ Найдено SQL файлов на хосте: $sql_count${NC}"
        fi
    else
        log "${YELLOW}⚠ Директория с SQL файлами не найдена: $sql_path${NC}"
        log "${YELLOW}Инициализация БД будет пропущена. Импортируйте SQL файлы вручную позже.${NC}"
        return 0
    fi
    
    # Проверка наличия пароля БД
    local db_password=""
    if [ -f "secrets/flibusta_pwd.txt" ]; then
        db_password=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
    fi
    
    if [ -z "$db_password" ] && [ -n "${FLIBUSTA_DBPASSWORD:-}" ]; then
        db_password="${FLIBUSTA_DBPASSWORD}"
    fi
    
    if [ -z "$db_password" ]; then
        log "${RED}✗ Пароль базы данных не найден${NC}"
        log "${YELLOW}Проверьте файл secrets/flibusta_pwd.txt или переменную FLIBUSTA_DBPASSWORD в .env${NC}"
        log "${YELLOW}Инициализацию БД нужно выполнить вручную позже${NC}"
        return 1
    fi
    
    log "${GREEN}✓ Пароль БД найден${NC}"
    
    # Ожидание готовности контейнера postgres с проверкой healthcheck
    log "${BLUE}Ожидание готовности PostgreSQL...${NC}"
    local postgres_ready=0
    local max_attempts=60
    
    for i in $(seq 1 $max_attempts); do
        # Проверяем healthcheck статус контейнера
        local health_status=$($compose_cmd ps postgres 2>/dev/null | grep -o "healthy\|unhealthy" | head -1 || echo "")
        
        # Проверяем доступность через pg_isready
        if $compose_cmd exec -T postgres pg_isready -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" >/dev/null 2>&1; then
            # Дополнительная проверка: реальное подключение с паролем
            export PGPASSWORD="$db_password"
            if $compose_cmd exec -T postgres psql -U "${FLIBUSTA_DBUSER:-flibusta}" -d "${FLIBUSTA_DBNAME:-flibusta}" -c "SELECT 1;" >/dev/null 2>&1; then
                postgres_ready=1
                log "${GREEN}✓ PostgreSQL готов и доступен${NC}"
                break
            fi
        fi
        
        if [ "$i" -eq "$max_attempts" ]; then
            log "${RED}✗ PostgreSQL не готов после $max_attempts попыток${NC}"
            log "${YELLOW}Проверьте статус контейнера: $compose_cmd ps postgres${NC}"
            log "${YELLOW}Проверьте логи: $compose_cmd logs postgres${NC}"
            log "${YELLOW}Инициализацию БД нужно выполнить вручную позже${NC}"
            return 1
        fi
        
        if [ $((i % 10)) -eq 0 ]; then
            log "${BLUE}Ожидание готовности PostgreSQL... (попытка $i/$max_attempts)${NC}"
        fi
        
        sleep 2
    done
    
    # Ожидание готовности контейнера php-fpm с проверкой healthcheck
    log "${BLUE}Ожидание готовности php-fpm...${NC}"
    local php_ready=0
    local php_container=""
    local max_attempts_php=60
    
    for i in $(seq 1 $max_attempts_php); do
        # Проверяем healthcheck статус контейнера
        local php_health_status=$($compose_cmd ps php-fpm 2>/dev/null | grep -o "healthy\|unhealthy" | head -1 || echo "")
        
        php_container=$(docker ps -q -f name=php-fpm 2>/dev/null | head -1)
        if [ -n "$php_container" ] && $compose_cmd exec -T php-fpm sh -c "test -d /application && test -f /application/scripts/init_database.sh" >/dev/null 2>&1; then
            php_ready=1
            log "${GREEN}✓ php-fpm готов и скрипт инициализации доступен${NC}"
            break
        fi
        
        if [ "$i" -eq "$max_attempts_php" ]; then
            log "${RED}✗ php-fpm не готов после $max_attempts_php попыток${NC}"
            log "${YELLOW}Проверьте статус контейнера: $compose_cmd ps php-fpm${NC}"
            log "${YELLOW}Проверьте логи: $compose_cmd logs php-fpm${NC}"
            log "${YELLOW}Инициализацию БД нужно выполнить вручную позже${NC}"
            return 1
        fi
        
        if [ $((i % 10)) -eq 0 ]; then
            log "${BLUE}Ожидание готовности php-fpm... (попытка $i/$max_attempts_php)${NC}"
        fi
        
        sleep 2
    done
    
    # Скрипт уже должен быть доступен через volume, но проверяем
    if [ $php_ready -eq 1 ]; then
        if ! $compose_cmd exec -T php-fpm sh -c "test -f /application/scripts/init_database.sh" >/dev/null 2>&1; then
            log "${YELLOW}⚠ Скрипт init_database.sh не найден в контейнере через volume${NC}"
            log "${BLUE}Попытка копирования скрипта в контейнер...${NC}"
            if [ -n "$php_container" ] && [ -f "scripts/init_database.sh" ]; then
                if docker cp scripts/init_database.sh ${php_container}:/application/scripts/init_database.sh 2>/dev/null; then
                    log "${GREEN}✓ Скрипт инициализации скопирован в контейнер${NC}"
                else
                    log "${RED}✗ Не удалось скопировать скрипт в контейнер${NC}"
                    log "${YELLOW}Проверьте, что volume scripts правильно смонтирован в docker-compose.yml${NC}"
                    return 1
                fi
            else
                log "${RED}✗ Не удалось найти скрипт или контейнер для копирования${NC}"
                return 1
            fi
        fi
    fi
    
    # Запуск инициализации с явной передачей переменных окружения
    if [ $php_ready -eq 1 ] && [ $postgres_ready -eq 1 ]; then
        log "${BLUE}Запуск инициализации базы данных...${NC}"
        
        # Подготовка переменных окружения для передачи в контейнер
        # Используем отдельные флаги -e для каждой переменной
        local docker_env_args=""
        if [ -n "${FLIBUSTA_DBUSER:-}" ]; then
            docker_env_args="$docker_env_args -e FLIBUSTA_DBUSER=${FLIBUSTA_DBUSER}"
        fi
        if [ -n "${FLIBUSTA_DBNAME:-}" ]; then
            docker_env_args="$docker_env_args -e FLIBUSTA_DBNAME=${FLIBUSTA_DBNAME}"
        fi
        if [ -n "${FLIBUSTA_DBHOST:-}" ]; then
            docker_env_args="$docker_env_args -e FLIBUSTA_DBHOST=${FLIBUSTA_DBHOST}"
        fi
        if [ -n "$db_password" ]; then
            docker_env_args="$docker_env_args -e FLIBUSTA_DBPASSWORD=${db_password}"
        fi
        if [ -n "${FLIBUSTA_DBPASSWORD_FILE:-}" ]; then
            docker_env_args="$docker_env_args -e FLIBUSTA_DBPASSWORD_FILE=${FLIBUSTA_DBPASSWORD_FILE}"
        fi
        
        # Запуск скрипта с переменными окружения
        local init_exit_code=1
        local init_output=""
        
        # Пробуем выполнить через docker exec с переменными окружения
        if [ -n "$php_container" ]; then
            # Используем eval для правильной обработки переменных окружения
            init_output=$(eval "docker exec $docker_env_args ${php_container} sh /application/scripts/init_database.sh" 2>&1)
            init_exit_code=$?
        fi
        
        # Если не получилось через docker exec, пробуем через docker-compose exec
        if [ $init_exit_code -ne 0 ]; then
            # Для docker-compose exec используем другой синтаксис
            if [ -n "$docker_env_args" ]; then
                init_output=$(eval "$compose_cmd exec -T $docker_env_args php-fpm sh /application/scripts/init_database.sh" 2>&1)
            else
                init_output=$($compose_cmd exec -T php-fpm sh /application/scripts/init_database.sh 2>&1)
            fi
            init_exit_code=$?
        fi
        
        # Выводим результат
        echo "$init_output"
        
        if [ $init_exit_code -eq 0 ]; then
            log "${GREEN}✓ Инициализация БД завершена успешно${NC}"
            return 0
        else
            log "${RED}✗ Ошибка при инициализации БД (код выхода: $init_exit_code)${NC}"
            log "${YELLOW}Проверьте вывод выше для деталей${NC}"
            log "${YELLOW}Также проверьте логи в контейнере: $compose_cmd exec php-fpm cat /var/log/flibusta_init.log${NC}"
            return 1
        fi
    else
        log "${YELLOW}⚠ Контейнеры не готовы. Инициализацию БД нужно выполнить вручную.${NC}"
        log "${YELLOW}Откройте: http://localhost:$WEB_PORT и перейдите в меню 'Сервис' -> 'Обновить базу'${NC}"
        return 1
    fi
}

# Проверка наличия certbot
check_certbot() {
    if command -v certbot &> /dev/null; then
        return 0
    else
        return 1
    fi
}

# Установка certbot
install_certbot() {
    log "${BLUE}Установка certbot...${NC}"
    
    if check_certbot; then
        log "${GREEN}✓ Certbot уже установлен${NC}"
        return 0
    fi
    
    # Определяем дистрибутив
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
    else
        log "${RED}✗ Не удалось определить дистрибутив${NC}"
        return 1
    fi
    
    case $OS in
        ubuntu|debian)
            log "${BLUE}Установка certbot для Ubuntu/Debian...${NC}"
            if command -v apt-get &> /dev/null; then
                sudo apt-get update -qq
                if sudo apt-get install -y certbot python3-certbot-nginx >/dev/null 2>&1; then
                    log "${GREEN}✓ Certbot установлен${NC}"
                    return 0
                else
                    log "${RED}✗ Ошибка при установке certbot${NC}"
                    return 1
                fi
            else
                log "${RED}✗ apt-get не найден${NC}"
                return 1
            fi
            ;;
        centos|rhel|fedora)
            log "${BLUE}Установка certbot для CentOS/RHEL/Fedora...${NC}"
            if command -v yum &> /dev/null; then
                if sudo yum install -y certbot python3-certbot-nginx >/dev/null 2>&1; then
                    log "${GREEN}✓ Certbot установлен${NC}"
                    return 0
                else
                    log "${RED}✗ Ошибка при установке certbot${NC}"
                    return 1
                fi
            elif command -v dnf &> /dev/null; then
                if sudo dnf install -y certbot python3-certbot-nginx >/dev/null 2>&1; then
                    log "${GREEN}✓ Certbot установлен${NC}"
                    return 0
                else
                    log "${RED}✗ Ошибка при установке certbot${NC}"
                    return 1
                fi
            else
                log "${RED}✗ yum/dnf не найден${NC}"
                return 1
            fi
            ;;
        *)
            log "${YELLOW}⚠ Неизвестный дистрибутив: $OS${NC}"
            log "${YELLOW}Установите certbot вручную:${NC}"
            log "${YELLOW}  Ubuntu/Debian: sudo apt-get install certbot python3-certbot-nginx${NC}"
            log "${YELLOW}  CentOS/RHEL: sudo yum install certbot python3-certbot-nginx${NC}"
            return 1
            ;;
    esac
}

# Открытие портов в firewall
setup_firewall_ports() {
    log "${BLUE}Настройка firewall для портов 80 и 443...${NC}"
    
    # Проверка UFW (Ubuntu/Debian)
    if command -v ufw &> /dev/null; then
        log "${BLUE}Использование UFW...${NC}"
        if sudo ufw status | grep -q "Status: active"; then
            sudo ufw allow 80/tcp >/dev/null 2>&1
            sudo ufw allow 443/tcp >/dev/null 2>&1
            log "${GREEN}✓ Порты 80 и 443 открыты в UFW${NC}"
        else
            log "${YELLOW}⚠ UFW не активен, порты не открыты${NC}"
            log "${YELLOW}Активируйте UFW вручную: sudo ufw enable${NC}"
        fi
        return 0
    fi
    
    # Проверка firewalld (CentOS/RHEL/Fedora)
    if command -v firewall-cmd &> /dev/null; then
        log "${BLUE}Использование firewalld...${NC}"
        if sudo firewall-cmd --state 2>/dev/null | grep -q "running"; then
            sudo firewall-cmd --permanent --add-service=http >/dev/null 2>&1
            sudo firewall-cmd --permanent --add-service=https >/dev/null 2>&1
            sudo firewall-cmd --reload >/dev/null 2>&1
            log "${GREEN}✓ Порты 80 и 443 открыты в firewalld${NC}"
        else
            log "${YELLOW}⚠ firewalld не активен, порты не открыты${NC}"
        fi
        return 0
    fi
    
    # Проверка iptables
    if command -v iptables &> /dev/null; then
        log "${YELLOW}⚠ Обнаружен iptables, но автоматическая настройка не выполняется${NC}"
        log "${YELLOW}Откройте порты вручную:${NC}"
        log "${YELLOW}  sudo iptables -A INPUT -p tcp --dport 80 -j ACCEPT${NC}"
        log "${YELLOW}  sudo iptables -A INPUT -p tcp --dport 443 -j ACCEPT${NC}"
        return 0
    fi
    
    log "${YELLOW}⚠ Firewall не обнаружен, пропускаем настройку портов${NC}"
    return 0
}

# Обновление домена в nginx конфигурации
update_nginx_domain() {
    local domain=$1
    local nginx_conf="phpdocker/nginx/nginx.conf"
    
    if [ ! -f "$nginx_conf" ]; then
        log "${RED}✗ Файл конфигурации nginx не найден: $nginx_conf${NC}"
        return 1
    fi
    
    log "${BLUE}Обновление домена в nginx конфигурации...${NC}"
    
    # Заменяем server_name _; на server_name $domain;
    if sed -i.bak "s/server_name _;/server_name $domain;/" "$nginx_conf" 2>/dev/null; then
        log "${GREEN}✓ Домен обновлен в nginx конфигурации: $domain${NC}"
        return 0
    else
        log "${RED}✗ Ошибка при обновлении домена в nginx конфигурации${NC}"
        return 1
    fi
}

# Создание директории для certbot
create_certbot_dir() {
    log "${BLUE}Создание директории для certbot...${NC}"
    
    if sudo mkdir -p /var/www/certbot 2>/dev/null; then
        if sudo chmod 755 /var/www/certbot 2>/dev/null; then
            log "${GREEN}✓ Директория /var/www/certbot создана${NC}"
            return 0
        else
            log "${YELLOW}⚠ Директория создана, но не удалось установить права${NC}"
            return 0
        fi
    else
        log "${RED}✗ Ошибка при создании директории /var/www/certbot${NC}"
        return 1
    fi
}

# Проверка доступности порта 80
check_port_80() {
    log "${BLUE}Проверка доступности порта 80...${NC}"
    
    # Проверяем, занят ли порт 80 на хосте
    if command -v netstat &> /dev/null; then
        if netstat -tuln 2>/dev/null | grep -q ":80 "; then
            log "${YELLOW}⚠ Порт 80 уже используется на хосте${NC}"
            log "${YELLOW}Убедитесь, что Docker контейнер может использовать порт 80${NC}"
            return 1
        fi
    elif command -v ss &> /dev/null; then
        if ss -tuln 2>/dev/null | grep -q ":80 "; then
            log "${YELLOW}⚠ Порт 80 уже используется на хосте${NC}"
            log "${YELLOW}Убедитесь, что Docker контейнер может использовать порт 80${NC}"
            return 1
        fi
    fi
    
    log "${GREEN}✓ Порт 80 свободен${NC}"
    return 0
}

# Получение SSL сертификата
get_ssl_certificate() {
    local domain=$1
    local email=$2
    
    if [ -z "$domain" ]; then
        log "${RED}✗ Домен не указан${NC}"
        return 1
    fi
    
    log "${BLUE}Получение SSL сертификата для домена: $domain${NC}"
    
    # Проверяем доступность порта 80
    check_port_80
    
    # Создаем директорию для certbot
    create_certbot_dir
    
    # Проверяем, что nginx доступен и отвечает на запросы
    log "${BLUE}Проверка доступности nginx...${NC}"
    local compose_cmd=$(get_compose_cmd)
    if ! $compose_cmd ps webserver 2>/dev/null | grep -q "Up"; then
        log "${RED}✗ Контейнер webserver не запущен${NC}"
        log "${YELLOW}Запустите контейнеры: $compose_cmd up -d${NC}"
        return 1
    fi
    
    # Проверяем доступность ACME challenge endpoint
    sleep 2
    if ! curl -s -o /dev/null -w "%{http_code}" "http://localhost/.well-known/acme-challenge/test" 2>/dev/null | grep -q "404\|403"; then
        log "${YELLOW}⚠ ACME challenge endpoint может быть недоступен${NC}"
        log "${YELLOW}Проверьте конфигурацию nginx и доступность порта 80${NC}"
    fi
    
    # Параметры для certbot
    local certbot_args="--webroot -w /var/www/certbot -d $domain --non-interactive --agree-tos"
    
    if [ -n "$email" ]; then
        certbot_args="$certbot_args --email $email"
    else
        certbot_args="$certbot_args --register-unsafely-without-email"
    fi
    
    # Получаем сертификат
    log "${BLUE}Запрос сертификата у Let's Encrypt...${NC}"
    log "${YELLOW}Это может занять несколько секунд...${NC}"
    
    if sudo certbot certonly $certbot_args 2>&1 | tee -a "$LOG_FILE"; then
        log "${GREEN}✓ SSL сертификат получен для домена: $domain${NC}"
        return 0
    else
        log "${RED}✗ Ошибка при получении SSL сертификата${NC}"
        log "${YELLOW}Возможные причины:${NC}"
        log "${YELLOW}  1. Домен не указывает на IP этого сервера (проверьте DNS)${NC}"
        log "${YELLOW}  2. Порт 80 не доступен из интернета (проверьте firewall и проброс портов)${NC}"
        log "${YELLOW}  3. Nginx не отвечает на запросы к /.well-known/acme-challenge/${NC}"
        log "${YELLOW}  4. Другой веб-сервер использует порт 80${NC}"
        log "${YELLOW}${NC}"
        log "${YELLOW}Проверьте:${NC}"
        log "${YELLOW}  - curl http://$domain/.well-known/acme-challenge/test${NC}"
        log "${YELLOW}  - docker-compose ps webserver${NC}"
        log "${YELLOW}  - sudo netstat -tuln | grep :80${NC}"
        return 1
    fi
}

# Настройка nginx для HTTPS
configure_nginx_ssl() {
    local domain=$1
    local nginx_conf="phpdocker/nginx/nginx.conf"
    
    if [ ! -f "$nginx_conf" ]; then
        log "${RED}✗ Файл конфигурации nginx не найден: $nginx_conf${NC}"
        return 1
    fi
    
    log "${BLUE}Настройка nginx для HTTPS...${NC}"
    
    # Проверяем, есть ли уже блок SSL
    if grep -q "listen 443 ssl" "$nginx_conf"; then
        log "${YELLOW}⚠ Блок SSL уже существует в конфигурации nginx${NC}"
        return 0
    fi
    
    # Создаем резервную копию
    cp "$nginx_conf" "${nginx_conf}.ssl.bak"
    
    # Добавляем блок SSL после блока HTTP (после закрывающей скобки первого server)
    # Используем awk для вставки нового блока
    local ssl_block=$(cat <<'EOF'
    # HTTPS сервер
    server {
        listen 443 ssl http2;
        server_name DOMAIN_PLACEHOLDER;

        client_max_body_size 508M;

        # SSL сертификаты
        ssl_certificate /etc/letsencrypt/live/DOMAIN_PLACEHOLDER/fullchain.pem;
        ssl_certificate_key /etc/letsencrypt/live/DOMAIN_PLACEHOLDER/privkey.pem;

        # SSL настройки
        ssl_protocols TLSv1.2 TLSv1.3;
        ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384';
        ssl_prefer_server_ciphers off;
        ssl_session_cache shared:SSL:10m;
        ssl_session_timeout 10m;

        # Gzip сжатие
        gzip on;
        gzip_vary on;
        gzip_proxied any;
        gzip_comp_level 6;
        gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/x-javascript application/rss+xml;
        gzip_disable "msie6";

        root /application/public;
        index index.php;

        # HTTP Security Headers
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-XSS-Protection "1; mode=block" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Permissions-Policy "geolocation=(self), camera=(), microphone=()" always;
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

        # CSP (Content Security Policy)
        add_header 'Content-Security-Policy' 'default-src * data: blob:; script-src *; style-src * unsafe-inline; img-src * data: blob:; font-src *; connect-src *; worker-src * blob:; frame-src *;' always;

        # Let's Encrypt ACME Challenge
        location /.well-known/acme-challenge/ {
            root /var/www/certbot;
            allow all;
            try_files $uri =404;
            access_log off;
            log_not_found off;
        }

        # Основные location блоки
        location / {
            limit_req zone=general burst=20 nodelay;
            limit_conn conn_limit 10;
            
            try_files $uri /index.php$is_args$args;
        }

        location /opds/ {
            limit_req zone=api burst=50 nodelay;
            limit_conn conn_limit 15;
            
            try_files $uri /index.php$is_args$args;
        }

        location /service/ {
            limit_req zone=api burst=10 nodelay;
            limit_conn conn_limit 5;
            
            try_files $uri /index.php$is_args$args;
        }

        location ~ \.php$ {
            limit_req zone=general burst=20 nodelay;
            limit_conn conn_limit 10;
            
            add_header 'Content-Security-Policy' 'worker-src * blob:';

            fastcgi_pass php-fpm:9000;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PHP_VALUE "error_log=/var/log/nginx/application_php_errors.log";
            fastcgi_buffers 16 16k;
            fastcgi_buffer_size 32k;
            include fastcgi_params;
            
            fastcgi_read_timeout 300;
            fastcgi_send_timeout 300;
        }
        
        location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|otf)$ {
            expires 1y;
            access_log off;
            add_header Cache-Control "public, immutable";
            limit_rate 0;
        }

        location ~ /\.(?!well-known) {
            deny all;
            access_log off;
            log_not_found off;
        }

        location ~ /\.(?:git|env|htaccess)$ {
            deny all;
            access_log off;
            log_not_found off;
        }

        location ~* \.(?:bak|config|sql|fla|psw|ini|log|sh|inc|swp)$ {
            deny all;
            access_log off;
            log_not_found off;
        }

        error_page 429 /429.html;
        location = /429.html {
            root /var/www/html;
            internal;
            default_type text/plain;
            return 429 "Too Many Requests - Slow down!";
        }

        location /health {
            access_log off;
            return 200 "OK";
            add_header Content-Type text/plain;
            limit_rate 0;
        }

        location /nginx_status {
            stub_status on;
            access_log off;
            allow 127.0.0.1;
            allow 172.16.0.0/12;
            allow 10.0.0.0/8;
            deny all;
            limit_rate 0;
        }
    }

    # Редирект HTTP на HTTPS
    server {
        listen 80;
        server_name DOMAIN_PLACEHOLDER;
        
        location /.well-known/acme-challenge/ {
            root /var/www/certbot;
            allow all;
            try_files $uri =404;
            access_log off;
            log_not_found off;
        }
        
        location / {
            return 301 https://$host$request_uri;
        }
    }
EOF
)
    
    # Заменяем placeholder на реальный домен
    ssl_block=$(echo "$ssl_block" | sed "s/DOMAIN_PLACEHOLDER/$domain/g")
    
    # Вставляем блок SSL перед закрывающей скобкой http блока
    # Ищем последний закрывающий блок } перед закрывающей скобкой http {
    local temp_file=$(mktemp)
    
    # Используем awk для вставки SSL блока перед последней закрывающей скобкой http блока
    awk -v ssl="$ssl_block" '
    /^}$/ {
        if (in_http && !ssl_added) {
            print ssl
            ssl_added = 1
        }
        in_http = 0
    }
    /^http {/ {
        in_http = 1
    }
    {
        print
    }
    END {
        if (!ssl_added && in_http) {
            print ssl
        }
    }
    ' "$nginx_conf" > "$temp_file" && mv "$temp_file" "$nginx_conf"
    
    if [ $? -eq 0 ]; then
        log "${GREEN}✓ Конфигурация nginx обновлена для HTTPS${NC}"
        return 0
    else
        log "${RED}✗ Ошибка при обновлении конфигурации nginx${NC}"
        # Восстанавливаем из резервной копии
        mv "${nginx_conf}.ssl.bak" "$nginx_conf"
        return 1
    fi
}

# Главная функция настройки HTTPS
setup_https() {
    if [ $ENABLE_HTTPS -eq 0 ]; then
        return 0
    fi
    
    if [ -z "$DOMAIN" ]; then
        log "${YELLOW}⚠ HTTPS включен, но домен не указан, пропускаем настройку${NC}"
        return 0
    fi
    
    log "${BLUE}Настройка HTTPS для домена: $DOMAIN${NC}"
    
    # Установка certbot
    if ! install_certbot; then
        log "${YELLOW}⚠ Не удалось установить certbot, пропускаем настройку HTTPS${NC}"
        return 1
    fi
    
    # Открытие портов в firewall
    setup_firewall_ports
    
    # Обновление домена в nginx конфигурации
    update_nginx_domain "$DOMAIN"
    
    # Перезапуск контейнеров для применения изменений (включая проброс порта 80)
    local compose_cmd=$(get_compose_cmd)
    if $compose_cmd ps webserver 2>/dev/null | grep -q "Up"; then
        log "${BLUE}Перезапуск контейнеров для применения изменений (проброс порта 80)...${NC}"
        $compose_cmd down webserver >/dev/null 2>&1
        sleep 2
        $compose_cmd up -d webserver >/dev/null 2>&1
        sleep 5
        
        # Проверяем, что контейнер запустился
        if ! $compose_cmd ps webserver 2>/dev/null | grep -q "Up"; then
            log "${RED}✗ Не удалось перезапустить контейнер webserver${NC}"
            log "${YELLOW}Возможно, порт 80 уже занят. Проверьте: sudo netstat -tuln | grep :80${NC}"
            return 1
        fi
        
        log "${GREEN}✓ Контейнер webserver перезапущен${NC}"
    fi
    
    # Получение SSL сертификата
    if ! get_ssl_certificate "$DOMAIN" "$SSL_EMAIL"; then
        log "${YELLOW}⚠ Не удалось получить SSL сертификат${NC}"
        log "${YELLOW}Вы можете получить сертификат позже вручную:${NC}"
        log "${YELLOW}  sudo certbot certonly --webroot -w /var/www/certbot -d $DOMAIN${NC}"
        return 1
    fi
    
    # Настройка nginx для HTTPS
    if ! configure_nginx_ssl "$DOMAIN"; then
        log "${YELLOW}⚠ Не удалось настроить nginx для HTTPS${NC}"
        return 1
    fi
    
    log "${GREEN}✓ HTTPS настроен для домена: $DOMAIN${NC}"
    return 0
}

# Проверка установки
verify_installation() {
    log "${BLUE}Проверка установки...${NC}"
    if [ -f "scripts/verify_installation.sh" ]; then
        if bash scripts/verify_installation.sh; then
            log "${GREEN}✓ Проверка установки пройдена${NC}"
            return 0
        else
            local verify_exit=$?
            log "${YELLOW}⚠ Проверка установки завершилась с предупреждениями (код: $verify_exit)${NC}"
            return $verify_exit
        fi
    else
        log "${YELLOW}Скрипт проверки установки не найден${NC}"
        return 0
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
    
    # Загрузка путей из .env если файл существует (до парсинга аргументов)
    init_paths_from_env
    
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
    # Временно отключаем set -e для корректной обработки кода выхода
    set +e
    check_requirements
    local check_result=$?
    set -e
    
    # Проверка требований может вернуть 0 даже с предупреждениями
    # Критические ошибки обрабатываются внутри функции
    if [ $check_result -ne 0 ]; then
        log "${RED}Критические ошибки при проверке требований. Установка остановлена.${NC}"
        exit 1
    fi
    
    # Продолжаем выполнение после проверки требований
    log "${BLUE}Продолжение установки после проверки требований...${NC}"
    
    # Создание директорий
    log "${BLUE}Создание директорий...${NC}"
    if ! init_directories; then
        log "${RED}✗ Ошибка при создании директорий${NC}"
        log "${YELLOW}Проверьте права доступа и наличие свободного места${NC}"
        exit 1
    fi
    
    # Копирование данных (с валидацией путей)
    # Временно отключаем set -e для обработки ошибок в copy_data
    set +e
    copy_data
    local copy_result=$?
    set -e
    
    if [ $copy_result -ne 0 ]; then
        log "${YELLOW}⚠ Ошибки при обработке путей к данным, но продолжаем установку${NC}"
    fi
    
    # Создание .env
    log "${BLUE}Создание файла конфигурации .env...${NC}"
    if ! create_env_file; then
        log "${RED}✗ Ошибка при создании .env файла${NC}"
        exit 1
    fi
    
    # Скачивание данных (до запуска контейнеров)
    log "${BLUE}Начало скачивания данных...${NC}"
    set +e  # Временно отключаем set -e для обработки ошибок скачивания
    download_sql
    download_covers
    update_library
    set -e  # Включаем обратно
    log "${BLUE}Скачивание данных завершено${NC}"
    
    # Проверка наличия файла секретов перед сборкой и запуском
    if [ ! -f "secrets/flibusta_pwd.txt" ]; then
        log "${RED}✗ Файл secrets/flibusta_pwd.txt не найден${NC}"
        log "${RED}Файл должен быть создан функцией create_env_file()${NC}"
        exit 1
    fi
    
    # Проверка прав доступа к файлу секретов
    local secret_perms=$(stat -c "%a" secrets/flibusta_pwd.txt 2>/dev/null || echo "000")
    if [ "$secret_perms" != "600" ]; then
        log "${YELLOW}⚠ Неправильные права доступа к secrets/flibusta_pwd.txt: $secret_perms (должно быть 600)${NC}"
        log "${BLUE}Исправление прав доступа...${NC}"
        if chmod 600 secrets/flibusta_pwd.txt; then
            log "${GREEN}✓ Права доступа исправлены${NC}"
        else
            log "${RED}✗ Не удалось исправить права доступа${NC}"
            exit 1
        fi
    fi
    
    # Проверка соответствия паролей в .env и secrets/flibusta_pwd.txt
    if [ -f ".env" ] && [ -f "secrets/flibusta_pwd.txt" ]; then
        local env_password=$(grep "^FLIBUSTA_DBPASSWORD=" .env | cut -d'=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || echo "")
        local secret_password=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' || echo "")
        
        if [ -n "$env_password" ] && [ -n "$secret_password" ] && [ "$env_password" != "$secret_password" ]; then
            log "${YELLOW}⚠ Пароли в .env и secrets/flibusta_pwd.txt не совпадают${NC}"
            log "${BLUE}Обновление пароля в secrets/flibusta_pwd.txt...${NC}"
            if echo -n "$env_password" > secrets/flibusta_pwd.txt && chmod 600 secrets/flibusta_pwd.txt; then
                log "${GREEN}✓ Пароль в secrets/flibusta_pwd.txt обновлен${NC}"
            else
                log "${RED}✗ Не удалось обновить пароль в secrets/flibusta_pwd.txt${NC}"
                exit 1
            fi
        fi
    fi
    
    # Проверка существования volume PostgreSQL и предупреждение
    local compose_cmd=$(get_compose_cmd)
    # Проверяем наличие volume с db-data в имени (может быть с префиксом проекта)
    if docker volume ls 2>/dev/null | grep -q "db-data"; then
        log "${YELLOW}⚠ Обнаружен существующий volume базы данных${NC}"
        log "${YELLOW}Если пароль был изменен и возникают ошибки подключения, удалите volume:${NC}"
        log "${YELLOW}  $compose_cmd down -v${NC}"
        log "${YELLOW}  (ВНИМАНИЕ: это удалит все данные базы!)${NC}"
        log "${YELLOW}Затем запустите установку заново.${NC}"
    fi
    
    # Сборка образов
    if ! build_containers; then
        log "${RED}Ошибка при сборке образов. Установка остановлена.${NC}"
        exit 1
    fi
    
    # Запуск контейнеров
    if ! start_containers; then
        log "${RED}Ошибка при запуске контейнеров. Установка остановлена.${NC}"
        exit 1
    fi
    
    # Обновление пароля в существующей БД (если volume уже существует)
    # Важно: это должно быть выполнено после запуска контейнеров, но перед инициализацией БД
    log "${BLUE}Синхронизация пароля БД...${NC}"
    
    # Проверка синхронизации паролей перед обновлением
    local env_password=""
    local secret_password=""
    
    if [ -f ".env" ]; then
        env_password=$(grep "^FLIBUSTA_DBPASSWORD=" .env | cut -d'=' -f2- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' || echo "")
    fi
    
    if [ -f "secrets/flibusta_pwd.txt" ]; then
        secret_password=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
    fi
    
    if [ -n "$env_password" ] && [ -n "$secret_password" ] && [ "$env_password" != "$secret_password" ]; then
        log "${YELLOW}⚠ Пароли в .env и secrets/flibusta_pwd.txt не совпадают${NC}"
        log "${BLUE}Обновление пароля в secrets/flibusta_pwd.txt...${NC}"
        if echo -n "$env_password" > secrets/flibusta_pwd.txt && chmod 600 secrets/flibusta_pwd.txt; then
            log "${GREEN}✓ Пароль в secrets/flibusta_pwd.txt обновлен${NC}"
            secret_password="$env_password"
        else
            log "${RED}✗ Не удалось обновить пароль в secrets/flibusta_pwd.txt${NC}"
        fi
    fi
    
    if ! update_db_password; then
        log "${YELLOW}⚠ Не удалось синхронизировать пароль БД${NC}"
        log "${YELLOW}Попробуйте выполнить скрипт исправления вручную:${NC}"
        log "${YELLOW}  bash scripts/fix_db_password.sh${NC}"
        log "${YELLOW}Или пересоздайте volume БД:${NC}"
        log "${YELLOW}  $compose_cmd down -v${NC}"
        log "${YELLOW}  (ВНИМАНИЕ: это удалит все данные базы!)${NC}"
    fi
    
    # Предварительные проверки перед инициализацией БД
    if [ $AUTO_INIT -eq 1 ]; then
        log "${BLUE}Проверка условий для инициализации БД...${NC}"
        
        # Проверка наличия SQL файлов
        local sql_path="${FLIBUSTA_SQL_PATH:-./FlibustaSQL}"
        local sql_files_found=0
        if [ -d "$sql_path" ]; then
            local sql_count=$(find "$sql_path" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
            if [ "$sql_count" -gt 0 ]; then
                sql_files_found=1
                log "${GREEN}✓ SQL файлы найдены: $sql_count файлов в $sql_path${NC}"
            else
                log "${YELLOW}⚠ SQL файлы не найдены в $sql_path${NC}"
            fi
        else
            log "${YELLOW}⚠ Директория с SQL файлами не найдена: $sql_path${NC}"
        fi
        
        # Проверка наличия пароля БД
        local db_password_check=""
        if [ -f "secrets/flibusta_pwd.txt" ]; then
            db_password_check=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
            if [ -n "$db_password_check" ]; then
                log "${GREEN}✓ Пароль БД найден в secrets/flibusta_pwd.txt${NC}"
            else
                log "${YELLOW}⚠ Файл secrets/flibusta_pwd.txt пуст${NC}"
            fi
        else
            log "${YELLOW}⚠ Файл secrets/flibusta_pwd.txt не найден${NC}"
        fi
        
        if [ -z "$db_password_check" ] && [ -n "${FLIBUSTA_DBPASSWORD:-}" ]; then
            db_password_check="${FLIBUSTA_DBPASSWORD}"
            log "${GREEN}✓ Пароль БД найден в переменной окружения${NC}"
        fi
        
        if [ -z "$db_password_check" ]; then
            log "${RED}✗ Пароль БД не найден${NC}"
            log "${YELLOW}Инициализация БД будет пропущена${NC}"
            AUTO_INIT=0
        fi
        
        if [ $sql_files_found -eq 0 ]; then
            log "${YELLOW}⚠ SQL файлы не найдены, инициализация БД будет пропущена${NC}"
            log "${YELLOW}Импортируйте SQL файлы вручную позже${NC}"
            AUTO_INIT=0
        fi
        
        if [ $AUTO_INIT -eq 1 ]; then
            log "${GREEN}✓ Все условия для инициализации БД выполнены${NC}"
        fi
    fi
    
    # Инициализация БД
    # Не критично, продолжаем даже при ошибке
    if ! init_database; then
        log "${YELLOW}⚠ Инициализация БД не выполнена. Выполните её вручную позже.${NC}"
        log "${YELLOW}Если проблема связана с паролем БД, проверьте:${NC}"
        log "${YELLOW}  1. Пароль в .env: grep FLIBUSTA_DBPASSWORD .env${NC}"
        log "${YELLOW}  2. Пароль в secrets: cat secrets/flibusta_pwd.txt${NC}"
        log "${YELLOW}  3. Если volume БД существует со старым паролем, удалите его:${NC}"
        log "${YELLOW}     $compose_cmd down -v${NC}"
        log "${YELLOW}     (ВНИМАНИЕ: это удалит все данные базы!)${NC}"
        log "${YELLOW}Для ручной инициализации выполните:${NC}"
        log "${YELLOW}  $compose_cmd exec php-fpm sh /application/scripts/init_database.sh${NC}"
    fi
    
    # Проверка установки
    # Не критично, только информационно
    verify_installation || log "${YELLOW}⚠ Проверка установки завершилась с предупреждениями${NC}"
    
    # Настройка HTTPS (после запуска контейнеров)
    if [ $ENABLE_HTTPS -eq 1 ] && [ -n "$DOMAIN" ]; then
        log "${BLUE}Начинаем настройку HTTPS...${NC}"
        setup_https || log "${YELLOW}⚠ Настройка HTTPS не завершена, выполните вручную позже${NC}"
    fi
    
    echo ""
    echo -e "${GREEN}Установка завершена!${NC}"
    echo ""
    if [ $ENABLE_HTTPS -eq 1 ] && [ -n "$DOMAIN" ]; then
        echo "Веб-интерфейс: https://$DOMAIN"
        echo "OPDS каталог: https://$DOMAIN/opds/"
        echo ""
        echo -e "${YELLOW}HTTP автоматически перенаправляется на HTTPS${NC}"
    else
        echo "Веб-интерфейс: http://localhost:$WEB_PORT"
        echo "OPDS каталог: http://localhost:$WEB_PORT/opds/"
    fi
    
    if [ -n "$DB_PASSWORD" ] && [ $QUICK_MODE -eq 0 ]; then
        echo ""
        echo -e "${YELLOW}Пароль БД: $DB_PASSWORD${NC}"
        echo -e "${YELLOW}(сохранен в secrets/flibusta_pwd.txt)${NC}"
    fi
}

# Запуск
main "$@"
