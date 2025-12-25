<?php
$dbname = getenv('FLIBUSTA_DBNAME')?getenv('FLIBUSTA_DBNAME'):'flibusta';
$dbhost = getenv('FLIBUSTA_DBHOST')?getenv('FLIBUSTA_DBHOST'):'postgres';
$dbuser = getenv('FLIBUSTA_DBUSER')?getenv('FLIBUSTA_DBUSER'):'flibusta';
$dbpasswd = '';

// Приоритет 1: Чтение из файла секрета (Docker secrets)
if (getenv('FLIBUSTA_DBPASSWORD_FILE')) {
	$passwordFile = getenv('FLIBUSTA_DBPASSWORD_FILE');
	if (file_exists($passwordFile) && is_readable($passwordFile)) {
		$dbpasswd = file_get_contents($passwordFile);
		if ($dbpasswd !== false) {
			$dbpasswd = trim($dbpasswd);
		}
	}
}

// Приоритет 2: Переменная окружения
if (empty($dbpasswd)) {
	$dbpasswd = getenv('FLIBUSTA_DBPASSWORD');
	if ($dbpasswd !== false) {
		$dbpasswd = trim($dbpasswd);
	}
}

// Приоритет 3: Дефолтный пароль
if (empty($dbpasswd)) {
	$dbpasswd = 'flibusta';
}

$dbtype = getenv('FLIBUSTA_DBTYPE')?trim(strtolower(getenv('FLIBUSTA_DBTYPE'))):'postgres';
if ($dbtype != 'postgres') { // check for valid type, currently only postgress is supported, but in the future others e.g. mysql will be added
	error_log('unsupported db type '.$dbtype.', reverting to postgress');
	$dbtype = 'postgres';
}
$dsn = match($dbtype) {
	'postgres' => "pgsql:host=".$dbhost.";dbname=".$dbname.";options='--client_encoding=UTF8'",
	// dsn for supported db types should be added here
	default => "pgsql:host=".$dbhost.";dbname=".$dbname.";options='--client_encoding=UTF8'"
};

$dbh = null;
try {
	$dbh = new PDO($dsn, $dbuser, $dbpasswd);
	$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
	$dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
} catch(PDOException $e) {
	// Логируем ошибку вместо вывода, чтобы не нарушать HTTP заголовки
	$error_msg = "Ошибка подключения к БД: " . $e->getMessage() . " (DSN: $dsn, User: $dbuser)";
	error_log($error_msg);
	// Устанавливаем $dbh в null для предотвращения использования неинициализированного объекта
	$dbh = null;
} catch(Exception $e) {
	// Обработка других исключений
	$error_msg = "Критическая ошибка при подключении к БД: " . $e->getMessage();
	error_log($error_msg);
	$dbh = null;
}

?>