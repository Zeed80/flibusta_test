
<div class='row'>
<div class="col-sm-6">
<div class='card'>
<h4 class="rounded-top p-1" style="background: #d0d0d0;">Статистика</h4>
<div class='card-body'>
<?php
// Константы путей к директориям
if (!defined('FLIBUSTA_CACHE_DIR')) {
	define('FLIBUSTA_CACHE_DIR', '/application/cache');
}
if (!defined('FLIBUSTA_CACHE_AUTHORS')) {
	define('FLIBUSTA_CACHE_AUTHORS', FLIBUSTA_CACHE_DIR . '/authors');
}
if (!defined('FLIBUSTA_CACHE_COVERS')) {
	define('FLIBUSTA_CACHE_COVERS', FLIBUSTA_CACHE_DIR . '/covers');
}
if (!defined('FLIBUSTA_CACHE_TMP')) {
	define('FLIBUSTA_CACHE_TMP', FLIBUSTA_CACHE_DIR . '/tmp');
}
if (!defined('FLIBUSTA_BOOKS_DIR')) {
	define('FLIBUSTA_BOOKS_DIR', '/application/flibusta');
}
if (!defined('FLIBUSTA_SQL_DIR')) {
	define('FLIBUSTA_SQL_DIR', '/application/sql');
}
if (!defined('FLIBUSTA_SQL_STATUS')) {
	// Используем cache директорию для файла статуса, так как там есть права на запись
	define('FLIBUSTA_SQL_STATUS', FLIBUSTA_CACHE_DIR . '/sql_status');
}
if (!defined('FLIBUSTA_TOOLS_DIR')) {
	define('FLIBUSTA_TOOLS_DIR', '/application/tools');
}
if (!defined('FLIBUSTA_SCRIPT_IMPORT')) {
	define('FLIBUSTA_SCRIPT_IMPORT', FLIBUSTA_TOOLS_DIR . '/app_import_sql.sh');
}
if (!defined('FLIBUSTA_SCRIPT_REINDEX')) {
	define('FLIBUSTA_SCRIPT_REINDEX', FLIBUSTA_TOOLS_DIR . '/app_reindex.sh');
}
if (!defined('FLIBUSTA_SCRIPT_UPDATE_ZIP')) {
	define('FLIBUSTA_SCRIPT_UPDATE_ZIP', FLIBUSTA_TOOLS_DIR . '/app_update_zip_list.php');
}

// Безопасная проверка статуса импорта (проверяем файл статуса вместо shell_exec)
$status_import = false;
if (file_exists(FLIBUSTA_SQL_STATUS)) {
	$status_content = trim(file_get_contents(FLIBUSTA_SQL_STATUS));
	// Импорт активен, если файл не пустой и не содержит только ошибку без "importing" или "Создание индекса"
	if (!empty($status_content) && 
	    (stripos($status_content, "importing") !== false || 
	     stripos($status_content, "Создание индекса") !== false ||
	     stripos($status_content, "Конвертация") !== false ||
	     stripos($status_content, "Импорт") !== false)) {
		$status_import = true;
	}
}

// Безопасное получение размера директории без shell_exec
function get_ds($path) {
	if (!is_dir($path)) {
		return 0;
	}
	
	$size = 0;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	
	foreach ($iterator as $file) {
		if ($file->isFile()) {
			$size += $file->getSize();
		}
	}
	
	return round($size / 1024 / 1024, 1); // Возврат в GB
}

if (!$status_import) {
	$cache_size = get_ds(FLIBUSTA_CACHE_AUTHORS) + get_ds(FLIBUSTA_CACHE_COVERS);
	$books_size = round(get_ds(FLIBUSTA_BOOKS_DIR) / 1024, 1);
	$qtotal = $dbh->query("SELECT (SELECT MAX(time) FROM libbook) mmod, (SELECT COUNT(*) FROM libbook) bcnt, (SELECT COUNT(*) FROM libbook WHERE deleted='0') bdcnt");
	$qtotal->execute();
	$total = $qtotal->fetch();
	echo "<table class='table'><tbody>";
	echo "<tr><td>Актуальность базы:</td><td>$total->mmod</td></tr>";
	echo "<tr><td>Всего произведений:</td><td>$total->bcnt</td></tr>";
	echo "<tr><td>Размер архива:</td><td>$books_size Gb</td></tr>";
	echo "<tr><td>Размер кэша:</td><td>$cache_size Mb</td></tr>";
	echo "</tbody></table>";
} else {
	echo "Идёт процесс импорта...";
}
?>
</div>
</div>
</div>

<div class="col-sm-6">
<div class='card'>
<h4 class="rounded-top p-1" style="background: #d0d0d0;">Операции</h4>
<div class='card-body'>
<?php

// Безопасная очистка кэша с использованием PHP функций
if (isset($_GET['empty'])) {
	$cleared_dirs = array();
	$errors = array();
	
	// Очистка кэша авторов
	$authors_cache_dir = FLIBUSTA_CACHE_AUTHORS;
	if (is_dir($authors_cache_dir)) {
		$files = glob($authors_cache_dir . '/*');
		if (is_array($files)) {
			$count = 0;
			foreach ($files as $file) {
				if (is_file($file)) {
					if (@unlink($file)) {
						$count++;
					} else {
						$errors[] = "Не удалось удалить файл: " . basename($file);
					}
				}
			}
			if ($count > 0) {
				$cleared_dirs[] = "Кэш авторов: удалено $count файлов";
			}
		}
	}
	
	// Очистка кэша обложек
	$covers_cache_dir = FLIBUSTA_CACHE_COVERS;
	if (is_dir($covers_cache_dir)) {
		$files = glob($covers_cache_dir . '/*');
		if (is_array($files)) {
			$count = 0;
			foreach ($files as $file) {
				if (is_file($file)) {
					if (@unlink($file)) {
						$count++;
					} else {
						$errors[] = "Не удалось удалить файл: " . basename($file);
					}
				}
			}
			if ($count > 0) {
				$cleared_dirs[] = "Кэш обложек: удалено $count файлов";
			}
		}
	}
	
	// Очистка временных файлов в tmp (но не файла статуса sql_status)
	$tmp_cache_dir = FLIBUSTA_CACHE_TMP;
	if (is_dir($tmp_cache_dir)) {
		$files = glob($tmp_cache_dir . '/*');
		if (is_array($files)) {
			$count = 0;
			foreach ($files as $file) {
				// Не удаляем файл статуса импорта
				if (basename($file) !== 'sql_status' && is_file($file)) {
					if (@unlink($file)) {
						$count++;
					} else {
						$errors[] = "Не удалось удалить временный файл: " . basename($file);
					}
				}
			}
			if ($count > 0) {
				$cleared_dirs[] = "Временные файлы: удалено $count файлов";
			}
		}
	}
	
	// Формируем сообщение о результате очистки
	$message = '';
	if (!empty($cleared_dirs)) {
		$message = 'success=' . urlencode(implode('; ', $cleared_dirs));
	}
	if (!empty($errors)) {
		if (!empty($message)) {
			$message .= '&';
		}
		$message .= 'error=' . urlencode(implode('; ', $errors));
	}
	
	if (!empty($message)) {
		header("location:$webroot/service/?cache_cleared&$message");
	} else {
		header("location:$webroot/service/");
	}
	exit;
}

// Безопасный запуск импорта SQL с использованием PHP proc_open в фоновом режиме
function run_background_import($script_path) {
	// Проверка существования файла скрипта
	if (!file_exists($script_path)) {
		$error_msg = "Ошибка: Скрипт не найден: $script_path";
		file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
		error_log($error_msg);
		return false;
	}
	
	// Проверка прав на выполнение
	if (!is_executable($script_path)) {
		$error_msg = "Ошибка: Скрипт не имеет прав на выполнение: $script_path";
		file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
		error_log($error_msg);
		
		// Попытка установить права на выполнение
		@chmod($script_path, 0755);
		if (!is_executable($script_path)) {
			return false;
		}
	}
	
	// Убеждаемся, что директория для файла статуса существует и имеет права на запись
	$sql_dir = dirname(FLIBUSTA_SQL_STATUS);
	if (!is_dir($sql_dir)) {
		@mkdir($sql_dir, 0777, true);
	}
	// Устанавливаем права на запись для директории (если они недостаточны)
	if (is_dir($sql_dir) && !is_writable($sql_dir)) {
		@chmod($sql_dir, 0777);
	}
	
	// Проверяем, что можем создать файл статуса
	if (!is_writable($sql_dir)) {
		$error_msg = "Ошибка: Нет прав на запись в директорию: $sql_dir";
		error_log($error_msg);
		return false;
	}
	
	// Запуск скрипта в фоновом режиме
	// Используем shell для запуска в фоне с перенаправлением вывода
	$log_file = FLIBUSTA_SQL_STATUS;
	
	// Создаем файл статуса заранее, чтобы скрипт мог в него писать
	file_put_contents($log_file, "Запуск скрипта: " . basename($script_path) . "\n");
	
	// Убеждаемся, что директория для временных скриптов существует
	if (!is_dir(FLIBUSTA_CACHE_TMP)) {
		@mkdir(FLIBUSTA_CACHE_TMP, 0777, true);
		@chmod(FLIBUSTA_CACHE_TMP, 0777);
	}
	
	// Создаем временный wrapper скрипт для надежного запуска в фоне
	$wrapper_script = FLIBUSTA_CACHE_TMP . '/run_' . basename($script_path) . '_' . time() . '.sh';
	$wrapper_content = "#!/bin/sh\n";
	$wrapper_content .= "cd /application\n";
	$wrapper_content .= ". /application/tools/dbinit.sh\n";
	$wrapper_content .= "sh " . escapeshellarg($script_path) . " >> " . escapeshellarg($log_file) . " 2>&1\n";
	
	// Создаем wrapper скрипт
	if (file_put_contents($wrapper_script, $wrapper_content) === false) {
		$error_msg = "Ошибка: Не удалось создать wrapper скрипт: $wrapper_script";
		error_log($error_msg);
		file_put_contents($log_file, $error_msg);
		return false;
	}
	
	// Устанавливаем права на выполнение
	if (!chmod($wrapper_script, 0755)) {
		$error_msg = "Ошибка: Не удалось установить права на выполнение для wrapper скрипта: $wrapper_script";
		error_log($error_msg);
		file_put_contents($log_file, $error_msg);
		return false;
	}
	
	// Запускаем wrapper скрипт в фоне через exec
	$command = "sh " . escapeshellarg($wrapper_script) . " > /dev/null 2>&1 &";
	
	// Запускаем через exec для фоновых задач
	$output = array();
	$return_var = 0;
	exec($command, $output, $return_var);
	
	// Логируем запуск
	error_log("Попытка запуска скрипта: $script_path через wrapper: $wrapper_script");
	
	// Удаляем wrapper скрипт через несколько секунд (после запуска)
	register_shutdown_function(function() use ($wrapper_script) {
		if (file_exists($wrapper_script)) {
			@unlink($wrapper_script);
		}
	});
	
	// Даем скрипту время создать файл статуса
	usleep(1000000); // 1 секунда для надежности
	
	// Проверяем, что файл статуса существует и обновляется
	if (file_exists(FLIBUSTA_SQL_STATUS)) {
		$status_content = file_get_contents(FLIBUSTA_SQL_STATUS);
		
		// Проверяем наличие ключевых слов, указывающих на успешный запуск
		$success_keywords = ["importing", "Создание индекса", "Конвертация", "Импорт", "Запуск скрипта"];
		$has_success_keyword = false;
		foreach ($success_keywords as $keyword) {
			if (stripos($status_content, $keyword) !== false) {
				$has_success_keyword = true;
				break;
			}
		}
		
		// Если содержит ошибку - это плохо
		$has_error = (stripos($status_content, "Ошибка") !== false || 
		              stripos($status_content, "Fatal error") !== false ||
		              stripos($status_content, "Warning") !== false);
		
		if ($has_success_keyword && !$has_error) {
			error_log("Скрипт успешно запущен: $script_path");
			return true;
		}
	}
	
	// Проверяем, запущен ли процесс скрипта через ps
	$script_basename = basename($script_path);
	$process_check = "ps aux | grep -v grep | grep -E '(sh|nohup).*" . preg_quote($script_basename, '/') . "'";
	$process_output = shell_exec($process_check);
	
	if (!empty($process_output) && trim($process_output) !== '') {
		error_log("Процесс скрипта найден: $script_path");
		return true;
	}
	
	// Если процесс не найден, читаем файл статуса для диагностики
	$error_details = "Скрипт не запустился: $script_path";
	if (file_exists(FLIBUSTA_SQL_STATUS)) {
		$status_content = file_get_contents(FLIBUSTA_SQL_STATUS);
		$error_details .= "\nСодержимое файла статуса:\n" . substr($status_content, 0, 500);
	}
	
	$error_msg = "Ошибка: Скрипт не смог запуститься.\n$error_details\nПроверьте права доступа, логи PHP-FPM и убедитесь, что скрипт имеет права на выполнение.";
	file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
	error_log($error_msg);
	return false;
}

if (!$status_import) {
	if (isset($_GET['import'])) {
		// Проверка доступности необходимых функций
		if (!function_exists('exec') && !function_exists('shell_exec')) {
			$error_msg = "Ошибка: Функции exec() и shell_exec() недоступны. Проверьте настройки PHP (disable_functions).";
			file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
			error_log($error_msg);
			header("location:$webroot/service/?error=" . urlencode($error_msg));
			exit;
		}
		
		// Создаём необходимые директории перед запуском импорта
		$dirs_to_create = [
			FLIBUSTA_SQL_DIR . '/psql',
			FLIBUSTA_SQL_DIR,
			FLIBUSTA_CACHE_DIR,
			FLIBUSTA_CACHE_DIR . '/authors',
			FLIBUSTA_CACHE_DIR . '/covers',
			FLIBUSTA_CACHE_DIR . '/tmp'
		];
		
		foreach ($dirs_to_create as $dir) {
			if (!is_dir($dir)) {
				@mkdir($dir, 0777, true);
			}
			// Устанавливаем права на запись для директории (если они недостаточны)
			if (is_dir($dir) && !is_writable($dir)) {
				@chmod($dir, 0777);
			}
		}
		
		// Безопасный запуск импорта SQL
		if (function_exists('run_background_import')) {
			$result = run_background_import(FLIBUSTA_SCRIPT_IMPORT);
			if (!$result) {
				// Если запуск не удался, показываем ошибку
				$error_content = file_exists(FLIBUSTA_SQL_STATUS) ? file_get_contents(FLIBUSTA_SQL_STATUS) : "Неизвестная ошибка";
				header("location:$webroot/service/?error=" . urlencode($error_content));
				exit;
			}
		} else {
			$error_msg = "Ошибка: Функция run_background_import не найдена.";
			file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
			error_log($error_msg);
			header("location:$webroot/service/?error=" . urlencode($error_msg));
			exit;
		}
		header("location:$webroot/service/");
		exit;
	}
	if (isset($_GET['reindex'])) {
		// Проверка доступности необходимых функций
		if (!function_exists('exec') && !function_exists('shell_exec')) {
			$error_msg = "Ошибка: Функции exec() и shell_exec() недоступны. Проверьте настройки PHP (disable_functions).";
			file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
			error_log($error_msg);
			header("location:$webroot/service/?error=" . urlencode($error_msg));
			exit;
		}
		
		// Создаём директорию для файла статуса, если нужно
		$sql_dir = dirname(FLIBUSTA_SQL_STATUS);
		if (!is_dir($sql_dir)) {
			@mkdir($sql_dir, 0777, true);
		}
		// Устанавливаем права на запись для директории (если они недостаточны)
		if (is_dir($sql_dir) && !is_writable($sql_dir)) {
			@chmod($sql_dir, 0777);
		}
		
		// Безопасный запуск реиндексации
		if (function_exists('run_background_import')) {
			$result = run_background_import(FLIBUSTA_SCRIPT_REINDEX);
			if (!$result) {
				// Если запуск не удался, показываем ошибку
				$error_content = file_exists(FLIBUSTA_SQL_STATUS) ? file_get_contents(FLIBUSTA_SQL_STATUS) : "Неизвестная ошибка";
				header("location:$webroot/service/?error=" . urlencode($error_content));
				exit;
			}
		} else {
			$error_msg = "Ошибка: Функция run_background_import не найдена.";
			file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
			error_log($error_msg);
			header("location:$webroot/service/?error=" . urlencode($error_msg));
			exit;
		}
		header("location:$webroot/service/");
		exit;
	}
}

if ($status_import) {
	$status = 'disabled';
} else {
	$status = '';
}

// Отображение ошибок запуска скриптов
if (isset($_GET['error']) && !empty($_GET['error'])) {
	$error_message = urldecode($_GET['error']);
	echo "<div class='alert alert-danger' role='alert'>";
	echo "<strong>❌ Ошибка при запуске скрипта:</strong><br>";
	echo "<pre style='white-space: pre-wrap; word-wrap: break-word;'>" . htmlspecialchars($error_message) . "</pre>";
	echo "<br><small>Проверьте логи PHP-FPM: <code>docker-compose logs php-fpm</code></small>";
	echo "</div>";
}

// Отображение результата очистки кэша
if (isset($_GET['cache_cleared'])) {
	if (isset($_GET['success']) && !empty($_GET['success'])) {
		$success_messages = explode('; ', urldecode($_GET['success']));
		echo "<div class='alert alert-success' role='alert'>";
		echo "<strong>✓ Кэш успешно очищен:</strong><br>";
		foreach ($success_messages as $msg) {
			echo "• " . htmlspecialchars($msg) . "<br>";
		}
		echo "</div>";
	}
	
	if (isset($_GET['error']) && !empty($_GET['error'])) {
		$error_messages = explode('; ', urldecode($_GET['error']));
		echo "<div class='alert alert-warning' role='alert'>";
		echo "<strong>⚠️ Предупреждения при очистке кэша:</strong><br>";
		foreach ($error_messages as $error) {
			echo "• " . htmlspecialchars($error) . "<br>";
		}
		echo "</div>";
	}
}

// Проверка доступности функций PHP
$function_errors = array();
if (!function_exists('exec') && !function_exists('shell_exec')) {
	$function_errors[] = "Критическая ошибка: Функции exec() и shell_exec() недоступны. Проверьте настройки PHP (disable_functions в php.ini).";
} elseif (!function_exists('shell_exec')) {
	$function_errors[] = "Предупреждение: Функция shell_exec() недоступна. Будет использоваться exec().";
}

// Проверка доступности скриптов
$script_errors = array();
if (!file_exists(FLIBUSTA_SCRIPT_IMPORT)) {
	$script_errors[] = "Скрипт импорта не найден: " . FLIBUSTA_SCRIPT_IMPORT;
} elseif (!is_executable(FLIBUSTA_SCRIPT_IMPORT)) {
	$script_errors[] = "Скрипт импорта не имеет прав на выполнение: " . FLIBUSTA_SCRIPT_IMPORT;
}

if (!file_exists(FLIBUSTA_SCRIPT_REINDEX)) {
	$script_errors[] = "Скрипт реиндексации не найден: " . FLIBUSTA_SCRIPT_REINDEX;
} elseif (!is_executable(FLIBUSTA_SCRIPT_REINDEX)) {
	$script_errors[] = "Скрипт реиндексации не имеет прав на выполнение: " . FLIBUSTA_SCRIPT_REINDEX;
}

if (!file_exists('/application/tools/app_topg')) {
	$script_errors[] = "Скрипт конвертации SQL не найден: /application/tools/app_topg";
} elseif (!is_executable('/application/tools/app_topg')) {
	$script_errors[] = "Скрипт конвертации SQL не имеет прав на выполнение: /application/tools/app_topg";
}

// Проверка прав на директорию cache
if (!is_writable(FLIBUSTA_CACHE_DIR)) {
	$script_errors[] = "Директория cache не имеет прав на запись: " . FLIBUSTA_CACHE_DIR;
}

// Вывод критических ошибок функций
if (!empty($function_errors)) {
	echo "<div class='alert alert-danger' role='alert'>";
	echo "<strong>🚨 Критические проблемы с PHP функциями:</strong><br>";
	foreach ($function_errors as $error) {
		echo "• " . htmlspecialchars($error) . "<br>";
	}
	echo "</div>";
}

// Диагностическая информация (только для отладки, можно отключить)
$show_debug = isset($_GET['debug']);
if ($show_debug) {
	echo "<div class='alert alert-info' role='alert'>";
	echo "<strong>🔍 Диагностическая информация:</strong><br>";
	echo "• exec() доступна: " . (function_exists('exec') ? '✅ Да' : '❌ Нет') . "<br>";
	echo "• shell_exec() доступна: " . (function_exists('shell_exec') ? '✅ Да' : '❌ Нет') . "<br>";
	echo "• Директория cache доступна для записи: " . (is_writable(FLIBUSTA_CACHE_DIR) ? '✅ Да' : '❌ Нет') . "<br>";
	echo "• Файл статуса существует: " . (file_exists(FLIBUSTA_SQL_STATUS) ? '✅ Да' : '❌ Нет') . "<br>";
	if (file_exists(FLIBUSTA_SQL_STATUS)) {
		echo "• Размер файла статуса: " . filesize(FLIBUSTA_SQL_STATUS) . " байт<br>";
	}
	echo "</div>";
}

// Вывод предупреждений о скриптах
if (!empty($script_errors)) {
	echo "<div class='alert alert-danger' role='alert'>";
	echo "<strong>⚠️ Обнаружены проблемы со скриптами:</strong><br>";
	foreach ($script_errors as $error) {
		echo "• " . htmlspecialchars($error) . "<br>";
	}
	echo "<br><small>Убедитесь, что все скрипты в /application/tools/ имеют права на выполнение.<br>";
	echo "Выполните: <code>docker-compose exec php-fpm sh -c \"cd /application/tools && chmod +x *.sh app_topg *.py\"</code><br>";
	echo "Проверьте права на директорию cache: <code>docker-compose exec php-fpm sh -c \"chmod 777 /application/cache\"</code></small>";
	echo "</div>";
}

echo "<div class='d-flex justify-content-between'>";
echo "<a class='btn btn-primary m-1 $status' href='?import=sql'>Обновить базу</a> ";
echo "<a class='btn btn-warning m-1' href='?empty=cache'>Очистить кэш</a> ";
echo "<a class='btn btn-warning m-1 $status' href='?reindex'>Сканирование ZIP</a> ";
echo "</div>";

// Ссылка для диагностики
if (empty($script_errors) && empty($function_errors)) {
	echo "<div class='mt-2'>";
	echo "<small><a href='?debug=1'>🔍 Показать диагностическую информацию</a></small>";
	echo "</div>";
}

if ($status_import) {
	$op = file_get_contents(FLIBUSTA_SQL_STATUS);
	echo "<div class='d-flex align-items-center m-3'>";
	echo nl2br(htmlspecialchars($op));
	echo "<div class='spinner-border ms-auto' role='status' aria-hidden='true'></div></div>";
	header("Refresh:10");
}

?>
</div>
</div>
</div>

</div>

<div class='row'>
<div class="col-sm-12 mt-3">
<div class='card'>
<div class='card-body'>
<p>
Для выполнения обновления необходимо разместить фалы дампа Флибусты (*.sql) в каталог FlibustaSQL. Процесс занимает до 30 минут, в зависимости от быстродействия сервера (SSD значительно увеличивает скорость импорта)
</p>
<p>
Чтобы отображались фото авторов и обложек для форматов, отличных от FB2, необходимо разместить в каталоге cache файлы архивов lib.a.attached.zip и lib.b.attached.zip соответственно.
В кэше хранятся распакованные фото авторов и обложек для FB2, а также их уменьшенные версии.</p>
<p>Файлы архивов Флибусты (*.zip) необходимо размещать в каталоге Flibusta.Net. Обрабатываются также файлы ежедневных обновлений, но обязательно необходимо подгружать свежие SQL файлы.</p>
<?php echo "<p>Доступен также OPDS-каталог для читалок: <a href='$webroot/opds/'>/opds/</a></p>"; ?>
<p><b>Каталоги FlibustaSQL, cache и их подкаталоги должны иметь права на запись для контейнера. Скрипты в каталоге /application/tools/ должны иметь права на выполнение.</b></p>
</div></div></div></div>

