#!/bin/sh
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

# Переходим в рабочую директорию для надежности
cd /application

# Запуск PHP скрипта с проверкой ошибок
if php /application/tools/app_update_zip_list.php >>/application/cache/sql_status 2>&1; then
	echo "Индекс zip-файлов успешно создан">>/application/cache/sql_status
	echo "=== Реиндексация завершена успешно ===" >>/application/cache/sql_status
	echo "">/application/cache/sql_status
	exit 0
else
	echo "Ошибка при создании индекса zip-файлов">>/application/cache/sql_status
	echo "=== Реиндексация завершена с ошибками ===" >>/application/cache/sql_status
	exit 1
fi


