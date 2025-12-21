# Скрипт для интерактивной публикации проекта Flibusta на GitHub
# Только для Windows PowerShell
# Использование: .\publish_to_github.ps1

# Установка кодировки UTF-8
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

function Show-Header {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "  Публикация проекта Flibusta на GitHub" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host ""
}

function Test-GitInstalled {
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        Write-Host "❌ Ошибка: Git не установлен или не найден в PATH" -ForegroundColor Red
        Write-Host ""
        Write-Host "Установите Git с официального сайта:" -ForegroundColor Yellow
        Write-Host "  https://git-scm.com/download/win" -ForegroundColor Cyan
        Write-Host ""
        Read-Host "Нажмите Enter для выхода"
        exit 1
    }
    Write-Host "✅ Git установлен" -ForegroundColor Green
}

function Initialize-Repository {
    if (-not (Test-Path .git)) {
        Write-Host ""
        Write-Host "📦 Инициализация нового Git репозитория..." -ForegroundColor Yellow
        git init | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-Host "❌ Ошибка при инициализации Git репозитория" -ForegroundColor Red
            Read-Host "Нажмите Enter для выхода"
            exit 1
        }
        Write-Host "✅ Репозиторий инициализирован" -ForegroundColor Green
    } else {
        Write-Host "✅ Git репозиторий уже существует" -ForegroundColor Green
    }
}

function Get-RemoteUrl {
    $currentRemote = git remote get-url origin 2>$null
    
    if ($currentRemote) {
        Write-Host ""
        Write-Host "📡 Найден удаленный репозиторий: $currentRemote" -ForegroundColor Green
        Write-Host ""
        $useCurrent = Read-Host "Использовать этот репозиторий? (Y/n)"
        if ($useCurrent -ne "n" -and $useCurrent -ne "N") {
            return $currentRemote
        }
    }
    
    Write-Host ""
    Write-Host "📝 Введите URL репозитория GitHub:" -ForegroundColor Yellow
    Write-Host "   Пример: https://github.com/username/flibusta.git" -ForegroundColor Gray
    Write-Host "   (или нажмите Ctrl+C для отмены)" -ForegroundColor Gray
    Write-Host ""
    
    $maxAttempts = 3
    $attempt = 0
    
    do {
        $attempt++
        $url = Read-Host "URL репозитория"
        
        if ([string]::IsNullOrWhiteSpace($url)) {
            if ($attempt -ge $maxAttempts) {
                Write-Host "❌ Превышено количество попыток. Публикация отменена." -ForegroundColor Red
                Read-Host "Нажмите Enter для выхода"
                exit 0
            }
            Write-Host "❌ URL не может быть пустым! (попытка $attempt из $maxAttempts)" -ForegroundColor Red
            Write-Host ""
            continue
        }
        
        # Проверка формата URL (необязательная, но предупреждаем)
        if ($url -notmatch '^https://github\.com/[\w\-]+/[\w\-\.]+(\.git)?$' -and 
            $url -notmatch '^git@github\.com:[\w\-]+/[\w\-\.]+(\.git)?$') {
            Write-Host "⚠️  URL выглядит некорректно." -ForegroundColor Yellow
            $confirm = Read-Host "Продолжить с этим URL? (y/N)"
            if ($confirm -ne "y" -and $confirm -ne "Y") {
                if ($attempt -ge $maxAttempts) {
                    Write-Host "❌ Превышено количество попыток. Публикация отменена." -ForegroundColor Red
                    Read-Host "Нажмите Enter для выхода"
                    exit 0
                }
                continue
            }
        }
        break
    } while ($attempt -lt $maxAttempts)
    
    if ($currentRemote) {
        git remote set-url origin $url
        Write-Host "✅ URL обновлен" -ForegroundColor Green
    } else {
        git remote add origin $url
        Write-Host "✅ Удаленный репозиторий добавлен" -ForegroundColor Green
    }
    
    return $url
}

function Get-BranchName {
    $currentBranch = git branch --show-current 2>$null
    
    if ($currentBranch) {
        Write-Host ""
        Write-Host "🌿 Текущая ветка: $currentBranch" -ForegroundColor Green
        $useCurrent = Read-Host "Использовать эту ветку? (Y/n)"
        if ($useCurrent -ne "n" -and $useCurrent -ne "N") {
            return $currentBranch
        }
    }
    
    Write-Host ""
    Write-Host "🌿 Введите имя ветки:" -ForegroundColor Yellow
    $branch = Read-Host "Имя ветки (по умолчанию: main)"
    
    if ([string]::IsNullOrWhiteSpace($branch)) {
        $branch = "main"
    }
    
    return $branch
}

function Test-ConfidentialFiles {
    Write-Host ""
    Write-Host "🔒 Проверка конфиденциальных файлов..." -ForegroundColor Yellow
    
    $secretsFiles = @("secrets\flibusta_pwd.txt", "secrets\postgres_admin_pwd.txt")
    $hasSecrets = $false
    $unprotectedFiles = @()
    
    foreach ($file in $secretsFiles) {
        if (Test-Path $file) {
            $status = git check-ignore $file 2>$null
            if (-not $status) {
                $unprotectedFiles += $file
                $hasSecrets = $true
            }
        }
    }
    
    if ($hasSecrets) {
        Write-Host ""
        Write-Host "⚠️  ВНИМАНИЕ: Обнаружены конфиденциальные файлы, которые не игнорируются Git!" -ForegroundColor Red
        foreach ($file in $unprotectedFiles) {
            Write-Host "   - $file" -ForegroundColor Red
        }
        Write-Host ""
        Write-Host "Эти файлы могут содержать пароли и другую конфиденциальную информацию." -ForegroundColor Yellow
        Write-Host ""
        $continue = Read-Host "Продолжить публикацию? (y/N)"
        if ($continue -ne "y" -and $continue -ne "Y") {
            Write-Host "Публикация отменена" -ForegroundColor Yellow
            Read-Host "Нажмите Enter для выхода"
            exit 0
        }
    } else {
        Write-Host "✅ Конфиденциальные файлы защищены" -ForegroundColor Green
    }
}

function Add-Files {
    Write-Host ""
    Write-Host "📁 Добавление файлов в индекс..." -ForegroundColor Yellow
    
    git add .
    if ($LASTEXITCODE -ne 0) {
        Write-Host "❌ Ошибка при добавлении файлов" -ForegroundColor Red
        Read-Host "Нажмите Enter для выхода"
        exit 1
    }
    
    Write-Host "✅ Файлы добавлены" -ForegroundColor Green
}

function Show-Changes {
    Write-Host ""
    Write-Host "📋 Проверка изменений..." -ForegroundColor Yellow
    
    $status = git status --short
    if (-not $status) {
        Write-Host "ℹ️  Нет изменений для коммита" -ForegroundColor Cyan
        return $false
    }
    
    Write-Host ""
    Write-Host "Изменения, которые будут закоммичены:" -ForegroundColor Cyan
    Write-Host "----------------------------------------" -ForegroundColor Gray
    git status --short
    Write-Host "----------------------------------------" -ForegroundColor Gray
    Write-Host ""
    
    return $true
}

function Get-CommitMessage {
    Write-Host ""
    Write-Host "💬 Сообщение коммита:" -ForegroundColor Yellow
    
    $existingCommit = git log -1 --oneline 2>$null
    if ($existingCommit) {
        $defaultMessage = "Update: Flibusta local mirror"
    } else {
        $defaultMessage = "Initial commit: Flibusta local mirror setup"
    }
    
    Write-Host "   По умолчанию: $defaultMessage" -ForegroundColor Gray
    Write-Host ""
    $message = Read-Host "Введите сообщение коммита (Enter для значения по умолчанию)"
    
    if ([string]::IsNullOrWhiteSpace($message)) {
        $message = $defaultMessage
    }
    
    return $message
}

function Create-Commit {
    param([string]$Message)
    
    Write-Host ""
    Write-Host "💾 Создание коммита..." -ForegroundColor Yellow
    
    git commit -m $Message
    if ($LASTEXITCODE -ne 0) {
        Write-Host "⚠️  Не удалось создать коммит (возможно, нет изменений)" -ForegroundColor Yellow
        return $false
    }
    
    Write-Host "✅ Коммит создан" -ForegroundColor Green
    return $true
}

function Publish-ToGitHub {
    param([string]$Branch)
    
    Write-Host ""
    Write-Host "🚀 Публикация на GitHub..." -ForegroundColor Yellow
    
    # Проверка существования ветки
    $branchExists = git show-ref --verify --quiet "refs/heads/$Branch" 2>$null
    if (-not $branchExists) {
        Write-Host "🌿 Создание ветки $Branch..." -ForegroundColor Yellow
        git checkout -b $Branch 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-Host "⚠️  Не удалось создать ветку (возможно, она уже существует)" -ForegroundColor Yellow
        }
    }
    
    Write-Host ""
    Write-Host "📤 Отправка изменений на GitHub..." -ForegroundColor Yellow
    Write-Host "   Это может занять некоторое время..." -ForegroundColor Gray
    Write-Host ""
    
    git push -u origin $Branch
    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Write-Host "❌ Ошибка при отправке на GitHub" -ForegroundColor Red
        Write-Host ""
        Write-Host "Возможные причины:" -ForegroundColor Yellow
        Write-Host "  1. Репозиторий не существует на GitHub" -ForegroundColor White
        Write-Host "  2. Нет прав доступа к репозиторию" -ForegroundColor White
        Write-Host "  3. Необходима аутентификация" -ForegroundColor White
        Write-Host "  4. Неправильный URL репозитория" -ForegroundColor White
        Write-Host ""
        Write-Host "Проверьте:" -ForegroundColor Yellow
        Write-Host "  - Репозиторий создан на https://github.com" -ForegroundColor White
        Write-Host "  - URL репозитория указан правильно" -ForegroundColor White
        Write-Host "  - Настроена аутентификация Git (Personal Access Token)" -ForegroundColor White
        Write-Host ""
        Read-Host "Нажмите Enter для выхода"
        exit 1
    }
    
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "  ✅ Проект успешно опубликован на GitHub!" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Репозиторий: $(git remote get-url origin)" -ForegroundColor Cyan
    Write-Host "Ветка: $Branch" -ForegroundColor Cyan
    Write-Host ""
}

# Основной процесс
Clear-Host
Show-Header

Write-Host "[1/8] Проверка Git..." -ForegroundColor Yellow
Test-GitInstalled

Write-Host "[2/8] Проверка репозитория..." -ForegroundColor Yellow
Initialize-Repository

Write-Host "[3/8] Настройка удаленного репозитория..." -ForegroundColor Yellow
$remoteUrl = Get-RemoteUrl

Write-Host "[4/8] Выбор ветки..." -ForegroundColor Yellow
$branch = Get-BranchName

Write-Host "[5/8] Проверка безопасности..." -ForegroundColor Yellow
Test-ConfidentialFiles

Write-Host "[6/8] Подготовка файлов..." -ForegroundColor Yellow
Add-Files

Write-Host "[7/8] Проверка изменений..." -ForegroundColor Yellow
$hasChanges = Show-Changes

if ($hasChanges) {
    $commitMessage = Get-CommitMessage
    $commitCreated = Create-Commit -Message $commitMessage
    
    if ($commitCreated) {
        Write-Host "[8/8] Публикация..." -ForegroundColor Yellow
        Publish-ToGitHub -Branch $branch
    } else {
        Write-Host ""
        Write-Host "ℹ️  Нет изменений для публикации" -ForegroundColor Cyan
        Write-Host ""
    }
} else {
    Write-Host ""
    Write-Host "ℹ️  Нет изменений для публикации" -ForegroundColor Cyan
    Write-Host ""
}

Write-Host "Готово! Нажмите любую клавишу для выхода..." -ForegroundColor Gray
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
