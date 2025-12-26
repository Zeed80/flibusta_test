<?php
declare(strict_types=1);

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
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
    error_log("OPDS search_book.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры для кэша
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$get = "?q=" . urlencode($q);

// Валидация поискового запроса
if ($q == '') {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:search:book:empty</id>
  <title>Ошибка поиска</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:empty_query</id>
    <title>Пустой запрос</title>
    <summary type="text">Пожалуйста, укажите строку для поиска книг</summary>
  </entry>
</feed>';
    exit;
}

// Создаем ключ кэша для поиска книг
$cacheKey = 'opds_search_book_' . md5($q) . '_' . OPDSVersion::detect();

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

// Настройка фида
$feed->setId('tag:root:authors');
$feed->setTitle('Поиск по книгам');
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
	$webroot . '/opds',
	'start',
	OPDSVersion::getProfile($version, 'navigation')
));

// Полнотекстовый поиск по названию, автору и аннотации
try {
	$searchParam = '%' . $q . '%';
	$books = $dbh->prepare("SELECT DISTINCT b.BookId, b.Title as BookTitle, b.time, b.lang, b.year, b.filetype, b.filesize,
			(SELECT Body FROM libbannotations WHERE BookId=b.BookId LIMIT 1) as Body
			FROM libbook b
			LEFT JOIN libavtor USING(BookId)
			LEFT JOIN libavtorname USING(AvtorId)
			LEFT JOIN libbannotations USING(BookId)
			WHERE b.deleted='0' 
			AND (
				b.Title ILIKE :q 
				OR libavtorname.LastName ILIKE :q 
				OR libavtorname.FirstName ILIKE :q
				OR libbannotations.Body ILIKE :q
			)
			GROUP BY b.BookId, b.Title, b.time, b.lang, b.year, b.filetype, b.filesize, Body
			ORDER BY b.time DESC
			LIMIT 100");

	$books->bindParam(":q", $searchParam);
	$books->execute();
} catch (PDOException $e) {
	error_log("OPDS search_book.php: SQL error: " . $e->getMessage());
	http_response_code(500);
	header('Content-Type: application/atom+xml; charset=utf-8');
	echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:sql</id>
  <title>Ошибка базы данных</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:search</id>
    <title>Ошибка поиска</title>
    <summary type="text">Не удалось выполнить поисковый запрос</summary>
  </entry>
</feed>';
	exit;
}

while ($b = $books->fetchObject()) {
	$entry = new OPDSEntry();
	// Исправляем регистр: в SQL используется BookId, поэтому обращаемся к BookId
	$bookId = $b->BookId ?? $b->bookid ?? null;
	if (!$bookId) {
		continue; // Пропускаем записи без ID
	}
	
	$entry->setId("tag:book:{$bookId}");
	$entry->setTitle($b->BookTitle ?? $b->booktitle ?? 'Без названия');
	$entry->setUpdated($b->time ?: date('c'));
	
	// Авторы
	$as = '';
	$authors = $dbh->prepare("SELECT lastname, firstname, middlename, AvtorId FROM libavtorname, libavtor 
		WHERE libavtor.BookId=:bookid AND libavtor.AvtorId=libavtorname.AvtorId ORDER BY LastName");
	$authors->bindParam(":bookid", $bookId, PDO::PARAM_INT);
	$authors->execute();
	while ($a = $authors->fetchObject()) {
		$authorName = trim(($a->lastname ?? '') . ' ' . ($a->firstname ?? '') . ' ' . ($a->middlename ?? ''));
		if ($authorName) {
			$as .= $authorName . ", ";
			$authorId = $a->AvtorId ?? $a->avtorid ?? null;
			if ($authorId) {
				$entry->addAuthor($authorName, $webroot . '/opds/author?author_id=' . $authorId);
			}
		}
	}
	
	// Исправляем регистр: в SQL используется Body, поэтому обращаемся к Body
	$body = $b->Body ?? $b->body ?? null;
	if ($body) {
		// Экранируем HTML контент для безопасного включения в XML
		$entry->setContent($body, 'text/html');
	}
	
	// Ссылки на изображения
	$entry->addLink(new OPDSLink(
		$webroot . '/extract_cover.php?id=' . $bookId,
		'http://opds-spec.org/image/thumbnail',
		'image/jpeg'
	));
	
	$entry->addLink(new OPDSLink(
		$webroot . '/extract_cover.php?id=' . $bookId,
		'http://opds-spec.org/image',
		'image/jpeg'
	));
	
	// Ссылка на скачивание
	$fileType = trim($b->filetype ?? '');
	if ($fileType == 'fb2') {
		$mimeType = 'application/fb2+zip';
		$downloadUrl = $webroot . '/fb2.php?id=' . $bookId;
	} else {
		$mimeType = 'application/' . $fileType;
		$downloadUrl = $webroot . '/usr.php?id=' . $bookId;
	}
	
	$entry->addLink(new OPDSLink(
		$downloadUrl,
		'http://opds-spec.org/acquisition/open-access',
		$mimeType
	));
	
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
