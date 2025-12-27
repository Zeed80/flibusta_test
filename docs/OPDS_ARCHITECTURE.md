# Архитектура OPDS модуля

Этот документ описывает архитектуру OPDS модуля проекта Flibusta.

## Обзор

OPDS модуль реализует сервер OPDS 1.2 для предоставления каталога книг через стандартный протокол OPDS (Open Publication Distribution System). Модуль построен на основе модульной архитектуры с четким разделением ответственности.

## Версия OPDS

Проект использует **только OPDS 1.2** - последнюю стабильную версию спецификации. Поддержка более старых версий не предусмотрена, так как все современные клиенты поддерживают OPDS 1.2.

## Архитектурные слои

```
┌─────────────────────────────────────────┐
│         HTTP Request Layer              │
│  (index.php, main.php, list.php, etc.) │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│       OPDS Core Classes                 │
│  (OPDSFeed, OPDSEntry, OPDSLink, etc.) │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│      Support Classes                    │
│  (OPDSCache, OPDSValidator, etc.)       │
└──────────────┬──────────────────────────┘
               │
┌──────────────▼──────────────────────────┐
│      Data Layer                         │
│  (PostgreSQL database)                  │
└─────────────────────────────────────────┘
```

## Структура классов

### Базовые классы

#### OPDSFeed (абстрактный)

Базовый класс для создания OPDS фидов. Определяет интерфейс и общие методы.

**Основные свойства:**
- `id` - уникальный идентификатор фида
- `title` - заголовок фида
- `updated` - время последнего обновления
- `entries` - массив записей (OPDSEntry[])
- `links` - массив ссылок (OPDSLink[])
- `facets` - массив фасетов (OPDSFacet[])
- `groups` - массив групп (OPDSGroup[])
- `navigation` - объект пагинации (OPDSNavigation)

**Методы:**
- `setId(string $id): self`
- `setTitle(string $title): self`
- `addEntry(OPDSEntry $entry): self`
- `addLink(OPDSLink $link): self`
- `addFacet(OPDSFacet $facet): self`
- `addGroup(OPDSGroup $group): self`
- `setNavigation(?OPDSNavigation $navigation): self`
- `render(): string` (абстрактный)

#### OPDSEntry

Класс для создания записей в OPDS фидах.

**Основные свойства:**
- `id` - уникальный идентификатор записи
- `title` - заголовок записи
- `authors` - массив авторов
- `categories` - массив категорий
- `links` - массив ссылок
- `content` - содержание записи
- `summary` - краткое описание

**Методы:**
- `setId(string $id): self`
- `setTitle(string $title): self`
- `addAuthor(string $name, ?string $uri = null): self`
- `addCategory(string $term, ?string $label = null, ?string $scheme = null): self`
- `addLink(OPDSLink $link): self`
- `render(): string`

#### OPDSLink

Класс для создания ссылок в OPDS фидах и записях.

**Основные свойства:**
- `href` - URL ссылки
- `rel` - тип связи (self, alternate, acquisition и т.д.)
- `type` - MIME-тип ресурса
- `title` - заголовок ссылки
- `length` - размер ресурса в байтах

**Методы:**
- `setHref(string $href): self`
- `setRel(string $rel): self`
- `setType(?string $type): self`
- `addProperty(string $name, string $value): self`
- `render(): string`

### Вспомогательные классы

#### OPDSNavigation

Класс для генерации навигационных ссылок пагинации.

**Методы:**
- `generateLinks(): OPDSLink[]` - генерирует ссылки first, previous, next, last
- `getMetadata(): array` - возвращает метаданные пагинации (numberOfItems, itemsPerPage)
- `render(): string`

#### OPDSFacet

Класс для создания фасетной навигации (фильтров).

**Методы:**
- `addFacet(string $term, string $label, string $href, ?int $count = null, bool $active = false): self`
- `render(): string` - рендерит `<opds:facetGroup>` и `<opds:facet>`

#### OPDSGroup

Класс для группировки записей (opds:group).

**Методы:**
- `addEntry(OPDSEntry $entry): self`
- `render(): string` - рендерит `<opds:group>` с заголовком и записями

#### OPDSCache

Класс для кэширования OPDS фидов.

**Методы:**
- `getInstance(?string $cacheDir = null, int $ttl = 3600, bool $enabled = true): OPDSCache` (singleton)
- `get(string $key): ?string`
- `set(string $key, string $data): bool`
- `isValid(string $key): bool`
- `generateETag(string $content): string`
- `checkETag(string $etag): void`
- `setCacheHeaders(string $etag, ?int $lastModified = null): void`

#### OPDSErrorHandler

Централизованная обработка ошибок.

**Методы:**
- `generateErrorFeed(string $id, string $title, string $message, int $httpCode = 500): string`
- `sendError(string $id, string $title, string $message, int $httpCode = 500): void`
- `handleException(\Throwable $exception, int $httpCode = 500): void`
- `sendNotFoundError(string $resourceName = 'Ресурс'): void`
- `sendValidationError(string $message): void`
- `sendSqlError(string $message): void`

#### OPDSValidator

Класс для валидации входных данных.

**Методы:**
- `validateId(string $paramName, int $minValue = 1, ?int $maxValue = null): ?int`
- `validateString(string $paramName, int $maxLength = 255, bool $required = false, ?string $pattern = null): ?string`
- `validatePage(string $paramName = 'page', int $defaultValue = 1, int $minValue = 1): int`
- `validateEnum(string $paramName, array $allowedValues, ?string $defaultValue = null): ?string`
- `validateUuid(string $paramName = 'uuid', bool $required = false): ?string`
- `validateSearchQuery(string $paramName = 'q', int $minLength = 1, int $maxLength = 255, bool $required = false): ?string`
- `handleValidationException(\Throwable $exception): void`

### Реализация OPDS 1.2

#### OPDS2Feed

Конкретная реализация OPDSFeed для OPDS 1.2.

**Особенности:**
- Правильный порядок элементов в XML: id, title, updated, icon, links, metadata, entries
- Правильные namespace декларации: Atom, Dublin Core, OpenSearch, OPDS
- Поддержка групп (opds:group)
- Поддержка фасетов (opds:facetGroup)

## Типизация

Все классы используют строгую типизацию:
- `declare(strict_types=1);` в начале каждого файла
- Type hints для всех параметров методов
- Return type hints для всех методов
- Типизированные свойства классов (PHP 7.4+)

## Обработка ошибок

Обработка ошибок централизована через класс `OPDSErrorHandler`:

1. **Исключения** - все ошибки обрабатываются через исключения
2. **Логирование** - детали ошибок логируются, клиенту отправляются общие сообщения
3. **HTTP коды** - правильные HTTP коды ошибок (400, 404, 500)
4. **XML формат** - ошибки возвращаются в формате OPDS фида

## Валидация входных данных

Валидация всех входных данных выполняется через класс `OPDSValidator`:

1. **ID параметры** - проверка на числовое значение и диапазон
2. **Строковые параметры** - проверка длины и формата
3. **Поисковые запросы** - проверка длины и экранирование
4. **Enum параметры** - проверка на соответствие списку допустимых значений

## Кэширование

Кэширование реализовано через класс `OPDSCache`:

1. **Файловое кэширование** - кэш хранится в файлах
2. **TTL** - время жизни кэша (по умолчанию 3600 секунд)
3. **ETag** - поддержка HTTP ETag для условных запросов
4. **Инвалидация** - автоматическая инвалидация при обновлении БД

## Порядок элементов в XML фиде (OPDS 1.2)

Согласно спецификации OPDS 1.2, порядок элементов должен быть:

1. `id` (обязательно)
2. `title` (обязательно)
3. `updated` (обязательно)
4. `icon` (опционально)
5. `subtitle` (опционально)
6. `rights` (опционально)
7. `links` (обязательно, если есть)
8. `opds:numberOfItems` (опционально)
9. `opds:itemsPerPage` (опционально)
10. Метаданные (dc:, opds: и т.д.)
11. `opds:facetGroup` (опционально, перед entries)
12. `entry` или `opds:group` (обязательно, если есть записи)

## Namespace декларации

Правильные namespace для OPDS 1.2:

```xml
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:dc="http://purl.org/dc/terms/"
      xmlns:dcterms="http://purl.org/dc/terms/"
      xmlns:os="http://a9.com/-/spec/opensearch/1.1/"
      xmlns:opds="https://specs.opds.io/opds-1.2">
```

**Важно:** Dublin Core namespace должен быть `http://purl.org/dc/terms/`, а не `http://purl.org/dc/elements/1.1/`.

## Navigation vs Acquisition фиды

### Navigation фиды
- Используют `profile=opds-catalog;kind=navigation`
- Используют `rel="subsection"` для ссылок на подразделы
- Содержат навигационные записи (ссылки на другие фиды)

### Acquisition фиды
- Используют `profile=opds-catalog;kind=acquisition`
- Используют `rel="http://opds-spec.org/acquisition/*"` для ссылок на скачивание
- Содержат записи книг с ссылками на скачивание

## Безопасность

1. **SQL инъекции** - все запросы используют prepared statements
2. **XSS** - все выходные данные экранируются через `htmlspecialchars()`
3. **Валидация** - все входные данные валидируются через `OPDSValidator`
4. **Обработка ошибок** - не раскрываются внутренние детали системы клиенту

## Тестирование

Unit тесты находятся в директории `tests/opds/`:

- `OPDSFeedTest.php` - тесты для OPDSFeed
- `OPDSLinkTest.php` - тесты для OPDSLink
- `OPDSEntryTest.php` - тесты для OPDSEntry
- `OPDSValidatorTest.php` - тесты для OPDSValidator

Запуск тестов:
```bash
vendor/bin/phpunit tests/opds/
```

## Будущие улучшения

### Сервисный слой
Планируется создание сервисного слоя для разделения бизнес-логики:

- `OPDSService` - базовый сервис с Dependency Injection
- `OPDSFeedService` - сервис для генерации фидов
- `OPDSBookService` - сервис для работы с книгами
- `OPDSNavigationService` - сервис навигации

### Integration тесты
Создание integration тестов для проверки работы endpoints с реальной БД.

### Валидация XML
Автоматическая валидация генерируемого XML через OPDS валидаторы.
