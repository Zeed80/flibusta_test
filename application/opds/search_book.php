<?php
declare(strict_types=1);

// Инвалидируем opcache для этого файла (на случай если изменения не применились)
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/atom+xml; charset=utf-8');

// Проверяем наличие необходимых глобальных переменных
if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
    error_log("OPDS search_book.php: Missing required global variables (dbh, webroot, or cdt)");
    OPDSErrorHandler::sendInitializationError();
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Валидируем поисковый запрос
// Поддерживаем оба варианта параметров: 'q' и 'searchTerm'
$q = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['searchTerm']) ? trim($_GET['searchTerm']) : '');
if (empty($q)) {
    OPDSValidator::handleValidationException(new \InvalidArgumentException('Пустой поисковый запрос'));
}
// Проверяем длину
if (mb_strlen($q, 'UTF-8') > 255) {
    $q = mb_substr($q, 0, 255, 'UTF-8');
}

// Создаем ключ кэша для поиска книг
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_search_book_v5_' . md5($q);

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
// Создаем фид OPDS 1.2 используя сервис
$feedService = new OPDSFeedService($dbh, $webroot, $cdt);
$feed = $feedService->createFeed('tag:root:search:books', 'Поиск по книгам', 'acquisition');

// Полнотекстовый поиск по названию, автору и аннотации
try {
	// Используем валидированное значение $q (без экранирования, так как оно уже обработано в валидаторе)
	// Но для SQL нужен параметр с подстановочными символами
	$searchParam = '%' . $q . '%';
	$books = $dbh->prepare("SELECT DISTINCT b.BookId, b.Title as BookTitle, b.time, b.lang, b.year, b.filetype, b.filesize,
			(SELECT body FROM libbannotations WHERE bookid=b.BookId LIMIT 1) as Body
			FROM libbook b
			LEFT JOIN libavtor USING(BookId)
			LEFT JOIN libavtorname USING(AvtorId)
			LEFT JOIN libbannotations USING(BookId)
			WHERE b.deleted='0' 
			AND (
				b.Title ILIKE :q 
				OR libavtorname.lastname ILIKE :q 
				OR libavtorname.firstname ILIKE :q
				OR libbannotations.body ILIKE :q
			)
			GROUP BY b.BookId, b.Title, b.time, b.lang, b.year, b.filetype, b.filesize, Body
			ORDER BY b.time DESC
			LIMIT 100");

	$books->bindParam(":q", $searchParam);
	$books->execute();
} catch (PDOException $e) {
	error_log("OPDS search_book.php: SQL error: " . $e->getMessage());
	OPDSErrorHandler::sendSqlError($e->getMessage());
}

// Используем OPDSBookService для создания entries
$bookService = new OPDSBookService($dbh, $webroot, $cdt);
while ($b = $books->fetchObject()) {
	// Приводим объект к формату, который ожидает opds_book_entry()
	// Преобразуем BookTitle в Title для совместимости
	$bookObj = $b;
	if (isset($bookObj->BookTitle) && !isset($bookObj->Title)) {
		$bookObj->Title = $bookObj->BookTitle;
	}
	
	// Используем сервис для создания entry
	$entry = $bookService->createBookEntry($bookObj);
	if ($entry) {
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
