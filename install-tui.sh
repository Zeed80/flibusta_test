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

# Получение абсолютного пути
get_absolute_path() {
    local path=$1
    if [ -z "$path" ]; then
        echo "$(pwd)"
    elif [[ "$path" = /* ]]; then
        echo "$path"
    else
        echo "$(cd "$(dirname "$path")" && pwd)/$(basename "$path")"
    fi
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
        # Используем dselect для выбора директории (как в проводнике)
        local default_path=$(get_absolute_path "$2")
        dialog --stdout --title "$1" --dselect "$default_path" 20 60
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

# Функция главного меню
show_main_menu() {
    while true; do
        choice=$(dialog_menu "Flibusta Local Mirror - Установка" \
            "Выберите действие:" \
            "1" "Основные настройки" \
            "2" "Пути к данным" \
            "3" "Дополнительные опции" \
            "4" "Проверить требования" \
            "5" "Начать установку" \
            "0" "Выход")
        
        case $choice in
            1) show_basic_settings ;;
            2) show_paths_selection ;;
            3) show_advanced_options ;;
            4) check_requirements_dialog ;;
            5) start_installation ;;
            0) exit 0 ;;
        esac
    done
}

# Функция выбора папки
select_directory() {
    local title=$1
    local default_path=$2
    local result
    local absolute_default
    
    # Преобразуем относительный путь в абсолютный
    if [ -z "$default_path" ]; then
        absolute_default="$(pwd)"
    elif [[ "$default_path" = /* ]]; then
        absolute_default="$default_path"
    else
        absolute_default="$(cd "$(dirname "$default_path")" 2>/dev/null && pwd)/$(basename "$default_path")"
        # Если путь не существует, используем текущую директорию
        if [ ! -d "$absolute_default" ]; then
            absolute_default="$(pwd)"
        fi
    fi
    
    result=$(dialog_dselect "$title" "$absolute_default")
    
    if [ $? -eq 0 ] && [ -n "$result" ]; then
        # Преобразуем абсолютный путь обратно в относительный (если возможно)
        local project_root="$(pwd)"
        if [[ "$result" = "$project_root"/* ]]; then
            echo ".${result#$project_root}"
        else
            echo "$result"
        fi
    else
        echo "$default_path"
    fi
}

# Функция выбора путей
show_paths_selection() {
    while true; do
        local menu_choice
        menu_choice=$(dialog_menu "Выбор путей к данным" \
            "Выберите действие:" \
            "1" "Папка с SQL файлами: ${SQL_DIR}" \
            "2" "Папка с архивами книг: ${BOOKS_DIR}" \
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
                        if [ $sql_count -eq 0 ]; then
                            if dialog_yesno "Предупреждение" "В выбранной папке не найдено SQL файлов.\n\nПапка: $new_sql_dir\n\nПродолжить?"; then
                                SQL_DIR="$new_sql_dir"
                                dialog_msgbox "Информация" "Папка установлена:\n$SQL_DIR"
                            fi
                        else
                            SQL_DIR="$new_sql_dir"
                            dialog_msgbox "Информация" "Папка установлена:\n$SQL_DIR\n\nНайдено SQL файлов: $sql_count"
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
                        BOOKS_DIR="$new_books_dir"
                        local books_count=$(find "$abs_path" -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l)
                        if [ $books_count -gt 0 ]; then
                            dialog_msgbox "Информация" "Папка установлена:\n$BOOKS_DIR\n\nНайдено архивов книг: $books_count"
                        else
                            dialog_msgbox "Информация" "Папка установлена:\n$BOOKS_DIR\n\n(Архивы книг не найдены)"
                        fi
                    fi
                fi
                ;;
            3)
                local info_msg="Текущие пути:\n\n"
                info_msg+="SQL файлы: $SQL_DIR\n"
                local sql_abs=$(get_absolute_path "$SQL_DIR")
                if [ -d "$sql_abs" ]; then
                    local sql_count=$(find "$sql_abs" -maxdepth 1 -type f \( -name "*.sql" -o -name "*.sql.gz" \) 2>/dev/null | wc -l)
                    info_msg+="  Найдено файлов: $sql_count\n"
                else
                    info_msg+="  ⚠ Папка не существует\n"
                fi
                info_msg+="\nАрхивы книг: $BOOKS_DIR\n"
                local books_abs=$(get_absolute_path "$BOOKS_DIR")
                if [ -d "$books_abs" ]; then
                    local books_count=$(find "$books_abs" -maxdepth 1 -type f -name "*.zip" 2>/dev/null | wc -l)
                    info_msg+="  Найдено архивов: $books_count\n"
                else
                    info_msg+="  ⚠ Папка не существует\n"
                fi
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
            "show_password" "Показать пароль после установки" $SHOW_PASSWORD)
    else
        checklist_result=$(whiptail --title "Дополнительные опции" \
            --checklist "Выберите опции:" 10 40 2 \
            "auto_init" "Автоматическая инициализация БД" $AUTO_INIT \
            "show_password" "Показать пароль после установки" $SHOW_PASSWORD \
            3>&1 1>&2 2>&3)
    fi
    
    if [ $? -eq 0 ]; then
        AUTO_INIT=0
        SHOW_PASSWORD=0
        if echo "$checklist_result" | grep -q "auto_init"; then
            AUTO_INIT=1
        fi
        if echo "$checklist_result" | grep -q "show_password"; then
            SHOW_PASSWORD=1
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
    
    # Подтверждение
    if ! dialog_yesno "Подтверждение" "Начать установку с выбранными параметрами?"; then
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
        
        bash install.sh --db-password "$DB_PASSWORD" \
            --port "$WEB_PORT" \
            --db-port "$DB_PORT" \
            --sql-dir "$SQL_DIR" \
            --books-dir "$BOOKS_DIR" \
            $AUTO_INIT_FLAG \
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

# Запуск главного меню
show_main_menu
