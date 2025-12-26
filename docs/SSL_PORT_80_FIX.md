# Решение проблемы с портом 80 для SSL сертификата

## Проблема

При попытке запустить контейнеры возникает ошибка:
```
Error: failed to bind host port for 0.0.0.0:80: address already in use
```

Это означает, что порт 80 уже занят другим процессом на хосте.

## Решение

### Вариант 1: Освободить порт 80 (рекомендуется)

1. **Найдите процесс, использующий порт 80:**
```bash
sudo lsof -i :80
# или
sudo netstat -tulnp | grep :80
# или
sudo ss -tulnp | grep :80
```

2. **Остановите процесс:**
```bash
# Если это Apache
sudo systemctl stop apache2
# или
sudo systemctl stop httpd

# Если это другой nginx
sudo systemctl stop nginx

# Или остановите по PID
sudo kill <PID>
```

3. **Запустите контейнеры:**
```bash
docker-compose up -d
```

4. **После получения сертификата можно вернуть процесс обратно** (если он нужен)

### Вариант 2: Временно добавить проброс порта 80

1. **Отредактируйте docker-compose.yml:**
```yaml
ports:
    - '${FLIBUSTA_PORT:-27100}:80'
    - '80:80'  # Добавьте эту строку временно
    - '443:443'
```

2. **Остановите процесс, использующий порт 80** (см. Вариант 1)

3. **Запустите контейнеры:**
```bash
docker-compose up -d
```

4. **Получите сертификат:**
```bash
sudo certbot certonly --webroot -w /var/www/certbot -d books.weberudit.ru
```

5. **Уберите проброс порта 80 из docker-compose.yml** и перезапустите контейнеры

### Вариант 3: Использовать DNS challenge (без порта 80)

Если порт 80 нельзя освободить, используйте DNS challenge:

```bash
sudo certbot certonly --manual --preferred-challenges dns -d books.weberudit.ru
```

Certbot попросит добавить TXT запись в DNS. После добавления записи нажмите Enter.

### Вариант 4: Настроить reverse proxy

Если на порту 80 работает другой веб-сервер (например, Apache или другой nginx), можно настроить его как reverse proxy:

1. **Настройте основной веб-сервер для перенаправления запросов:**
```nginx
# В конфигурации основного nginx/apache
location /.well-known/acme-challenge/ {
    proxy_pass http://localhost:27100;
}
```

2. **Получите сертификат через основной веб-сервер**

## Проверка

После освобождения порта 80 проверьте:

```bash
# Проверка, что порт 80 свободен
sudo netstat -tuln | grep :80

# Проверка доступности
curl -I http://books.weberudit.ru/.well-known/acme-challenge/test
```

## Автоматическое решение

Скрипт установки попытается автоматически освободить порт 80, если это возможно. Если автоматическое освобождение не удалось, следуйте инструкциям выше.
