#!/bin/sh
# getsql.sh - Скачивание SQL файлов с Флибусты

# Определение директории для SQL файлов (можно переопределить через переменную окружения)
SQL_DIR="${FLIBUSTA_SQL_DIR:-FlibustaSQL}"

# Создание директории если не существует
mkdir -p "$SQL_DIR"

echo "Скачивание SQL файлов с Флибусты в $SQL_DIR..."

# Скачивание всех SQL файлов
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libavtor.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libtranslator.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libavtorname.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libbook.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libfilename.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libgenre.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libgenrelist.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libjoinedbooks.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.librate.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.librecs.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libseqname.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.libseq.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.reviews.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.b.annotations.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.a.annotations.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.b.annotations_pics.sql.gz
wget --directory-prefix="$SQL_DIR" -c -nc http://flibusta.is/sql/lib.a.annotations_pics.sql.gz

echo "SQL файлы скачаны в $SQL_DIR"

# Импорт SQL файлов (только если контейнеры запущены)
if docker ps | grep -q "flibusta.*php-fpm\|php-fpm.*flibusta"; then
    echo "Импорт SQL файлов в базу данных..."
    docker exec $(docker ps -q --filter "ancestor=flibusta_php-fpm" | head -1) /application/tools/app_import_sql.sh 2>/dev/null || \
    docker exec $(docker ps -q -f name=php-fpm | head -1) /application/tools/app_import_sql.sh 2>/dev/null || \
    echo "Контейнеры не запущены. Импорт будет выполнен при инициализации БД."
fi
