<?php
declare(strict_types=1);

// ВАЖНО: Устанавливаем заголовок ДО включения других файлов
// Это гарантирует, что заголовки будут установлены правильно
header('Content-Type: application/atom+xml; charset=utf-8');

$search = isset($_GET['by']) ? $_GET['by'] : '';
switch ($search) {
	case 'author':
		include('search_author.php');
		break;

	default:
		include('search_book.php');
}
?>