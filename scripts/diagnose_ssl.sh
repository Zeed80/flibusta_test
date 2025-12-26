#!/bin/bash
# Скрипт диагностики проблем с SSL сертификатом

set -e

DOMAIN="${1:-books.weberudit.ru}"

echo "=== Диагностика SSL для домена: $DOMAIN ==="
echo ""

# Проверка директории certbot
echo "1. Проверка директории /var/www/certbot..."
if [ -d "/var/www/certbot" ]; then
    echo "   ✓ Директория существует"
    ls -la /var/www/certbot | head -5
else
    echo "   ✗ Директория не существует"
    echo "   Создайте: sudo mkdir -p /var/www/certbot && sudo chmod 755 /var/www/certbot"
fi
echo ""

# Проверка прав доступа
echo "2. Проверка прав доступа..."
if [ -w "/var/www/certbot" ] || sudo test -w "/var/www/certbot"; then
    echo "   ✓ Директория доступна для записи"
else
    echo "   ✗ Директория не доступна для записи"
    echo "   Исправьте: sudo chmod 755 /var/www/certbot"
fi
echo ""

# Проверка контейнера
echo "3. Проверка контейнера webserver..."
if command -v docker-compose &> /dev/null; then
    COMPOSE_CMD="docker-compose"
elif command -v docker &> /dev/null && docker compose version &> /dev/null; then
    COMPOSE_CMD="docker compose"
else
    echo "   ✗ Docker Compose не найден"
    exit 1
fi

if $COMPOSE_CMD ps webserver 2>/dev/null | grep -q "Up"; then
    echo "   ✓ Контейнер webserver запущен"
else
    echo "   ✗ Контейнер webserver не запущен"
    echo "   Запустите: $COMPOSE_CMD up -d"
    exit 1
fi
echo ""

# Проверка конфигурации nginx
echo "4. Проверка конфигурации nginx..."
if $COMPOSE_CMD exec -T webserver nginx -t 2>&1 | grep -q "successful"; then
    echo "   ✓ Конфигурация nginx валидна"
else
    echo "   ✗ Конфигурация nginx содержит ошибки:"
    $COMPOSE_CMD exec -T webserver nginx -t 2>&1 | head -10
fi
echo ""

# Проверка монтирования volume
echo "5. Проверка монтирования volume..."
if $COMPOSE_CMD exec -T webserver test -d /var/www/certbot 2>/dev/null; then
    echo "   ✓ Volume /var/www/certbot смонтирован"
    echo "   Содержимое в контейнере:"
    $COMPOSE_CMD exec -T webserver ls -la /var/www/certbot 2>/dev/null | head -5 || echo "   (пусто)"
else
    echo "   ✗ Volume /var/www/certbot не смонтирован"
fi
echo ""

# Проверка доступности через localhost
echo "6. Проверка доступности через localhost..."
TEST_FILE="test-$(date +%s)"
echo "test-content" | sudo tee "/var/www/certbot/$TEST_FILE" >/dev/null 2>&1
sleep 1

LOCAL_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/.well-known/acme-challenge/$TEST_FILE" 2>/dev/null || echo "000")
if [ "$LOCAL_RESPONSE" = "200" ]; then
    echo "   ✓ Nginx обслуживает файлы из /var/www/certbot (код: $LOCAL_RESPONSE)"
elif [ "$LOCAL_RESPONSE" = "404" ]; then
    echo "   ⚠ Nginx отвечает, но файл не найден (код: $LOCAL_RESPONSE)"
    echo "   Проверьте конфигурацию location /.well-known/acme-challenge/"
else
    echo "   ✗ Nginx не отвечает правильно (код: $LOCAL_RESPONSE)"
fi
sudo rm -f "/var/www/certbot/$TEST_FILE" 2>/dev/null || true
echo ""

# Проверка доступности через домен
echo "7. Проверка доступности через домен $DOMAIN..."
DOMAIN_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "http://$DOMAIN/.well-known/acme-challenge/test" 2>/dev/null || echo "000")
if [ "$DOMAIN_RESPONSE" = "404" ] || [ "$DOMAIN_RESPONSE" = "403" ]; then
    echo "   ✓ Домен доступен и nginx отвечает (код: $DOMAIN_RESPONSE)"
elif [ "$DOMAIN_RESPONSE" = "000" ]; then
    echo "   ✗ Домен недоступен (нет ответа)"
    echo "   Возможные причины:"
    echo "     - DNS не указывает на этот сервер"
    echo "     - Порт 80 заблокирован firewall"
    echo "     - Проброс портов не настроен"
else
    echo "   ⚠ Домен отвечает, но неожиданный код: $DOMAIN_RESPONSE"
fi
echo ""

# Проверка порта 80
echo "8. Проверка порта 80..."
if command -v netstat &> /dev/null; then
    PORT_80=$(netstat -tuln 2>/dev/null | grep ":80 " | grep -v "docker-proxy" || true)
    if [ -n "$PORT_80" ]; then
        echo "   ⚠ Порт 80 используется (кроме Docker):"
        echo "$PORT_80" | sed 's/^/     /'
    else
        echo "   ✓ Порт 80 свободен (кроме Docker)"
    fi
elif command -v ss &> /dev/null; then
    PORT_80=$(ss -tuln 2>/dev/null | grep ":80 " | grep -v "docker-proxy" || true)
    if [ -n "$PORT_80" ]; then
        echo "   ⚠ Порт 80 используется (кроме Docker):"
        echo "$PORT_80" | sed 's/^/     /'
    else
        echo "   ✓ Порт 80 свободен (кроме Docker)"
    fi
fi
echo ""

# Проверка DNS
echo "9. Проверка DNS..."
DOMAIN_IP=$(dig +short "$DOMAIN" 2>/dev/null | tail -1 || echo "")
SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com 2>/dev/null || echo "")
if [ -n "$DOMAIN_IP" ] && [ -n "$SERVER_IP" ]; then
    if [ "$DOMAIN_IP" = "$SERVER_IP" ]; then
        echo "   ✓ DNS указывает на этот сервер ($DOMAIN_IP)"
    else
        echo "   ✗ DNS указывает на другой IP:"
        echo "     Домен: $DOMAIN -> $DOMAIN_IP"
        echo "     Сервер: $SERVER_IP"
        echo "   Обновите DNS запись A для домена $DOMAIN"
    fi
else
    echo "   ⚠ Не удалось проверить DNS"
fi
echo ""

# Итоговые рекомендации
echo "=== Рекомендации ==="
echo ""
echo "Если все проверки пройдены, но сертификат не получается:"
echo "1. Убедитесь, что порт 80 открыт в firewall:"
echo "   sudo ufw allow 80/tcp"
echo "   sudo ufw allow 443/tcp"
echo ""
echo "2. Проверьте доступность из интернета:"
echo "   curl -I http://$DOMAIN/.well-known/acme-challenge/test"
echo ""
echo "3. Попробуйте получить сертификат вручную:"
echo "   sudo certbot certonly --webroot -w /var/www/certbot -d $DOMAIN --dry-run"
echo ""
echo "4. Проверьте логи nginx:"
echo "   $COMPOSE_CMD logs webserver | tail -20"
echo ""
