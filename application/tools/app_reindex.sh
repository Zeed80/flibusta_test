#!/bin/sh
# app_reindex.sh - Реиндексация ZIP архивов с улучшенным логированием

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Выводим информацию о запуске для диагностики
echo -e "${YELLOW}Запуск скрипта app_reindex.sh${NC}" >&2
echo "Запуск скрипта app_reindex.sh" >>/application/cache/sql_status 2>/dev/null || true

# Убеждаемся, что директория cache существует и имеет права на запись
mkdir -p /application/cache
chmod 777 /application/cache 2>/dev/null || true

# Создаем файл статуса, если его нет, и устанавливаем права
if [ ! -f /application/cache/sql_status ]; then
    touch /application/cache/sql_status
    chmod 666 /application/cache/sql_status 2>/dev/null || true
fi

# Добавляем сообщение в файл статуса
echo "Создание индекса zip-файлов">>/application/cache/sql_status
echo "Начало создания индекса zip-файлов" >&2

# Переходим в рабочую директорию для надежности
cd /application || {
    echo "Ошибка: Не удалось перейти в /application" >&2
    echo "Ошибка: Не удалось перейти в /application">>/application/cache/sql_status
    exit 1
}

# Проверяем существование PHP скрипта
if [ ! -f /application/tools/app_update_zip_list.php ]; then
    echo "Ошибка: Файл /application/tools/app_update_zip_list.php не найден" >&2
    echo "Ошибка: Файл /application/tools/app_update_zip_list.php не найден">>/application/cache/sql_status
    exit 1
fi

# Проверяем существование dbinit.php
if [ ! -f /application/dbinit.php ]; then
    echo "Ошибка: Файл /application/dbinit.php не найден" >&2
    echo "Ошибка: Файл /application/dbinit.php не найден">>/application/cache/sql_status
    exit 1
fi

# Проверка подключения к базе данных
echo -e "${YELLOW}Проверка подключения к базе данных...${NC}" >&2
. /application/tools/dbinit.sh 2>/dev/null || true
if [ -n "$SQL_CMD" ]; then
    if $SQL_CMD -c "SELECT 1;" >/dev/null 2>&1; then
        echo -e "${GREEN}✓ Подключение к базе данных успешно${NC}" >&2
        echo "Подключение к базе данных успешно">>/application/cache/sql_status
    else
        echo -e "${RED}✗ Не удалось подключиться к базе данных${NC}" >&2
        echo "Ошибка: Не удалось подключиться к базе данных">>/application/cache/sql_status
        echo "=== Реиндексация завершена с ошибками (нет подключения к БД) ===" >>/application/cache/sql_status
        exit 1
    fi
else
    echo -e "${YELLOW}⚠ Не удалось загрузить dbinit.sh, пропускаем проверку БД${NC}" >&2
fi

# Проверка наличия PHP расширения zip
echo -e "${YELLOW}Проверка PHP расширения zip...${NC}" >&2
if php -m 2>/dev/null | grep -q "^zip$"; then
    echo -e "${GREEN}✓ PHP расширение zip установлено${NC}" >&2
else
    echo -e "${RED}✗ PHP расширение zip не установлено${NC}" >&2
    echo "Ошибка: PHP расширение zip не установлено">>/application/cache/sql_status
    echo "=== Реиндексация завершена с ошибками (нет расширения zip) ===" >>/application/cache/sql_status
    exit 1
fi

# Проверка прав на директорию flibusta
if [ ! -r "/application/flibusta" ]; then
    echo -e "${RED}✗ Нет прав на чтение директории /application/flibusta${NC}" >&2
    echo "Ошибка: Нет прав на чтение директории /application/flibusta">>/application/cache/sql_status
    echo "=== Реиндексация завершена с ошибками (нет прав на чтение) ===" >>/application/cache/sql_status
    exit 1
fi

# Проверяем наличие ZIP файлов перед запуском
echo -e "${YELLOW}Проверка наличия ZIP файлов в /application/flibusta...${NC}" >&2
echo "Проверка наличия ZIP файлов в /application/flibusta">>/application/cache/sql_status
zip_count=$(find /application/flibusta -maxdepth 1 -name "*.zip" -type f 2>/dev/null | wc -l)
echo -e "${GREEN}Найдено ZIP файлов: $zip_count${NC}" >&2
echo "Найдено ZIP файлов: $zip_count">>/application/cache/sql_status

if [ "$zip_count" -eq 0 ]; then
    echo -e "${YELLOW}⚠ Предупреждение: ZIP файлы не найдены в /application/flibusta${NC}" >&2
    echo "Предупреждение: ZIP файлы не найдены в /application/flibusta">>/application/cache/sql_status
    echo "Проверьте, что директория /application/flibusta содержит ZIP файлы" >&2
    echo "Проверьте, что директория /application/flibusta содержит ZIP файлы">>/application/cache/sql_status
    echo -e "${YELLOW}Реиндексация продолжится, но может не найти файлов для обработки${NC}" >&2
fi

# Запуск PHP скрипта с проверкой ошибок
echo -e "${YELLOW}Запуск PHP скрипта app_update_zip_list.php...${NC}" >&2
echo "Запуск PHP скрипта app_update_zip_list.php">>/application/cache/sql_status

# Создаем временный файл в cache/tmp для надежности
temp_output="/application/cache/tmp/reindex_output_$$.txt"
mkdir -p /application/cache/tmp
touch "$temp_output"
chmod 666 "$temp_output" 2>/dev/null || true
if php /application/tools/app_update_zip_list.php >"$temp_output" 2>&1; then
    php_exit_code=$?
    # Копируем вывод PHP скрипта в файл статуса
    cat "$temp_output" >>/application/cache/sql_status
    echo "PHP скрипт завершился с кодом: $php_exit_code" >&2
    echo "PHP скрипт завершился с кодом: $php_exit_code">>/application/cache/sql_status
    
    # Проверяем, что скрипт действительно что-то сделал
    if grep -q "Обработано файлов:" "$temp_output"; then
        processed_line=$(grep "Обработано файлов:" "$temp_output")
        echo "$processed_line" >&2
        # Записываем результат в файл статуса
        {
            echo "Индекс zip-файлов успешно создан"
            echo "=== Реиндексация завершена успешно ==="
        } >>/application/cache/sql_status
        # Принудительно синхронизируем запись на диск
        sync /application/cache/sql_status 2>/dev/null || true
        echo "Реиндексация завершена успешно" >&2
        rm -f "$temp_output"
        # Небольшая задержка для синхронизации
        sleep 0.5
        exit 0
    else
        echo "Предупреждение: PHP скрипт завершился, но не обработал файлы" >&2
        echo "Предупреждение: PHP скрипт завершился, но не обработал файлы">>/application/cache/sql_status
        cat "$temp_output" >&2
        rm -f "$temp_output"
        exit 0  # Все равно считаем успешным, если нет ошибок
    fi
else
    php_exit_code=$?
    # Копируем вывод PHP скрипта в файл статуса
    cat "$temp_output" >>/application/cache/sql_status
    echo "PHP скрипт завершился с кодом ошибки: $php_exit_code" >&2
    echo "PHP скрипт завершился с кодом ошибки: $php_exit_code">>/application/cache/sql_status
    # Записываем ошибку в файл статуса
    {
        echo "Ошибка при создании индекса zip-файлов"
        echo "=== Реиндексация завершена с ошибками ==="
    } >>/application/cache/sql_status
    # Принудительно синхронизируем запись на диск
    sync /application/cache/sql_status 2>/dev/null || true
    echo "Реиндексация завершена с ошибками" >&2
    cat "$temp_output" >&2
    rm -f "$temp_output"
    # Небольшая задержка для синхронизации
    sleep 0.5
    exit 1
fi


