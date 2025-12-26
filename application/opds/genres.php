<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS
$opdsCache = new OPDSCache(null, 3600, true); // 1 час TTL

// Создаем ключ кэша для страницы жанров
$cacheKey = 'opds_genres_' . OPDSVersion::detect();

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

$feed->setId('tag:root:genres');
$feed->setTitle('Категории жанров');
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
	$webroot . '/opds/genres/',
	'self',
	OPDSVersion::getProfile($version, 'navigation')
));

$gs = $dbh->prepare("SELECT DISTINCT(genremeta) genre FROM libgenrelist ORDER BY genre");
$gs->execute();

while ($g = $gs->fetch()) {
	$entry = new OPDSEntry();
	$entry->setId("tag:genre:" . urlencode($g->genre));
	$entry->setTitle($g->genre);
	$entry->setUpdated($cdt);
	$entry->setContent('', 'text');
	$entry->addLink(new OPDSLink(
		$webroot . '/opds/listgenres/?id=' . urlencode($g->genre),
		'subsection',
		OPDSVersion::getProfile($version, 'acquisition')
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
