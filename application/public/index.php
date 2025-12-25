<?php
// Включаем init.php ДО ob_start(), чтобы ошибки подключения к БД обрабатывались правильно
include("../init.php");

// Теперь запускаем буферизацию вывода
ob_start();

// Дополнительная проверка $dbh (на случай если init.php не завершил выполнение)
if (!isset($dbh) || $dbh === null) {
	ob_end_clean(); // Очищаем буфер перед выводом ошибки
	error_log("КРИТИЧЕСКАЯ ОШИБКА в index.php: \$dbh не установлен");
	if (!headers_sent()) {
		http_response_code(500);
		header('Content-Type: text/html; charset=utf-8');
	}
	die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка подключения к БД</title></head><body><h1>Ошибка подключения к базе данных</h1><p>Не удалось подключиться к базе данных. Проверьте логи PHP-FPM для деталей.</p></body></html>');
}

session_start();
decode_gurl($webroot);

// Валидация UUID v4
function validate_uuid($uuid) {
	return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
}

$user_name = 'Книжные полки';

// Логин пользователя с валидацией UUID
if (isset($_GET['login_uuid'])) {
	if (validate_uuid($_GET['login_uuid'])) {
		$_SESSION['user_uuid'] = $_GET['login_uuid'];
	} else {
		// Неверный UUID, игнорируем
	}
}

// Удаление пользователя с валидацией UUID
if (isset($_GET['delete_uuid'])) {
	if (validate_uuid($_GET['delete_uuid'])) {
		$uu = $_GET['delete_uuid'];
		try {
			$stmt = $dbh->prepare("DELETE FROM fav_users WHERE user_uuid=:uuid");
			$stmt->bindParam(":uuid", $uu);
			$stmt->execute();
			$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid");
			$st->bindParam(":uuid", $uu);
			$st->execute();
		} catch (PDOException $e) {
			error_log("Ошибка при удалении пользователя: " . $e->getMessage());
		}
	}
}

// Создание новой книжной полки с валидацией имени
if (isset($_GET['new_uuid'])) {
	$nname = trim($_GET['new_uuid']);
	// Валидация имени: только буквы, цифры, пробелы и русские символы, макс 32 символа
	if ($nname !== '' && preg_match('/^[a-zA-Zа-яА-ЯёЁ0-9\s\-\.]{1,32}$/u', $nname)) {
		try {
			$stmt = $dbh->prepare("INSERT INTO fav_users (user_uuid, name) VALUES (uuid_generate_v1(), :name)");
			$stmt->bindParam(":name", $nname);
			$stmt->execute();

			$stmt = $dbh->prepare("SELECT user_uuid FROM fav_users WHERE name=:name LIMIT 1");
			$stmt->bindParam(":name", $nname);
			$stmt->execute();
			$r = $stmt->fetch();
			if ($r) {
				$user_uuid = $r->user_uuid;
				$user_name = $nname;
				$_SESSION['user_uuid'] = $user_uuid;
			}
		} catch (PDOException $e) {
			error_log("Ошибка при создании книжной полки: " . $e->getMessage());
		}
	}
}

if (isset($_SESSION['user_uuid'])) {
	$user_uuid = $_SESSION['user_uuid'];
	try {
		$stmt = $dbh->prepare("SELECT * FROM fav_users WHERE user_uuid=:uuid");
		$stmt->bindParam(":uuid", $user_uuid);
		$stmt->execute();
		$user = $stmt->fetch();
	} catch (PDOException $e) {
		error_log("Ошибка при получении пользователя: " . $e->getMessage());
		$user = null;
	}
	
	if (isset($user->name)) {
		$user_name = $user->name;

		try {
			if (isset($_GET['fav_book'])) {
				$id = intval($_GET['fav_book']);
				$st = $dbh->prepare("INSERT INTO fav (user_uuid, bookid) VALUES(:uuid, :id) ON CONFLICT DO NOTHING");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
			}
			if (isset($_GET['fav_author'])) {
				$id = intval($_GET['fav_author']);
				$st = $dbh->prepare("INSERT INTO fav (user_uuid, avtorid) VALUES(:uuid, :id) ON CONFLICT DO NOTHING");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
			}
			if (isset($_GET['fav_seq'])) {
				$id = intval($_GET['fav_seq']);
				$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND seqid=:id");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
				$st = $dbh->prepare("INSERT INTO fav (user_uuid, seqid) VALUES(:uuid, :id) ON CONFLICT DO NOTHING");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
			}
		
			if (isset($_GET['unfav_book'])) {
				$id = intval($_GET['unfav_book']);
				$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND bookid=:id");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
			}
			if (isset($_GET['unfav_author'])) {
				$id = intval($_GET['unfav_author']);
				$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND avtorid=:id");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
			}
			if (isset($_GET['unfav_seq'])) {
				$id = intval($_GET['unfav_seq']);
				$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND seqid=:id");
				$st->bindParam(":uuid", $user_uuid);
				$st->bindParam(":id", $id);
				$st->execute();
			}
		} catch (PDOException $e) {
			error_log("Ошибка при работе с избранным: " . $e->getMessage());
		}
	} else {
		unset($_SESSION['user_uuid']);
		$user_name = 'Книжные полки';
	}
} else {
	$user_uuid = '';
}

if (isset($_GET['sort'])) {
	$sort_mode = $_GET['sort'];
} else {
	$sort_mode = 'abc';
	if ($url->action == '') {
		$sort_mode = 'date';
	}
}

if (isset($_GET['page'])) {
	$page = intval($_GET['page']);
} else {
	$page = 0;
}

$start = $page * RECORDS_PAGE;
$lang = 'ru';
$filter = "";

switch ($sort_mode) {
	case 'abc':
		$order = 'b.Title';
		break;

	case 'author':
		$order = 'b.Title';
		break;

	case 'date':
		$order = 'b.Time DESC';
		break;

	case 'rating':
		$order = 'b.Title';
		break;
}

if ($url->mod == 'opds') {
	include(ROOT_PATH . "/opds/index.php");
} else {
	include(ROOT_PATH . "renderer.php");
}

