<?php
/**
 * Диагностический скрипт для проверки реального XML ответа от OPDS
 */

require_once(__DIR__ . '/../init.php');
require_once(ROOT_PATH . 'opds/core/autoload.php');

// Определяем базовый URL
function getBaseUrl() {
    global $webroot;
    if (php_sapi_name() === 'cli') {
        $webserverIp = @gethostbyname('webserver');
        if ($webserverIp !== 'webserver' && filter_var($webserverIp, FILTER_VALIDATE_IP)) {
            return 'http://webserver' . ($webroot ?: '') . '/opds';
        } else {
            return 'http://localhost:27100' . ($webroot ?: '') . '/opds';
        }
    } else {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:27100';
        return $protocol . '://' . $host . ($webroot ?: '') . '/opds';
    }
}

$baseUrl = getBaseUrl();

function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: FBReader/2.0.3']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'content' => $response];
}

echo "=== ДИАГНОСТИКА OPDS ===\n\n";

// 1. Главная страница
echo "1. Главная страница:\n";
$response = fetchUrl($baseUrl);
echo "HTTP код: {$response['code']}\n";
if ($response['code'] === 200) {
    // Ищем title
    if (preg_match('/<title[^>]*>(.*?)<\/title>/s', $response['content'], $matches)) {
        echo "✅ Найден title: " . htmlspecialchars(substr($matches[1], 0, 100)) . "\n";
    } else {
        echo "❌ Title не найден\n";
        echo "Первые 500 символов XML:\n" . substr($response['content'], 0, 500) . "\n";
    }
}
echo "\n";

// 2. Список новинок
echo "2. Список новинок:\n";
$response = fetchUrl($baseUrl . '/list/');
echo "HTTP код: {$response['code']}\n";
if ($response['code'] === 200) {
    // Ищем acquisition ссылки в entries
    $acquisitionCount = preg_match_all('/rel=["\']http:\/\/opds-spec\.org\/acquisition[^"\']*["\']/', $response['content']);
    echo "Найдено acquisition ссылок: $acquisitionCount\n";
    if ($acquisitionCount === 0) {
        // Ищем любые ссылки в entries
        if (preg_match_all('/<entry[^>]*>.*?<\/entry>/s', $response['content'], $entries)) {
            echo "Найдено entries: " . count($entries[0]) . "\n";
            if (count($entries[0]) > 0) {
                echo "Первый entry (первые 500 символов):\n" . substr($entries[0][0], 0, 500) . "\n";
            }
        }
    }
}
echo "\n";

// 3. Поиск по книгам
echo "3. Поиск по книгам:\n";
$response = fetchUrl($baseUrl . '/search?q=пушкин');
echo "HTTP код: {$response['code']}\n";
if ($response['code'] === 200) {
    // Проверяем валидность XML
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($response['content']);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    
    if ($doc === false && !empty($errors)) {
        echo "❌ Невалидный XML:\n";
        foreach (array_slice($errors, 0, 3) as $error) {
            echo "  - " . trim($error->message) . " (строка {$error->line})\n";
        }
        // Показываем проблемную область
        $lines = explode("\n", $response['content']);
        if (isset($lines[30])) {
            echo "\nСтрока 31 (проблемная):\n";
            echo htmlspecialchars($lines[30]) . "\n";
        }
    } else {
        echo "✅ XML валиден\n";
    }
}
echo "\n";

// 4. Жанры
echo "4. Жанры:\n";
$response = fetchUrl($baseUrl . '/genres');
echo "HTTP код: {$response['code']}\n";
if ($response['code'] === 200) {
    $acquisitionCount = preg_match_all('/rel=["\']http:\/\/opds-spec\.org\/acquisition[^"\']*["\']/', $response['content']);
    echo "Найдено acquisition ссылок: $acquisitionCount\n";
    if ($acquisitionCount === 0) {
        // Ищем любые ссылки
        if (preg_match_all('/<link[^>]*>/', $response['content'], $links)) {
            echo "Найдено ссылок всего: " . count($links[0]) . "\n";
            if (count($links[0]) > 0) {
                echo "Первая ссылка: " . $links[0][0] . "\n";
            }
        }
    }
}
echo "\n";

// 5. MIME-типы
echo "5. MIME-типы в списке:\n";
$response = fetchUrl($baseUrl . '/list/');
echo "HTTP код: {$response['code']}\n";
if ($response['code'] === 200) {
    $mimeTypes = [
        'application/fb2+zip',
        'application/epub+zip',
        'application/x-mobipocket-ebook',
        'application/pdf'
    ];
    foreach ($mimeTypes as $mime) {
        if (strpos($response['content'], $mime) !== false) {
            echo "✅ Найден: $mime\n";
        }
    }
    // Ищем все type атрибуты
    if (preg_match_all('/type=["\']([^"\']+)["\']/', $response['content'], $types)) {
        $uniqueTypes = array_unique($types[1]);
        echo "Найдено уникальных type атрибутов: " . count($uniqueTypes) . "\n";
        echo "Примеры: " . implode(', ', array_slice($uniqueTypes, 0, 5)) . "\n";
    }
}
echo "\n";
