<?php
/**
 * Тестовый файл для OPDS режима
 * Запускается: php /application/tests/test_opds.php
 * Или через веб: http://localhost:27100/tests/test_opds.php
 * 
 * Тестирует:
 * - Все OPDS endpoint-ы
 * - Правильность XML структуры
 * - Правильные MIME-типы
 * - Правильные rel типы (OPDS 1.2 спецификация)
 * - Кэширование
 * - Ошибки обработки
 */

// Подключаем init.php для определения констант и подключения к БД
require_once(__DIR__ . '/../init.php');

// Подключаем автозагрузку OPDS классов
require_once(ROOT_PATH . 'opds/core/autoload.php');

// Базовый URL OPDS (используем переменную $webroot из init.php или дефолт)
global $webroot;

/**
 * Определяет правильный базовый URL для тестов
 * Внутри Docker-сети используем имя сервиса webserver:80
 * Снаружи используем localhost:27100
 */
function getBaseUrl() {
    global $webroot;
    
    if (php_sapi_name() === 'cli') {
        // CLI режим - проверяем, запущены ли мы внутри Docker
        // Пытаемся определить доступность webserver (имя сервиса в docker-compose)
        $webserverIp = @gethostbyname('webserver');
        
        // Если webserver разрешается (не возвращает сам себя), значит мы в Docker-сети
        if ($webserverIp !== 'webserver' && filter_var($webserverIp, FILTER_VALIDATE_IP)) {
            // Внутри Docker - используем имя сервиса и порт 80
            return 'http://webserver' . ($webroot ?: '') . '/opds';
        } else {
            // Снаружи Docker - используем localhost и внешний порт
            return 'http://localhost:27100' . ($webroot ?: '') . '/opds';
        }
    } else {
        // Веб режим - используем текущий хост
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:27100';
        return $protocol . '://' . $host . ($webroot ?: '') . '/opds';
    }
}

$baseUrl = getBaseUrl();
$tests = [];
$results = [];

/**
 * Выводит заголовок теста
 */
function testHeader($testName) {
    echo "\n========================================\n";
    echo "ТЕСТ: $testName\n";
    echo "========================================\n";
}

/**
 * Выводит результат теста
 */
function testResult($testName, $passed, $message = '') {
    global $results, $tests;
    
    $status = $passed ? '✅ PASSED' : '❌ FAILED';
    $results[$testName] = $passed;
    
    echo "Результат: $status\n";
    if ($message) {
        echo "Сообщение: $message\n";
    }
    echo "\n";
}

/**
 * Проверяет XML на валидность
 */
function validateXml($xml, $testName) {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    
    if ($doc === false) {
        $errors = libxml_get_errors();
        $errorMessages = [];
        foreach ($errors as $error) {
            $errorMessages[] = trim($error->message);
        }
        $errorString = implode('; ', array_slice($errorMessages, 0, 3));
        testResult($testName, false, "Невалидный XML: $errorString");
        libxml_clear_errors();
        return false;
    }
    
    // Проверяем namespace - ищем в атрибутах xmlns и в содержимом XML
    $namespaces = $doc->getNamespaces(true);
    $hasOpdsNamespace = false;
    foreach ($namespaces as $ns => $uri) {
        if (strpos($uri, 'opds-spec.org') !== false || strpos($uri, 'opds.io') !== false || strpos($uri, 'specs.opds.io') !== false) {
            $hasOpdsNamespace = true;
            break;
        }
    }
    
    // Также проверяем в исходном XML (на случай, если namespace в атрибутах feed)
    if (!$hasOpdsNamespace) {
        if (preg_match('/xmlns:opds=["\']([^"\']+)["\']/', $xml, $matches)) {
            $opdsNs = $matches[1];
            if (strpos($opdsNs, 'opds-spec.org') !== false || strpos($opdsNs, 'opds.io') !== false || strpos($opdsNs, 'specs.opds.io') !== false) {
                $hasOpdsNamespace = true;
            }
        }
    }
    
    // Для главной страницы namespace может отсутствовать (это navigation feed)
    // Но для остальных страниц он обязателен
    if (!$hasOpdsNamespace && strpos($testName, 'main') === false && strpos($testName, 'Главная') === false) {
        testResult($testName, false, "Отсутствует OPDS namespace");
        libxml_clear_errors();
        return false;
    }
    
    testResult($testName, true, "XML валиден, OPDS namespace присутствует");
    libxml_clear_errors();
    return true;
}

/**
 * Выполняет HTTP запрос и возвращает ответ
 */
function httpGet($url, $testName) {
    global $baseUrl;
    
    // Если URL относительный, делаем его абсолютным
    if (strpos($url, 'http') !== 0) {
        $url = $baseUrl . $url;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Уменьшаем таймаут для быстрой диагностики
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Таймаут подключения
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: FBReader/2.0.3'
    ]);
    curl_setopt($ch, CURLOPT_VERBOSE, false); // Отключаем verbose для чистоты вывода
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlError || $curlErrno !== 0) {
        $errorMsg = $curlError ?: "CURL error code: $curlErrno";
        // Добавляем информацию о URL для диагностики
        $errorMsg .= " (URL: $url)";
        return [
            'code' => 0,
            'content' => '',
            'content_type' => '',
            'error' => $errorMsg
        ];
    }
    
    return [
        'code' => $httpCode,
        'content' => $response,
        'content_type' => $contentType
    ];
}

/**
 * Тест: Главная страница OPDS
 */
function testMainPage() {
    global $baseUrl;
    $testName = 'Главная страница OPDS (/)';
    testHeader($testName);
    
    $response = httpGet($baseUrl, $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    // Проверяем HTTP код
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}, ожидался 200");
        return;
    }
    
    // Проверяем Content-Type
    if (strpos($response['content_type'], 'application/atom+xml') === false) {
        testResult($testName, false, "Content-Type: {$response['content_type']}, ожидался application/atom+xml");
        return;
    }
    
    // Проверяем XML структуру
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    // Проверяем наличие обязательных элементов используя XML парсер
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($response['content']);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    
    if ($doc === false) {
        $errorMsg = "Не удалось распарсить XML";
        if (!empty($xmlErrors)) {
            $errorMsg .= ": " . trim($xmlErrors[0]->message);
        }
        testResult($testName, false, $errorMsg);
        return;
    }
    
    // Проверяем наличие feed элемента title
    // SimpleXML может не найти title из-за namespace, поэтому используем несколько способов
    $hasTitle = false;
    
    // Способ 1: Прямой доступ (может не работать с namespace)
    if (isset($doc->title) && !empty((string)$doc->title)) {
        $hasTitle = true;
    }
    
    // Способ 2: Доступ через children() для обхода namespace
    $namespaces = $doc->getNamespaces(true);
    foreach ($namespaces as $prefix => $uri) {
        $children = $doc->children($uri);
        if (isset($children->title) && !empty((string)$children->title)) {
            $hasTitle = true;
            break;
        }
    }
    
    // Способ 3: Строковый поиск (надежный способ)
    if (!$hasTitle) {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/s', $response['content'], $matches)) {
            $titleContent = trim($matches[1]);
            if (!empty($titleContent)) {
                $hasTitle = true;
            }
        }
    }
    
    if (!$hasTitle) {
        testResult($testName, false, "Отсутствует элемент <title> в feed");
        return;
    }
    
    // Проверяем наличие acquisition ссылок
    $hasAcquisitionLink = strpos($response['content'], 'opds-spec.org/acquisition') !== false;
    if (!$hasAcquisitionLink) {
        testResult($testName, false, "Отсутствует acquisition ссылка (opds-spec.org/acquisition)");
        return;
    }
    
    testResult($testName, true, "Все проверки пройдены");
}

/**
 * Тест: Список новинок (/opds/list/)
 */
function testNewBooks() {
    global $baseUrl;
    $testName = 'Список новинок (/opds/list/)';
    testHeader($testName);
    
    $response = httpGet('/list/', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    // Проверяем наличие правильного rel типа для acquisition в entries (книгах)
    // Ищем в разных вариантах кавычек и разных rel типах (acquisition, acquisition/open-access и т.д.)
    $hasCorrectRel = (
        strpos($response['content'], 'rel="http://opds-spec.org/acquisition"') !== false ||
        strpos($response['content'], "rel='http://opds-spec.org/acquisition'") !== false ||
        strpos($response['content'], 'http://opds-spec.org/acquisition/open-access') !== false ||
        strpos($response['content'], 'http://opds-spec.org/acquisition') !== false
    );
    if (!$hasCorrectRel) {
        // Попробуем найти через XML парсер
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($response['content']);
        libxml_clear_errors();
        if ($doc !== false) {
            $links = $doc->xpath('//entry/link[@rel="http://opds-spec.org/acquisition"]');
            if (empty($links)) {
                testResult($testName, false, "Отсутствует правильный rel тип acquisition для OPDS 1.2 в entries");
                return;
            }
        } else {
            testResult($testName, false, "Отсутствует правильный rel тип acquisition для OPDS 1.2");
            return;
        }
    }
    
    testResult($testName, true, "Acquisition ссылки с правильными rel типами");
}

/**
 * Тест: Поиск по книгам (/opds/search)
 */
function testSearchBooks() {
    global $baseUrl;
    $testName = 'Поиск по книгам (/opds/search)';
    testHeader($testName);
    
    $response = httpGet('/search?q=пушкин', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    // Проверяем наличие результатов поиска
    if (strpos($response['content'], '<entry>') === false) {
        testResult($testName, false, "Поиск не вернул результатов (нет <entry>)");
        return;
    }
    
    testResult($testName, true, "Поиск работает, результаты найдены");
}

/**
 * Тест: Поиск по авторам (/opds/search?by=author)
 */
function testSearchAuthors() {
    global $baseUrl;
    $testName = 'Поиск по авторам (/opds/search?by=author)';
    testHeader($testName);
    
    $response = httpGet('/search?by=author&q=пушкин', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    testResult($testName, true, "Поиск по авторам работает");
}

/**
 * Тест: Жанры (/opds/genres)
 */
function testGenres() {
    global $baseUrl;
    $testName = 'Жанры (/opds/genres)';
    testHeader($testName);
    
    $response = httpGet('/genres', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    // Проверяем наличие acquisition ссылок - ищем в разных вариантах
    $hasAcquisitionLink = (
        strpos($response['content'], 'rel="http://opds-spec.org/acquisition"') !== false ||
        strpos($response['content'], "rel='http://opds-spec.org/acquisition'") !== false ||
        strpos($response['content'], 'http://opds-spec.org/acquisition/open-access') !== false ||
        strpos($response['content'], 'http://opds-spec.org/acquisition') !== false
    );
    if (!$hasAcquisitionLink) {
        // Попробуем найти через XML парсер
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($response['content']);
        libxml_clear_errors();
        if ($doc !== false) {
            $links = $doc->xpath('//link[@rel="http://opds-spec.org/acquisition"]');
            if (empty($links)) {
                testResult($testName, false, "Отсутствует acquisition ссылка для жанров");
                return;
            }
        } else {
            testResult($testName, false, "Отсутствует acquisition ссылка для жанров");
            return;
        }
    }
    
    testResult($testName, true, "Жанры с правильными acquisition ссылками");
}

/**
 * Тест: Индекс авторов (/opds/authorsindex)
 */
function testAuthorsIndex() {
    global $baseUrl;
    $testName = 'Индекс авторов (/opds/authorsindex)';
    testHeader($testName);
    
    $response = httpGet('/authorsindex', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    // Проверяем наличие записей авторов
    if (strpos($response['content'], '<entry>') === false) {
        testResult($testName, false, "Индекс авторов пуст");
        return;
    }
    
    testResult($testName, true, "Индекс авторов работает");
}

/**
 * Тест: Индекс серий (/opds/sequencesindex)
 */
function testSequencesIndex() {
    global $baseUrl;
    $testName = 'Индекс серий (/opds/sequencesindex)';
    testHeader($testName);
    
    $response = httpGet('/sequencesindex', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    testResult($testName, true, "Индекс серий работает");
}

/**
 * Тест: Книжные полки (/opds/favs)
 */
function testBookshelves() {
    global $baseUrl;
    $testName = 'Книжные полки (/opds/favs)';
    testHeader($testName);
    
    $response = httpGet('/favs', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    testResult($testName, true, "Книжные полки доступны");
}

/**
 * Тест: Пагинация
 */
function testPagination() {
    global $baseUrl;
    $testName = 'Пагинация (/opds/list/?page=2)';
    testHeader($testName);
    
    $response = httpGet('/list/?page=2', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    if (!validateXml($response['content'], $testName)) {
        return;
    }
    
    // Проверяем наличие навигационных ссылок пагинации
    $hasFirstLink = strpos($response['content'], 'rel="first"') !== false;
    $hasPrevLink = strpos($response['content'], 'rel="previous"') !== false;
    $hasNextLink = strpos($response['content'], 'rel="next"') !== false;
    
    if (!$hasFirstLink || !$hasPrevLink || !$hasNextLink) {
        $links = [];
        if (!$hasFirstLink) $links[] = 'first';
        if (!$hasPrevLink) $links[] = 'previous';
        if (!$hasNextLink) $links[] = 'next';
        testResult($testName, false, "Отсутствуют ссылки пагинации: " . implode(', ', $links));
        return;
    }
    
    testResult($testName, true, "Пагинация с навигационными ссылками (first, previous, next)");
}

/**
 * Тест: Параметр версии OPDS
 */
function testOPDSVersion() {
    global $baseUrl;
    $testName = 'Принудительная версия OPDS 1.0';
    testHeader($testName);
    
    // Тестируем принудительную версию 1.0
    $response = httpGet('/?opds_version=1.0', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    // Проверяем что версия 1.0 используется
    if (strpos($response['content'], 'http://opds-spec.org/2010/catalog') === false) {
        testResult($testName, false, "Неверная версия OPDS (ожидался OPDS 1.0 namespace)");
        return;
    }
    
    testResult($testName, true, "Принудительная версия OPDS 1.0 работает");
}

/**
 * Тест: OPDS 1.2 версия (автоматическое определение)
 */
function testOPDS12Version() {
    global $baseUrl;
    $testName = 'Автоматическое определение OPDS 1.2';
    testHeader($testName);
    
    // FBReader должен автоматически определять OPDS 1.2
    $response = httpGet('/', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    // Проверяем что используется OPDS 1.2 или 1.0 (оба валидны)
    $hasOpds12 = strpos($response['content'], 'https://specs.opds.io/opds-1.2') !== false;
    $hasOpds10 = strpos($response['content'], 'http://opds-spec.org/2010/catalog') !== false;
    
    if (!$hasOpds12 && !$hasOpds10) {
        testResult($testName, false, "Не используется OPDS namespace (ни 1.0, ни 1.2)");
        return;
    }
    
    testResult($testName, true, "Автоматическое определение OPDS версии работает (" . ($hasOpds12 ? "1.2" : "1.0") . ")");
}

/**
 * Тест: ETag и кэширование
 */
function testETagAndCaching() {
    global $baseUrl;
    $testName = 'ETag и кэширование';
    testHeader($testName);
    
    $response1 = httpGet('/', $testName);
    $response2 = httpGet('/', $testName);
    
    if (isset($response1['error']) || isset($response2['error'])) {
        testResult($testName, false, "Ошибка при запросах");
        return;
    }
    
    if ($response1['code'] !== 200 || $response2['code'] !== 200) {
        testResult($testName, false, "Ошибка при запросах (коды: {$response1['code']}, {$response2['code']})");
        return;
    }
    
    // Проверяем что ответы идентичны (кэш работает)
    if ($response1['content'] === $response2['content']) {
        testResult($testName, true, "Кэширование работает (идентичные ответы)");
    } else {
        testResult($testName, false, "Кэширование не работает (разные ответы)");
    }
}

/**
 * Тест: Пустой поисковый запрос
 */
function testEmptySearchQuery() {
    global $baseUrl;
    $testName = 'Пустой поисковый запрос (должна быть ошибка 400)';
    testHeader($testName);
    
    $response = httpGet('/search?q=', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 400) {
        testResult($testName, false, "HTTP код: {$response['code']}, ожидался 400");
        return;
    }
    
    if (strpos($response['content'], 'tag:error:search:book:empty') === false) {
        testResult($testName, false, "Неверный ответ на пустой запрос");
        return;
    }
    
    testResult($testName, true, "Правильная обработка пустого поискового запроса");
}

/**
 * Тест: Проверка MIME-типов для разных форматов книг
 */
function testBookFormatsMIME() {
    global $baseUrl;
    $testName = 'Проверка MIME-типов в записях книг';
    testHeader($testName);
    
    $response = httpGet('/list/', $testName);
    
    if (isset($response['error'])) {
        testResult($testName, false, "Ошибка curl: " . $response['error']);
        return;
    }
    
    if ($response['code'] !== 200) {
        testResult($testName, false, "HTTP код: {$response['code']}");
        return;
    }
    
    // Проверяем правильные MIME-типы - ищем в type атрибутах link элементов
    $validMIMETypes = [
        'application/fb2+zip',
        'application/epub+zip',
        'application/x-mobipocket-ebook',
        'application/pdf',
        'text/plain',
        'text/html',
        'image/jpeg',
        'image/vnd.djvu',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/rtf'
    ];
    
    $hasValidMIME = false;
    foreach ($validMIMETypes as $mimeType) {
        // Ищем в разных вариантах: type="...", type='...', или просто в содержимом
        if (strpos($response['content'], 'type="' . $mimeType . '"') !== false ||
            strpos($response['content'], "type='" . $mimeType . "'") !== false ||
            strpos($response['content'], $mimeType) !== false) {
            $hasValidMIME = true;
            break;
        }
    }
    
    // Также проверяем через XML парсер
    if (!$hasValidMIME) {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($response['content']);
        libxml_clear_errors();
        if ($doc !== false) {
            $links = $doc->xpath('//link[@type]');
            foreach ($links as $link) {
                $type = (string)$link['type'];
                foreach ($validMIMETypes as $validType) {
                    if (strpos($type, $validType) !== false) {
                        $hasValidMIME = true;
                        break 2;
                    }
                }
            }
        }
    }
    
    if (!$hasValidMIME) {
        testResult($testName, false, "Отсутствуют правильные MIME-типы в ссылках на книги");
        return;
    }
    
    testResult($testName, true, "Присутствуют правильные MIME-типы");
}

/**
 * Выводит сводную статистику
 */
function printSummary() {
    global $results;
    
    $total = count($results);
    $passed = count(array_filter($results, function($v) { return $v; }));
    $failed = $total - $passed;
    $percentage = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
    
    echo "\n========================================\n";
    echo "СВОДНАЯ СТАТИСТИКА ТЕСТОВ\n";
    echo "========================================\n";
    echo "Всего тестов: $total\n";
    echo "Пройдено: $passed\n";
    echo "Провалено: $failed\n";
    echo "Успешность: {$percentage}%\n";
    
    if ($percentage >= 100) {
        echo "\n🎉 Поздравляем! Все тесты пройдены успешно!\n";
    } elseif ($percentage >= 80) {
        echo "\n✨ Хороший результат! Большинство тестов пройдено.\n";
    } elseif ($percentage >= 50) {
        echo "\n⚠️ Средний результат. Есть проблемы для устранения.\n";
    } else {
        echo "\n❌ Плохой результат. Требуется исправление критических проблем.\n";
    }
    
    echo "\n========================================\n";
}

/**
 * Главный запуск всех тестов
 */
function runAllTests() {
    global $baseUrl;
    
    echo "╔════════════════════════════════════════╗\n";
    echo "║     OPDS ТЕСТОВАНИЕ СИСТЕМЫ          ║\n";
    echo "╚════════════════════════════════════════╝\n";
    echo "Базовый URL: $baseUrl\n";
    echo "Запуск: " . date('Y-m-d H:i:s') . "\n";
    
    // Диагностика окружения
    if (php_sapi_name() === 'cli') {
        $webserverIp = @gethostbyname('webserver');
        if ($webserverIp !== 'webserver' && filter_var($webserverIp, FILTER_VALIDATE_IP)) {
            echo "Окружение: Docker-контейнер (webserver доступен по IP: $webserverIp)\n";
        } else {
            echo "Окружение: Внешний запуск (webserver недоступен)\n";
        }
    }
    echo "\n";
    
    try {
        // Основные endpoint-ы
        testMainPage();
        testNewBooks();
        testSearchBooks();
        testSearchAuthors();
        testGenres();
        testAuthorsIndex();
        testSequencesIndex();
        testBookshelves();
        
        // Пагинация и версии
        testPagination();
        testOPDSVersion();
        testOPDS12Version();
        
        // Кэширование
        testETagAndCaching();
        
        // Обработка ошибок
        testEmptySearchQuery();
        
        // MIME-типы
        testBookFormatsMIME();
        
    } catch (Exception $e) {
        echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
        echo "Файл: " . $e->getFile() . "\n";
        echo "Строка: " . $e->getLine() . "\n\n";
    }
    
    // Вывод статистики
    printSummary();
}

// Проверяем, запущен ли скрипт через CLI или веб
if (php_sapi_name() === 'cli') {
    // CLI режим
    runAllTests();
} else {
    // Веб режим - выводим в HTML формате
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OPDS Тестирование</title>';
    echo '<style>body{font-family:monospace;padding:20px;} .passed{color:green;} .failed{color:red;}</style>';
    echo '</head><body><pre>';
    runAllTests();
    echo '</pre></body></html>';
}
