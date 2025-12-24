<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS
$opdsCache = new OPDSCache(null, 3600, true); // 1 час TTL

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

// Создаем фид с учетом версии
$feed = OPDSFeedFactory::create(); // Пересоздаем с версией
$version = $feed->getVersion();

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
	$title = "в жанре $g->genremeta: $g->genredesc";
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
	$title = "в сборнике $s->seqname";
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
	$title = ($a->nickname != '')?"$a->firstname $a->middlename $a->lastname ($a->nickname)"
		:"$a->firstname  $a->middlename $a->lastname";
}

// Подсчет общего количества записей
$countQuery = "SELECT COUNT(DISTINCT b.BookId) as cnt FROM libbook b $join WHERE $filter";
$countStmt = $dbh->prepare($countQuery);
if (isset($gid)) {
	$countStmt->bindParam(":gid", $gid);
}
if (isset($sid)) {
	$countStmt->bindParam(":sid", $sid);
}
if (isset($aid)) {
	$countStmt->bindParam(":aid", $aid);
}
$countStmt->execute();
$totalItems = (int)$countStmt->fetch()->cnt;
$totalPages = max(1, ceil($totalItems / $itemsPerPage));

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
$books = $dbh->prepare("SELECT b.*
	FROM libbook b
	$join
	WHERE
		$filter
	ORDER BY $orderby
	LIMIT :limit OFFSET :offset");

if (isset($gid)) {
	$books->bindParam(":gid", $gid);
}
if (isset($sid)) {
	$books->bindParam(":sid", $sid);
}
if (isset($aid)) {
	$books->bindParam(":aid", $aid);
}
$books->bindValue(":limit", $itemsPerPage, PDO::PARAM_INT);
$books->bindValue(":offset", $offset, PDO::PARAM_INT);
$books->execute();

while ($b = $books->fetch()) {
	$entry = opds_book_entry($b, $webroot, $version);
	$feed->addEntry($entry);
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
