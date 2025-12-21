# Инструкция по публикации проекта на GitHub

Этот документ описывает процесс публикации проекта Flibusta на GitHub.

## Подготовка

### 1. Создание репозитория на GitHub

1. Войдите в свой аккаунт GitHub
2. Нажмите кнопку "New repository" (или перейдите по ссылке https://github.com/new)
3. Заполните форму:
   - **Repository name**: `flibusta` (или другое имя)
   - **Description**: "Docker-контейнер для локальной копии библиотеки Флибусты"
   - **Visibility**: Public или Private (на ваше усмотрение)
   - **НЕ** инициализируйте репозиторий с README, .gitignore или лицензией (они уже есть в проекте)
4. Нажмите "Create repository"

### 2. Получение URL репозитория

После создания репозитория GitHub покажет URL. Он будет выглядеть так:
- HTTPS: `https://github.com/username/flibusta.git`
- SSH: `git@github.com:username/flibusta.git`

Скопируйте HTTPS URL (рекомендуется для начинающих).

## Публикация

### Windows (PowerShell)

1. Откройте PowerShell в директории проекта
2. Запустите скрипт:

```powershell
.\publish_to_github.ps1 -RemoteUrl "https://github.com/ваш_username/flibusta.git"
```

Или если удаленный репозиторий уже настроен:

```powershell
.\publish_to_github.ps1
```

### Linux / macOS

1. Откройте терминал в директории проекта
2. Убедитесь, что скрипт имеет права на выполнение:

```bash
chmod +x publish_to_github.sh
```

3. Запустите скрипт:

```bash
./publish_to_github.sh https://github.com/ваш_username/flibusta.git
```

Или если удаленный репозиторий уже настроен:

```bash
./publish_to_github.sh
```

## Что делает скрипт

Скрипт автоматически выполняет следующие шаги:

1. ✅ Проверяет наличие Git
2. ✅ Инициализирует репозиторий (если необходимо)
3. ✅ Настраивает удаленный репозиторий
4. ✅ Проверяет .gitignore
5. ✅ Проверяет конфиденциальные файлы (secrets)
6. ✅ Добавляет все файлы в индекс
7. ✅ Создает коммит
8. ✅ Публикует на GitHub

## Безопасность

⚠️ **Важно**: Скрипт проверяет, что конфиденциальные файлы (пароли) не попадут в репозиторий. 

Убедитесь, что следующие файлы/директории игнорируются:
- `secrets/` - все файлы с паролями
- `*.pwd.txt` - файлы с паролями
- `cache/` - кэш приложения
- `Flibusta.Net/` - архивы книг
- `FlibustaSQL/` - дампы базы данных

## Ручная публикация (альтернатива)

Если вы предпочитаете публиковать вручную:

```bash
# Инициализация (если еще не сделано)
git init

# Добавление удаленного репозитория
git remote add origin https://github.com/ваш_username/flibusta.git

# Добавление файлов
git add .

# Создание коммита
git commit -m "Initial commit: Flibusta local mirror setup"

# Публикация
git branch -M main
git push -u origin main
```

## Обновление репозитория

После внесения изменений в проект:

```bash
# Windows
.\publish_to_github.ps1

# Linux/Mac
./publish_to_github.sh
```

Или вручную:

```bash
git add .
git commit -m "Описание изменений"
git push
```

## Устранение проблем

### Ошибка: "Repository not found"

- Убедитесь, что репозиторий создан на GitHub
- Проверьте правильность URL
- Убедитесь, что у вас есть права доступа к репозиторию

### Ошибка: "Authentication failed"

- Настройте аутентификацию Git:
  - Для HTTPS: используйте Personal Access Token вместо пароля
  - Для SSH: настройте SSH ключи

### Ошибка: "Permission denied"

- Проверьте права доступа к репозиторию на GitHub
- Убедитесь, что вы являетесь владельцем или имеете права на запись

## Дополнительные ресурсы

- [Документация GitHub](https://docs.github.com/)
- [Git Handbook](https://guides.github.com/introduction/git-handbook/)
- [GitHub CLI](https://cli.github.com/) - альтернативный способ работы с GitHub
