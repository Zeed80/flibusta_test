<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$genreMeta = isset($_GET['id']) ? trim($_GET['id']) : '';

if ($genreMeta == '') {
	die('Genre meta required');
}

// Валидация входных данных
$genreMeta = htmlspecialchars($genreMeta, ENT_QUOTES, 'UTF-8');

$feed->setId('tag:root:listgenres');
$feed->setTitle("Жанры в $genreMeta");
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds-opensearch.xml.php',
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
	$webroot . '/opds/listgenres/?id=' . urlencode($genreMeta),
	'self',
	OPDSVersion::getProfile($version, 'acquisition')
));

$gs = $dbh->prepare("SELECT *,
	(SELECT COUNT(*) FROM libgenre WHERE libgenre.genreid=g.genreid) cnt
	FROM libgenrelist g
	WHERE g.genremeta=:id");
$gs->bindParam(":id", $genreMeta);
$gs->execute();

while ($g = $gs->fetch()) {
	$entry = new OPDSEntry();
	$entry->setId("tag:genre:" . htmlspecialchars($g->genrecode, ENT_XML1, 'UTF-8'));
	$entry->setTitle(htmlspecialchars($g->genredesc, ENT_XML1, 'UTF-8'));
	$entry->setUpdated($cdt);
	$entry->setContent("Книг: $g->cnt", 'text');
	$entry->addLink(new OPDSLink(
		$webroot . '/opds/list/?genre_id=' . (int)$g->genreid,
		'subsection',
		OPDSVersion::getProfile($version, 'acquisition')
	));
	$feed->addEntry($entry);
}

echo $feed->render();
?>
