#!/bin/bash
# init_directories.sh - Создание необходимых директорий для Flibusta

set -e

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Создание директорий...${NC}"

# Основные директории
mkdir -p FlibustaSQL
mkdir -p Flibusta.Net
mkdir -p cache
mkdir -p secrets

# Поддиректории кэша
mkdir -p cache/authors
mkdir -p cache/covers
mkdir -p cache/tmp
mkdir -p cache/opds

# Установка прав доступа
chmod 755 FlibustaSQL Flibusta.Net 2>/dev/null || true
chmod 777 cache cache/authors cache/covers cache/tmp cache/opds 2>/dev/null || true
chmod 700 secrets 2>/dev/null || true

# Создание .gitkeep файлов для пустых директорий
touch FlibustaSQL/.gitkeep
touch Flibusta.Net/.gitkeep
touch cache/authors/.gitkeep
touch cache/covers/.gitkeep
touch cache/tmp/.gitkeep
touch cache/opds/.gitkeep

echo -e "${GREEN}✓ Директории созданы${NC}"
