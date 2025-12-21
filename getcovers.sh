#!/bin/sh
# getcovers.sh - Скачивание обложек книг с Флибусты

# Определение директории для кэша (можно переопределить через переменную окружения)
CACHE_DIR="${FLIBUSTA_CACHE_DIR:-cache}"

# Создание директории если не существует
mkdir -p "$CACHE_DIR"

#zipchkcdm='zip -T'
zipchkcmd='7z -bsp0 -bso0 t'

# Проверка наличия 7z или zip
if ! command -v 7z &> /dev/null && ! command -v zip &> /dev/null; then
    echo "Предупреждение: 7z или zip не найдены. Проверка архивов будет пропущена."
    zipchkcmd="true"
fi

echo "Скачивание обложек книг с Флибусты в $CACHE_DIR..."

# Резервное копирование старых файлов
if [ -f "$CACHE_DIR/lib.a.attached.zip" ]; then
    echo "Резервное копирование старых файлов..."
    mv "$CACHE_DIR/lib.a.attached.zip" "$CACHE_DIR/lib.a.attached.zip.old" 2>/dev/null || true
fi
if [ -f "$CACHE_DIR/lib.b.attached.zip" ]; then
    mv "$CACHE_DIR/lib.b.attached.zip" "$CACHE_DIR/lib.b.attached.zip.old" 2>/dev/null || true
fi

# Скачивание архивов обложек
wget --directory-prefix="$CACHE_DIR" -c -nc https://flibusta.is/sql/lib.a.attached.zip
res=$?
if test "$res" != "0"; then
   echo "Ошибка wget при скачивании lib.a.attached.zip: $res"
   if [ -f "$CACHE_DIR/lib.a.attached.zip.old" ]; then
       echo "Восстановление lib.a.attached.zip из резервной копии"
       mv "$CACHE_DIR/lib.a.attached.zip.old" "$CACHE_DIR/lib.a.attached.zip" 2>/dev/null || true
   fi
fi

wget --directory-prefix="$CACHE_DIR" -c -nc https://flibusta.is/sql/lib.b.attached.zip
res=$?
if test "$res" != "0"; then
   echo "Ошибка wget при скачивании lib.b.attached.zip: $res"
   if [ -f "$CACHE_DIR/lib.b.attached.zip.old" ]; then
       echo "Восстановление lib.b.attached.zip из резервной копии"
       mv "$CACHE_DIR/lib.b.attached.zip.old" "$CACHE_DIR/lib.b.attached.zip" 2>/dev/null || true
   fi
fi

# Проверка целостности архивов
if [ -f "$CACHE_DIR/lib.a.attached.zip" ]; then
    eval $zipchkcmd "$CACHE_DIR/lib.a.attached.zip" > /dev/null 2>&1
    res=$?
    if test "$res" == "0"; then
       echo "Архив lib.a.attached.zip проверен успешно"
       rm -f "$CACHE_DIR/lib.a.attached.zip.old"
    else
       echo "Ошибка проверки lib.a.attached.zip. Восстановление из резервной копии..."
       if [ -f "$CACHE_DIR/lib.a.attached.zip.old" ]; then
           mv "$CACHE_DIR/lib.a.attached.zip.old" "$CACHE_DIR/lib.a.attached.zip" 2>/dev/null || true
       fi
    fi
fi

if [ -f "$CACHE_DIR/lib.b.attached.zip" ]; then
    eval $zipchkcmd "$CACHE_DIR/lib.b.attached.zip" > /dev/null 2>&1
    res=$?
    if test "$res" == "0"; then
       echo "Архив lib.b.attached.zip проверен успешно"
       rm -f "$CACHE_DIR/lib.b.attached.zip.old"
    else
       echo "Ошибка проверки lib.b.attached.zip. Восстановление из резервной копии..."
       if [ -f "$CACHE_DIR/lib.b.attached.zip.old" ]; then
           mv "$CACHE_DIR/lib.b.attached.zip.old" "$CACHE_DIR/lib.b.attached.zip" 2>/dev/null || true
       fi
    fi
fi

echo "Обложки скачаны в $CACHE_DIR"

