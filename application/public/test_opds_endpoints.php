<?php
/**
 * Диагностический скрипт для проверки конкретных OPDS endpoints
 * Запускается через браузер: http://your-domain/test_opds_endpoints.php
 */

// Подключаем init.php
require_once(__DIR__ . '/../init.php');

// Включаем отображение всех ошибок
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/php_errors.log');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>OPDS Endpoints Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test { margin: 20px 0; padding: 15px; border: 1px solid #ddd; }
        .success { background: #d4edda; border-color: #c3e6cb; }
        .error { background: #f8d7da; border-color: #f5c6cb; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
<h1>OPDS Endpoints Test</h1>

<?php
// Получаем базовый URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:27100';
$baseUrl = $protocol . '://' . $host;

echo "<p><strong>Base URL:</strong> " . htmlspecialchars($baseUrl) . "</p>";
echo "<p><strong>Webroot:</strong> '" . htmlspecialchars($webroot ?? 'NOT SET') . "'</p>";

// Тестируем endpoints
$endpoints = [
    '/' => 'Главная страница OPDS',
    '/list/' => 'Список книг',
    '/authorsindex' => 'Индекс авторов',
    '/genres' => 'Жанры',
    '/sequencesindex' => 'Индекс серий',
];

// Симулируем запросы
foreach ($endpoints as $endpoint => $description) {
    echo "<div class='test'>";
    echo "<h2>$description ($endpoint)</h2>";
    
    // Создаем URL
    $testUrl = $baseUrl . ($webroot ?? '') . '/opds' . $endpoint;
    echo "<p><strong>URL:</strong> <a href='" . htmlspecialchars($testUrl) . "' target='_blank'>" . htmlspecialchars($testUrl) . "</a></p>";
    
    // Пытаемся выполнить запрос через внутренний механизм
    try {
        // Сохраняем текущие переменные
        $oldGet = $_GET;
        $oldServer = $_SERVER;
        $oldRequestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Устанавливаем REQUEST_URI для симуляции запроса
        $_SERVER['REQUEST_URI'] = ($webroot ?? '') . '/opds' . $endpoint;
        
        // Парсим путь
        $pathParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
        $module = $pathParts[0] ?? '';
        $action = $pathParts[1] ?? '';
        
        echo "<p><strong>Parsed:</strong> module='$module', action='$action'</p>";
        
        // Пытаемся вызвать decode_gurl
        if (function_exists('decode_gurl')) {
            global $url;
            $url = decode_gurl($webroot ?? '');
            echo "<p><strong>URL object:</strong> mod='{$url->mod}', action='{$url->action}'</p>";
            
            if ($url->mod === 'opds') {
                // Включаем автозагрузку
                require_once(ROOT_PATH . 'opds/core/autoload.php');
                
                // Определяем, какой файл нужно включить
                $opdsFile = null;
                switch ($url->action) {
                    case 'list':
                        $opdsFile = 'list.php';
                        break;
                    case 'authorsindex':
                        $opdsFile = 'authorsindex.php';
                        break;
                    case 'genres':
                        $opdsFile = 'genres.php';
                        break;
                    case 'sequencesindex':
                        $opdsFile = 'sequencesindex.php';
                        break;
                    default:
                        $opdsFile = 'main.php';
                }
                
                echo "<p><strong>OPDS file:</strong> $opdsFile</p>";
                
                // Пытаемся включить файл
                $opdsPath = ROOT_PATH . 'opds/' . $opdsFile;
                if (file_exists($opdsPath)) {
                    echo "<p class='success'>✓ Файл существует</p>";
                    
                    // Пытаемся выполнить файл с перехватом вывода
                    ob_start();
                    try {
                        // Устанавливаем webroot если не установлен
                        if (!isset($webroot) || empty($webroot)) {
                            $webroot = '';
                        }
                        
                        include($opdsPath);
                        $output = ob_get_clean();
                        
                        if (!empty($output)) {
                            $outputLen = strlen($output);
                            echo "<p class='success'>✓ Файл выполнен, вывод: $outputLen байт</p>";
                            echo "<p><strong>Первые 500 символов:</strong></p>";
                            echo "<pre>" . htmlspecialchars(substr($output, 0, 500)) . "...</pre>";
                        } else {
                            echo "<p class='error'>✗ Файл выполнен, но вывод пустой</p>";
                        }
                    } catch (Throwable $e) {
                        ob_end_clean();
                        echo "<p class='error'>✗ Ошибка выполнения: " . htmlspecialchars($e->getMessage()) . "</p>";
                        echo "<p>Файл: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
                        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
                    }
                } else {
                    echo "<p class='error'>✗ Файл не существует: " . htmlspecialchars($opdsPath) . "</p>";
                }
            } else {
                echo "<p class='error'>✗ URL mod не 'opds': '{$url->mod}'</p>";
            }
        } else {
            echo "<p class='error'>✗ Функция decode_gurl не найдена</p>";
        }
        
        // Восстанавливаем переменные
        $_GET = $oldGet;
        $_SERVER = $oldServer;
        $_SERVER['REQUEST_URI'] = $oldRequestUri;
        
    } catch (Throwable $e) {
        echo "<p class='error'>✗ Исключение: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>Файл: " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    echo "</div>";
}
?>

<h2>Проверка логов ошибок</h2>
<?php
$logFiles = [
    '/tmp/php_errors.log',
    '/var/log/php_errors.log',
    '/var/log/nginx/application_php_errors.log',
    sys_get_temp_dir() . '/php_errors.log',
];

foreach ($logFiles as $logFile) {
    if (file_exists($logFile) && is_readable($logFile)) {
        echo "<h3>" . htmlspecialchars($logFile) . "</h3>";
        $logContent = file_get_contents($logFile);
        if (!empty($logContent)) {
            $lines = explode("\n", $logContent);
            $recentLines = array_slice($lines, -50);
            echo "<pre>" . htmlspecialchars(implode("\n", $recentLines)) . "</pre>";
        } else {
            echo "<p>Файл пустой</p>";
        }
    }
}
?>
</body>
</html>
