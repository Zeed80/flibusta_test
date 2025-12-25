#!/bin/bash
# update_project.sh - Обновление проекта с автоматическим сбросом локальных изменений
# Этот скрипт всегда перезаписывает локальные изменения версией из репозитория

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=== Обновление проекта Flibusta Local Mirror ===${NC}"
echo ""

# Проверка, что мы в git репозитории
if [ ! -d ".git" ]; then
    echo -e "${RED}✗ Ошибка: это не git репозиторий${NC}"
    exit 1
fi

# Проверка наличия git
if ! command -v git &> /dev/null; then
    echo -e "${RED}✗ Ошибка: git не установлен${NC}"
    exit 1
fi

# Определение текущей ветки
CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "main")
REMOTE_BRANCH="origin/${CURRENT_BRANCH}"

echo -e "${BLUE}Текущая ветка: $CURRENT_BRANCH${NC}"
echo -e "${BLUE}Удаленная ветка: $REMOTE_BRANCH${NC}"
echo ""

# Проверка наличия удаленного репозитория
if ! git remote get-url origin &>/dev/null; then
    echo -e "${YELLOW}⚠ Удаленный репозиторий не настроен${NC}"
    echo -e "${YELLOW}Настройте remote: git remote add origin <url>${NC}"
    exit 1
fi

# Получение последних изменений
echo -e "${BLUE}Получение изменений из репозитория...${NC}"
git fetch origin

# Проверка наличия изменений
LOCAL_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "")
REMOTE_COMMIT=$(git rev-parse "$REMOTE_BRANCH" 2>/dev/null || echo "")

if [ "$LOCAL_COMMIT" = "$REMOTE_COMMIT" ]; then
    echo -e "${GREEN}✓ Проект уже обновлен до последней версии${NC}"
    exit 0
fi

# Сохранение важных файлов (опционально, если нужно сохранить конфигурацию)
BACKUP_DIR="/tmp/flibusta_update_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo -e "${BLUE}Создание резервной копии важных файлов...${NC}"
# Сохраняем только конфигурационные файлы, которые могут быть изменены локально
[ -f ".env" ] && cp .env "$BACKUP_DIR/.env" 2>/dev/null || true
[ -d "secrets" ] && cp -r secrets "$BACKUP_DIR/secrets" 2>/dev/null || true

# Сброс всех локальных изменений
echo -e "${BLUE}Сброс локальных изменений...${NC}"
git reset --hard "$REMOTE_BRANCH"

# Очистка неотслеживаемых файлов (опционально, раскомментируйте если нужно)
# git clean -fd

# Восстановление важных файлов (если они были сохранены)
if [ -f "$BACKUP_DIR/.env" ]; then
    echo -e "${BLUE}Восстановление .env файла...${NC}"
    cp "$BACKUP_DIR/.env" .env 2>/dev/null || true
fi

if [ -d "$BACKUP_DIR/secrets" ]; then
    echo -e "${BLUE}Восстановление secrets...${NC}"
    cp -r "$BACKUP_DIR/secrets"/* secrets/ 2>/dev/null || true
fi

# Очистка резервной копии
rm -rf "$BACKUP_DIR"

# Показ изменений
echo ""
echo -e "${GREEN}✓ Проект обновлен до версии: $(git rev-parse --short HEAD)${NC}"
echo -e "${BLUE}Последние коммиты:${NC}"
git log --oneline -5 "$REMOTE_BRANCH"

echo ""
echo -e "${YELLOW}⚠ Рекомендуется:${NC}"
echo -e "${YELLOW}  1. Проверить конфигурацию (.env)${NC}"
echo -e "${YELLOW}  2. Пересобрать Docker образы: docker-compose build${NC}"
echo -e "${YELLOW}  3. Перезапустить контейнеры: docker-compose restart${NC}"
