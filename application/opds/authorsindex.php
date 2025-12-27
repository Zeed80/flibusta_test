<?php
declare(strict_types=1);

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Устанавливаем заголовок сразу, чтобы в случае ошибки вернуть XML
header('Content-Type: application/atom+xml; charset=utf-8');

// Проверяем наличие необходимых глобальных переменных
if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="https://specs.opds.io/opds-1.2">
  <id>tag:error:internal</id>
  <title>Внутренняя ошибка сервера</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:init</id>
    <title>Ошибка инициализации</title>
    <summary type="text">Не удалось инициализировать необходимые переменные</summary>
  </entry>
</feed>';
    error_log("OPDS authorsindex.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры для кэша
$letters = isset($_GET['letters']) ? trim($_GET['letters']) : '';

// Создаем ключ кэша для индекса авторов
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_authorsindex_v7_' . md5($letters);

// Проверяем кэш
$cachedContent = $opdsCache->get($cacheKey);
if ($cachedContent !== null) {
    // Кэш действителен, отправляем с заголовками кэширования
    // ВАЖНО: устанавливаем Content-Type ДО setCacheHeaders
    header('Content-Type: application/atom+xml; charset=utf-8');
    $etag = $opdsCache->generateETag($cachedContent);
    $opdsCache->checkETag($etag);
    $opdsCache->setCacheHeaders($etag);
    echo $cachedContent;
    exit;
}

// Если кэша нет или устарел, генерируем фид
// Создаем фид OPDS 1.2
$feed = OPDSFeedFactory::create();

$feed->setId('tag:root:authors');
$feed->setTitle('Книги по авторам');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds/opensearch.xml.php',
	'search',
	'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex?letters={searchTerms}',
	'search',
	OPDSVersion::getProfile( 'acquisition')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds',
	'start',
	OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex' . ($letters ? '?letters=' . urlencode($letters) : ''),
	'self',
	OPDSVersion::getProfile( 'navigation')
));

$length_letters = mb_strlen($letters, 'UTF-8');

// Исправляем SQL-инъекцию, используя prepared statement
// Логика согласно стандарту OPDS: иерархическая навигация
if ($length_letters > 0) {
	// Группируем авторов по префиксам (N+1 символов)
	// Если авторов много (>500), показываем подгруппы, иначе - отдельных авторов
	$pattern = $letters . '_';
	$alphaExpr = "UPPER(SUBSTR(TRIM(LastName), 1, " . ($length_letters + 1) . "))";
	// Применяем русский collation, если доступен
	$orderByExpr = $alphaExpr;
	if (class_exists('OPDSCollation')) {
		$orderByExpr = OPDSCollation::applyRussianCollation($alphaExpr, $dbh);
	}
	$query = "
		SELECT " . $alphaExpr . " as alpha, COUNT(*) as cnt
		FROM libavtorname
		WHERE LastName IS NOT NULL 
		AND TRIM(LastName) != ''
		AND SUBSTR(TRIM(LastName), 1, " . $length_letters . ") = :prefix
		AND " . $alphaExpr . " SIMILAR TO :pattern
		GROUP BY " . $alphaExpr . "
		ORDER BY " . $orderByExpr;
	$ai = $dbh->prepare($query);
	$lettersUpper = mb_strtoupper($letters, 'UTF-8');
	$ai->bindParam(":prefix", $lettersUpper);
	$ai->bindParam(":pattern", $pattern);
	$ai->execute();
} else {
	// Фильтруем на уровне SQL: исключаем пустые LastName и те, что начинаются не с буквы
	// Используем TRIM для удаления пробелов и проверяем, что первый символ - буква
	$alphaExpr = "UPPER(SUBSTR(TRIM(LastName), 1, 1))";
	// Применяем русский collation, если доступен
	$orderByExpr = $alphaExpr;
	if (class_exists('OPDSCollation')) {
		$orderByExpr = OPDSCollation::applyRussianCollation($alphaExpr, $dbh);
	}
	$query = "
		SELECT " . $alphaExpr . " as alpha, COUNT(*) as cnt
		FROM libavtorname
		WHERE LastName IS NOT NULL 
		AND TRIM(LastName) != ''
		AND SUBSTR(TRIM(LastName), 1, 1) ~ '^[[:alpha:]]'
		GROUP BY " . $alphaExpr . "
		HAVING " . $alphaExpr . " ~ '^[A-ZА-ЯЁ]'
		ORDER BY " . $orderByExpr;
	$ai = $dbh->query($query);
}

// Отображаем результаты согласно стандарту OPDS
if ($length_letters > 0) {
	// Если передан префикс, показываем подгруппы или отдельных авторов
	// В зависимости от количества авторов в каждой подгруппе
	while ($ach = $ai->fetchObject()) {
		$alpha = trim($ach->alpha ?? '');
		if (empty($alpha)) {
			continue;
		}
		
		$firstChar = mb_substr($alpha, 0, 1, 'UTF-8');
		if (!preg_match('/^[\p{Cyrillic}\p{Latin}]$/u', $firstChar)) {
			continue;
		}
		
		// Если авторов в подгруппе много (>30), создаем подгруппу следующего уровня
		// Иначе - показываем отдельных авторов с этим префиксом
		if ($ach->cnt > 30) {
			// Много авторов - создаем подгруппу (переход на более длинный префикс)
			$url = $webroot . '/opds/authorsindex?letters=' . urlencode($alpha);
			$entry = new OPDSEntry();
			$entry->setId("tag:authors:" . htmlspecialchars($alpha, ENT_XML1, 'UTF-8'));
			$entry->setTitle($alpha);
			$entry->setUpdated($cdt);
			$entry->setContent("$ach->cnt авторов на " . $alpha, 'text');
			$entry->addLink(new OPDSLink(
				$url,
				'subsection',
				OPDSVersion::getProfile( 'navigation')
			));
			$feed->addEntry($entry);
		} else {
			// Мало авторов - показываем список авторов напрямую
			$alphaUpper = mb_strtoupper($alpha, 'UTF-8');
			$searchPattern = $alphaUpper . '%';
			$authorsQuery = "
				SELECT avtorid, lastname, firstname, middlename, nickname,
					(SELECT COUNT(*) FROM libavtor, libbook WHERE 
					libbook.deleted='0' AND
					libbook.bookid=libavtor.bookid AND
					libavtor.avtorid=libavtorname.avtorid) as cnt
				FROM libavtorname
				WHERE LastName IS NOT NULL 
				AND TRIM(LastName) != ''
				AND UPPER(SUBSTR(TRIM(LastName), 1, " . mb_strlen($alpha, 'UTF-8') . ")) = :prefix
				ORDER BY " . (class_exists('OPDSCollation') ? OPDSCollation::applyRussianCollationToMultiple(['lastname', 'firstname'], $dbh) : 'lastname, firstname');
			$authorsStmt = $dbh->prepare($authorsQuery);
			$authorsStmt->bindParam(":prefix", $alphaUpper);
			$authorsStmt->execute();
			
			while ($a = $authorsStmt->fetch(PDO::FETCH_OBJ)) {
				if ($a->cnt > 0) {
					$authorName = trim("$a->lastname $a->firstname $a->middlename $a->nickname");
					if (empty($authorName)) {
						continue;
					}
					
					$entry = new OPDSEntry();
					$entry->setId("tag:author:$a->avtorid");
					$entry->setTitle($authorName);
					$entry->setUpdated($cdt);
					$entry->setContent("$a->cnt книг", 'text');
					$entry->addLink(new OPDSLink(
						$webroot . '/opds/author?author_id=' . $a->avtorid,
						'subsection',
						OPDSVersion::getProfile( 'navigation')
					));
					$feed->addEntry($entry);
				}
			}
		}
	}
} else {
	// Показываем алфавитный индекс (первые буквы фамилий)
	while ($ach = $ai->fetchObject()) {
		$alpha = trim($ach->alpha ?? '');
		if (empty($alpha)) {
			continue;
		}
		
		$firstChar = mb_substr($alpha, 0, 1, 'UTF-8');
		if (!preg_match('/^[\p{Cyrillic}\p{Latin}]$/u', $firstChar)) {
			continue;
		}
		
		$url = $webroot . '/opds/authorsindex?letters=' . urlencode($alpha);
		$entry = new OPDSEntry();
		$entry->setId("tag:authors:" . htmlspecialchars($alpha, ENT_XML1, 'UTF-8'));
		$entry->setTitle($alpha);
		$entry->setUpdated($cdt);
		$entry->setContent("$ach->cnt авторов на " . $alpha, 'text');
		$entry->addLink(new OPDSLink(
			$url,
			'subsection',
			OPDSVersion::getProfile( 'navigation')
		));
		$feed->addEntry($entry);
	}
}

// Рендерим фид
$content = $feed->render();

// Сохраняем в кэш
$opdsCache->set($cacheKey, $content);

// Устанавливаем заголовки кэширования и отправляем ответ
// ВАЖНО: устанавливаем Content-Type перед setCacheHeaders
header('Content-Type: application/atom+xml; charset=utf-8');
$etag = $opdsCache->generateETag($content);
$opdsCache->setCacheHeaders($etag);
echo $content;
?>
