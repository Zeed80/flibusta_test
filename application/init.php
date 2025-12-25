<?php
define('ROOT_PATH', '/application/');
define('RECORDS_PAGE', 10);
define('BOOKS_PAGE', 10);
define('AUTHORS_PAGE', 50);
define('SERIES_PAGE', 50);
define('OPDS_FEED_COUNT', 100);
define('COUNT_BOOKS', true);
include(ROOT_PATH . 'functions.php');
include(ROOT_PATH . 'dbinit.php');
include_once(ROOT_PATH . 'webroot.php');

// Проверка успешности подключения к БД
if (!isset($dbh) || $dbh === null) {
	// Логируем критическую ошибку
	error_log("КРИТИЧЕСКАЯ ОШИБКА: Не удалось подключиться к базе данных. Проверьте настройки подключения.");
	
	// Если это веб-запрос, отправляем HTTP 500 и выводим понятное сообщение
	if (!headers_sent()) {
		http_response_code(500);
		header('Content-Type: text/html; charset=utf-8');
	}
	
	// Выводим понятное сообщение об ошибке
	die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка подключения к БД</title></head><body><h1>Ошибка подключения к базе данных</h1><p>Не удалось подключиться к базе данных. Пожалуйста, проверьте:</p><ul><li>Настройки подключения в .env файле</li><li>Пароль БД в secrets/flibusta_pwd.txt</li><li>Статус контейнера PostgreSQL: <code>docker-compose ps postgres</code></li><li>Логи контейнера: <code>docker-compose logs postgres</code></li></ul><p>Подробности ошибки в логах PHP-FPM.</p></body></html>');
}

session_set_cookie_params(3600 * 24 * 31 * 12,"/");
#session_start();

// Установка кодировки UTF-8
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

error_reporting(E_ALL);

$cdt = date('Y-m-d H:i:s');

