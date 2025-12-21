<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$uuid = isset($_GET['uuid']) ? $_GET['uuid'] : '';
if ($uuid == '') {
	die('uuid required');
}

// Валидация UUID
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
	die('invalid uuid');
}

$feed->setId("tag:fav:$uuid");
$feed->setTitle('Избранное');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds-opensearch.xml.php',
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

echo $feed->render();
?>
