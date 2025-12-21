#!/bin/sh
# update_daily.sh - Обновление библиотеки (скачивание ежедневных обновлений с Флибусты)

URL="http://flibusta.is/daily/"
DEST_DIR="${FLIBUSTA_DATA_DIR:-./Flibusta.Net}"

# Создание директории если не существует
mkdir -p "$DEST_DIR"

echo "Обновление библиотеки из $URL..."
echo "Скачивание в $DEST_DIR..."

# Проверка наличия curl или wget
if command -v curl &> /dev/null; then
    curl -s "$URL" > page.html
elif command -v wget &> /dev/null; then
    wget -q -O page.html "$URL"
else
    echo "Ошибка: curl или wget не найдены"
    exit 1
fi

# Извлечение ссылок на файлы
grep -Eo 'href="f\.(fb2|n)\.[0-9\-]+\.zip"' page.html | sed 's/href="//;s/"//' > links.txt

# Подсчет файлов для скачивания
file_count=$(wc -l < links.txt | tr -d ' ')
echo "Найдено файлов для скачивания: $file_count"

if [ $file_count -eq 0 ]; then
    echo "Нет новых файлов для скачивания"
    rm -f page.html links.txt
    exit 0
fi

# Скачивание файлов
downloaded=0
while IFS= read -r file; do
    if [ -n "$file" ]; then
        echo "Скачивание: $file"
        if wget -c -P "$DEST_DIR" "$URL$file" 2>/dev/null; then
            downloaded=$((downloaded + 1))
        fi
    fi
done < links.txt

echo "Скачано файлов: $downloaded из $file_count"

# Очистка временных файлов
rm -f page.html links.txt

echo "Обновление библиотеки завершено"
