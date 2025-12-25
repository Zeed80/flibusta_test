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

# Сборка образов
build_containers() {
    log "${BLUE}Сборка Docker образов...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
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
    if [ -z "$DB_PASSWORD" ]; then
        return 0
    fi
    
    log "${BLUE}Проверка и обновление пароля БД...${NC}"
    
    local compose_cmd=$(get_compose_cmd)
    
    # Загрузка .env
    if [ -f ".env" ]; then
        set -a
        source .env 2>/dev/null || true
        set +a
    fi
    
    local db_user="${FLIBUSTA_DBUSER:-flibusta}"
    local db_name="${FLIBUSTA_DBNAME:-flibusta}"
    local new_password="$DB_PASSWORD"
    
    # Ожидание готовности PostgreSQL
    local postgres_ready=0
    for i in {1..30}; do
        if $compose_cmd exec -T postgres pg_isready -U postgres >/dev/null 2>&1; then
            postgres_ready=1
            break
        fi
        sleep 2
    done
    
    if [ $postgres_ready -eq 0 ]; then
        log "${YELLOW}⚠ PostgreSQL не готов, пропуск обновления пароля${NC}"
        return 0
    fi
    
    # Пробуем подключиться с новым паролем
    export PGPASSWORD="$new_password"
    if $compose_cmd exec -T postgres psql -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
        log "${GREEN}✓ Пароль БД уже правильный${NC}"
        return 0
    fi
    
    # Если не получилось, пробуем подключиться как postgres (суперпользователь)
    # В PostgreSQL контейнере пароль postgres обычно совпадает с POSTGRES_PASSWORD
    # который при первой установке равен FLIBUSTA_DBPASSWORD
    local postgres_password="${FLIBUSTA_DBPASSWORD:-flibusta}"
    
    # Пробуем несколько вариантов пароля для postgres:
    # 1. Текущий пароль из .env (если volume новый)
    # 2. Старый пароль из secrets (если volume старый и пароль postgres совпадал с flibusta)
    # 3. Стандартные значения
    local old_secret_password=""
    if [ -f "secrets/flibusta_pwd.txt" ]; then
        old_secret_password=$(cat secrets/flibusta_pwd.txt | tr -d '\n\r' 2>/dev/null || echo "")
    fi
    
    local admin_passwords=("$postgres_password" "$old_secret_password" "flibusta" "$new_password")
    
    # Убираем пустые значения
    local filtered_passwords=()
    for pwd in "${admin_passwords[@]}"; do
        if [ -n "$pwd" ]; then
            filtered_passwords+=("$pwd")
        fi
    done
    admin_passwords=("${filtered_passwords[@]}")
    
    local connected=0
    for admin_pass in "${admin_passwords[@]}"; do
        export PGPASSWORD="$admin_pass"
        if $compose_cmd exec -T postgres psql -U postgres -d postgres -c "SELECT 1;" >/dev/null 2>&1; then
            connected=1
            break
        fi
    done
    
    if [ $connected -eq 0 ]; then
        log "${YELLOW}⚠ Не удалось подключиться к PostgreSQL как postgres для обновления пароля${NC}"
        log "${YELLOW}Пароль может быть не обновлен. Если возникают проблемы, удалите volume:${NC}"
        log "${YELLOW}  $compose_cmd down -v${NC}"
        return 0
    fi
    
    # Обновляем пароль пользователя flibusta
    log "${BLUE}Обновление пароля пользователя $db_user...${NC}"
    
    # Экранируем специальные символы в пароле для SQL
    local escaped_password=$(echo "$new_password" | sed "s/'/''/g")
    
    if $compose_cmd exec -T postgres psql -U postgres -d postgres -c "ALTER USER $db_user WITH PASSWORD '$escaped_password';" >/dev/null 2>&1; then
        log "${GREEN}✓ Пароль пользователя $db_user обновлен${NC}"
        
        # Проверяем, что новый пароль работает
        export PGPASSWORD="$new_password"
        if $compose_cmd exec -T postgres psql -U "$db_user" -d "$db_name" -c "SELECT 1;" >/dev/null 2>&1; then
            log "${GREEN}✓ Подключение с новым паролем успешно${NC}"
            return 0
        else
            log "${YELLOW}⚠ Пароль обновлен, но подключение с новым паролем не работает${NC}"
            log "${YELLOW}Возможно, требуется перезапуск контейнеров${NC}"
            return 0
        fi
    else
        log "${YELLOW}⚠ Не удалось обновить пароль пользователя $db_user${NC}"
        log "${YELLOW}Возможно, пользователь не существует или нет прав${NC}"
        return 0
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
    
    local compose_cmd="docker-compose"
    if ! command -v docker-compose &> /dev/null; then
        compose_cmd="docker compose"
    fi
    
    # Ожидание готовности контейнера postgres
    log "${BLUE}Ожидание готовности PostgreSQL...${NC}"
    local postgres_ready=0
    for i in {1..30}; do
        if $compose_cmd exec -T postgres pg_isready -U flibusta -d flibusta >/dev/null 2>&1; then
            postgres_ready=1
            log "${GREEN}✓ PostgreSQL готов${NC}"
            break
        fi
        if [ $i -eq 30 ]; then
            log "${RED}✗ PostgreSQL не готов после 30 попыток${NC}"
            log "${YELLOW}Инициализацию БД нужно выполнить вручную позже${NC}"
            return 1
        fi
        sleep 2
    done
    
    # Ожидание готовности контейнера php-fpm
    log "${BLUE}Ожидание готовности php-fpm...${NC}"
    local php_ready=0
    local php_container=""
    for i in {1..30}; do
        php_container=$(docker ps -q -f name=php-fpm | head -1)
        if [ -n "$php_container" ] && $compose_cmd exec -T php-fpm sh -c "test -d /application" >/dev/null 2>&1; then
            php_ready=1
            log "${GREEN}✓ php-fpm готов${NC}"
            break
        fi
        if [ $i -eq 30 ]; then
            log "${RED}✗ php-fpm не готов после 30 попыток${NC}"
            log "${YELLOW}Инициализацию БД нужно выполнить вручную позже${NC}"
            return 1
        fi
        sleep 2
    done
    
    # Копирование скрипта инициализации в контейнер
    if [ -n "$php_container" ] && [ -f "scripts/init_database.sh" ]; then
        if docker cp scripts/init_database.sh ${php_container}:/application/scripts/init_database.sh 2>/dev/null; then
            log "${GREEN}✓ Скрипт инициализации скопирован в контейнер${NC}"
        else
            log "${YELLOW}⚠ Не удалось скопировать скрипт в контейнер${NC}"
        fi
    fi
    
    # Запуск инициализации
    if [ $php_ready -eq 1 ] && [ $postgres_ready -eq 1 ]; then
        log "${BLUE}Запуск инициализации базы данных...${NC}"
        if docker exec ${php_container} sh /application/scripts/init_database.sh 2>&1 || \
            $compose_cmd exec -T php-fpm sh /application/scripts/init_database.sh 2>&1; then
            log "${GREEN}✓ Инициализация БД завершена${NC}"
        else
            log "${RED}✗ Ошибка при инициализации БД${NC}"
            log "${YELLOW}Проверьте логи выше для деталей${NC}"
            return 1
        fi
    else
        log "${YELLOW}⚠ Контейнеры не готовы. Инициализацию БД нужно выполнить вручную.${NC}"
        log "${YELLOW}Откройте: http://localhost:$WEB_PORT и перейдите в меню 'Сервис' -> 'Обновить базу'${NC}"
        return 1
    fi
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
    update_db_password
    
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
    fi
    
    # Проверка установки
    # Не критично, только информационно
    verify_installation || log "${YELLOW}⚠ Проверка установки завершилась с предупреждениями${NC}"
    
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
