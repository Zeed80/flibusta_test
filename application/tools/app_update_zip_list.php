<?php
// Константы путей (вынесены для удобства конфигурации)
if (!defined('FLIBUSTA_BOOKS_DIR')) {
	define('FLIBUSTA_BOOKS_DIR', '/application/flibusta');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Используем абсолютный путь для надежности
$dbinit_path = '/application/dbinit.php';
if (!file_exists($dbinit_path)) {
	$error = "Ошибка: Файл dbinit.php не найден: $dbinit_path";
	error_log($error);
	echo $error . "\n";
	exit(1);
}
include($dbinit_path);

// Проверяем подключение к базе данных
if (!isset($dbh) || $dbh === null) {
	$error = "Ошибка: Не удалось подключиться к базе данных";
	error_log($error);
	echo $error . "\n";
	exit(1);
}

// Проверяем существование директории
if (!is_dir(FLIBUSTA_BOOKS_DIR)) {
	$error = "Ошибка: Директория не существует: " . FLIBUSTA_BOOKS_DIR;
	error_log($error);
	echo $error . "\n";
	exit(1);
}

// Проверяем права на чтение директории
if (!is_readable(FLIBUSTA_BOOKS_DIR)) {
	$error = "Ошибка: Нет прав на чтение директории: " . FLIBUSTA_BOOKS_DIR;
	error_log($error);
	echo $error . "\n";
	exit(1);
}

echo "Начало сканирования ZIP файлов в директории: " . FLIBUSTA_BOOKS_DIR . "\n";

// Открываем директорию с константой
$handle = @opendir(FLIBUSTA_BOOKS_DIR);
if (!$handle) {
	$error = "Ошибка: Не удалось открыть директорию: " . FLIBUSTA_BOOKS_DIR;
	error_log($error);
	echo $error . "\n";
	exit(1);
}

try {
	$stmt = $dbh->prepare("TRUNCATE book_zip;");
	$stmt->execute();
	echo "Таблица book_zip очищена\n";

	$dbh->beginTransaction();
	$processed_count = 0;
	$skipped_count = 0;

	while (false !== ($entry = readdir($handle))) {
		// Пропускаем . и ..
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		
		if (strpos($entry, "-") !== false && strpos($entry, ".zip") !== false && substr($entry, -9) !== ".zip.part") {
			$dt = str_replace(".zip", "", $entry);
			$dt = str_replace("f.n.", "f.n-", $dt);
			$dt = str_replace("f.fb2.", "f.n-", $dt);
			echo "[$dt]";
			
			$fn = explode("-", $dt);
			$u = 1;
			if (strpos($entry, "fb2") !== false) {
				$u = 0;
			}
			
			if (strpos($entry, "d.fb2-009") !== false) {
				$skipped_count++;
			} else {
				// Проверяем, что у нас есть достаточно элементов в массиве
				if (count($fn) >= 3 && !empty($fn[1]) && !empty($fn[2])) {
					$stmt = $dbh->prepare("INSERT INTO book_zip (filename, start_id, end_id, usr) VALUES (:fn, :start, :end, :usr)");
					$stmt->bindParam(":fn", $entry);
					$stmt->bindParam(":start", $fn[1]);
					$stmt->bindParam(":end", $fn[2]);
					$stmt->bindParam(":usr", $u);
					$stmt->execute();
					$processed_count++;
				} else {
					echo " (пропущен: неверный формат имени)";
					$skipped_count++;
				}
			}
		}
		echo "\n";
	}
	
	$dbh->commit();
	closedir($handle);
	
	echo "\nОбработано файлов: $processed_count\n";
	if ($skipped_count > 0) {
		echo "Пропущено файлов: $skipped_count\n";
	}
	
	if ($processed_count === 0) {
		echo "Предупреждение: Не найдено ZIP файлов для обработки\n";
	}
	
} catch (Exception $e) {
	if ($dbh->inTransaction()) {
		$dbh->rollBack();
	}
	$error = "Ошибка при обработке: " . $e->getMessage();
	error_log($error);
	echo $error . "\n";
	closedir($handle);
	exit(1);
}
