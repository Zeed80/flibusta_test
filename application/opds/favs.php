<?php
header('Content-Type: application/atom+xml; charset=utf-8');

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
		'subsection',
		OPDSVersion::getProfile($version, 'acquisition')
	));
	$feed->addEntry($entry);
}

echo $feed->render();
?>
