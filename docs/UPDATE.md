# Обновление Flibusta Local Mirror

Этот документ описывает процесс безопасного обновления локальной копии библиотеки Флибусты.

## Подготовка к обновлению

### 1. Резервное копирование

**Обязательно сделайте резервную копию перед обновлением!**

```bash
# Создание резервной копии базы данных
docker-compose exec postgres pg_dump -U flibusta flibusta > backup_$(date +%Y%m%d).sql

# Создание резервной копии конфигурации
cp .env .env.backup
cp secrets/flibusta_pwd.txt secrets/flibusta_pwd.txt.backup
```

### 2. Остановка контейнеров

```bash
docker-compose down
```

### 3. Создание новой версии

```bash
# Переход в директорию предыдущей версии
cd flibusta_old

# Создание архива данных
tar -czf flibusta_data_backup.tar.gz \
    FlibustaSQL/ \
    Flibusta.Net/ \
    cache/ \
    .env.backup \
    secrets/
```

## Автоматическое обновление

### Метод 1: Использование скрипта git

```bash
# Создание новой директории
cd ..
mkdir flibusta_new
cd flibusta_new

# Клонирование новой версии
git clone https://github.com/Zeed80/flibusta_test.git
cd flibusta_test

# Восстановление данных
cp -r ../flibusta_old/FlibustaSQL/ ./
cp -r ../flibusta_old/Flibusta.Net/ ./
cp -r ../flibusta_old/cache/ ./
cp ../flibusta_old/.env.backup ./
cp -r ../flibusta_old/secrets/ ./

# Запуск установки
./install.sh \
  --db-password "$(cat secrets/flibusta_pwd.txt)" \
  --port "$(grep FLIBUSTA_PORT .env | cut -d= -f2)" \
  --db-port "$(grep FLIBUSTA_DB_PORT .env | cut -d= -f2)" \
  --no-auto-init

# Запуск контейнеров
docker-compose up -d

# Веб-интерфейс для инициализации БД: http://localhost:$(grep FLIBUSTA_PORT .env | cut -d= -f2)
```

### Метод 2: Использование скрипта обновления (рекомендуется для production)

Скрипт `update_project.sh` автоматически сбрасывает все локальные изменения и обновляет проект до последней версии из репозитория:

```bash
# Остановка контейнеров
docker-compose down

# Переход в директорию проекта
cd flibusta_test

# Обновление проекта (автоматически перезаписывает локальные изменения)
chmod +x update_project.sh
./update_project.sh

# Обновление Docker образов
docker-compose pull
docker-compose build --no-cache

# Запуск контейнеров
docker-compose up -d
```

**Важно:** Скрипт автоматически сохраняет и восстанавливает `.env` и `secrets/`, чтобы не потерять конфигурацию.

### Метод 3: Ручное обновление с git pull

```bash
# Остановка контейнеров
docker-compose down

# Переход в директорию проекта
cd flibusta_test

# Сброс локальных изменений и обновление
git fetch origin
git reset --hard origin/main

# Обновление Docker образов
docker-compose pull
docker-compose build --no-cache

# Запуск контейнеров
docker-compose up -d
```

### Настройка автоматического сброса локальных изменений

Для production-серверов, где локальные изменения не должны сохраняться, можно настроить git:

```bash
# Настройка git для автоматического сброса при pull (опционально)
git config pull.rebase false
git config pull.ff only

# Или создать алиас для безопасного обновления
git config alias.update '!git fetch origin && git reset --hard origin/$(git branch --show-current)'
```

После этого можно использовать:
```bash
git update  # Вместо git pull
```

## Обновление базы данных

### Вариант 1: Через веб-интерфейс

1. Откройте в браузере: `http://localhost:27100/service/`
2. Нажмите кнопку "Обновить базу"
3. Подождите завершения процесса (может занять 10-30 минут)
4. Проверьте статистику на странице сервиса

### Вариант 2: Через командную строку

```bash
# Скачивание SQL файлов
./getsql.sh

# Скачивание обложек (опционально)
./getcovers.sh

# Импорт SQL файлов
docker-compose exec php-fpm sh /application/tools/app_import_sql.sh

# Сканирование ZIP-архивов
docker-compose exec php-fpm sh /application/tools/app_reindex.sh
```

## Обновление архивов книг

### Ежедневные обновления

```bash
# Скачивание ежедневных обновлений
./update_daily.sh
```

### Полное обновление архива

```bash
# Скачивание полного архива с Флибусты
wget http://flibusta.is/sql/f.n.fb2-000001-999999.zip -P Flibusta.Net/
```

## Обновление Docker образов

```bash
# Просмотр текущих версий
docker images | grep flibusta

# Обновление всех образов
docker-compose pull

# Пересборка без использования кэша
docker-compose build --no-cache
```

## Откат обновления

Если после обновления возникли проблемы:

### 1. Восстановление базы данных

```bash
# Остановка контейнеров
docker-compose down

# Удаление текущей базы данных
docker-compose down -v

# Восстановление из резервной копии
docker-compose up -d
docker-compose exec -T postgres psql -U flibusta -d flibusta < backup_20240101.sql
```

### 2. Восстановление конфигурации

```bash
cp .env.backup .env
cp secrets/flibusta_pwd.txt.backup secrets/flibusta_pwd.txt
docker-compose restart
```

### 3. Откат к предыдущей версии Git

```bash
git log --oneline
git checkout <commit_hash>
docker-compose down
docker-compose up -d
```

## Проверка после обновления

### Тестовый список проверки

1. ✅ Веб-интерфейс доступен: `http://localhost:27100`
2. ✅ OPDS каталог доступен: `http://localhost:27100/opds/`
3. ✅ Поиск работает (попробуйте найти "Толстой")
4. ✅ Скачивание книги работает (попробуйте скачать FB2)
5. ✅ Избранное работает (добавьте книгу в избранное)
6. ✅ Статистика корректна (проверьте раздел "Сервис")

### Команды для диагностики

```bash
# Проверка статуса контейнеров
docker-compose ps

# Проверка логов PHP-FPM
docker-compose logs --tail=50 php-fpm

# Проверка логов PostgreSQL
docker-compose logs --tail=50 postgres

# Проверка подключения к БД
docker-compose exec postgres pg_isready -U flibusta -d flibusta

# Проверка размера БД
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT pg_size_pretty(pg_database_size('flibusta'));"
```

## Решение проблем при обновлении

### Проблема: База данных не инициализируется

**Симптомы:**
- Сервис "Сервис" показывает процесс импорта, но он завис
- Кнопка "Обновить базу" не работает

**Решения:**
1. Проверьте наличие SQL файлов: `ls -la FlibustaSQL/`
2. Проверьте логи импорта: `docker-compose logs php-fpm`
3. Попробуйте ручной импорт: `./scripts/init_database.sh`
4. Проверьте свободное место на диске (минимум 10GB)

### Проблема: Ошибка версии PostgreSQL

**Симптомы:**
- Контейнер postgres не запускается
- Логи показывают ошибки несовместимости данных

**Решения:**
1. Убедитесь, что используете PostgreSQL 16
2. Если была более старая версия, создайте дамп и восстановите:
```bash
docker-compose exec postgres pg_dumpall -U flibusta > full_backup.sql
# Обновите Dockerfile до postgres:16
docker-compose down -v
docker-compose up -d
docker-compose exec -T postgres psql -U flibusta < full_backup.sql
```

### Проблема: Неверный пароль базы данных

**Симптомы:**
- Ошибка "FATAL: password authentication failed"
- Веб-интерфейс не загружается

**Решения:**
1. Проверьте пароль в `.env`: `grep FLIBUSTA_DBPASSWORD .env`
2. Проверьте пароль в secrets: `cat secrets/flibusta_pwd.txt`
3. Обновите пароль если нужно:
```bash
# Генерация нового пароля
openssl rand -base64 24 | tr -d "=+/" | cut -c1-32

# Обновление в .env
sed -i "s/FLIBUSTA_DBPASSWORD=.*/FLIBUSTA_DBPASSWORD=newpassword/" .env

# Обновление в secrets
echo "newpassword" > secrets/flibusta_pwd.txt
chmod 600 secrets/flibusta_pwd.txt

# Обновление в PostgreSQL
docker-compose exec -T postgres psql -U postgres -c "ALTER USER flibusta PASSWORD 'newpassword';"

# Перезапуск контейнеров
docker-compose restart
```

### Проблема: Медленная работа после обновления

**Симптомы:**
- Страницы загружаются медленно
- Поиск работает долго

**Решения:**
1. Очистите кэш: перейдите в "Сервис" → "Очистить кэш"
2. Пересоздайте индексы: в "Сервис" → "Сканирование ZIP"
3. Проверьте ресурсы сервера: `htop` или `top`
4. Настройте Nginx кэширование (уже включено)

## Плановое обновление

### Рекомендуемый интервал обновления

- **SQL файлы**: Ежемесячно
- **Обложки**: Раз в месяц
- **Ежедневные обновления**: Ежедневно (через cron)
- **Docker образы**: Ежемесячно
- **Зависимости PHP**: Ежемесячно

### Настройка автоматического обновления

Добавьте в crontab для ежедневных обновлений:

```bash
# Открытие crontab
crontab -e

# Добавление задачи для ежедневного обновления в 2:00 ночи
0 2 * * * cd /path/to/flibusta && ./update_daily.sh >> /var/log/flibusta_update.log 2>&1
```

## Контрольный список перед обновлением

- [ ] Резервная копия создана
- [ ] Контейнеры остановлены
- [ ] Новые файлы скачаны
- [ ] Конфигурация проверена
- [ ] Порты свободны (netstat -tuln | grep 27100)
- [ ] Достаточно места на диске (df -h)
- [ ] Резервный план тестирован

## Полезные команды

```bash
# Быстрая проверка здоровья системы
docker-compose ps && \
docker-compose exec postgres pg_isready -U flibusta -d flibusta && \
echo "Все системы работают"

# Полный отчет о состоянии
echo "=== Статус контейнеров ===" && \
docker-compose ps && \
echo -e "\n=== Использование диска ===" && \
df -h . && \
echo -e "\n=== Статистика базы данных ===" && \
docker-compose exec -T postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) as total_books FROM libbook;" && \
docker-compose exec -T postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) as active_books FROM libbook WHERE deleted='0';"
```

## Контакты и поддержка

При возникновении проблем после обновления:

1. Проверьте этот документ
2. Проверьте [README.md](../README.md)
3. Создайте issue на GitHub: https://github.com/Zeed80/flibusta_test/issues
4. Включите в issue следующие данные:
   - Версия Docker: `docker -v`
   - Версия Docker Compose: `docker-compose --version`
   - Версия PostgreSQL: `docker-compose exec postgres psql --version`
   - Логи ошибок: `docker-compose logs --tail=100`
   - Шаги воспроизведения
