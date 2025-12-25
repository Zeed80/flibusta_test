#!/bin/bash
# Скрипт для обновления домена в конфигурации nginx
# Использование: ./scripts/update_nginx_domain.sh your.domain.com

set -e

NGINX_CONF="phpdocker/nginx/nginx.conf"
DOMAIN="${1:-}"

if [ -z "$DOMAIN" ]; then
    echo "Использование: $0 <domain>"
    echo "Пример: $0 books.weberudit.ru"
    exit 1
fi

if [ ! -f "$NGINX_CONF" ]; then
    echo "Ошибка: файл $NGINX_CONF не найден"
    exit 1
fi

# Заменяем server_name _; на server_name $DOMAIN;
sed -i.bak "s/server_name _;/server_name $DOMAIN;/" "$NGINX_CONF"

echo "Домен обновлен в $NGINX_CONF: server_name $DOMAIN;"
echo "Резервная копия сохранена в ${NGINX_CONF}.bak"
echo ""
echo "Для применения изменений перезапустите nginx:"
echo "  docker-compose restart webserver"
