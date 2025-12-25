<?php
$dbname = getenv('FLIBUSTA_DBNAME')?getenv('FLIBUSTA_DBNAME'):'flibusta';
$dbhost = getenv('FLIBUSTA_DBHOST')?getenv('FLIBUSTA_DBHOST'):'postgres';
$dbuser = getenv('FLIBUSTA_DBUSER')?getenv('FLIBUSTA_DBUSER'):'flibusta';
if (getenv('FLIBUSTA_DBPASSWORD_FILE')) {
	$passwordFile = getenv('FLIBUSTA_DBPASSWORD_FILE');
	if (file_exists($passwordFile) && is_readable($passwordFile)) {
		$dbpasswd = file_get_contents($passwordFile);
		if ($dbpasswd !== false) {
			$dbpasswd = trim($dbpasswd);
		}
	}
}
if (empty($dbpasswd)) {
	$dbpasswd = getenv('FLIBUSTA_DBPASSWORD') ?: 'flibusta';
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

try {
	$dbh = new PDO($dsn, $dbuser, $dbpasswd);
	$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
	$dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
} catch(Exception $e) {
	print_r($e);
}

?>