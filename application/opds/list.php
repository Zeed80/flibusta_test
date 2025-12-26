<?php
declare(strict_types=1);

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Не показываем ошибки пользователю, только логируем
ini_set('log_errors', '1');

header('Content-Type: application/atom+xml; charset=utf-8');

// Проверяем наличие необходимых глобальных переменных
if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
    http_response_code(500);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:internal</id>
  <title>Внутренняя ошибка сервера</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:init</id>
    <title>Ошибка инициализации</title>
    <summary type="text">Не удалось инициализировать необходимые переменные</summary>
  </entry>
</feed>';
    error_log("OPDS list.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры фильтрации для кэша
$cacheParams = [
    'genre_id' => isset($_GET['genre_id']) ? intval($_GET['genre_id']) : null,
    'seq_id' => isset($_GET['seq_id']) ? intval($_GET['seq_id']) : null,
    'author_id' => isset($_GET['author_id']) ? intval($_GET['author_id']) : null,
    'display_type' => isset($_GET['display_type']) ? $_GET['display_type'] : null,
    'lang' => isset($_GET['lang']) ? $_GET['lang'] : null,
    'format' => isset($_GET['format']) ? $_GET['format'] : null,
    'page' => isset($_GET['page']) ? (int)$_GET['page'] : 1
];

// Создаем ключ кэша
$cacheKey = 'opds_list_' . $opdsCache->getCacheKey($cacheParams);

// Проверяем кэш
$cachedContent = $opdsCache->get($cacheKey);
if ($cachedContent !== null) {
    // Кэш действителен, отправляем с заголовками кэширования
    $etag = $opdsCache->generateETag($cachedContent);
    $opdsCache->checkETag($etag);
    $opdsCache->setCacheHeaders($etag);
    echo $cachedContent;
    exit;
}

// Если кэша нет или устарел, генерируем фид
// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

// Добавляем версию в параметры кэша
$cacheParams['version'] = $version;

// Определяем параметры фильтрации
$filter = "deleted='0' ";
$join = '';
$orderby = ' time DESC ';
$title = 'в новинках';
$params = [];

// Пагинация
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$itemsPerPage = OPDS_FEED_COUNT;
$offset = ($page - 1) * $itemsPerPage;

if (isset($_GET['genre_id'])) {
	$gid = intval($_GET['genre_id']);
	$filter .= 'AND genreid=:gid ';
	$join .= 'LEFT JOIN libgenre g USING(BookId) ';
	$orderby = ' time DESC ';
	$params['genre_id'] = $gid;
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist WHERE genreid=:gid");
	$stmt->bindParam(":gid", $gid);
	$stmt->execute();
	$g = $stmt->fetch();
	$normalizedGenreDesc = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($g->genredesc) : $g->genredesc;
	$title = "в жанре $g->genremeta: $normalizedGenreDesc";
}

if (isset($_GET['seq_id'])) {
	$sid = intval($_GET['seq_id']);
	$filter .= 'AND seqid=:sid ';
	$join .= 'LEFT JOIN libseq s USING(BookId) ';
	$orderby = " s.seqnumb ";
	$params['seq_id'] = $sid;
	$stmt = $dbh->prepare("SELECT * FROM libseqname WHERE seqid=:sid");
	$stmt->bindParam(":sid", $sid);
	$stmt->execute();
	$s = $stmt->fetch();
	$normalizedSeqName = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($s->seqname) : $s->seqname;
	$title = "в сборнике $normalizedSeqName";
}

if (isset($_GET['author_id'])) {
	$aid = intval($_GET['author_id']);
	$filter .= 'AND avtorid=:aid ';
	$join .= 'JOIN libavtor USING (bookid) JOIN libavtorname USING (avtorid) ';
	$params['author_id'] = $aid;
	
	$display_type = isset($_GET['display_type']) ? $_GET['display_type'] : '';
	if ($display_type == 'sequenceless') {
		$filter .= 'AND s.seqid is null ';
		$join .= ' LEFT JOIN libseq s ON s.bookId= b.bookId ';
		$orderby = ' time DESC ';
		$params['display_type'] = 'sequenceless';
	} else if ($display_type == 'year'){
		$orderby = ' year ';
		$params['display_type'] = 'year';
	} else if ($display_type == 'alphabet') {
		$orderby = ' title ';
		$params['display_type'] = 'alphabet';
	} else {
		$orderby = ' time DESC ';
	}
	$stmt = $dbh->prepare("SELECT * FROM libavtorname WHERE avtorid=:aid");
	$stmt->bindParam(":aid", $aid);
	$stmt->execute();
	$a = $stmt->fetch();
	$authorName = ($a->nickname != '')?"$a->firstname $a->middlename $a->lastname ($a->nickname)"
		:"$a->firstname  $a->middlename $a->lastname";
	$title = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($authorName) : $authorName;
}

// Подсчет общего количества записей
try {
	$countQuery = "SELECT COUNT(DISTINCT b.BookId) as cnt FROM libbook b $join WHERE $filter";
	$countStmt = $dbh->prepare($countQuery);
	if (isset($gid)) {
		$countStmt->bindParam(":gid", $gid, PDO::PARAM_INT);
	}
	if (isset($sid)) {
		$countStmt->bindParam(":sid", $sid, PDO::PARAM_INT);
	}
	if (isset($aid)) {
		$countStmt->bindParam(":aid", $aid, PDO::PARAM_INT);
	}
	$countStmt->execute();
	$countResult = $countStmt->fetch(PDO::FETCH_OBJ);
	$totalItems = $countResult ? (int)$countResult->cnt : 0;
	$totalPages = max(1, ceil($totalItems / $itemsPerPage));
} catch (PDOException $e) {
	error_log("OPDS list.php: SQL error in count query: " . $e->getMessage());
	http_response_code(500);
	header('Content-Type: application/atom+xml; charset=utf-8');
	echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:sql</id>
  <title>Ошибка базы данных</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:count</id>
    <title>Ошибка подсчета записей</title>
    <summary type="text">Не удалось выполнить запрос к базе данных</summary>
  </entry>
</feed>';
	exit;
}

// Настройка фида
$feed->setId('tag:root:home');
$feed->setTitle("Книги $title");
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds/opensearch.xml.php',
	'search',
	'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/search?q={searchTerms}',
	'search',
	OPDSVersion::getProfile($version, 'acquisition')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/',
	'start',
	OPDSVersion::getProfile($version, 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/list?' . http_build_query(array_merge($params, ['page' => $page])),
	'self',
	OPDSVersion::getProfile($version, 'acquisition')
));

// Добавляем навигацию
$baseUrl = $webroot . '/opds/list';
$navigation = new OPDSNavigation($page, $totalPages, $totalItems, $itemsPerPage, $baseUrl, $params);
$feed->setNavigation($navigation);

// Добавляем фасетную навигацию (только для OPDS 1.2)
if ($version === OPDSVersion::VERSION_1_2) {
	// Фасет по языкам
	$langFacet = new OPDSFacet('language', 'Язык');
	$langs = $dbh->query("SELECT DISTINCT lang, COUNT(*) as cnt FROM libbook WHERE deleted='0' AND lang != '' GROUP BY lang ORDER BY lang LIMIT 10");
	while ($lang = $langs->fetch()) {
		$langParams = array_merge($params, ['lang' => $lang->lang, 'page' => 1]);
		$langFacet->addFacetValue(
			$lang->lang,
			$lang->lang,
			$baseUrl . '?' . http_build_query($langParams),
			(int)$lang->cnt,
			isset($_GET['lang']) && $_GET['lang'] === $lang->lang
		);
	}
	if (count($langFacet->getActiveFacets()) > 0 || $langs->rowCount() > 0) {
		$feed->addFacet($langFacet);
	}
	
	// Фасет по форматам
	$formatFacet = new OPDSFacet('format', 'Формат');
	$formats = $dbh->query("SELECT DISTINCT filetype, COUNT(*) as cnt FROM libbook WHERE deleted='0' AND filetype != '' GROUP BY filetype ORDER BY filetype");
	while ($format = $formats->fetch()) {
		$formatParams = array_merge($params, ['format' => $format->filetype, 'page' => 1]);
		$formatFacet->addFacetValue(
			$format->filetype,
			strtoupper($format->filetype),
			$baseUrl . '?' . http_build_query($formatParams),
			(int)$format->cnt,
			isset($_GET['format']) && $_GET['format'] === $format->filetype
		);
	}
	if (count($formatFacet->getActiveFacets()) > 0 || $formats->rowCount() > 0) {
		$feed->addFacet($formatFacet);
	}
}

// Получаем книги
try {
	$books = $dbh->prepare("SELECT b.*
		FROM libbook b
		$join
		WHERE
			$filter
		ORDER BY $orderby
		LIMIT :limit OFFSET :offset");

	if (isset($gid)) {
		$books->bindParam(":gid", $gid, PDO::PARAM_INT);
	}
	if (isset($sid)) {
		$books->bindParam(":sid", $sid, PDO::PARAM_INT);
	}
	if (isset($aid)) {
		$books->bindParam(":aid", $aid, PDO::PARAM_INT);
	}
	$books->bindValue(":limit", $itemsPerPage, PDO::PARAM_INT);
	$books->bindValue(":offset", $offset, PDO::PARAM_INT);
	$books->execute();

	while ($b = $books->fetch(PDO::FETCH_OBJ)) {
		$entry = opds_book_entry($b, $webroot, $version);
		if ($entry) {
			$feed->addEntry($entry);
		}
	}
} catch (PDOException $e) {
	error_log("OPDS list.php: SQL error in books query: " . $e->getMessage());
	http_response_code(500);
	header('Content-Type: application/atom+xml; charset=utf-8');
	echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:sql</id>
  <title>Ошибка базы данных</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:books</id>
    <title>Ошибка получения книг</title>
    <summary type="text">Не удалось выполнить запрос к базе данных</summary>
  </entry>
</feed>';
	exit;
}

// Рендерим фид
$content = $feed->render();

// Сохраняем в кэш
$opdsCache->set($cacheKey, $content);

// Устанавливаем заголовки кэширования и отправляем ответ
$etag = $opdsCache->generateETag($content);
$opdsCache->setCacheHeaders($etag);
echo $content;
?>
