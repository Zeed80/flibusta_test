<?php
/**
 * Диагностический скрипт для проверки OPDS
 * Запускается через браузер: http://your-domain/debug_opds_test.php
 */

// Подключаем init.php для инициализации
require_once(__DIR__ . '/../init.php');

// Устанавливаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>OPDS Debug Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 30px; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
    </style>
</head>
<body>
<h1>OPDS Debug Test</h1>

<?php
// Проверяем, что init.php был выполнен
echo "<h2>1. Проверка глобальных переменных</h2>";
if (!defined('ROOT_PATH')) {
    die("<p class='error'>ERROR: ROOT_PATH не определена. Нужно подключить init.php</p>");
}
echo "<p class='success'>✓ ROOT_PATH определена: " . htmlspecialchars(ROOT_PATH) . "</p>";

// Проверяем глобальные переменные
global $dbh, $webroot, $cdt;
if (!isset($dbh)) {
    echo "<p class='error'>✗ ERROR: \$dbh не установлена</p>";
} else {
    echo "<p class='success'>✓ \$dbh установлена (" . get_class($dbh) . ")</p>";
}

if (!isset($webroot)) {
    echo "<p class='error'>✗ ERROR: \$webroot не установлена</p>";
} else {
    echo "<p class='success'>✓ \$webroot установлена: " . htmlspecialchars($webroot) . "</p>";
}

if (!isset($cdt)) {
    echo "<p class='error'>✗ ERROR: \$cdt не установлена</p>";
} else {
    echo "<p class='success'>✓ \$cdt установлена: " . htmlspecialchars($cdt) . "</p>";
}

// Проверяем автозагрузку
echo "<h2>2. Проверка автозагрузки классов</h2>";
try {
    require_once(ROOT_PATH . 'opds/core/autoload.php');
    echo "<p class='success'>✓ autoload.php подключен</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ ERROR при подключении autoload.php: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    exit;
}

// Проверяем основные классы
$classesToCheck = [
    'OPDSErrorHandler',
    'OPDSValidator',
    'OPDSCache',
    'OPDSFeedFactory',
    'OPDSFeed',
    'OPDSEntry',
    'OPDSLink',
    'OPDSVersion',
    'OPDSFeedService',
    'OPDSBookService',
    'OPDSNavigationService',
    'OPDS2Feed'
];

foreach ($classesToCheck as $className) {
    if (class_exists($className)) {
        echo "<p class='success'>✓ Класс $className загружен</p>";
    } else {
        echo "<p class='error'>✗ ERROR: Класс $className НЕ найден</p>";
    }
}

// Тестируем создание объектов
echo "<h2>3. Тест создания объектов</h2>";
try {
    if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
        throw new Exception("Глобальные переменные не установлены");
    }
    $feedService = new OPDSFeedService($dbh, $webroot, $cdt);
    echo "<p class='success'>✓ OPDSFeedService создан успешно</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ ERROR при создании OPDSFeedService: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Файл: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

try {
    $cache = OPDSCache::getInstance();
    echo "<p class='success'>✓ OPDSCache создан успешно</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ ERROR при создании OPDSCache: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Тестируем создание фида
echo "<h2>4. Тест создания фида</h2>";
try {
    if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
        throw new Exception("Глобальные переменные не установлены");
    }
    $feedService = new OPDSFeedService($dbh, $webroot, $cdt);
    $feed = $feedService->createFeed('tag:test', 'Test Feed', 'navigation');
    echo "<p class='success'>✓ Фид создан успешно</p>";
    
    $feedService->addSelfLink($feed, $webroot . '/opds/', 'navigation');
    echo "<p class='success'>✓ Self ссылка добавлена</p>";
    
    $xml = $feed->render();
    echo "<p class='success'>✓ Фид отрендерен, размер: " . strlen($xml) . " байт</p>";
    echo "<h3>Первые 500 символов XML:</h3>";
    echo "<pre>" . htmlspecialchars(substr($xml, 0, 500)) . "...</pre>";
} catch (Exception $e) {
    echo "<p class='error'>✗ ERROR при создании фида: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Файл: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>Тест завершен</h2>";
echo "<p><a href='" . htmlspecialchars($webroot) . "/opds/'>Попробовать открыть OPDS каталог</a></p>";
?>
</body>
</html>
