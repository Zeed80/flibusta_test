# Руководство по устранению неполадок - Flibusta Local Mirror

Это руководство поможет вам диагностировать и устранить проблемы, которые могут возникнуть при установке, настройке и использовании Flibusta Local Mirror.

## Содержание

1. [Установка](#установка)
2. [Docker и контейнеры](#docker-и-контейнеры)
3. [База данных](#база-данных)
4. [Веб-интерфейс и OPDS](#веб-интерфейс-и-opds)
5. [Производительность](#производительность)
6. [Сеть и порты](#сеть-и-порты)
7. [Безопасность](#безопасность)
8. [Данные и файлы](#данные-и-файлы)
9. [Автоматизация](#автоматизация)

---

## Установка

### Проблема: Docker не установлен

**Симптомы:**
```
bash: docker: command not found
```

**Диагностика:**
```bash
docker --version
```

**Решение:**

Для Ubuntu/Debian:
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
# Перезайдите в систему
```

Для CentOS/Rocky Linux:
```bash
sudo yum install -y yum-utils
sudo yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
sudo yum install -y docker-ce docker-ce-cli containerd.io
sudo systemctl start docker
sudo systemctl enable docker
sudo usermod -aG docker $USER
# Перезайдите в систему
```

### Проблема: Docker Compose не найден

**Симптомы:**
```
bash: docker-compose: command not found
```

**Диагностика:**
```bash
docker-compose --version
# или
docker compose version
```

**Решение:**

Для Docker Compose v2 (плагин):
```bash
sudo apt-get install docker-compose-plugin  # Ubuntu/Debian
sudo yum install docker-compose-plugin      # CentOS/Rocky Linux
```

Для Docker Compose v1 (отдельно):
```bash
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

### Проблема: Несовместимость версий Docker

**Симптомы:**
```
ERROR: The Docker Engine version is incompatible with this version of docker-compose
```

**Диагностика:**
```bash
docker --version
docker-compose --version
```

**Требования:**
- Docker: 20.10 или выше
- Docker Compose: 2.0 или выше

**Решение:**
Обновите Docker и Docker Compose до последних версий.

### Проблема: Недостаточно места на диске

**Симптомы:**
```
ERROR: no space left on device
```

**Диагностика:**
```bash
df -h
docker system df
```

**Решение:**

1. Очистите Docker:
```bash
docker system prune -a --volumes
```

2. Удалите старые Docker образы:
```bash
docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | grep flibusta
docker rmi <image_id>
```

3. Увеличьте раздел диска или используйте другой диск.

### Проблема: Ошибка TLS handshake timeout

**Симптомы:**
```
Error response from daemon: Get "https://registry-1.docker.io/v2/": net/http: request canceled while waiting for connection (Client.Timeout exceeded while awaiting headers)
```

**Диагностика:**
```bash
ping registry-1.docker.io
```

**Решение:**

1. Проверьте подключение к интернету
2. Настройте прокси для Docker (если используется):
```bash
sudo mkdir -p /etc/docker
sudo tee /etc/docker/daemon.json > /dev/null <<EOF
{
  "proxies": {
    "http-proxy": "http://proxy.example.com:8080",
    "https-proxy": "http://proxy.example.com:8080",
    "no-proxy": "localhost,127.0.0.1"
  }
}
EOF
sudo systemctl restart docker
```

3. Используйте альтернативные репозитории:
```bash
sudo tee /etc/docker/daemon.json > /dev/null <<EOF
{
  "registry-mirrors": [
    "https://docker.mirrors.ustc.edu.cn",
    "https://hub-mirror.c.163.com"
  ]
}
EOF
sudo systemctl restart docker
```

---

## Docker и контейнеры

### Проблема: Контейнеры не запускаются

**Симптомы:**
```
ERROR: for postgres  Cannot start service postgres: driver failed programming external connectivity
```

**Диагностика:**
```bash
docker-compose ps
docker-compose logs postgres
docker-compose logs php-fpm
docker-compose logs webserver
```

**Возможные причины и решения:**

1. **Порт занят:**
```bash
sudo netstat -tuln | grep 27100
sudo netstat -tuln | grep 27101

# Измените порт в .env
nano .env
# FLIBUSTA_PORT=8080
# FLIBUSTA_DB_PORT=8081

docker-compose restart
```

2. **Недостаточно памяти:**
```bash
free -h
# Увеличьте Swap или RAM
```

3. **Неверный конфигурационный файл:**
```bash
# Проверьте синтаксис docker-compose.yml
docker-compose config
```

### Проблема: Контейнер зависает

**Симптомы:**
Контейнер показывается как "Up", но не отвечает на запросы.

**Диагностика:**
```bash
docker-compose ps
docker-compose exec postgres pg_isready
docker-compose logs --tail=100 postgres
```

**Решение:**

1. Перезапустите контейнер:
```bash
docker-compose restart postgres
```

2. Если не помогло - остановите и запустите заново:
```bash
docker-compose down
docker-compose up -d
```

3. Проверьте использование ресурсов:
```bash
docker stats
```

### Проблема: Контейнер постоянно перезапускается

**Симптомы:**
```
Restarting (1) X seconds ago
```

**Диагностика:**
```bash
docker-compose logs --tail=100 <container_name>
```

**Возможные причины и решения:**

1. **Ошибка в конфигурации:**
```bash
# Проверьте .env
cat .env
```

2. **Отсутствует секрет:**
```bash
ls -la secrets/
# Создайте секрет
echo "your_password" > secrets/flibusta_pwd.txt
chmod 600 secrets/flibusta_pwd.txt
sudo chown 82:82 secrets/flibusta_pwd.txt
```

3. **Проблема с PostgreSQL:**
```bash
# Проверьте логи PostgreSQL
docker-compose logs postgres

# Возможно, нужно пересоздать volume
docker-compose down -v
docker-compose up -d
```

---

## База данных

### Проблема: Невозможно подключиться к базе данных

**Симптомы:**
```
SQLSTATE[08006] [7] could not connect to server: Connection refused
```

**Диагностика:**
```bash
docker-compose exec postgres pg_isready -U flibusta -d flibusta
docker-compose logs postgres
```

**Возможные причины и решения:**

1. **Контейнер PostgreSQL не готов:**
```bash
# Подождите несколько секунд
docker-compose logs -f postgres
# Дождитесь сообщения: "database system is ready to accept connections"
```

2. **Неверный пароль:**
```bash
# Проверьте пароль
cat secrets/flibusta_pwd.txt
grep FLIBUSTA_DBPASSWORD .env

# Они должны совпадать
```

3. **База данных не инициализирована:**
```bash
# Инициализируйте базу данных
docker-compose exec php-fpm bash /application/tools/app_import_sql.sh
```

### Проблема: База данных пуста

**Симптомы:**
Веб-интерфейс показывает "Нет книг" или поиск не возвращает результатов.

**Диагностика:**
```bash
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) FROM libbook;"
```

**Решение:**

1. Проверьте наличие SQL файлов:
```bash
ls -la FlibustaSQL/
```

2. Импортируйте SQL файлы:
```bash
docker-compose exec php-fpm bash /application/tools/app_import_sql.sh
```

3. Или через веб-интерфейс:
   - Откройте http://localhost:27100/service/
   - Нажмите "Обновить базу"

### Проблема: Кнопка "Обновить базу" не работает в веб-интерфейсе

**Симптомы:**
При нажатии на кнопку "Обновить базу" на странице http://localhost:27100/service/ ничего не происходит, появляется ошибка, или на странице отображается красное предупреждение.

**Диагностика:**
```bash
# Проверьте наличие предупреждений на странице
curl http://localhost:27100/service/

# Проверьте права на скрипты
docker-compose exec -T php-fpm ls -la /application/tools/
```

**Возможные причины и решения:**

1. **Скрипты не имеют прав на выполнение (наиболее частая причина):**
```bash
# Установка прав на выполнение для всех скриптов
docker-compose exec -T php-fpm sh -c "chmod +x /application/tools/*.sh /application/tools/app_topg"

# Проверка прав
docker-compose exec -T php-fpm ls -la /application/tools/
# Все .sh файлы и app_topg должны иметь права -rwxr-xr-x
```

2. **Опечатка в shebang файла app_topg:**
```bash
# Проверьте первую строку файла
docker-compose exec php-fpm head -1 /application/tools/app_topg
# Должно быть: #!/bin/sh
# Если вы видите: #/bin/sh (без !) - файл нужно исправить
```

3. **Проверьте файл статуса импорта:**
```bash
# Файл статуса теперь находится в cache директории
docker-compose exec php-fpm cat /application/cache/sql_status

# Проверьте права на директорию cache
docker-compose exec php-fpm ls -la /application/cache/
```

4. **Проверьте права на директории:**
```bash
# Проверьте права на запись в директории
docker-compose exec php-fpm ls -la /application/sql/
docker-compose exec php-fpm ls -la /application/cache/
docker-compose exec php-fpm ls -la /application/cache/tmp/
```

5. **Проверьте логи PHP-FPM:**
```bash
# Просмотр последних ошибок
docker-compose logs php-fpm --tail=100
```

6. **Если кнопка серая и неактивная:**
Это значит, что уже запущен процесс импорта. Дождитесь его завершения или выполните:
```bash
# Проверьте, не запущен ли процесс импорта
docker-compose exec php-fpm ps aux | grep -E 'app_import|app_reindex|app_topg'
```

6. **Проверьте доступность скриптов в веб-интерфейсе:**
Откройте http://localhost:27100/service/ и проверьте наличие красного предупреждения. Если оно есть, выполните команду из п.1.

7. **Убедитесь, что права на запись есть во всех необходимых директориях:**
```bash
# Создайте директории, если их нет
docker-compose exec php-fpm sh -c "mkdir -p /application/sql/psql /application/cache/tmp"

# Установите права
docker-compose exec php-fpm sh -c "chmod 777 /application/cache/tmp /application/sql/psql 2>/dev/null || true"
```

**Примечание:** Директория `/application/cache/tmp` используется для временных файлов при конвертации SQL. Без прав на запись импорт не выполнится.

**Предупреждение на странице сервиса:**
Если на странице отображается красное предупреждение с текстом вроде:
- "Скрипт импорта не найден"
- "Скрипт не имеет прав на выполнение"
- "Скрипт конвертации SQL не найден"

Это означает что PHP не может выполнить необходимые скрипты. Исправьте права доступа, как описано в п.1.

### Проблема: Ошибка при импорте SQL файлов

**Симптомы:**
```
ERROR: relation "libbook" does not exist
```

**Диагностика:**
```bash
docker-compose logs php-fpm | tail -100
```

**Возможные причины и решения:**

1. **Файлы повреждены:**
```bash
# Проверьте целостность файлов
file FlibustaSQL/*.sql*
# Переименуйте .sql.gz в .sql
gunzip FlibustaSQL/*.sql.gz
```

2. **Неправильный порядок импорта:**
Импортируйте файлы в правильном порядке:
```bash
docker-compose exec -T postgres psql -U flibusta -d flibusta < FlibustaSQL/lib.libavtor.sql
docker-compose exec -T postgres psql -U flibusta -d flibusta < FlibustaSQL/lib.libbook.sql
# ... остальные файлы
```

3. **Скрипт инициализации:**
```bash
./scripts/init_database.sh
```

### Проблема: Медленный поиск

**Симптомы:**
Поиск занимает несколько секунд.

**Диагностика:**
```bash
# Проверьте наличие индексов
docker-compose exec postgres psql -U flibusta -d flibusta -c "\d libbook"
```

**Решение:**

1. Создайте полнотекстовый индекс:
```bash
docker-compose exec postgres psql -U flibusta -d flibusta <<EOF
CREATE INDEX IF NOT EXISTS idx_libbook_title ON libbook USING gin(to_tsvector('russian', title));
CREATE INDEX IF NOT EXISTS idx_libavtorname_name ON libavtorname USING gin(to_tsvector('russian', name));
EOF
```

2. Очистите кэш:
```bash
docker-compose exec php-fpm rm -rf /application/cache/authors/* /application/cache/covers/*
```

3. Используйте SSD для данных PostgreSQL.

---

## Веб-интерфейс и OPDS

### Проблема: Сайт недоступен

**Симптомы:**
```
curl: (7) Failed to connect to localhost port 27100: Connection refused
```

**Диагностика:**
```bash
docker-compose ps
docker-compose logs webserver
curl -I http://localhost:27100
```

**Возможные причины и решения:**

1. **Контейнер не запущен:**
```bash
docker-compose up -d
```

2. **Неверный порт:**
```bash
# Проверьте порт
grep FLIBUSTA_PORT .env
# Используйте правильный порт
curl http://localhost:<correct_port>
```

3. **Проблема с PHP-FPM:**
```bash
docker-compose logs php-fpm
docker-compose restart php-fpm
```

### Проблема: Ошибка 502 Bad Gateway

**Симптомы:**
Веб-интерфейс показывает "502 Bad Gateway".

**Диагностика:**
```bash
docker-compose logs webserver
docker-compose logs php-fpm
```

**Возможные причины и решения:**

1. **PHP-FPM недоступен:**
```bash
docker-compose restart php-fpm
```

2. **Проблема с конфигурацией Nginx:**
```bash
# Проверьте конфигурацию
docker-compose exec webserver nginx -t
# Перезагрузите Nginx
docker-compose restart webserver
```

3. **Недостаточно памяти:**
```bash
free -h
# Увеличьте RAM или Swap
```

### Проблема: OPDS не работает

**Симптомы:**
OPDS клиенты не могут подключиться.

**Диагностика:**
```bash
curl -v http://localhost:27100/opds/
docker-compose logs webserver
```

**Возможные причины и решения:**

1. **Неверный URL:**
Убедитесь, что используете правильный URL: `http://localhost:27100/opds/` (с косой чертой в конце)

2. **Проблема с версией OPDS:**
Попробуйте принудительно указать версию:
```
http://localhost:27100/opds/?opds_version=1.0
http://localhost:27100/opds/?opds_version=1.2
```

3. **Проблема с PHP:**
```bash
docker-compose logs php-fpm
docker-compose restart php-fpm
```

### Проблема: Изображения не отображаются

**Симптомы:**
Обложки книг не загружаются.

**Диагностика:**
```bash
ls -la cache/covers/
docker-compose logs php-fpm | grep -i cover
```

**Возможные причины и решения:**

1. **Обложки не скачаны:**
```bash
./getcovers.sh
```

2. **Архив обложек поврежден:**
```bash
# Удалите старые архивы
rm -f cache/lib.a.attached.zip cache/lib.b.attached.zip
# Скачайте заново
./getcovers.sh
```

3. **Проблемы с правами доступа:**
```bash
chmod -R 777 cache/
```

---

## Производительность

### Проблема: Сайт работает медленно

**Симптомы:**
Страницы загружаются дольше 5 секунд.

**Диагностика:**
```bash
# Проверка времени отклика
time curl http://localhost:27100 > /dev/null

# Проверка ресурсов
docker stats

# Проверка размера БД
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT pg_size_pretty(pg_database_size('flibusta'));"
```

**Возможные решения:**

1. **Используйте SSD:**
Переместите данные на SSD для ускорения.

2. **Очистите кэш:**
```bash
docker-compose exec php-fpm rm -rf /application/cache/authors/* /application/cache/covers/*
```

3. **Настройте PostgreSQL:**
```bash
docker-compose exec postgres nano /var/lib/postgresql/data/postgresql.conf
```

Добавьте:
```
shared_buffers = 256MB
effective_cache_size = 1GB
maintenance_work_mem = 128MB
```

Перезапустите контейнер:
```bash
docker-compose restart postgres
```

4. **Увеличьте ресурсы контейнеров:**
В `docker-compose.yml` добавьте:
```yaml
deploy:
    resources:
        limits:
            cpus: '2'
            memory: 2G
```

### Проблема: Высокое использование CPU

**Симптомы:**
Процессор постоянно загружен на 100%.

**Диагностика:**
```bash
docker stats
top
```

**Возможные причины и решения:**

1. **Импорт базы данных:**
Это нормально во время импорта. Дождитесь завершения.

2. **Много посетителей:**
Настройте балансировку нагрузки или увеличьте ресурсы.

3. **Неэффективные запросы:**
```bash
# Проверьте активные запросы
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT * FROM pg_stat_activity;"
```

---

## Сеть и порты

### Проблема: Порт занят

**Симптомы:**
```
ERROR: for webserver  Cannot start service webserver: Bind for 0.0.0.0:27100 failed: port is already allocated
```

**Диагностика:**
```bash
sudo netstat -tuln | grep 27100
sudo lsof -i :27100
```

**Решение:**

1. **Измените порт:**
```bash
nano .env
# FLIBUSTA_PORT=8080
docker-compose restart
```

2. **Остановите процесс, занимающий порт:**
```bash
# Найдите PID
sudo lsof -i :27100
# Остановите процесс
sudo kill -9 <PID>
```

### Проблема: Недоступен с других устройств

**Симптомы:**
Доступен только с localhost, но не с других устройств в сети.

**Диагностика:**
```bash
sudo netstat -tuln | grep 27100
```

Если видите `127.0.0.1:27100` - сервер слушает только локально.

**Решение:**

1. **Измените привязку порта в docker-compose.yml:**
```yaml
ports:
    - '0.0.0.0:27100:80'  # Слушает на всех интерфейсах
```

2. **Настройте firewall:**
```bash
sudo ufw allow 27100/tcp
```

3. **Проверьте IP адрес сервера:**
```bash
ip addr show
# Используйте этот IP для подключения с других устройств
# http://<server_ip>:27100
```

---

## Безопасность

### Проблема: Предупреждение о небезопасном пароле

**Симптомы:**
```
⚠ Пароль БД не настроен или использует значение по умолчанию
```

**Решение:**

1. Сгенерируйте новый пароль:
```bash
openssl rand -base64 24 | tr -d "=+/" | cut -c1-32
```

2. Обновите .env:
```bash
sed -i "s/FLIBUSTA_DBPASSWORD=.*/FLIBUSTA_DBPASSWORD=NEW_PASSWORD/" .env
```

3. Обновите секрет:
```bash
echo "NEW_PASSWORD" > secrets/flibusta_pwd.txt
chmod 600 secrets/flibusta_pwd.txt
```

4. Обновите PostgreSQL:
```bash
docker-compose exec -T postgres psql -U postgres -c "ALTER USER flibusta PASSWORD 'NEW_PASSWORD';"
```

5. Перезапустите контейнеры:
```bash
docker-compose restart
```

### Проблема: Доступ к базе данных извне

**Симптомы:**
PostgreSQL доступен из сети.

**Решение:**

Ограничьте доступ в `docker-compose.yml`:
```yaml
ports:
    - '127.0.0.1:27101:5432'  # Только локально
```

Или полностью уберите порт:
```yaml
ports:
    # - '${FLIBUSTA_DB_PORT:-27101}:5432'  # Закомментировано
```

---

## Данные и файлы

### Проблема: SQL файлы не находятся

**Симптомы:**
```
✗ SQL файлы не найдены в ./FlibustaSQL
```

**Диагностика:**
```bash
ls -la FlibustaSQL/
```

**Решение:**

1. **Создайте директорию:**
```bash
mkdir -p FlibustaSQL
```

2. **Скачайте файлы:**
```bash
./getsql.sh
```

3. **Или скопируйте из другого места:**
```bash
cp /path/to/sql/*.sql FlibustaSQL/
```

### Проблема: Архивы книг не находятся

**Симптомы:**
Поиск возвращает результаты, но скачать книгу нельзя.

**Диагностика:**
```bash
ls -la Flibusta.Net/
```

**Решение:**

1. **Создайте директорию:**
```bash
mkdir -p Flibusta.Net
```

2. **Скачайте архивы:**
```bash
# Полный архив (займет много времени)
wget http://flibusta.is/sql/f.n.fb2-000001-999999.zip -P Flibusta.Net/

# Или ежедневные обновления
./update_daily.sh
```

### Проблема: Неверные права доступа

**Симптомы:**
```
✗ Нет прав на запись в директорию: cache
```

**Диагностика:**
```bash
ls -la cache/
ls -la secrets/
```

**Решение:**

1. **Исправьте права для кэша:**
```bash
chmod -R 777 cache/
```

2. **Исправьте права для секретов:**
```bash
chmod 700 secrets/
chmod 600 secrets/flibusta_pwd.txt
sudo chown 82:82 secrets/flibusta_pwd.txt
```

### Важное примечание об удалении данных

> ⚠️ **Книги и SQL файлы имеют разное поведение при удалении!**

По соображениям безопасности и удобства:
- **Книги** (`Flibusta.Net/`) - **НИКОГДА** не удаляются автоматически
- **SQL файлы** (`FlibustaSQL/`) - удаляются только с опцией `--remove-sql`
- **Кэш** (`cache/`) - удаляется с опцией `--remove-cache`

Это сделано намеренно, так как:
1. Книги часто занимают много места (десятки ГБ)
2. Они могут находиться на других дисках или внешних носителях
3. SQL файлы могут быть на других дисках
4. Автоматическое удаление может привести к потере данных
5. **Пользователь должен иметь полный контроль над удалением**

**Если вы хотите удалить книги или SQL файлы:**

```bash
# Удаление книг (вручную, по вашему усмотрению)
# ⚠️  Это необратимая операция!
rm -rf Flibusta.Net/

# Удаление SQL файлов (вручную, по вашему усмотрению)
# ⚠️  Это необратимая операция!
rm -rf FlibustaSQL/

# Или через скрипт (удалит только SQL файлы, но НЕ книги)
./uninstall.sh --remove-sql

# Скрипт с опцией --all удалит SQL файлы, но НЕ книги
./uninstall.sh --all
```

**При использовании скрипта удаления:**
```bash
# Скрипт uninstall.sh НИКОГДА не удаляет книги!
# Книги (Flibusta.Net) всегда остаются

# Для удаления SQL файлов используйте опцию --remove-sql
./uninstall.sh --remove-sql

# Или используйте --all (удалит всё кроме книг)
./uninstall.sh --all

# После удаления скрипт напомнит вам:
# ⚠️  Для ручного удаления книг:
#     rm -rf Flibusta.Net
```

Это предотвращает случайное удаление больших объемов данных и дает пользователю полный контроль над тем, что и когда удалять.

---

## Автоматизация

### Проблема: Cron задачи не выполняются

**Симптомы:**
Автоматические обновления не работают.

**Диагностика:**
```bash
crontab -l
grep cron /var/log/syslog
```

**Возможные причины и решения:**

1. **Неверный путь в crontab:**
Используйте абсолютные пути:
```bash
0 2 * * * cd /absolute/path/to/flibusta && ./update_daily.sh >> /var/log/flibusta_update.log 2>&1
```

2. **Скрипт не имеет прав на выполнение:**
```bash
chmod +x update_daily.sh
```

3. **Проблемы с окружением:**
Добавьте в crontab:
```bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
```

### Проблема: Автоматическое обновление не скачивает файлы

**Симптомы:**
`update_daily.sh` выполняется, но файлы не скачиваются.

**Диагностика:**
```bash
./update_daily.sh
cat /var/log/flibusta_update.log
```

**Возможные причины и решения:**

1. **Недоступен сайт Флибусты:**
```bash
ping flibusta.is
curl -I http://flibusta.is
```

2. **Нет новых файлов:**
Это нормально. Скрипт скачивает только новые файлы.

3. **Проблемы с сетью:**
Проверьте подключение к интернету и настройки firewall.

---

## Дополнительная диагностика

### Полная проверка состояния системы

```bash
# Статус контейнеров
docker-compose ps

# Логи всех сервисов
docker-compose logs --tail=50

# Использование ресурсов
docker stats --no-stream

# Проверка диска
df -h

# Проверка памяти
free -h

# Проверка портов
sudo netstat -tuln | grep 2710

# Проверка базы данных
docker-compose exec postgres pg_isready -U flibusta -d flibusta
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) FROM libbook;"

# Проверка веб-интерфейса
curl -I http://localhost:27100
curl -I http://localhost:27100/opds/
```

### Создание отчета об ошибке

Если проблема не решена, создайте отчет:

```bash
# Создайте директорию для отчета
mkdir -p ~/flibusta_debug
cd ~/flibusta_debug

# Соберите информацию
{
  echo "=== Версии ==="
  docker --version
  docker-compose --version
  docker-compose exec postgres psql --version
  
  echo -e "\n=== Статус контейнеров ==="
  docker-compose ps
  
  echo -e "\n=== Логи ==="
  docker-compose logs --tail=100 > docker_logs.txt
  
  echo -e "\n=== Конфигурация .env ==="
  grep -v "PASSWORD" ~/.env 2>/dev/null || cat ~/.env
  
  echo -e "\n=== Статистика БД ==="
  docker-compose exec -T postgres psql -U flibusta -d flibusta -c "SELECT COUNT(*) as total_books FROM libbook;"
  
  echo -e "\n=== Использование диска ==="
  df -h
  
  echo -e "\n=== Использование памяти ==="
  free -h
} > debug_report.txt 2>&1
```

Прикрепите `debug_report.txt` и `docker_logs.txt` к вашему Issue на GitHub.

---

## Получение помощи

Если вы не нашли решение своей проблемы:

1. Проверьте [FAQ](FAQ.md)
2. Проверьте [README.md](../README.md)
3. Создайте Issue на GitHub: https://github.com/Zeed80/flibusta_test/issues
4. Убедитесь, что включили в Issue:
   - Версию Docker
   - Версию Docker Compose
   - Логи ошибок
   - Шаги воспроизведения
   - Результаты диагностики
