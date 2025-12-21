#!/bin/bash
# Скрипт для публикации проекта Flibusta на GitHub
# Использование: ./publish_to_github.sh [remote_url] [branch]

set -e

REMOTE_URL="${1:-}"
BRANCH="${2:-main}"

echo "========================================"
echo "  Публикация проекта Flibusta на GitHub"
echo "========================================"
echo ""

# Проверка наличия Git
if ! command -v git &> /dev/null; then
    echo "Ошибка: Git не установлен"
    echo "Установите Git: sudo apt-get install git  # для Ubuntu/Debian"
    echo "              brew install git              # для macOS"
    exit 1
fi

echo "[1/7] Проверка статуса Git репозитория..."

# Проверка, является ли директория Git репозиторием
if [ ! -d .git ]; then
    echo "Инициализация нового Git репозитория..."
    git init
fi

# Проверка наличия удаленного репозитория
CURRENT_REMOTE=$(git remote get-url origin 2>/dev/null || echo "")
if [ -n "$CURRENT_REMOTE" ]; then
    echo "Текущий удаленный репозиторий: $CURRENT_REMOTE"
    if [ -n "$REMOTE_URL" ] && [ "$REMOTE_URL" != "$CURRENT_REMOTE" ]; then
        echo "Обновление URL удаленного репозитория..."
        git remote set-url origin "$REMOTE_URL"
    fi
else
    if [ -z "$REMOTE_URL" ]; then
        echo "Ошибка: Удаленный репозиторий не настроен"
        echo "Укажите URL репозитория:"
        echo "  ./publish_to_github.sh https://github.com/username/flibusta.git"
        exit 1
    fi
    echo "Добавление удаленного репозитория: $REMOTE_URL"
    git remote add origin "$REMOTE_URL"
fi

echo "[2/7] Проверка .gitignore..."
if [ ! -f .gitignore ]; then
    echo "Предупреждение: .gitignore не найден"
else
    echo ".gitignore найден"
fi

echo "[3/7] Проверка конфиденциальных файлов..."
SECRETS_FILES=("secrets/flibusta_pwd.txt" "secrets/postgres_admin_pwd.txt")
HAS_SECRETS=false

for file in "${SECRETS_FILES[@]}"; do
    if [ -f "$file" ]; then
        if ! git check-ignore -q "$file" 2>/dev/null; then
            echo "Предупреждение: $file не игнорируется Git!"
            echo "Убедитесь, что файл добавлен в .gitignore"
            HAS_SECRETS=true
        fi
    fi
done

if [ "$HAS_SECRETS" = true ]; then
    echo ""
    echo "ВНИМАНИЕ: Обнаружены конфиденциальные файлы!"
    read -p "Продолжить публикацию? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Публикация отменена"
        exit 0
    fi
fi

echo "[4/7] Добавление файлов в индекс..."
git add .

echo "[5/7] Проверка изменений..."
if [ -z "$(git status --short)" ]; then
    echo "Нет изменений для коммита"
else
    echo "Изменения:"
    git status --short
    echo ""
fi

echo "[6/7] Создание коммита..."
if git log -1 --oneline &>/dev/null; then
    COMMIT_MESSAGE="Update: Flibusta local mirror"
else
    COMMIT_MESSAGE="Initial commit: Flibusta local mirror setup"
fi

if ! git commit -m "$COMMIT_MESSAGE" 2>/dev/null; then
    echo "Предупреждение: Не удалось создать коммит (возможно, нет изменений)"
fi

echo "[7/7] Публикация на GitHub..."

# Проверка существования ветки
if ! git show-ref --verify --quiet "refs/heads/$BRANCH"; then
    echo "Создание ветки $BRANCH..."
    git checkout -b "$BRANCH"
fi

# Установка upstream и push
echo "Отправка изменений на GitHub..."
if ! git push -u origin "$BRANCH"; then
    echo ""
    echo "Ошибка при отправке на GitHub"
    echo "Возможные причины:"
    echo "  1. Репозиторий не существует на GitHub"
    echo "  2. Нет прав доступа к репозиторию"
    echo "  3. Необходима аутентификация"
    echo ""
    echo "Создайте репозиторий на GitHub и повторите попытку"
    exit 1
fi

echo ""
echo "========================================"
echo "  Проект успешно опубликован на GitHub!"
echo "========================================"
echo ""
echo "Репозиторий: $(git remote get-url origin)"
echo "Ветка: $BRANCH"
echo ""
