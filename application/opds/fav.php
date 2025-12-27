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
    error_log("OPDS fav.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры для кэша
$uuid = isset($_GET['uuid']) ? $_GET['uuid'] : '';

// Валидация UUID перед кэшем
if ($uuid == '') {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="https://specs.opds.io/opds-1.2">
  <id>tag:error:fav:missing</id>
  <title>Ошибка</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:missing_uuid</id>
    <title>Не указан UUID</title>
    <summary type="text">Необходимо указать UUID пользователя</summary>
  </entry>
</feed>';
    exit;
}

// Валидация UUID
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="https://specs.opds.io/opds-1.2">
  <id>tag:error:fav:invalid</id>
  <title>Ошибка</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:invalid_uuid</id>
    <title>Некорректный UUID</title>
    <summary type="text">Указанный UUID имеет некорректный формат</summary>
  </entry>
</feed>';
    exit;
}

// Создаем ключ кэша для избранного пользователя
$cacheKey = 'opds_fav_' . md5($uuid) . '_v2';

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

$feed->setId("tag:fav:$uuid");
$feed->setTitle('Избранное');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds/opensearch.xml.php',
	'search',
	'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/',
	'start',
	OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/favs/',
	'up',
	OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/fav?uuid=' . urlencode($uuid),
	'self',
	OPDSVersion::getProfile( 'acquisition')
));

// Получаем избранные книги
$books = $dbh->prepare("SELECT b.*
	FROM libbook b
	JOIN fav f ON f.bookid = b.BookId
	WHERE f.user_uuid = :uuid AND b.deleted = '0'
	ORDER BY b.time DESC
	LIMIT 100");

$books->bindParam(":uuid", $uuid);
$books->execute();

while ($b = $books->fetch()) {
	$entry = opds_book_entry($b, $webroot);
	$feed->addEntry($entry);
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
