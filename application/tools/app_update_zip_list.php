<?php
/**
 * Улучшенный скрипт индексации ZIP архивов для продакшен
 * Включает:
 * - Валидацию ZIP файлов перед индексацией
 * - Инкрементальную индексацию (вместо полного TRUNCATE)
 * - Детальное логирование с временными метками
 * - Обработку ошибок и восстановление
 */

// Константы путей (вынесены для удобства конфигурации)
if (!defined('FLIBUSTA_BOOKS_DIR')) {
	define('FLIBUSTA_BOOKS_DIR', '/application/flibusta');
}

// Константы логирования
if (!defined('LOG_INFO')) {
	define('LOG_INFO', 'INFO');
}
if (!defined('LOG_WARNING')) {
	define('LOG_WARNING', 'WARNING');
}
if (!defined('LOG_ERROR')) {
	define('LOG_ERROR', 'ERROR');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 7200); // 2 часа для больших библиотек
ini_set('memory_limit', '512M');

// Используем абсолютный путь для надежности
$dbinit_path = '/application/dbinit.php';
if (!file_exists($dbinit_path)) {
	$error = log_message(LOG_ERROR, "Файл dbinit.php не найден: $dbinit_path");
	echo $error . "\n";
	exit(1);
}
include($dbinit_path);

// Проверяем подключение к базе данных
if (!isset($dbh) || $dbh === null) {
	$error = log_message(LOG_ERROR, "Не удалось подключиться к базе данных");
	echo $error . "\n";
	exit(1);
}

// Проверяем существование директории
if (!is_dir(FLIBUSTA_BOOKS_DIR)) {
	$error = log_message(LOG_ERROR, "Директория не существует: " . FLIBUSTA_BOOKS_DIR);
	echo $error . "\n";
	exit(1);
}

// Проверяем права на чтение директории
if (!is_readable(FLIBUSTA_BOOKS_DIR)) {
	$error = log_message(LOG_ERROR, "Нет прав на чтение директории: " . FLIBUSTA_BOOKS_DIR);
	echo $error . "\n";
	exit(1);
}

$start_time = microtime(true);
echo log_message(LOG_INFO, "Начало сканирования ZIP файлов в директории: " . FLIBUSTA_BOOKS_DIR);

// Открываем директорию
$handle = @opendir(FLIBUSTA_BOOKS_DIR);
if (!$handle) {
	$error = log_message(LOG_ERROR, "Не удалось открыть директорию: " . FLIBUSTA_BOOKS_DIR);
	echo $error . "\n";
	exit(1);
}

try {
	// Проверяем существование таблицы book_zip и создаем если нужно
	$table_check = $dbh->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'book_zip')");
	if (!$table_check || !$table_check->fetchColumn()) {
		echo log_message(LOG_INFO, "Создание таблицы book_zip...\n");
		$dbh->exec("
			CREATE TABLE book_zip (
				id SERIAL PRIMARY KEY,
				filename VARCHAR(64) NOT NULL UNIQUE,
				start_id BIGINT NOT NULL,
				end_id BIGINT NOT NULL,
				usr BIGINT DEFAULT 0 NOT NULL,
				file_size BIGINT DEFAULT 0,
				file_count INTEGER DEFAULT 0,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				checked_at TIMESTAMP,
				is_valid BOOLEAN DEFAULT TRUE
			)
		");
		$dbh->exec("CREATE INDEX idx_book_zip_start_end ON book_zip(start_id, end_id)");
		$dbh->exec("CREATE INDEX idx_book_zip_usr ON book_zip(usr)");
	} else {
		// Проверяем наличие колонок file_size, file_count, is_valid и checked_at и добавляем их при необходимости
		$columns_check = $dbh->query("
			SELECT column_name 
			FROM information_schema.columns 
			WHERE table_name = 'book_zip' 
			AND column_name IN ('file_size', 'file_count', 'is_valid', 'checked_at', 'updated_at', 'created_at')
		")->fetchAll(PDO::FETCH_COLUMN);
		
		if (!in_array('file_size', $columns_check)) {
			echo log_message(LOG_INFO, "Добавление колонки file_size в таблицу book_zip...\n");
			$dbh->exec("ALTER TABLE book_zip ADD COLUMN file_size BIGINT DEFAULT 0");
		}
		
		if (!in_array('file_count', $columns_check)) {
			echo log_message(LOG_INFO, "Добавление колонки file_count в таблицу book_zip...\n");
			$dbh->exec("ALTER TABLE book_zip ADD COLUMN file_count INTEGER DEFAULT 0");
		}
		
		if (!in_array('is_valid', $columns_check)) {
			echo log_message(LOG_INFO, "Добавление колонки is_valid в таблицу book_zip...\n");
			$dbh->exec("ALTER TABLE book_zip ADD COLUMN is_valid BOOLEAN DEFAULT TRUE");
		}
		
		if (!in_array('checked_at', $columns_check)) {
			echo log_message(LOG_INFO, "Добавление колонки checked_at в таблицу book_zip...\n");
			$dbh->exec("ALTER TABLE book_zip ADD COLUMN checked_at TIMESTAMP");
		}
		
		if (!in_array('updated_at', $columns_check)) {
			echo log_message(LOG_INFO, "Добавление колонки updated_at в таблицу book_zip...\n");
			$dbh->exec("ALTER TABLE book_zip ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
		}
		
		if (!in_array('created_at', $columns_check)) {
			echo log_message(LOG_INFO, "Добавление колонки created_at в таблицу book_zip...\n");
			$dbh->exec("ALTER TABLE book_zip ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
		}
		
		// Проверяем наличие уникального индекса на filename для ON CONFLICT
		$unique_index_check = $dbh->query("
			SELECT COUNT(*) 
			FROM pg_indexes 
			WHERE tablename = 'book_zip' 
			AND indexname LIKE '%filename%'
			AND indexdef LIKE '%UNIQUE%'
		")->fetchColumn();
		
		if ($unique_index_check == 0) {
			echo log_message(LOG_INFO, "Создание уникального индекса на filename...\n");
			try {
				$dbh->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_book_zip_filename_unique ON book_zip(filename)");
			} catch (PDOException $e) {
				// Если индекс уже существует или есть дубликаты, пытаемся создать через ALTER TABLE
				echo log_message(LOG_WARNING, "Не удалось создать уникальный индекс через CREATE INDEX, пробуем через ALTER TABLE...\n");
				try {
					// Удаляем дубликаты перед созданием уникального ограничения
					$dbh->exec("
						DELETE FROM book_zip a 
						USING book_zip b 
						WHERE a.id < b.id AND a.filename = b.filename
					");
					$dbh->exec("ALTER TABLE book_zip ADD CONSTRAINT book_zip_filename_unique UNIQUE (filename)");
				} catch (PDOException $e2) {
					echo log_message(LOG_WARNING, "Не удалось создать уникальное ограничение: " . $e2->getMessage() . "\n");
					echo log_message(LOG_WARNING, "Продолжаем работу без уникального индекса, ON CONFLICT может не работать\n");
				}
			}
		}
	}

	// Получаем существующие записи для сравнения
	$existing_files = $dbh->query("SELECT filename, file_size, checked_at FROM book_zip")->fetchAll(PDO::FETCH_ASSOC);
	$existing_map = [];
	foreach ($existing_files as $file) {
		$existing_map[$file['filename']] = $file;
	}
	
	echo log_message(LOG_INFO, "Текущее количество индексированных файлов: " . count($existing_map) . "\n");

	$dbh->beginTransaction();
	
	$processed_count = 0;
	$updated_count = 0;
	$skipped_count = 0;
	$invalid_count = 0;
	$new_count = 0;
	
	$validation_stats = [
		'opened' => 0,
		'failed_open' => 0,
		'empty' => 0,
		'valid' => 0
	];

	while (false !== ($entry = readdir($handle))) {
		// Пропускаем . и ..
		if ($entry === '.' || $entry === '..') {
			continue;
		}
		
		// Проверяем, что это ZIP файл и не часть
		if (strpos($entry, "-") !== false && strpos($entry, ".zip") !== false && substr($entry, -9) !== ".zip.part") {
			$full_path = FLIBUSTA_BOOKS_DIR . '/' . $entry;
			$file_mtime = filemtime($full_path);
			
			echo "[$entry]";
			
			$dt = str_replace(".zip", "", $entry);
			$dt = str_replace("f.n.", "f.n-", $dt);
			$dt = str_replace("f.fb2.", "f.n-", $dt);
			
			$fn = explode("-", $dt);
			$u = 1;
			if (strpos($entry, "fb2") !== false) {
				$u = 0;
			}
			
			// Пропускаем проблемные файлы
			if (strpos($entry, "d.fb2-009") !== false) {
				echo " (пропущен: известный проблемный файл)\n";
				$skipped_count++;
				continue;
			}
			
			// Парсим имя файла: используем индексы массива [1] и [2]
			$start_id = null;
			$end_id = null;
			
			// Проверяем наличие элементов массива и валидируем числовые значения
			if (isset($fn[1]) && isset($fn[2])) {
				if (is_numeric($fn[1]) && is_numeric($fn[2])) {
					$start_id = (int)$fn[1];
					$end_id = (int)$fn[2];
				}
			}
			
			// Проверяем валидность диапазона ID
			if ($start_id === null || $end_id === null || $start_id <= 0 || $end_id <= 0) {
				echo " (пропущен: неверный формат имени файла, start_id=" . ($start_id ?? 'null') . ", end_id=" . ($end_id ?? 'null') . ")\n";
				log_message(LOG_WARNING, "Неверный формат имени файла: $entry - start_id=" . ($start_id ?? 'null') . ", end_id=" . ($end_id ?? 'null'));
				$skipped_count++;
				continue;
			}
			
			if ($start_id > $end_id) {
				echo " (пропущен: start_id > end_id)\n";
				log_message(LOG_WARNING, "Неверный диапазон ID в файле: $entry - start_id=$start_id, end_id=$end_id");
				$skipped_count++;
				continue;
			}
			
			// Валидация ZIP файла
			$file_size = filesize($full_path);
			$file_count = 0;
			$is_valid = true;
			$validation_error = '';
			
			// Проверяем, нужно ли повторно валидировать файл
			$needs_validation = true;
			if (isset($existing_map[$entry])) {
				$existing_file = $existing_map[$entry];
				// Повторная валидация каждые 7 дней или если размер изменился
				$needs_validation = ($existing_file['file_size'] != $file_size) || 
					(!$existing_file['checked_at'] || (strtotime($existing_file['checked_at']) < time() - 7 * 24 * 3600));
			}
			
			if ($needs_validation) {
				$zip = new ZipArchive();
				$open_result = $zip->open($full_path);
				
				if ($open_result !== TRUE) {
					$is_valid = false;
					$validation_error = "Код ошибки ZipArchive: $open_result";
					$validation_stats['failed_open']++;
					echo " [ОШИБКА: $validation_error]";
					log_message(LOG_ERROR, "Невозможно открыть ZIP: $entry - $validation_error");
				} else {
					$validation_stats['opened']++;
					$file_count = $zip->numFiles;
					
					if ($file_count == 0) {
						$is_valid = false;
						$validation_error = "Пустой архив";
						$validation_stats['empty']++;
						echo " [ОШИБКА: Пустой ZIP архив]";
						log_message(LOG_WARNING, "Пустой ZIP архив: $entry");
					} else {
						$validation_stats['valid']++;
						echo " [ОК: $file_count файлов, " . formatBytes($file_size) . "]";
					}
					
					$zip->close();
				}
			} else {
				// Используем существующие данные валидации
				$is_valid = true;
				$file_count = $existing_map[$entry]['file_count'] ?? 0;
				$validation_stats['opened']++; // Считаем как уже проверенный
				echo " [кэш валидации: $file_count файлов]";
			}
			
			// Проверяем валидность перед вставкой
			if (!$is_valid) {
				// Обновляем запись как недействительную
				if (isset($existing_map[$entry])) {
					$stmt = $dbh->prepare("
						UPDATE book_zip 
						SET is_valid = FALSE, checked_at = CURRENT_TIMESTAMP
						WHERE filename = :fn
					");
					$stmt->execute([":fn" => $entry]);
				}
				$invalid_count++;
				echo " (помечен как недействительный)\n";
				continue;
			}
			
			// Вставляем или обновляем запись (инкрементальная индексация)
			// Проверяем, существует ли запись
			$check_stmt = $dbh->prepare("SELECT id FROM book_zip WHERE filename = :fn LIMIT 1");
			$check_stmt->execute([":fn" => $entry]);
			$existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
			
			if ($existing_record) {
				// Обновляем существующую запись
				$stmt = $dbh->prepare("
					UPDATE book_zip 
					SET start_id = :start,
						end_id = :end,
						usr = :usr,
						file_size = :size,
						file_count = :count,
						updated_at = CURRENT_TIMESTAMP,
						checked_at = CURRENT_TIMESTAMP,
						is_valid = TRUE
					WHERE filename = :fn
				");
			} else {
				// Вставляем новую запись
				$stmt = $dbh->prepare("
					INSERT INTO book_zip (filename, start_id, end_id, usr, file_size, file_count, created_at, updated_at, checked_at, is_valid)
					VALUES (:fn, :start, :end, :usr, :size, :count, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, TRUE)
				");
			}
			
			$params = [
				":fn" => $entry,
				":start" => $start_id,
				":end" => $end_id,
				":usr" => $u,
				":size" => $file_size,
				":count" => $file_count
			];
			
			$stmt->execute($params);
			
			if (isset($existing_map[$entry])) {
				$updated_count++;
				echo " [обновлен]";
			} else {
				$new_count++;
				echo " [добавлен]";
			}
			
			$processed_count++;
			echo "\n";
		}
	}
	
	$dbh->commit();
	closedir($handle);
	
	// Проверяем, что данные действительно записались в базу
	$check_stmt = $dbh->prepare("SELECT COUNT(*) as cnt FROM book_zip WHERE is_valid = TRUE");
	$check_stmt->execute();
	$row = $check_stmt->fetch(PDO::FETCH_ASSOC);
	$db_count = $row['cnt'] ?? 0;
	
	// Проверяем количество недействительных файлов
	$invalid_check_stmt = $dbh->prepare("SELECT COUNT(*) as cnt FROM book_zip WHERE is_valid = FALSE");
	$invalid_check_stmt->execute();
	$invalid_row = $invalid_check_stmt->fetch(PDO::FETCH_ASSOC);
	$db_invalid_count = $invalid_row['cnt'] ?? 0;
	
	// Общая продолжительность
	$end_time = microtime(true);
	$duration = round($end_time - $start_time, 2);
	
	// Вывод статистики
	echo "\n" . str_repeat("=", 70) . "\n";
	echo "СТАТИСТИКА ИНДЕКСАЦИИ\n";
	echo str_repeat("=", 70) . "\n";
	echo "Обработано файлов: $processed_count\n";
	echo "  - Новых: $new_count\n";
	echo "  - Обновлено: $updated_count\n";
	echo "Пропущено файлов: $skipped_count\n";
	echo "Недействительных файлов: $invalid_count\n";
	echo "Записей в БД (действительные): $db_count\n";
	echo "Записей в БД (недействительные): $db_invalid_count\n";
	echo "\n";
	
	// Статистика валидации
	echo "СТАТИСТИКА ВАЛИДАЦИИ ZIP:\n";
	echo str_repeat("-", 70) . "\n";
	echo "Открыто успешно: " . $validation_stats['opened'] . "\n";
	echo "Ошибок открытия: " . $validation_stats['failed_open'] . "\n";
	echo "Пустых архивов: " . $validation_stats['empty'] . "\n";
	echo "Валидных файлов: " . $validation_stats['valid'] . "\n";
	echo "\n";
	
	// Производительность
	echo "ПРОИЗВОДИТЕЛЬНОСТЬ:\n";
	echo str_repeat("-", 70) . "\n";
	echo "Общее время выполнения: {$duration} сек (" . gmdate('H:i:s', $duration) . ")\n";
	if ($processed_count > 0) {
		$avg_time = round($duration / $processed_count, 3);
		echo "Среднее время на файл: {$avg_time} сек\n";
		echo "Скорость обработки: " . round($processed_count / $duration, 2) . " файлов/сек\n";
	}
	echo "\n";
	
	// Проверки и предупреждения
	if ($processed_count === 0) {
		echo "ПРЕДУПРЕЖДЕНИЕ: Не найдено ZIP файлов для обработки\n";
		log_message(LOG_WARNING, "Не найдено ZIP файлов для обработки");
	} elseif ($db_invalid_count > 0) {
		echo "ВНИМАНИЕ: Обнаружено $db_invalid_count недействительных ZIP файлов\n";
		echo "Проверьте их с помощью: SELECT * FROM book_zip WHERE is_valid = FALSE;\n";
		log_message(LOG_WARNING, "Обнаружено $db_invalid_count недействительных ZIP файлов");
	}
	
	if ($processed_count !== $new_count && $updated_count === 0) {
		$expected = $processed_count + count($existing_map);
		if ($db_count !== $expected) {
			echo "ВНИМАНИЕ: Несоответствие количества файлов ($expected) с количеством записей в БД ($db_count)!\n";
			log_message(LOG_ERROR, "Несоответствие: ожидается $expected записей, в БД $db_count записей");
		}
	}
	
	// Если все прошло успешно, удаляем старые недействительные записи (старше 30 дней)
	if ($db_invalid_count > 0) {
		echo "Удаление старых недействительных записей (старше 30 дней)...\n";
		$cleanup_stmt = $dbh->exec("
			DELETE FROM book_zip 
			WHERE is_valid = FALSE 
			AND checked_at < CURRENT_TIMESTAMP - INTERVAL '30 days'
		");
		$deleted_count = $cleanup_stmt;
		echo "Удалено старых записей: $deleted_count\n";
	}
	
	echo log_message(LOG_INFO, "Индексация успешно завершена. Обработано файлов: $processed_count, Время: {$duration} сек");
	
} catch (Exception $e) {
	if ($dbh->inTransaction()) {
		$dbh->rollBack();
	}
	$error = log_message(LOG_ERROR, "Критическая ошибка при обработке: " . $e->getMessage());
	echo $error . "\n";
	closedir($handle);
	exit(1);
}

/**
 * Форматирование размера в читаемый вид
 */
function formatBytes($bytes, $precision = 2) {
	$units = ['B', 'KB', 'MB', 'GB', 'TB'];
	$bytes = max($bytes, 0);
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Логирование сообщения с временной меткой
 */
function log_message($level, $message) {
	$timestamp = date('Y-m-d H:i:s');
	$formatted = "[$timestamp] [$level] $message";
	error_log($formatted);
	return $formatted;
}
