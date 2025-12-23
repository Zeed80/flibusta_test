
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
	$status_import = true;
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

// Безопасный запуск импорта SQL с использованием PHP proc_open
function run_background_import($script_path) {
	$descriptorspec = array(
		0 => array("pipe", "r"),  // stdin
		1 => array("pipe", "w"),  // stdout
		2 => array("pipe", "w"),  // stderr
	);
	
	$process = proc_open($script_path, $descriptorspec, $pipes);
	
	if (is_resource($process)) {
		fclose($pipes[0]);  // Не пишем в stdin
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		return true;
	}
	
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
echo "<div class='d-flex justify-content-between'>";
echo "<a class='btn btn-primary m-1 $status' href='?import=sql'>Обновить базу</a> ";
echo "<a class='btn btn-warning m-1' href='?empty=cache'>Очистить кэш</a> ";
echo "<a class='btn btn-warning m-1' href='?reindex'>Сканирование ZIP</a> ";
echo "</div>";

if ($status_import) {
	$op = file_get_contents('/application/sql/status');;
	echo "<div class='d-flex align-items-center m-3'>";
	echo nl2br($op);
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

