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

# Запуск PHP скрипта с проверкой ошибок
echo "Запуск PHP скрипта app_update_zip_list.php" >&2
if php /application/tools/app_update_zip_list.php >>/application/cache/sql_status 2>&1; then
    php_exit_code=$?
    echo "PHP скрипт завершился с кодом: $php_exit_code" >&2
    echo "Индекс zip-файлов успешно создан">>/application/cache/sql_status
    echo "=== Реиндексация завершена успешно ===" >>/application/cache/sql_status
    echo "Реиндексация завершена успешно" >&2
    exit 0
else
    php_exit_code=$?
    echo "PHP скрипт завершился с кодом ошибки: $php_exit_code" >&2
    echo "Ошибка при создании индекса zip-файлов">>/application/cache/sql_status
    echo "=== Реиндексация завершена с ошибками ===" >>/application/cache/sql_status
    echo "Реиндексация завершена с ошибками" >&2
    exit 1
fi


