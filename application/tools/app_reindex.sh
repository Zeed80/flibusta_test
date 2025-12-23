#!/bin/sh
# Выводим информацию о запуске для диагностики
echo "Запуск скрипта app_reindex.sh" >&2

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

# Проверяем наличие ZIP файлов перед запуском
echo "Проверка наличия ZIP файлов в /application/flibusta" >&2
zip_count=$(find /application/flibusta -maxdepth 1 -name "*.zip" -type f 2>/dev/null | wc -l)
echo "Найдено ZIP файлов: $zip_count" >&2
echo "Найдено ZIP файлов: $zip_count">>/application/cache/sql_status

if [ "$zip_count" -eq 0 ]; then
    echo "Предупреждение: ZIP файлы не найдены в /application/flibusta" >&2
    echo "Предупреждение: ZIP файлы не найдены в /application/flibusta">>/application/cache/sql_status
    echo "Проверьте, что директория /application/flibusta содержит ZIP файлы" >&2
    echo "Проверьте, что директория /application/flibusta содержит ZIP файлы">>/application/cache/sql_status
fi

# Запуск PHP скрипта с проверкой ошибок
echo "Запуск PHP скрипта app_update_zip_list.php" >&2
# Запускаем PHP скрипт и сохраняем вывод во временный файл для анализа
temp_output=$(mktemp)
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


