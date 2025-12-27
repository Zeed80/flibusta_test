<?php
declare(strict_types=1);

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Не показываем ошибки пользователю, только логируем
ini_set('log_errors', '1');

header('Content-Type: application/atom+xml; charset=utf-8');

// Проверяем наличие необходимых глобальных переменных
if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
    error_log("OPDS list.php: Missing required global variables (dbh, webroot, or cdt)");
    OPDSErrorHandler::sendInitializationError();
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Валидируем входные параметры
try {
    $genre_id = OPDSValidator::validateId('genre_id', 1);
    $seq_id = OPDSValidator::validateId('seq_id', 1);
    $author_id = OPDSValidator::validateId('author_id', 1);
    $display_type = OPDSValidator::validateEnum('display_type', ['year', 'sequenceless', 'alphabet'], null);
    $lang = OPDSValidator::validateString('lang', 10, false, '/^[a-z]{2}$/');
    $format = OPDSValidator::validateString('format', 20, false);
    $page = OPDSValidator::validatePage('page', 1, 1);
} catch (\InvalidArgumentException $e) {
    OPDSValidator::handleValidationException($e);
}

// Получаем параметры фильтрации для кэша
$cacheParams = [
    'genre_id' => $genre_id,
    'seq_id' => $seq_id,
    'author_id' => $author_id,
    'display_type' => $display_type,
    'lang' => $lang,
    'format' => $format,
    'page' => $page
];

// Создаем ключ кэша
$cacheKey = 'opds_list_' . $opdsCache->getCacheKey($cacheParams);

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
// Создаем сервисы
$feedService = new OPDSFeedService($dbh, $webroot, $cdt);
$navService = new OPDSNavigationService($dbh, $webroot, $cdt);

// Определяем параметры фильтрации
$filter = "deleted='0' ";
$join = '';
$orderby = ' time DESC ';
$title = 'в новинках';
$params = [];

// Используем валидированные значения
$gid = $genre_id;
$sid = $seq_id;
$aid = $author_id;

// Пагинация
$itemsPerPage = OPDS_FEED_COUNT;
$offset = ($page - 1) * $itemsPerPage;

if ($genre_id !== null) {
	$filter .= 'AND genreid=:gid ';
	$join .= 'LEFT JOIN libgenre g USING(BookId) ';
	$orderby = ' time DESC ';
	$params['genre_id'] = $genre_id;
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist WHERE genreid=:gid");
	$stmt->bindParam(":gid", $genre_id, PDO::PARAM_INT);
	$stmt->execute();
	$g = $stmt->fetch(PDO::FETCH_OBJ);
	if ($g) {
		// НЕ нормализуем описание жанра - сохраняем оригинальный текст (включая кириллицу)
		$genreDesc = trim($g->genredesc ?? '');
		$title = "в жанре " . ($g->genremeta ?? '') . ": $genreDesc";
	} else {
		$title = "в жанре (не найден)";
	}
}

if ($seq_id !== null) {
	$filter .= 'AND seqid=:sid ';
	$join .= 'LEFT JOIN libseq s USING(BookId) ';
	$orderby = " s.seqnumb "; // Для серий сортировка по номеру в серии (число), COLLATE не нужен
	$params['seq_id'] = $seq_id;
	$stmt = $dbh->prepare("SELECT * FROM libseqname WHERE seqid=:sid");
	$stmt->bindParam(":sid", $seq_id, PDO::PARAM_INT);
	$stmt->execute();
	$s = $stmt->fetch(PDO::FETCH_OBJ);
	if ($s) {
		// НЕ нормализуем название серии - сохраняем оригинальный текст (включая кириллицу)
		$seqName = trim($s->seqname ?? '');
		$title = "в сборнике $seqName";
	} else {
		$title = "в сборнике (не найден)";
	}
}

if ($author_id !== null) {
	$filter .= 'AND avtorid=:aid ';
	$join .= 'JOIN libavtor USING (bookid) JOIN libavtorname USING (avtorid) ';
	$params['author_id'] = $author_id;
	
	if ($display_type === 'sequenceless') {
		$filter .= 'AND s.seqid is null ';
		$join .= ' LEFT JOIN libseq s ON s.bookId= b.bookId ';
		$orderby = ' time DESC ';
		$params['display_type'] = 'sequenceless';
	} else if ($display_type === 'year'){
		$orderby = ' year ';
		$params['display_type'] = 'year';
	} else if ($display_type === 'alphabet') {
		// Сортировка по названию с русским алфавитом (кириллица перед латиницей)
		$orderby = ' b.title COLLATE "ru_RU.UTF-8" ';
		$params['display_type'] = 'alphabet';
	} else {
		$orderby = ' time DESC ';
	}
	$stmt = $dbh->prepare("SELECT * FROM libavtorname WHERE avtorid=:aid");
	$stmt->bindParam(":aid", $author_id, PDO::PARAM_INT);
	$stmt->execute();
	$a = $stmt->fetch(PDO::FETCH_OBJ);
	if ($a) {
		$authorName = (!empty($a->nickname)) ? "$a->firstname $a->middlename $a->lastname ($a->nickname)"
			: "$a->firstname  $a->middlename $a->lastname";
		// НЕ нормализуем имя автора - сохраняем оригинальный текст (включая кириллицу)
		$title = trim($authorName);
	} else {
		$title = "автора (не найден)";
	}
}

// Подсчет общего количества записей
try {
	$countQuery = "SELECT COUNT(DISTINCT b.BookId) as cnt FROM libbook b $join WHERE $filter";
	$countStmt = $dbh->prepare($countQuery);
	if ($genre_id !== null) {
		$countStmt->bindParam(":gid", $genre_id, PDO::PARAM_INT);
	}
	if ($seq_id !== null) {
		$countStmt->bindParam(":sid", $seq_id, PDO::PARAM_INT);
	}
	if ($author_id !== null) {
		$countStmt->bindParam(":aid", $author_id, PDO::PARAM_INT);
	}
	$countStmt->execute();
	$countResult = $countStmt->fetch(PDO::FETCH_OBJ);
	$totalItems = $countResult ? (int)$countResult->cnt : 0;
	$totalPages = max(1, ceil($totalItems / $itemsPerPage));
} catch (PDOException $e) {
	error_log("OPDS list.php: SQL error in count query: " . $e->getMessage());
	OPDSErrorHandler::sendSqlError($e->getMessage());
}

// Создаем фид используя сервис
$feed = $feedService->createFeed('tag:root:home', "Книги $title", 'acquisition');

// Добавляем self ссылку
$baseUrl = $webroot . '/opds/list';
$selfUrl = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $page]));
$feedService->addSelfLink($feed, $selfUrl, 'acquisition');

// Добавляем навигацию используя сервис
$navigation = $navService->createNavigation($page, $totalPages, $totalItems, $itemsPerPage, $baseUrl, $params);
$feed->setNavigation($navigation);

// Добавляем ссылки для сортировки (opds:sortBy) - OPDS 1.2 feature
$navService->addSortByLinks($feed, $baseUrl, $params);

// Добавляем фасетную навигацию (OPDS 1.2)
// Фасет по языкам
	$langFacet = new OPDSFacet('language', 'Язык');
	$langs = $dbh->query("SELECT DISTINCT lang, COUNT(*) as cnt FROM libbook WHERE deleted='0' AND lang != '' GROUP BY lang ORDER BY lang COLLATE \"ru_RU.UTF-8\" LIMIT 10");
	while ($lang = $langs->fetch(PDO::FETCH_OBJ)) {
		$langValue = $lang->lang ?? '';
		if ($langValue) {
			$langParams = array_merge($params, ['lang' => $langValue, 'page' => 1]);
			$langFacet->addFacetValue(
				$langValue,
				$langValue,
				$baseUrl . '?' . http_build_query($langParams),
				(int)($lang->cnt ?? 0),
				isset($_GET['lang']) && $_GET['lang'] === $langValue
			);
		}
	}
	if (count($langFacet->getActiveFacets()) > 0 || $langs->rowCount() > 0) {
		$feed->addFacet($langFacet);
	}
	
	// Фасет по форматам
	$formatFacet = new OPDSFacet('format', 'Формат');
	$formats = $dbh->query("SELECT DISTINCT filetype, COUNT(*) as cnt FROM libbook WHERE deleted='0' AND filetype != '' GROUP BY filetype ORDER BY filetype COLLATE \"ru_RU.UTF-8\"");
	while ($format = $formats->fetch(PDO::FETCH_OBJ)) {
		$filetype = $format->filetype ?? '';
		if ($filetype) {
			$formatParams = array_merge($params, ['format' => $filetype, 'page' => 1]);
			$formatFacet->addFacetValue(
				$filetype,
				strtoupper($filetype),
				$baseUrl . '?' . http_build_query($formatParams),
				(int)($format->cnt ?? 0),
				isset($_GET['format']) && $_GET['format'] === $filetype
			);
		}
	}
	if (count($formatFacet->getActiveFacets()) > 0 || $formats->rowCount() > 0) {
		$feed->addFacet($formatFacet);
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

	if ($genre_id !== null) {
		$books->bindParam(":gid", $genre_id, PDO::PARAM_INT);
	}
	if ($seq_id !== null) {
		$books->bindParam(":sid", $seq_id, PDO::PARAM_INT);
	}
	if ($author_id !== null) {
		$books->bindParam(":aid", $author_id, PDO::PARAM_INT);
	}
	$books->bindValue(":limit", $itemsPerPage, PDO::PARAM_INT);
	$books->bindValue(":offset", $offset, PDO::PARAM_INT);
	$books->execute();

	// Используем OPDSBookService для создания entries
	$bookService = new OPDSBookService($dbh, $webroot, $cdt);
	
	$entriesCount = 0;
	$errorsCount = 0;
	while ($b = $books->fetch(PDO::FETCH_OBJ)) {
		try {
			$entry = $bookService->createBookEntry($b);
			if ($entry) {
				$feed->addEntry($entry);
				$entriesCount++;
			} else {
				$errorsCount++;
				error_log("OPDS list.php: createBookEntry returned null for book " . ($b->BookId ?? $b->bookid ?? 'unknown'));
			}
		} catch (Exception $e) {
			$errorsCount++;
			error_log("OPDS list.php: Error creating entry for book " . ($b->BookId ?? $b->bookid ?? 'unknown') . ": " . $e->getMessage());
			// Продолжаем обработку других книг
			continue;
		}
	}
	
	// Логируем статистику
	if ($entriesCount === 0) {
		error_log("OPDS list.php: WARNING - No entries were added to feed! Errors: $errorsCount");
	}
} catch (PDOException $e) {
	error_log("OPDS list.php: SQL error in books query: " . $e->getMessage());
	error_log("OPDS list.php: Query: SELECT b.* FROM libbook b $join WHERE $filter ORDER BY $orderby LIMIT :limit OFFSET :offset");
	error_log("OPDS list.php: Params: genre_id=" . ($genre_id ?? 'null') . ", seq_id=" . ($seq_id ?? 'null') . ", author_id=" . ($author_id ?? 'null'));
	OPDSErrorHandler::sendSqlError($e->getMessage());
} catch (Exception $e) {
	OPDSErrorHandler::handleException($e, 500);
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
