#!/bin/sh
echo "Создание индекса zip-файлов">>/application/cache/sql_status

# Запуск PHP скрипта с проверкой ошибок
if php /application/tools/app_update_zip_list.php >>/application/cache/sql_status 2>&1; then
	echo "Индекс zip-файлов успешно создан">>/application/cache/sql_status
	echo "">/application/cache/sql_status
	exit 0
else
	echo "Ошибка при создании индекса zip-файлов">>/application/cache/sql_status
	exit 1
fi


