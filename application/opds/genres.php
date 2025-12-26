<?php
header('Content-Type: application/atom+xml; charset=utf-8');

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

echo $feed->render();
?>
