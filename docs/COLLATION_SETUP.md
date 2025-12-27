# Настройка русского collation для сортировки

Для правильной сортировки с приоритетом кириллицы над латиницей необходимо создать collation `ru_RU.UTF-8` в PostgreSQL.

## Способ 1: Создание через SQL (требует прав суперпользователя)

### Шаг 1: Проверка доступных collations

```bash
# Подключитесь к серверу через SSH, затем:
docker-compose exec postgres psql -U flibusta -d flibusta -f /application/tools/check_collations.sql
```

### Шаг 2: Создание collation (требует прав postgres)

```bash
# Подключитесь к серверу через SSH, затем:
# Вариант A: через docker-compose (если postgres пользователь имеет права)
docker-compose exec postgres psql -U postgres -d flibusta -f /application/tools/create_russian_collation.sql

# Вариант B: прямое подключение (если есть доступ к серверу БД)
psql -U postgres -d flibusta -f /path/to/create_russian_collation.sql
```

### Шаг 3: Проверка создания

```bash
docker-compose exec postgres psql -U flibusta -d flibusta -c "SELECT collname FROM pg_collation WHERE collname = 'ru_RU.UTF-8';"
```

Если collation создан успешно, должно вернуться:
```
  collname   
-------------
 ru_RU.UTF-8
```

## Способ 2: Создание через миграцию (альтернатива)

Если у пользователя базы данных нет прав на создание collation, можно:

1. Попросить администратора БД создать collation
2. Использовать существующие collations (C, POSIX) - сортировка будет стандартной

## Использование после создания

После создания collation код автоматически начнет его использовать через класс `OPDSCollation`. 

Если collation недоступен, используется сортировка по умолчанию базы данных.

## Проверка работы

После создания collation проверьте сортировку:

```sql
-- Должно сортировать: А, Б, В (кириллица) перед A, B, C (латиница)
SELECT title FROM libbook WHERE deleted='0' ORDER BY title COLLATE "ru_RU.UTF-8" LIMIT 20;
```

## Проблемы и решения

### Ошибка: "collation ru_RU.UTF-8 does not exist"

**Причина:** Collation не создан в базе данных.

**Решение:** 
1. Создайте collation по инструкции выше
2. Или используйте существующие collations (код автоматически переключится)

### Ошибка: "permission denied to create collation"

**Причина:** Пользователь базы данных не имеет прав на создание collation.

**Решение:**
1. Подключитесь как пользователь `postgres` (суперпользователь)
2. Или попросите администратора БД создать collation

### Collation создан, но сортировка не работает как ожидается

**Причина:** Возможно, локаль `ru_RU.UTF-8` не установлена на системе.

**Решение:**
1. Проверьте доступные локали на сервере: `locale -a | grep ru`
2. Если локаль недоступна, установите её или используйте альтернативную

## Альтернативные решения

Если создание collation невозможно:

1. **Использовать сортировку по умолчанию** - код автоматически использует collation базы данных
2. **Сортировать на уровне приложения** - можно сортировать результаты в PHP после получения из БД
3. **Использовать ICU collations** (PostgreSQL 10+) - более гибкий вариант, но требует настройки
