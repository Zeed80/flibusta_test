# Исправление проблемы с паролем базы данных

## Проблема

Ошибка при подключении к базе данных:
```
FATAL: password authentication failed for user "flibusta"
```

Эта ошибка возникает, когда пароль в базе данных PostgreSQL не совпадает с паролем, который использует приложение.

## Причины

1. **Volume БД был создан с дефолтным паролем**, а затем был создан новый пароль в `.env` и `secrets/flibusta_pwd.txt`
2. **Пароли в разных местах не синхронизированы**:
   - `.env` (FLIBUSTA_DBPASSWORD)
   - `secrets/flibusta_pwd.txt`
   - База данных PostgreSQL

## Решение

### Автоматическое исправление

Выполните скрипт исправления пароля:

```bash
bash scripts/fix_db_password.sh
```

Этот скрипт:
1. Проверит пароль в `secrets/flibusta_pwd.txt` и `.env`
2. Подключится к БД с известными паролями
3. Обновит пароль пользователя `flibusta` на правильный
4. Проверит, что новый пароль работает

### Ручное исправление

Если автоматическое исправление не помогло:

#### Вариант 1: Обновление пароля в БД

1. Узнайте текущий пароль из `secrets/flibusta_pwd.txt`:
   ```bash
   cat secrets/flibusta_pwd.txt
   ```

2. Подключитесь к БД с дефолтным паролем:
   ```bash
   docker-compose exec postgres psql -U flibusta -d flibusta
   # Пароль: flibusta (дефолтный)
   ```

3. Обновите пароль:
   ```sql
   ALTER USER flibusta WITH PASSWORD 'ваш_пароль_из_secrets';
   ```

4. Выйдите из psql: `\q`

5. Перезапустите контейнеры:
   ```bash
   docker-compose restart
   ```

#### Вариант 2: Пересоздание volume БД

**ВНИМАНИЕ: Это удалит все данные базы данных!**

Если у вас нет важных данных в БД:

```bash
# Остановка контейнеров
docker-compose down

# Удаление volume БД
docker volume rm flibusta_db-data

# Запуск контейнеров заново
docker-compose up -d

# Инициализация БД
docker-compose exec php-fpm sh /application/scripts/init_database.sh
```

## Проверка

После исправления проверьте подключение:

```bash
bash scripts/test_db_connection.sh
```

Или проверьте в браузере, что сайт работает без ошибок подключения к БД.

## Предотвращение проблемы

При установке через `install.sh`:
1. Пароль автоматически синхронизируется между `.env`, `secrets/flibusta_pwd.txt` и БД
2. Функция `update_db_password()` автоматически обновляет пароль в БД при необходимости

Если вы меняете пароль вручную:
1. Обновите пароль в `.env`: `FLIBUSTA_DBPASSWORD=новый_пароль`
2. Обновите пароль в `secrets/flibusta_pwd.txt`: `echo "новый_пароль" > secrets/flibusta_pwd.txt`
3. Выполните скрипт исправления: `bash scripts/fix_db_password.sh`
4. Перезапустите контейнеры: `docker-compose restart`

## Дополнительная информация

- Пароль хранится в трех местах:
  - `.env` - для docker-compose
  - `secrets/flibusta_pwd.txt` - для контейнера php-fpm (через Docker secrets)
  - База данных PostgreSQL - для аутентификации пользователя

- Все три места должны содержать одинаковый пароль.
