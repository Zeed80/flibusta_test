<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Создаем ключ кэша для страницы избранных пользователей
$cacheKey = 'opds_favs_' . OPDSVersion::detect();

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

$feed->setId('tag:root:favs');
$feed->setTitle('Книжные полки');
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
	'self',
	OPDSVersion::getProfile($version, 'navigation')
));

// Получаем список пользователей с избранным
$favs = $dbh->prepare("SELECT * FROM fav_users");
$favs->execute();

while ($fav = $favs->fetch()) {
	$entry = new OPDSEntry();
	$entry->setId("tag:fav:{$fav->user_uuid}");
	$entry->setTitle($fav->name ?: "Избранное пользователя");
	$entry->setUpdated($cdt);
	$entry->setContent("Избранные книги пользователя", 'text');
	$entry->addLink(new OPDSLink(
		$webroot . '/opds/fav?uuid=' . urlencode($fav->user_uuid),
		'http://opds-spec.org/acquisition',
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
