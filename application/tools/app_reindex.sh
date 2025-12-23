#!/bin/sh
echo "Создание индекса zip-файлов">>/application/cache/sql_status
php /application/tools/app_update_zip_list.php

echo "">/application/cache/sql_status


