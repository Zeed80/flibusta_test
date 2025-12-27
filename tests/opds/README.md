# OPDS Тесты

Этот каталог содержит unit и integration тесты для OPDS модуля.

## Структура тестов

- `OPDSFeedTest.php` - тесты для OPDSFeed и связанных классов
- `OPDSLinkTest.php` - тесты для OPDSLink
- `OPDSEntryTest.php` - тесты для OPDSEntry
- `OPDSValidatorTest.php` - тесты для OPDSValidator
- `OPDSIntegrationTest.php` - integration тесты для endpoints (TODO)

## Установка зависимостей

Для запуска тестов требуется PHPUnit:

```bash
composer require --dev phpunit/phpunit
```

## Запуск тестов

```bash
# Все тесты OPDS
vendor/bin/phpunit tests/opds/

# Конкретный тест
vendor/bin/phpunit tests/opds/OPDSFeedTest.php
```

## Покрытие кода

Для генерации отчета о покрытии:

```bash
vendor/bin/phpunit --coverage-html coverage/ tests/opds/
```

Целевое покрытие: > 80%
