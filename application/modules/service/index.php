
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
	define('FLIBUSTA_SQL_STATUS', FLIBUSTA_SQL_DIR . '/status');
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
	// Очистка кэша авторов
	$authors_cache_dir = FLIBUSTA_CACHE_AUTHORS;
	if (is_dir($authors_cache_dir)) {
		$files = glob($authors_cache_dir . '/*');
		if (is_array($files)) {
			foreach ($files as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}
		}
	}
	
	// Очистка кэша обложек
	$covers_cache_dir = FLIBUSTA_CACHE_COVERS;
	if (is_dir($covers_cache_dir)) {
		$files = glob($covers_cache_dir . '/*');
		if (is_array($files)) {
			foreach ($files as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}
		}
	}
	
	header("location:$webroot/service/");
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
	
	// Убеждаемся, что директория для файла статуса существует
	$sql_dir = dirname(FLIBUSTA_SQL_STATUS);
	if (!is_dir($sql_dir)) {
		@mkdir($sql_dir, 0755, true);
	}
	
	// Запуск скрипта в фоновом режиме
	// Используем shell для запуска в фоне с перенаправлением вывода
	$log_file = FLIBUSTA_SQL_STATUS;
	$command = "sh " . escapeshellarg($script_path) . " >> " . escapeshellarg($log_file) . " 2>&1 &";
	
	// Запускаем через shell_exec в фоне (более надежно чем proc_open для фоновых задач)
	$output = array();
	$return_var = 0;
	exec($command . " echo $!", $output, $return_var);
	
	// Даем скрипту время создать файл статуса
	usleep(500000); // 0.5 секунды
	
	// Проверяем, что файл статуса создан (скрипт начал работу)
	if (file_exists(FLIBUSTA_SQL_STATUS)) {
		$status_content = file_get_contents(FLIBUSTA_SQL_STATUS);
		// Если файл содержит "importing" или "Создание индекса", значит скрипт запустился
		if (strpos($status_content, "importing") !== false || 
		    strpos($status_content, "Создание индекса") !== false ||
		    strpos($status_content, "Ошибка") === false) {
			return true;
		}
	}
	
	// Если файл статуса не создан или содержит ошибку, проверяем через процесс
	// Проверяем, запущен ли процесс скрипта
	$process_check = "ps aux | grep -E '[s]h.*" . basename($script_path) . "'";
	exec($process_check, $process_output, $process_return);
	
	if (!empty($process_output)) {
		// Процесс запущен
		return true;
	}
	
	// Если процесс не найден, возможно скрипт упал сразу
	$error_msg = "Ошибка: Скрипт не смог запуститься. Проверьте права доступа и логи.";
	file_put_contents(FLIBUSTA_SQL_STATUS, $error_msg);
	error_log($error_msg);
	return false;
}

if (!$status_import) {
	if (isset($_GET['import'])) {
		// Безопасный запуск импорта SQL
		if (function_exists('run_background_import') && run_background_import(FLIBUSTA_SCRIPT_IMPORT)) {
			$status_fetch = true;
		}
		header("location:$webroot/service/");
	}
	if (isset($_GET['reindex'])) {
		// Безопасный запуск реиндексации
		if (function_exists('run_background_import') && run_background_import(FLIBUSTA_SCRIPT_REINDEX)) {
			$status_fetch = true;
		}
		header("location:$webroot/service/");
	}
}

if ($status_import) {
	$status = 'disabled';
} else {
	$status = '';
}

// Проверка доступности скриптов
$script_errors = array();
if (!file_exists(FLIBUSTA_SCRIPT_IMPORT)) {
	$script_errors[] = "Скрипт импорта не найден: " . FLIBUSTA_SCRIPT_IMPORT;
} elseif (!is_executable(FLIBUSTA_SCRIPT_IMPORT)) {
	$script_errors[] = "Скрипт импорта не имеет прав на выполнение";
}

if (!file_exists(FLIBUSTA_SCRIPT_REINDEX)) {
	$script_errors[] = "Скрипт реиндексации не найден: " . FLIBUSTA_SCRIPT_REINDEX;
}

if (!file_exists('/application/tools/app_topg')) {
	$script_errors[] = "Скрипт конвертации SQL не найден: /application/tools/app_topg";
} elseif (!is_executable('/application/tools/app_topg')) {
	$script_errors[] = "Скрипт конвертации SQL не имеет прав на выполнение";
}

// Вывод предупреждений о скриптах
if (!empty($script_errors)) {
	echo "<div class='alert alert-danger' role='alert'>";
	echo "<strong>⚠️ Обнаружены проблемы со скриптами:</strong><br>";
	foreach ($script_errors as $error) {
		echo "• " . htmlspecialchars($error) . "<br>";
	}
	echo "<br><small>Убедитесь, что все скрипты в /application/tools/ имеют права на выполнение.<br>";
	echo "Выполните: <code>chmod +x /application/tools/*.sh /application/tools/app_topg</code></small>";
	echo "</div>";
}

echo "<div class='d-flex justify-content-between'>";
echo "<a class='btn btn-primary m-1 $status' href='?import=sql'>Обновить базу</a> ";
echo "<a class='btn btn-warning m-1' href='?empty=cache'>Очистить кэш</a> ";
echo "<a class='btn btn-warning m-1' href='?reindex'>Сканирование ZIP</a> ";
echo "</div>";

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

