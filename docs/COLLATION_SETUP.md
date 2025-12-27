# Настройка русского collation для сортировки

Для правильной сортировки с приоритетом кириллицы над латиницей необходимо создать collation `ru_RU.UTF-8` в PostgreSQL.

## ⚠️ Важно: Текущая ситуация

В вашей установке:
- ✅ Код **работает корректно** без специального collation
- ❌ Локаль `ru_RU.UTF-8` не установлена в Docker контейнере PostgreSQL
- ❌ Пользователь `flibusta` не имеет прав суперпользователя (нормально)

**Рекомендация:** Используйте существующие collations. Код автоматически адаптируется.

## Проверка доступных локалей

Проверьте, какие локали доступны в вашем Docker контейнере:

```bash
# Проверка доступных локалей
docker-compose exec postgres locale -a | grep -i ru

# Если команда locale не найдена, попробуйте:
docker-compose exec postgres sh -c "locale -a | grep -i ru" || echo "Команда locale не доступна"
```

Если русских локалей нет, это нормально - код продолжит работать с существующими collations.

## Способ 1: Использование существующих collations (рекомендуется)

Код автоматически проверяет доступность collation и переключается на collation базы данных по умолчанию, если `ru_RU.UTF-8` недоступен.

**Это нормально и безопасно!** Все функции OPDS работают корректно, просто сортировка будет стандартной.

## Способ 2: Установка русской локали в Docker (продвинутый)

Если вы действительно хотите создать русский collation, нужно установить локаль в Docker контейнере PostgreSQL.

### Вариант A: Изменение Dockerfile (требует пересборки)

Добавьте в `phpdocker/pg/Dockerfile` перед последней строкой:

```dockerfile
FROM postgres:16

# Установка русской локали
RUN apt-get update && \
    apt-get install -y locales && \
    sed -i '/ru_RU.UTF-8/s/^# //g' /etc/locale.gen && \
    locale-gen ru_RU.UTF-8 && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /docker-entrypoint-initdb.d
ENV POSTGRES_DB flibusta

# Копируем схему БД
COPY init_db.sql /docker-entrypoint-initdb.d/

# Копируем пользовательскую конфигурацию PostgreSQL
COPY postgresql_custom.conf /etc/postgresql/

# Создаем конфиг с включением пользовательских настроек
RUN echo "include 'postgresql_custom.conf'" >> /etc/postgresql/postgresql.conf
```

**ВАЖНО:** После изменения Dockerfile:
1. Пересоберите образ: `docker-compose build postgres`
2. Пересоздайте контейнер: `docker-compose up -d postgres`
3. Данные БД сохранятся (если используете volumes), но это требует времени

### Вариант B: Создание collation при инициализации БД

Если вы пересобрали образ с русской локалью, добавьте в `phpdocker/pg/init_db.sql` в начало файла:

```sql
-- Создание collation для русской сортировки
-- Выполняется автоматически при создании базы данных
CREATE COLLATION IF NOT EXISTS "ru_RU.UTF-8" (
    LOCALE = 'ru_RU.UTF-8',
    PROVIDER = 'libc'
);
```

**ВАЖНО:** Это сработает только если база данных создается заново. **Не делайте этого, если у вас уже есть данные!**

## Текущее состояние

Проверьте текущее состояние:

```bash
# Проверка, существует ли collation
docker-compose exec -T postgres psql -U flibusta -d flibusta -c "SELECT EXISTS (SELECT 1 FROM pg_collation WHERE collname = 'ru_RU.UTF-8') as collation_exists;"

# Проверка доступных collations
docker-compose exec -T postgres psql -U flibusta -d flibusta -f /application/tools/check_collations.sql
```

## Как работает код

Код автоматически:

1. **Проверяет доступность collation** при первом использовании через класс `OPDSCollation`
2. **Кэширует результат** проверки в памяти (статическая переменная)
3. **Переключается на безопасный режим**, если collation недоступен
4. **Использует стандартную сортировку** базы данных (C или другой доступный collation)

Все SQL запросы с сортировкой безопасно обрабатываются:

```php
// Пример из кода:
$orderBy = OPDSCollation::applyRussianCollation('b.title', $dbh);
// Если collation недоступен, вернет просто: 'b.title'
// Если доступен, вернет: 'b.title COLLATE "ru_RU.UTF-8"'
```

## Рекомендация

**Не устанавливайте русскую локаль**, если у вас уже работает система. Это требует:
- Пересборки Docker образа
- Возможных проблем с совместимостью
- Дополнительного времени

**Используйте существующие collations** - код уже работает правильно без специального collation. Сортировка будет стандартной, что вполне приемлемо для большинства случаев использования OPDS каталога.

## Проверка работы

Код работает корректно даже без специального collation. Проверьте:

```bash
# Тест сортировки (использует collation по умолчанию)
docker-compose exec -T postgres psql -U flibusta -d flibusta -c "
SELECT title FROM libbook WHERE deleted='0' ORDER BY title LIMIT 10;
"
```

Если запрос выполняется без ошибок, значит всё работает правильно.
