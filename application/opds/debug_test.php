<?php
/**
 * Диагностический скрипт для проверки OPDS
 * Запускается через браузер: http://your-domain/opds/debug_test.php
 */

// Устанавливаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

echo "<h1>OPDS Debug Test</h1>";

// Проверяем, что init.php был выполнен
echo "<h2>1. Проверка глобальных переменных</h2>";
if (!defined('ROOT_PATH')) {
    die("ERROR: ROOT_PATH не определена. Нужно подключить init.php");
}
echo "✓ ROOT_PATH определена: " . ROOT_PATH . "<br>";

// Проверяем глобальные переменные
global $dbh, $webroot, $cdt;
if (!isset($dbh)) {
    echo "✗ ERROR: \$dbh не установлена<br>";
} else {
    echo "✓ \$dbh установлена<br>";
}

if (!isset($webroot)) {
    echo "✗ ERROR: \$webroot не установлена<br>";
} else {
    echo "✓ \$webroot установлена: " . htmlspecialchars($webroot) . "<br>";
}

if (!isset($cdt)) {
    echo "✗ ERROR: \$cdt не установлена<br>";
} else {
    echo "✓ \$cdt установлена: " . htmlspecialchars($cdt) . "<br>";
}

// Проверяем автозагрузку
echo "<h2>2. Проверка автозагрузки классов</h2>";
require_once(ROOT_PATH . 'opds/core/autoload.php');
echo "✓ autoload.php подключен<br>";

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
        echo "✓ Класс $className загружен<br>";
    } else {
        echo "✗ ERROR: Класс $className НЕ найден<br>";
    }
}

// Тестируем создание объектов
echo "<h2>3. Тест создания объектов</h2>";
try {
    $feedService = new OPDSFeedService($dbh, $webroot, $cdt);
    echo "✓ OPDSFeedService создан успешно<br>";
} catch (Exception $e) {
    echo "✗ ERROR при создании OPDSFeedService: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "   Файл: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "<br>";
}

try {
    $cache = OPDSCache::getInstance();
    echo "✓ OPDSCache создан успешно<br>";
} catch (Exception $e) {
    echo "✗ ERROR при создании OPDSCache: " . htmlspecialchars($e->getMessage()) . "<br>";
}

// Тестируем создание фида
echo "<h2>4. Тест создания фида</h2>";
try {
    $feedService = new OPDSFeedService($dbh, $webroot, $cdt);
    $feed = $feedService->createFeed('tag:test', 'Test Feed', 'navigation');
    echo "✓ Фид создан успешно<br>";
    
    $feedService->addSelfLink($feed, $webroot . '/opds/', 'navigation');
    echo "✓ Self ссылка добавлена<br>";
    
    $xml = $feed->render();
    echo "✓ Фид отрендерен, размер: " . strlen($xml) . " байт<br>";
    echo "<pre>" . htmlspecialchars(substr($xml, 0, 500)) . "...</pre>";
} catch (Exception $e) {
    echo "✗ ERROR при создании фида: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "   Файл: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "<br>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>Тест завершен</h2>";
