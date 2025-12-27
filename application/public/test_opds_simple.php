<?php
/**
 * Простой тест OPDS endpoints
 * Открыть: http://192.168.1.15:27100/test_opds_simple.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

echo "<h1>Simple OPDS Test</h1>";

// Включаем init.php
require_once(__DIR__ . '/../init.php');

echo "<p>✓ init.php loaded</p>";
echo "<p>DBH: " . (isset($dbh) ? 'SET' : 'NOT SET') . "</p>";
echo "<p>Webroot: '" . ($webroot ?? 'NOT SET') . "'</p>";
echo "<p>CDT: " . ($cdt ?? 'NOT SET') . "</p>";

// Вызываем decode_gurl
if (function_exists('decode_gurl')) {
    global $url;
    $_SERVER['REQUEST_URI'] = '/opds/authorsindex';
    $url = decode_gurl($webroot ?? '');
    echo "<p>✓ decode_gurl called</p>";
    echo "<p>URL mod: " . ($url->mod ?? 'NOT SET') . "</p>";
    echo "<p>URL action: " . ($url->action ?? 'NOT SET') . "</p>";
} else {
    die("decode_gurl function not found!");
}

// Подключаем автозагрузку
require_once(ROOT_PATH . 'opds/core/autoload.php');
echo "<p>✓ autoload.php loaded</p>";

// Проверяем классы
if (class_exists('OPDSErrorHandler')) {
    echo "<p>✓ OPDSErrorHandler exists</p>";
} else {
    die("OPDSErrorHandler class not found!");
}

// Устанавливаем webroot если пустой
if (empty($webroot)) {
    $webroot = '';
}

// Пытаемся включить authorsindex.php напрямую
echo "<h2>Testing authorsindex.php</h2>";
$opdsFile = ROOT_PATH . 'opds/authorsindex.php';
if (file_exists($opdsFile)) {
    echo "<p>✓ File exists: $opdsFile</p>";
    
    // Захватываем вывод
    ob_start();
    try {
        include($opdsFile);
        $output = ob_get_clean();
        echo "<p class='success'>✓ File executed successfully</p>";
        echo "<p>Output length: " . strlen($output) . " bytes</p>";
        echo "<pre>" . htmlspecialchars(substr($output, 0, 1000)) . "...</pre>";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "<p class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>File: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
} else {
    echo "<p class='error'>✗ File not found: $opdsFile</p>";
}
