# Руководство по установке Flibusta Local Mirror

## Содержание

1. [Требования](#требования)
2. [Быстрый старт](#быстрый-старт)
3. [Автоматическая установка (CLI)](#автоматическая-установка-cli)
4. [Консольная установка (TUI)](#консольная-установка-tui)
5. [Быстрая установка](#быстрая-установка)
6. [Ручная установка](#ручная-установка)
7. [Проверка установки](#проверка-установки)
8. [Устранение проблем](#устранение-проблем)
9. [FAQ](#faq)

## Требования

### Системные требования

- **ОС**: Ubuntu 18.04+ или Debian 10+
- **Docker**: версия 20.10 или выше
- **Docker Compose**: версия 2.0 или выше
- **Свободное место**: минимум 2 GB (рекомендуется 10+ GB)
- **Память**: минимум 2 GB RAM
- **Порты**: 27100 (веб-сервер), 27101 (база данных) должны быть свободны

### Необходимые данные

- Файлы дампа базы данных Флибусты (*.sql или *.sql.gz)
- Архивы книг Флибусты (*.zip)

### Дополнительные пакеты (опционально)

- `dialog` или `whiptail` - для TUI установки
- `curl` - для проверки установки

## Быстрый старт

Самый простой способ установки:

```bash
# 1. Клонирование репозитория
git clone https://github.com/Zeed80/flibusta_test.git
cd flibusta_test

# 2. Запуск установки
chmod +x install.sh
./install.sh
```

Скрипт автоматически:
- Проверит все требования
- Создаст необходимые директории
- Сгенерирует безопасный пароль
- Настроит конфигурацию
- Запустит контейнеры
- Инициализирует базу данных

## Автоматическая установка (CLI)

### Интерактивная установка

```bash
./install.sh
```

Скрипт задаст вопросы:
1. Пароль базы данных (можно сгенерировать автоматически)
2. Порт веб-сервера (по умолчанию: 27100)
3. Порт базы данных (по умолчанию: 27101)
4. Путь к SQL файлам (по умолчанию: ./FlibustaSQL)
5. Путь к архивам книг (по умолчанию: ./Flibusta.Net)
6. Автоматическая инициализация БД (по умолчанию: да)

### Установка с параметрами

```bash
./install.sh \
  --db-password "my_secure_password" \
  --port 8080 \
  --db-port 8081 \
  --sql-dir /path/to/sql \
  --books-dir /path/to/books \
  --auto-init
```

### Параметры командной строки

- `--db-password PASSWORD` - пароль базы данных
- `--port PORT` - порт веб-сервера
- `--db-port PORT` - порт базы данных
- `--sql-dir DIR` - путь к папке с SQL файлами
- `--books-dir DIR` - путь к папке с архивами книг
- `--auto-init` - автоматическая инициализация БД
- `--no-auto-init` - пропустить инициализацию БД
- `--skip-checks` - пропустить проверки требований
- `--quick` - быстрый режим (минимальные вопросы)

## Консольная установка (TUI)

Для удобного выбора папок и настроек используйте TUI-установщик:

```bash
chmod +x install-tui.sh
./install-tui.sh
```

### Возможности TUI

- **Главное меню** - навигация по разделам
- **Выбор папок** - визуальный файловый менеджер для выбора SQL файлов и архивов книг
- **Форма настроек** - ввод портов и пароля
- **Дополнительные опции** - чекбоксы для различных настроек
- **Прогресс-бар** - отображение прогресса установки

### Требования для TUI

Установите `dialog` или `whiptail`:

```bash
sudo apt-get install dialog
# или
sudo apt-get install whiptail
```

Скрипт автоматически определит доступный инструмент.

## Быстрая установка

Для опытных пользователей:

```bash
./quick_start.sh \
  --sql-dir /path/to/sql \
  --books-dir /path/to/books \
  --db-password "secure_password" \
  --auto-init
```

### Параметры quick_start.sh

- `--sql-dir DIR` - путь к SQL файлам (обязательно)
- `--books-dir DIR` - путь к архивам книг (обязательно)
- `--db-password PASS` - пароль БД (обязательно)
- `--port PORT` - порт веб-сервера
- `--db-port PORT` - порт базы данных
- `--auto-init` - автоматическая инициализация БД
- `--skip-checks` - пропустить проверки
- `--quiet` - тихий режим

## Ручная установка

Для продвинутых пользователей, которые хотят полный контроль:

### 1. Клонирование репозитория

```bash
git clone https://github.com/Zeed80/flibusta_test.git
cd flibusta_test
```

### 2. Создание директорий

```bash
mkdir -p FlibustaSQL Flibusta.Net cache secrets
mkdir -p cache/authors cache/covers cache/tmp cache/opds
chmod 755 FlibustaSQL Flibusta.Net
chmod 777 cache cache/authors cache/covers cache/tmp cache/opds
chmod 700 secrets
```

### 3. Настройка пароля

```bash
echo "your_secure_password" > secrets/flibusta_pwd.txt
chmod 600 secrets/flibusta_pwd.txt
```

### 4. Размещение данных

```bash
# SQL файлы
cp /path/to/sql/* FlibustaSQL/

# Архивы книг
cp /path/to/books/* Flibusta.Net/
```

### 5. Создание .env файла

```bash
cp .env.example .env
# Отредактируйте .env файл
nano .env
```

### 6. Запуск контейнеров

```bash
docker-compose build
docker-compose up -d
```

### 7. Инициализация БД

```bash
docker-compose exec php-fpm bash /application/scripts/init_database.sh
```

## Проверка установки

После установки проверьте работоспособность:

```bash
./scripts/verify_installation.sh
```

Скрипт проверит:
- Статус контейнеров
- Доступность веб-интерфейса
- Доступность OPDS каталога
- Подключение к базе данных
- Наличие данных в БД
- Работоспособность поиска

## Устранение проблем

### Проблемы с Docker

**Docker не установлен:**
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
# Перезайдите в систему
```

**Docker Compose не найден:**
```bash
# Для Docker Compose v2 (плагин)
sudo apt-get update
sudo apt-get install docker-compose-plugin

# Или установите отдельно
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
```

### Проблемы с портами

**Порт занят:**
```bash
# Проверка занятых портов
sudo ss -tuln | grep 27100
sudo ss -tuln | grep 27101

# Измените порты в .env файле
nano .env
# FLIBUSTA_PORT=8080
# FLIBUSTA_DB_PORT=8081
```

### Проблемы с правами доступа

```bash
# Установка прав для кэша
sudo chmod -R 777 cache

# Установка прав для secrets
sudo chmod 700 secrets
sudo chmod 600 secrets/flibusta_pwd.txt
```

### Проблемы с инициализацией БД

**БД не инициализируется:**
```bash
# Проверка логов
docker-compose logs postgres
docker-compose logs php-fpm

# Ручной запуск инициализации
docker-compose exec php-fpm bash /application/scripts/init_database.sh
```

### Проблемы с TUI

**dialog/whiptail не найден:**
```bash
sudo apt-get install dialog
# или используйте CLI установку: ./install.sh
```

## FAQ

### Как изменить порты после установки?

1. Отредактируйте `.env` файл
2. Перезапустите контейнеры: `docker-compose restart`

### Как обновить базу данных?

1. Разместите новые SQL файлы в `FlibustaSQL/`
2. Разместите новые архивы в `Flibusta.Net/`
3. Запустите инициализацию: `docker-compose exec php-fpm bash /application/scripts/init_database.sh`

### Как удалить установку?

```bash
./uninstall.sh
```

Опции:
- `--remove-images` - удалить Docker образы
- `--remove-volumes` - удалить volumes (БД будет удалена!)
- `--remove-config` - удалить .env и secrets
- `--remove-data` - удалить книги и SQL файлы
- `--all` - удалить всё

### Где хранится пароль БД?

Пароль хранится в `secrets/flibusta_pwd.txt` и в `.env` файле.

### Как проверить требования перед установкой?

```bash
./scripts/check_requirements.sh
```

### Как валидировать конфигурацию?

```bash
./scripts/validate_config.sh
```

### Можно ли установить без интернета?

Да, если у вас уже есть Docker образы. Скрипт установки попытается загрузить образы из интернета, но если они уже есть локально, установка пройдет.

### Как изменить пароль БД после установки?

1. Измените пароль в `.env` файле
2. Измените пароль в `secrets/flibusta_pwd.txt`
3. Измените пароль в PostgreSQL: `docker-compose exec postgres psql -U postgres -c "ALTER USER flibusta PASSWORD 'new_password';"`
4. Перезапустите контейнеры: `docker-compose restart`

## Дополнительная информация

- [README.md](../README.md) - общая информация о проекте
- [OPDS документация](application/opds/README.md) - документация по OPDS функциям
