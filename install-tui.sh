#!/bin/bash
# install-tui.sh - TUI установщик Flibusta Local Mirror

set -e

# Определение доступного TUI инструмента
detect_tui_tool() {
    if command -v dialog &> /dev/null; then
        TUI_TOOL="dialog"
        return 0
    elif command -v whiptail &> /dev/null; then
        TUI_TOOL="whiptail"
        return 0
    else
        echo "Установите dialog или whiptail:"
        echo "  sudo apt-get install dialog"
        echo "  или"
        echo "  sudo apt-get install whiptail"
        exit 1
    fi
}

# Цвета для вывода (для fallback)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Переменные
SQL_DIR="./FlibustaSQL"
BOOKS_DIR="./Flibusta.Net"
DB_PASSWORD=""
WEB_PORT="27100"
DB_PORT="27101"
AUTO_INIT=1
SHOW_PASSWORD=1
DOWNLOAD_SQL=0
DOWNLOAD_COVERS=0
UPDATE_LIBRARY=0

# Получение абсолютного пути
get_absolute_path() {
    local path=$1
    if [ -z "$path" ]; then
        echo "$(pwd)"
    elif [[ "$path" = /* ]]; then
        echo "$path"
    elif [[ "$path" = ./* ]] || [[ "$path" != /* ]]; then
        # Относительный путь от корня проекта
        local project_root="$(pwd)"
        echo "$project_root/${path#./}"
    else
        echo "$(cd "$(dirname "$path")" 2>/dev/null && pwd)/$(basename "$path")"
    fi
}

# Получение корня проекта (где находится скрипт)
get_project_root() {
    local script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    echo "$script_dir"
}

# Получение домашней директории
get_home_dir() {
    echo "$HOME"
}

# Определение TUI инструмента
detect_tui_tool

# Функции для dialog
dialog_msgbox() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --title "$1" --msgbox "$2" 10 50
    else
        whiptail --title "$1" --msgbox "$2" 10 50
    fi
}

dialog_yesno() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --title "$1" --yesno "$2" 10 50
    else
        whiptail --title "$1" --yesno "$2" 10 50
    fi
}

dialog_inputbox() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --stdout --title "$1" --inputbox "$2" 10 50 "$3"
    else
        whiptail --title "$1" --inputbox "$2" 10 50 "$3" 3>&1 1>&2 2>&3
    fi
}

dialog_passwordbox() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --stdout --title "$1" --passwordbox "$2" 10 50
    else
        whiptail --title "$1" --passwordbox "$2" 10 50 3>&1 1>&2 2>&3
    fi
}

dialog_dselect() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        # Используем dselect для выбора директории (как в Total Commander)
        local title=$1
        local default_path=$2
        local abs_path=$(get_absolute_path "$default_path")
        
        # Убеждаемся, что путь существует и это директория
        if [ ! -d "$abs_path" ]; then
            abs_path=$(get_project_root)
        fi
        
        # Улучшенный интерфейс с подсказками и увеличенным размером
        # Высота 25, ширина 70 для лучшей видимости
        # dselect позволяет навигацию: стрелки для перемещения, Enter для входа/выбора, Tab для переключения
        local full_title="$title"
        
        dialog --stdout \
            --title "$full_title" \
            --dselect "$abs_path" 25 70 \
            --no-shadow \
            2>&1
    else
        # whiptail не поддерживает dselect, используем inputbox
        whiptail --title "$1" --inputbox "Введите путь к папке:" 10 50 "$2" 3>&1 1>&2 2>&3
    fi
}

dialog_menu() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --stdout --title "$1" --menu "$2" 15 50 6 "${@:3}"
    else
        whiptail --title "$1" --menu "$2" 15 50 6 "${@:3}" 3>&1 1>&2 2>&3
    fi
}

dialog_checklist() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --stdout --title "$1" --checklist "$2" 10 40 2 "${@:3}"
    else
        whiptail --title "$1" --checklist "$2" 10 40 2 "${@:3}" 3>&1 1>&2 2>&3
    fi
}

dialog_gauge() {
    if [ "$TUI_TOOL" = "dialog" ]; then
        dialog --title "$1" --gauge "$2" 10 50 0
    else
        whiptail --title "$1" --gauge "$2" 10 50 0
    fi
}

# Функция управления контейнерами
show_container_management() {
    while true; do
        local compose_cmd="docker-compose"
        if ! command -v docker-compose &> /dev/null; then
            compose_cmd="docker compose"
        fi
        
        # Проверка статуса контейнеров
        local status_info=""
        if [ -f "docker-compose.yml" ]; then
            local running=$(docker ps --filter "name=flibusta" --format "{{.Names}}" 2>/dev/null | wc -l)
            status_info=" (Запущено: $running)"
        fi
        
        local choice
        choice=$(dialog_menu "Управление контейнерами$status_info" \
            "Выберите действие:" \
            "1" "Собрать образы" \
            "2" "Запустить контейнеры" \
            "3" "Остановить контейнеры" \
            "4" "Перезапустить контейнеры" \
            "5" "Показать статус" \
            "6" "Показать логи" \
            "0" "Назад")
        
        case $choice in
            1)
                if dialog_yesno "Подтверждение" "Собрать Docker образы?\n\nЭто может занять некоторое время."; then
                    (
                        echo "10"
                        echo "XXX"
                        echo "Сборка образов..."
                        echo "XXX"
                        if [ -f ".env" ]; then
                            export $(grep -v '^#' .env | xargs)
                        fi
                        $compose_cmd build 2>&1 | tee -a /tmp/flibusta_build.log
                        echo "100"
                        echo "XXX"
                        echo "Сборка завершена!"
                        echo "XXX"
                    ) | dialog_gauge "Сборка образов" "Начало сборки..."
                    
                    if [ $? -eq 0 ]; then
                        dialog_msgbox "Успешно" "Образы собраны успешно!"
                    else
                        dialog_msgbox "Ошибка" "Ошибка при сборке образов.\n\nПроверьте логи."
                    fi
                fi
                ;;
            2)
                if dialog_yesno "Подтверждение" "Запустить контейнеры?"; then
                    (
                        echo "10"
                        echo "XXX"
                        echo "Запуск контейнеров..."
                        echo "XXX"
                        if [ -f ".env" ]; then
                            export $(grep -v '^#' .env | xargs)
                        fi
                        $compose_cmd up -d 2>&1 | tee -a /tmp/flibusta_start.log
                        echo "50"
                        echo "XXX"
                        echo "Ожидание готовности..."
                        echo "XXX"
                        sleep 10
                        echo "100"
                        echo "XXX"
                        echo "Готово!"
                        echo "XXX"
                    ) | dialog_gauge "Запуск контейнеров" "Запуск..."
                    
                    if [ $? -eq 0 ]; then
                        dialog_msgbox "Успешно" "Контейнеры запущены!"
                    else
                        dialog_msgbox "Ошибка" "Ошибка при запуске контейнеров.\n\nПроверьте логи."
                    fi
                fi
                ;;
            3)
                if dialog_yesno "Подтверждение" "Остановить контейнеры?"; then
                    if $compose_cmd stop 2>&1; then
                        dialog_msgbox "Успешно" "Контейнеры остановлены!"
                    else
                        dialog_msgbox "Ошибка" "Ошибка при остановке контейнеров."
                    fi
                fi
                ;;
            4)
                if dialog_yesno "Подтверждение" "Перезапустить контейнеры?"; then
                    if [ -f ".env" ]; then
                        export $(grep -v '^#' .env | xargs)
                    fi
                    if $compose_cmd restart 2>&1; then
                        dialog_msgbox "Успешно" "Контейнеры перезапущены!"
                    else
                        dialog_msgbox "Ошибка" "Ошибка при перезапуске контейнеров."
                    fi
                fi
                ;;
            5)
                local status_output
                status_output=$($compose_cmd ps 2>&1)
                dialog_msgbox "Статус контейнеров" "$status_output"
                ;;
            6)
                local log_choice
                log_choice=$(dialog_menu "Просмотр логов" \
                    "Выберите сервис:" \
                    "1" "Все сервисы" \
                    "2" "PHP-FPM" \
                    "3" "PostgreSQL" \
                    "4" "Nginx" \
                    "0" "Назад")
                
                case $log_choice in
                    1)
                        local logs_output
                        logs_output=$($compose_cmd logs --tail=50 2>&1)
                        dialog_msgbox "Логи всех сервисов" "$logs_output"
                        ;;
                    2)
                        local logs_output
                        logs_output=$($compose_cmd logs --tail=50 php-fpm 2>&1)
                        dialog_msgbox "Логи PHP-FPM" "$logs_output"
                        ;;
                    3)
                        local logs_output
                        logs_output=$($compose_cmd logs --tail=50 postgres 2>&1)
                        dialog_msgbox "Логи PostgreSQL" "$logs_output"
                        ;;
                    4)
                        local logs_output
                        logs_output=$($compose_cmd logs --tail=50 webserver 2>&1)
                        dialog_msgbox "Логи Nginx" "$logs_output"
                        ;;
                esac
                ;;
            0)
                return
                ;;
        esac
    done
}

# Функция главного меню
show_main_menu() {
    while true; do
        choice=$(dialog_menu "Flibusta Local Mirror - Установка" \
            "Выберите действие:" \
            "1" "Основные настройки" \
            "2" "Пути к данным" \
            "3" "Дополнительные опции" \
            "4" "Управление контейнерами" \
            "5" "Проверить требования" \
            "6" "Начать установку" \
            "0" "Выход")
        
        case $choice in
            1) show_basic_settings ;;
            2) show_paths_selection ;;
            3) show_advanced_options ;;
            4) show_container_management ;;
            5) check_requirements_dialog ;;
            6) start_installation ;;
            0) exit 0 ;;
        esac
    done
}

# Функция выбора папки с улучшенной навигацией (как в Total Commander)
select_directory() {
    local title=$1
    local default_path=$2
    local result
    local absolute_default
    local project_root=$(get_project_root)
    
    # Преобразуем относительный путь в абсолютный для начальной директории
    if [ -z "$default_path" ]; then
        absolute_default="$project_root"
    elif [[ "$default_path" = /* ]]; then
        absolute_default="$default_path"
    else
        # Для относительных путей начинаем с корня проекта
        if [[ "$default_path" = ./* ]] || [[ "$default_path" != /* ]]; then
            absolute_default="$project_root/${default_path#./}"
        else
            absolute_default="$(cd "$(dirname "$default_path")" 2>/dev/null && pwd)/$(basename "$default_path")"
        fi
        # Если путь не существует, используем корень проекта
        if [ ! -d "$absolute_default" ]; then
            absolute_default="$project_root"
        fi
    fi
    
    # Убеждаемся, что это директория
    if [ ! -d "$absolute_default" ]; then
        absolute_default="$(dirname "$absolute_default" 2>/dev/null || echo "$project_root")"
    fi
    if [ ! -d "$absolute_default" ]; then
        absolute_default="$project_root"
    fi
    
    # Показываем диалог выбора директории (dselect позволяет навигацию по папкам)
    # В dialog --dselect можно:
    # - Использовать стрелки для перемещения по списку
    # - Нажать Enter для входа в папку или выбора
    # - Использовать Tab для переключения между полем пути и списком
    # - Нажать Escape для отмены
    result=$(dialog_dselect "$title" "$absolute_default")
    local dialog_exit=$?
    
    if [ $dialog_exit -eq 0 ] && [ -n "$result" ]; then
        # Убеждаемся, что выбранная директория существует
        if [ ! -d "$result" ]; then
            # Если выбран файл, берем его директорию
            if [ -f "$result" ]; then
                result="$(dirname "$result")"
            else
                # Если путь не существует, возвращаем исходный
                echo "$default_path"
                return
            fi
        fi
        
        # Преобразуем абсолютный путь обратно в относительный (если возможно)
        if [[ "$result" = "$project_root"/* ]]; then
            local relative_path=".${result#$project_root}"
            echo "$relative_path"
        elif [ "$result" = "$project_root" ]; then
            echo "."
        else
            echo "$result"
        fi
    else
        # При отмене возвращаем исходный путь
        echo "$default_path"
    fi
}

# Функция выбора путей
show_paths_selection() {
    while true; do
        # Формируем отображение путей с полной информацией
        local sql_display="$SQL_DIR"
        local sql_abs=$(get_absolute_path "$SQL_DIR")
        if [ "$sql_display" != "$sql_abs" ] && [ -n "$sql_abs" ]; then
            sql_display="$SQL_DIR\n    ($sql_abs)"
        fi
        
        local books_display="$BOOKS_DIR"
        local books_abs=$(get_absolute_path "$BOOKS_DIR")
        if [ "$books_display" != "$books_abs" ] && [ -n "$books_abs" ]; then
            books_display="$BOOKS_DIR\n    ($books_abs)"
        fi
        
        local menu_choice
        menu_choice=$(dialog_menu "Выбор путей к данным" \
            "Выберите действие:" \
            "1" "Папка с SQL файлами" \
            "2" "Папка с архивами книг" \
            "3" "Проверить выбранные папки" \
            "0" "Назад")
        
        case $menu_choice in
            1)
                local new_sql_dir
                new_sql_dir=$(select_directory "Выбор папки с SQL файлами" "$SQL_DIR")
                
                if [ -n "$new_sql_dir" ] && [ "$new_sql_dir" != "$SQL_DIR" ]; then
                    # Валидация
                    local abs_path=$(get_absolute_path "$new_sql_dir")
                    if [ ! -d "$abs_path" ]; then
                        dialog_msgbox "Ошибка" "Папка не существует:\n$abs_path"
                    else
                        # Проверка наличия SQL файлов
                        local sql_count=$(find "$abs_path" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
                        
                        # Подтверждение выбранного пути с информацией
                        local confirm_msg="Выбранная папка:\n\n"
                        confirm_msg+="Путь: $new_sql_dir\n"
                        confirm_msg+="Абсолютный путь: $abs_path\n"
                        confirm_msg+="Найдено SQL файлов: $sql_count\n\n"
                        confirm_msg+="Подтвердить выбор?"
                        
                        if dialog_yesno "Подтверждение выбора папки" "$confirm_msg"; then
                            SQL_DIR="$new_sql_dir"
                            if [ $sql_count -gt 0 ]; then
                                dialog_msgbox "Успешно" "Папка установлена:\n$SQL_DIR\n\nНайдено SQL файлов: $sql_count"
                            else
                                dialog_msgbox "Успешно" "Папка установлена:\n$SQL_DIR\n\n(SQL файлы не найдены в этой папке)"
                            fi
                        fi
                    fi
                fi
                ;;
            2)
                local new_books_dir
                new_books_dir=$(select_directory "Выбор папки с архивами книг" "$BOOKS_DIR")
                
                if [ -n "$new_books_dir" ] && [ "$new_books_dir" != "$BOOKS_DIR" ]; then
                    local abs_path=$(get_absolute_path "$new_books_dir")
                    if [ ! -d "$abs_path" ]; then
                        dialog_msgbox "Ошибка" "Папка не существует:\n$abs_path"
                    else
                        local books_count=$(find "$abs_path" -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l)
                        
                        # Подтверждение выбранного пути с информацией
                        local confirm_msg="Выбранная папка:\n\n"
                        confirm_msg+="Путь: $new_books_dir\n"
                        confirm_msg+="Абсолютный путь: $abs_path\n"
                        confirm_msg+="Найдено архивов книг: $books_count\n\n"
                        confirm_msg+="Подтвердить выбор?"
                        
                        if dialog_yesno "Подтверждение выбора папки" "$confirm_msg"; then
                            BOOKS_DIR="$new_books_dir"
                            if [ $books_count -gt 0 ]; then
                                dialog_msgbox "Успешно" "Папка установлена:\n$BOOKS_DIR\n\nНайдено архивов книг: $books_count"
                            else
                                dialog_msgbox "Успешно" "Папка установлена:\n$BOOKS_DIR\n\n(Архивы книг не найдены в этой папке)"
                            fi
                        fi
                    fi
                fi
                ;;
            3)
                local info_msg="Текущие пути к данным:\n\n"
                
                # Информация о SQL папке
                info_msg+="📁 SQL файлы:\n"
                info_msg+="  Относительный путь: $SQL_DIR\n"
                local sql_abs=$(get_absolute_path "$SQL_DIR")
                info_msg+="  Абсолютный путь: $sql_abs\n"
                if [ -d "$sql_abs" ]; then
                    local sql_count=$(find "$sql_abs" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
                    info_msg+="  ✓ Папка существует\n"
                    info_msg+="  Найдено файлов: $sql_count\n"
                else
                    info_msg+="  ⚠ Папка не существует\n"
                fi
                
                info_msg+="\n📁 Архивы книг:\n"
                info_msg+="  Относительный путь: $BOOKS_DIR\n"
                local books_abs=$(get_absolute_path "$BOOKS_DIR")
                info_msg+="  Абсолютный путь: $books_abs\n"
                if [ -d "$books_abs" ]; then
                    local books_count=$(find "$books_abs" -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l)
                    info_msg+="  ✓ Папка существует\n"
                    info_msg+="  Найдено архивов: $books_count\n"
                else
                    info_msg+="  ⚠ Папка не существует\n"
                fi
                
                info_msg+="\n📂 Корень проекта:\n"
                info_msg+="  $(get_project_root)\n"
                
                dialog_msgbox "Проверка путей" "$info_msg"
                ;;
            0)
                return
                ;;
        esac
    done
}

# Функция основных настроек
show_basic_settings() {
    local form_result
    
    # Dialog form
    if [ "$TUI_TOOL" = "dialog" ]; then
        form_result=$(dialog --stdout --title "Основные настройки" \
            --form "Введите параметры установки:" 15 50 4 \
            "Порт веб-сервера:" 1 1 "$WEB_PORT" 1 25 20 0 \
            "Порт базы данных:" 2 1 "$DB_PORT" 2 25 20 0 \
            "Пароль БД:" 3 1 "$DB_PASSWORD" 3 25 20 1)
        
        if [ $? -eq 0 ]; then
            WEB_PORT=$(echo "$form_result" | sed -n '1p')
            DB_PORT=$(echo "$form_result" | sed -n '2p')
            DB_PASSWORD=$(echo "$form_result" | sed -n '3p')
        fi
    else
        # Whiptail - отдельные inputbox
        WEB_PORT=$(dialog_inputbox "Основные настройки" "Порт веб-сервера:" "$WEB_PORT")
        DB_PORT=$(dialog_inputbox "Основные настройки" "Порт базы данных:" "$DB_PORT")
        DB_PASSWORD=$(dialog_passwordbox "Основные настройки" "Пароль БД:" "$DB_PASSWORD")
    fi
    
    # Генерация пароля (опция)
    if [ -z "$DB_PASSWORD" ]; then
        if dialog_yesno "Генерация пароля" "Сгенерировать случайный пароль?"; then
            DB_PASSWORD=$(openssl rand -base64 24 | tr -d "=+/" | cut -c1-32 2>/dev/null || \
                cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 32 | head -n 1)
            dialog_msgbox "Пароль сгенерирован" "Пароль: $DB_PASSWORD\n\nСохраните его!"
        fi
    fi
}

# Функция дополнительных опций
show_advanced_options() {
    local checklist_result
    
    if [ "$TUI_TOOL" = "dialog" ]; then
        checklist_result=$(dialog_checklist "Дополнительные опции" \
            "Выберите опции:" \
            "auto_init" "Автоматическая инициализация БД" $AUTO_INIT \
            "show_password" "Показать пароль после установки" $SHOW_PASSWORD \
            "download_sql" "Скачать SQL файлы с Флибусты" $DOWNLOAD_SQL \
            "download_covers" "Скачать обложки книг" $DOWNLOAD_COVERS \
            "update_library" "Обновить библиотеку (ежедневные обновления)" $UPDATE_LIBRARY)
    else
        checklist_result=$(whiptail --title "Дополнительные опции" \
            --checklist "Выберите опции:" 15 50 5 \
            "auto_init" "Автоматическая инициализация БД" $AUTO_INIT \
            "show_password" "Показать пароль после установки" $SHOW_PASSWORD \
            "download_sql" "Скачать SQL файлы с Флибусты" $DOWNLOAD_SQL \
            "download_covers" "Скачать обложки книг" $DOWNLOAD_COVERS \
            "update_library" "Обновить библиотеку (ежедневные обновления)" $UPDATE_LIBRARY \
            3>&1 1>&2 2>&3)
    fi
    
    if [ $? -eq 0 ]; then
        AUTO_INIT=0
        SHOW_PASSWORD=0
        DOWNLOAD_SQL=0
        DOWNLOAD_COVERS=0
        UPDATE_LIBRARY=0
        
        if echo "$checklist_result" | grep -q "auto_init"; then
            AUTO_INIT=1
        fi
        if echo "$checklist_result" | grep -q "show_password"; then
            SHOW_PASSWORD=1
        fi
        if echo "$checklist_result" | grep -q "download_sql"; then
            DOWNLOAD_SQL=1
        fi
        if echo "$checklist_result" | grep -q "download_covers"; then
            DOWNLOAD_COVERS=1
        fi
        if echo "$checklist_result" | grep -q "update_library"; then
            UPDATE_LIBRARY=1
        fi
    fi
}

# Функция проверки требований
check_requirements_dialog() {
    local check_output
    
    if [ -f "scripts/check_requirements.sh" ]; then
        check_output=$(bash scripts/check_requirements.sh 2>&1)
        dialog_msgbox "Проверка требований" "$check_output"
    else
        dialog_msgbox "Ошибка" "Скрипт проверки требований не найден"
    fi
}

# Функция запуска установки
start_installation() {
    # Валидация
    if [ -z "$SQL_DIR" ]; then
        dialog_msgbox "Ошибка" "Выберите папку с SQL файлами!"
        return
    fi
    
    if [ -z "$BOOKS_DIR" ]; then
        dialog_msgbox "Ошибка" "Выберите папку с архивами книг!"
        return
    fi
    
    if [ -z "$DB_PASSWORD" ]; then
        dialog_msgbox "Ошибка" "Введите или сгенерируйте пароль БД!"
        return
    fi
    
    # Подтверждение с отображением параметров и полных путей
    local sql_abs=$(get_absolute_path "$SQL_DIR")
    local books_abs=$(get_absolute_path "$BOOKS_DIR")
    
    local confirm_msg="Начать установку с параметрами:\n\n"
    confirm_msg+="🌐 Порт веб-сервера: $WEB_PORT\n"
    confirm_msg+="🗄️  Порт базы данных: $DB_PORT\n"
    confirm_msg+="\n📁 Папка SQL файлов:\n"
    confirm_msg+="  Относительный: $SQL_DIR\n"
    confirm_msg+="  Абсолютный: $sql_abs\n"
    confirm_msg+="\n📁 Папка архивов книг:\n"
    confirm_msg+="  Относительный: $BOOKS_DIR\n"
    confirm_msg+="  Абсолютный: $books_abs\n"
    confirm_msg+="\n⚙️  Автоинициализация БД: $([ $AUTO_INIT -eq 1 ] && echo "Да" || echo "Нет")\n"
    
    if ! dialog_yesno "Подтверждение установки" "$confirm_msg"; then
        return
    fi
    
    # Запуск установки с прогресс-баром
    (
        echo "10"
        echo "XXX"
        echo "Проверка требований..."
        echo "XXX"
        bash scripts/check_requirements.sh > /dev/null 2>&1 || true
        echo "30"
        echo "XXX"
        echo "Создание директорий..."
        echo "XXX"
        bash scripts/init_directories.sh > /dev/null 2>&1 || true
        echo "50"
        echo "XXX"
        echo "Настройка конфигурации..."
        echo "XXX"
        # Вызов install.sh с параметрами
        AUTO_INIT_FLAG=""
        if [ $AUTO_INIT -eq 1 ]; then
            AUTO_INIT_FLAG="--auto-init"
        else
            AUTO_INIT_FLAG="--no-auto-init"
        fi
        
        # Формируем команду с опциями скачивания
        local download_flags=""
        [ $DOWNLOAD_SQL -eq 1 ] && download_flags+=" --download-sql"
        [ $DOWNLOAD_COVERS -eq 1 ] && download_flags+=" --download-covers"
        [ $UPDATE_LIBRARY -eq 1 ] && download_flags+=" --update-library"
        
        bash install.sh --db-password "$DB_PASSWORD" \
            --port "$WEB_PORT" \
            --db-port "$DB_PORT" \
            --sql-dir "$SQL_DIR" \
            --books-dir "$BOOKS_DIR" \
            $AUTO_INIT_FLAG \
            $download_flags \
            --skip-checks > /dev/null 2>&1
        echo "100"
        echo "XXX"
        echo "Завершено!"
        echo "XXX"
    ) | dialog_gauge "Установка Flibusta" "Начало установки..."
    
    if [ $? -eq 0 ]; then
        local success_msg="Установка завершена успешно!\n\n"
        success_msg+="Веб-интерфейс: http://localhost:$WEB_PORT\n"
        success_msg+="OPDS каталог: http://localhost:$WEB_PORT/opds/\n\n"
        
        if [ $SHOW_PASSWORD -eq 1 ]; then
            success_msg+="Пароль БД: $DB_PASSWORD"
        fi
        
        dialog_msgbox "Успешно" "$success_msg"
    else
        dialog_msgbox "Ошибка" "Ошибка при установке. Проверьте логи."
    fi
}

# Новая функция: выбор из стандартных путей
select_standard_path() {
    local title=$1
    local project_root=$(get_project_root)
    local home_dir=$(get_home_dir)
    local choice
    
    choice=$(dialog_menu "$title (Стандартные пути)" \
        "Выберите стандартную папку:" \
        "1" "Текущая папка проекта ($project_root)" \
        "2" "Папка по умолчанию для SQL (./FlibustaSQL)" \
        "3" "Папка по умолчанию для книг (./Flibusta.Net)" \
        "4" "Домашняя директория ($home_dir)" \
        "5" "/var/lib/flibusta (стандартный системный путь)" \
        "6" "/mnt/data (распространенный путь для данных)")
    
    case $choice in
        1)
            echo "$project_root"
            ;;
        2)
            echo "$project_root/FlibustaSQL"
            ;;
        3)
            echo "$project_root/Flibusta.Net"
            ;;
        4)
            echo "$home_dir"
            ;;
        5)
            echo "/var/lib/flibusta"
            ;;
        6)
            echo "/mnt/data"
            ;;
        *)
            echo ""
            ;;
    esac
}

# Новая функция: детальная валидация и подтверждение директории
validate_and_confirm_directory() {
    local title=$1
    local selected_path=$2
    local default_path=$3
    local project_root=$(get_project_root)
    
    # Преобразуем в абсолютный путь
    if [[ "$selected_path" != /* ]]; then
        selected_path="$project_root/${selected_path#./}"
    fi
    
    # Детальная проверка директории
    local check_results=""
    local has_errors=0
    
    # Проверка существования
    if [ ! -d "$selected_path" ]; then
        check_results+="❌ Директория не существует\n"
        has_errors=1
    else
        check_results+="✅ Директория существует\n"
        
        # Проверка прав на чтение
        if [ -r "$selected_path" ]; then
            check_results+="✅ Есть права на чтение\n"
        else
            check_results+="❌ Нет прав на чтение\n"
            has_errors=1
        fi
        
        # Проверка прав на запись
        if [ -w "$selected_path" ]; then
            check_results+="✅ Есть права на запись\n"
        else
            check_results+="⚠️  Нет прав на запись (требуется для кэша)\n"
        fi
        
        # Проверка содержимого
        local sql_count=$(find "$selected_path" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
        local zip_count=$(find "$selected_path" -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l)
        local all_count=$(find "$selected_path" -maxdepth 1 -type f 2>/dev/null | wc -l)
        
        check_results+="📁 Найдено файлов: $all_count\n"
        if [ $sql_count -gt 0 ]; then
            check_results+="   - SQL файлов: $sql_count\n"
        fi
        if [ $zip_count -gt 0 ]; then
            check_results+="   - ZIP архивов: $zip_count\n"
        fi
        
        # Проверка доступного места
        local free_space=$(df -h "$selected_path" 2>/dev/null | tail -1 | awk '{print $4}')
        if [ -n "$free_space" ]; then
            check_results+="💾 Свободно места: $free_space\n"
        fi
        
        # Проверка на файловой системе (монтирование)
        local mount_point=$(df "$selected_path" 2>/dev/null | tail -1 | awk '{print $6}')
        if [ -n "$mount_point" ]; then
            check_results+="🔀 Точка монтирования: $mount_point\n"
        fi
    fi
    
    # Относительный путь для отображения
    local relative_path="$selected_path"
    if [[ "$selected_path" = "$project_root"* ]]; then
        relative_path=".${selected_path#$project_root}"
    elif [ "$selected_path" = "$project_root" ]; then
        relative_path="."
    fi
    
    # Формируем сообщение подтверждения
    local confirm_msg="Выбрана папка:\n\n"
    confirm_msg+="📂 Путь:\n"
    confirm_msg+="   Абсолютный: $selected_path\n"
    confirm_msg+="   Относительный: $relative_path\n\n"
    confirm_msg+="Проверки:\n$check_results\n"
    
    if [ $has_errors -eq 1 ]; then
        confirm_msg+="⚠️  Обнаружены проблемы. Рекомендуется выбрать другую папку.\n"
    fi
    
    confirm_msg+="Подтвердить выбор?"
    
    if dialog_yesno "Подтверждение выбора папки" "$confirm_msg"; then
        if [ $has_errors -eq 1 ]; then
            local confirm_with_errors
            confirm_with_errors=$(dialog_menu "Подтверждение с предупреждениями" \
                "В выбранной папке есть проблемы:\n\n$check_results\n\nВсё равно использовать эту папку?" \
                "1" "Да, использовать эту папку" \
                "2" "Нет, выбрать другую")
            
            case $confirm_with_errors in
                1)
                    echo "$relative_path"
                    ;;
                2)
                    # Рекурсивный вызов
                    select_directory "$title" "$default_path"
                    ;;
            esac
        else
            echo "$relative_path"
        fi
    else
        # При отмене предлагаем выбрать другой путь
        select_directory "$title" "$default_path"
    fi
}

# Улучшенная функция выбора папки с несколькими режимами
select_directory() {
    local title=$1
    local default_path=$2
    local result
    local absolute_default
    local project_root=$(get_project_root)
    
    # Преобразуем относительный путь в абсолютный для начальной директории
    if [ -z "$default_path" ]; then
        absolute_default="$project_root"
    elif [[ "$default_path" = /* ]]; then
        absolute_default="$default_path"
    else
        # Для относительных путей начинаем с корня проекта
        if [[ "$default_path" = ./* ]] || [[ "$default_path" != /* ]]; then
            absolute_default="$project_root/${default_path#./}"
        else
            absolute_default="$(cd "$(dirname "$default_path")" 2>/dev/null && pwd)/$(basename "$default_path")"
        fi
        # Если путь не существует, используем корень проекта
        if [ ! -d "$absolute_default" ]; then
            absolute_default="$project_root"
        fi
    fi
    
    # Убеждаемся, что это директория
    if [ ! -d "$absolute_default" ]; then
        absolute_default="$(dirname "$absolute_default" 2>/dev/null || echo "$project_root")"
    fi
    if [ ! -d "$absolute_default" ]; then
        absolute_default="$project_root"
    fi
    
    # Предлагаем несколько режимов выбора
    local choice
    choice=$(dialog_menu "$title" \
        "Выберите способ указания папки:" \
        "1" "Использовать файловый навигатор (dialog dselect)" \
        "2" "Ввести путь вручную" \
        "3" "Выбрать из стандартных путей")
    
    case $choice in
        1)
            # Режим 1: Файловый навигатор (если доступен)
            if [ "$TUI_TOOL" = "dialog" ]; then
                local full_title="$title (навигатор)"
                
                # Пробуем использовать dselect с таймаутом для обнаружения проблем
                result=$(dialog --stdout \
                    --title "$full_title" \
                    --dselect "$absolute_default" 25 70 \
                    --no-shadow \
                    2>&1)
                
                local dialog_exit=$?
                
                # Если dselect вернул ошибку, предлагаем альтернативу
                if [ $dialog_exit -ne 0 ] || [ -z "$result" ]; then
                    dialog_msgbox "Предупреждение" \
                        "Файловый навигатор не сработал в вашей терминальной среде.\n\nПопробуйте выбрать путь вручную."
                    result=$(dialog_inputbox "$title" \
                        "Введите полный путь к папке:" "$absolute_default")
                fi
            else
                # Для whiptail используем inputbox
                result=$(dialog_inputbox "$title" \
                    "Введите путь к папке:" "$absolute_default")
            fi
            ;;
        2)
            # Режим 2: Ручной ввод пути
            result=$(dialog_inputbox "$title" \
                "Введите полный путь к папке (можно использовать Tab для автодополнения в некоторых терминалах):" "$absolute_default")
            ;;
        3)
            # Режим 3: Стандартные пути
            result=$(select_standard_path "$title")
            ;;
        *)
            # Отмена
            echo "$default_path"
            return
            ;;
    esac
    
    local dialog_exit=$?
    
    if [ $dialog_exit -eq 0 ] && [ -n "$result" ]; then
        # Убеждаемся, что выбранная директория существует
        if [ ! -d "$result" ]; then
            # Если выбран файл, берем его директорию
            if [ -f "$result" ]; then
                result="$(dirname "$result")"
            else
                # Если путь не существует, предлагаем создать или выбрать другой
                local create_choice
                create_choice=$(dialog_menu "Папка не существует" \
                    "Выбранная папка не существует:\n\n$result\n\nЧто сделать?" \
                    "1" "Выбрать другой путь" \
                    "2" "Ввести путь заново" \
                    "3" "Использовать путь по умолчанию")
                
                case $create_choice in
                    1|2)
                        # Рекурсивный вызов
                        select_directory "$title" "$default_path"
                        return
                        ;;
                    3)
                        echo "$default_path"
                        return
                        ;;
                esac
            fi
        fi
        
        # Детальная проверка выбранной директории
        validate_and_confirm_directory "$title" "$result" "$default_path"
    else
        # При отмене возвращаем исходный путь
        echo "$default_path"
    fi
}

# Запуск главного меню
show_main_menu
