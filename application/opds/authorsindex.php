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
$cacheKey = 'opds_authorsindex_v8_' . md5($letters);

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
	// Проверяем общее количество авторов с этим префиксом
	$lettersUpper = mb_strtoupper($letters, 'UTF-8');
	$countQuery = "
		SELECT COUNT(*) as total_cnt
		FROM libavtorname
		WHERE LastName IS NOT NULL 
		AND TRIM(LastName) != ''
		AND UPPER(SUBSTR(TRIM(LastName), 1, " . $length_letters . ")) = :prefix";
	$countStmt = $dbh->prepare($countQuery);
	$countStmt->bindParam(":prefix", $lettersUpper);
	$countStmt->execute();
	$countResult = $countStmt->fetch(PDO::FETCH_OBJ);
	$totalAuthors = $countResult ? (int)$countResult->total_cnt : 0;
	
	// Если авторов немного (<=30), показываем их напрямую
	// Если много (>30), группируем по префиксам (N+1 символов)
	if ($totalAuthors <= 30) {
		// Показываем отдельных авторов
		$query = "
			SELECT avtorid, lastname, firstname, middlename, nickname,
				(SELECT COUNT(*) FROM libavtor, libbook WHERE 
				libbook.deleted='0' AND
				libbook.bookid=libavtor.bookid AND
				libavtor.avtorid=libavtorname.avtorid) as cnt
			FROM libavtorname
			WHERE LastName IS NOT NULL 
			AND TRIM(LastName) != ''
			AND UPPER(SUBSTR(TRIM(LastName), 1, " . $length_letters . ")) = :prefix
			ORDER BY " . (class_exists('OPDSCollation') ? OPDSCollation::applyRussianCollationToMultiple(['lastname', 'firstname'], $dbh) : 'lastname, firstname');
		$ai = $dbh->prepare($query);
		$ai->bindParam(":prefix", $lettersUpper);
		$ai->execute();
		$showAuthors = true; // Флаг, что нужно показывать авторов
	} else {
		// Группируем по префиксам (N+1 символов)
		// ВАЖНО: показываем только уникальные префиксы следующего уровня
		// Используем DISTINCT ON для PostgreSQL, чтобы получить только уникальные префиксы
		$alphaExpr = "UPPER(SUBSTR(TRIM(LastName), 1, " . ($length_letters + 1) . "))";
		// Применяем русский collation, если доступен
		$orderByExpr = $alphaExpr;
		if (class_exists('OPDSCollation')) {
			$orderByExpr = OPDSCollation::applyRussianCollation($alphaExpr, $dbh);
		}
		// Используем подзапрос для получения уникальных префиксов
		// и затем считаем количество авторов для каждого префикса
		$query = "
			SELECT 
				prefix as alpha,
				COUNT(*) as cnt
			FROM (
				SELECT DISTINCT " . $alphaExpr . " as prefix
				FROM libavtorname
				WHERE LastName IS NOT NULL 
				AND TRIM(LastName) != ''
				AND LENGTH(TRIM(LastName)) >= " . ($length_letters + 1) . "
				AND UPPER(SUBSTR(TRIM(LastName), 1, " . $length_letters . ")) = :prefix
			) AS unique_prefixes
			JOIN libavtorname ON UPPER(SUBSTR(TRIM(libavtorname.LastName), 1, " . ($length_letters + 1) . ")) = unique_prefixes.prefix
			WHERE libavtorname.LastName IS NOT NULL 
			AND TRIM(libavtorname.LastName) != ''
			AND UPPER(SUBSTR(TRIM(libavtorname.LastName), 1, " . $length_letters . ")) = :prefix
			GROUP BY prefix
			ORDER BY " . $orderByExpr;
		$ai = $dbh->prepare($query);
		$ai->bindParam(":prefix", $lettersUpper);
		$ai->execute();
		$showAuthors = false; // Флаг, что нужно показывать подгруппы
	}
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
	if (isset($showAuthors) && $showAuthors) {
		// Показываем отдельных авторов
		while ($a = $ai->fetch(PDO::FETCH_OBJ)) {
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
	} else {
		// Показываем подгруппы следующего уровня (N+1 символов)
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
