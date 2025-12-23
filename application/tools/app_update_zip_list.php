<?php
// Константы путей (вынесены для удобства конфигурации)
if (!defined('FLIBUSTA_BOOKS_DIR')) {
	define('FLIBUSTA_BOOKS_DIR', '/application/flibusta');
}

error_reporting(E_ALL);
// Используем абсолютный путь для надежности
$dbinit_path = '/application/dbinit.php';
if (!file_exists($dbinit_path)) {
	error_log("Ошибка: Файл dbinit.php не найден: $dbinit_path");
	exit(1);
}
include($dbinit_path);

// Открываем директорию с константой
if ($handle = opendir(FLIBUSTA_BOOKS_DIR)) {
	$stmt = $dbh->prepare("TRUNCATE book_zip;");
	$stmt->execute();

	$dbh->beginTransaction();

	while (false !== ($entry = readdir($handle))) {   
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
			} else {
				$stmt = $dbh->prepare("INSERT INTO book_zip (filename, start_id, end_id, usr) VALUES (:fn, :start, :end, :usr)");
				$stmt->bindParam(":fn", $entry);
				$stmt->bindParam(":start", $fn[1]);
				$stmt->bindParam(":end", $fn[2]);
				$stmt->bindParam(":usr", $u);
				$stmt->execute();
			}
		}
		echo "\n";
	}
	$dbh->commit();
	closedir($handle);
}
