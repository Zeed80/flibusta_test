<?php
declare(strict_types=1);

// ВАЖНО: Устанавливаем заголовок ДО включения других файлов
// Это гарантирует, что заголовки будут установлены правильно
header('Content-Type: application/atom+xml; charset=utf-8');

// Определяем тип поиска: по автору или по книгам
// Поддерживаем оба варианта параметров: 'by' и прямой запрос
$search = isset($_GET['by']) ? $_GET['by'] : '';

// Если параметр 'by' не указан, но есть параметр 'q', это поиск по книгам
if (empty($search) && isset($_GET['q'])) {
	$search = 'book';
}

switch ($search) {
	case 'author':
		include('search_author.php');
		break;

	case 'book':
	default:
		include('search_book.php');
		break;
}
?>