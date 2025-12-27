<?php
declare(strict_types=1);

// Инициализация webroot для OPDS запросов
if (!isset($webroot) || empty($webroot)) {
    // Пытаемся определить webroot из REQUEST_URI
    $urlx = parse_url($_SERVER['REQUEST_URI'] ?? '');
    $path = $urlx['path'] ?? '';
    
    // Если путь содержит /opds/, то webroot - это часть пути до /opds/
    if (preg_match('#^(.*?)/opds/#', $path, $matches)) {
        $webroot = $matches[1];
    } else {
        // По умолчанию пустая строка (корень сайта)
        $webroot = '';
    }
    
    // Убеждаемся, что webroot заканчивается на / или пустая
    if (!empty($webroot) && !str_ends_with($webroot, '/')) {
        $webroot = $webroot . '/';
    }
}

// ВАЖНО: Устанавливаем заголовок ДО включения других файлов
// Это гарантирует, что заголовки будут установлены правильно
// Заголовок будет переустановлен в каждом файле, но это безопасно
header('Content-Type: application/atom+xml; charset=utf-8');

// Подключаем автозагрузку OPDS классов
require_once(ROOT_PATH . 'opds/core/autoload.php');

// Проверяем, что $url определен (должен быть установлен через decode_gurl в index.php)
if (!isset($url) || !is_object($url)) {
    error_log("OPDS index.php: \$url object is not defined. decode_gurl() must be called before including this file.");
    OPDSErrorHandler::sendError(
        'tag:error:init',
        'Ошибка инициализации',
        'Не удалось определить параметры запроса',
        500
    );
}

// Определяем action из $url->action, если он установлен
$action = isset($url->action) ? (string)$url->action : '';

switch ($action) {
	case 'list':
		include('list.php');
		break;
	case 'authorsindex':
		include('authorsindex.php');
		break;
	case 'author':
		include('author.php');
		break;
	case 'sequencesindex':
		include('sequencesindex.php');
		break;
	case 'genres':
		include('genres.php');
		break;
	case 'listgenres':
		include('listgenres.php');
		break;
	case 'fav':
		include('fav.php');
		break;
	case 'favs':
		include('favs.php');
		break;
	case 'search':
		include('search.php');
		break;

	default:
		include('main.php');
}
