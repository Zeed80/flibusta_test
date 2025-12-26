<?php
$search = isset($_GET['by']) ? $_GET['by'] : '';
switch ($search) {
	case 'author':
		include('search_author.php');
		break;

	default:
		include('search_book.php');
}
?>