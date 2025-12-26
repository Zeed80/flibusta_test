<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS
$opdsCache = new OPDSCache(null, 3600, true); // 1 час TTL

// Получаем параметры для кэша
$uuid = isset($_GET['uuid']) ? $_GET['uuid'] : '';

// Валидация UUID перед кэшем
if ($uuid == '') {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
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
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
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
$cacheKey = 'opds_fav_' . md5($uuid) . '_' . OPDSVersion::detect();

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
	OPDSVersion::getProfile($version, 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/favs/',
	'up',
	OPDSVersion::getProfile($version, 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/fav?uuid=' . urlencode($uuid),
	'self',
	OPDSVersion::getProfile($version, 'acquisition')
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
