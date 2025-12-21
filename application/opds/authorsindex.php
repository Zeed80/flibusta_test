<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$letters = isset($_GET['letters']) ? trim($_GET['letters']) : '';

$feed->setId('tag:root:authors');
$feed->setTitle('Книги по авторам');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds-opensearch.xml.php',
	'search',
	'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex?letters={searchTerms}',
	'search',
	OPDSVersion::getProfile($version, 'acquisition')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds',
	'start',
	OPDSVersion::getProfile($version, 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex' . ($letters ? '?letters=' . urlencode($letters) : ''),
	'self',
	OPDSVersion::getProfile($version, 'navigation')
));

$length_letters = mb_strlen($letters, 'UTF-8');

// Исправляем SQL-инъекцию, используя prepared statement
if ($length_letters > 0) {
	$pattern = $letters . '_';
	$query = "
		SELECT UPPER(SUBSTR(LastName, 1, " . ($length_letters + 1) . ")) as alpha, COUNT(*) as cnt
		FROM libavtorname
		WHERE UPPER(SUBSTR(LastName, 1, " . ($length_letters + 1) . ")) SIMILAR TO :pattern
		GROUP BY UPPER(SUBSTR(LastName, 1, " . ($length_letters + 1) . "))
		ORDER BY alpha";
	$ai = $dbh->prepare($query);
	$ai->bindParam(":pattern", $pattern);
	$ai->execute();
} else {
	$query = "
		SELECT UPPER(SUBSTR(LastName, 1, 1)) as alpha, COUNT(*) as cnt
		FROM libavtorname
		GROUP BY UPPER(SUBSTR(LastName, 1, 1))
		ORDER BY alpha";
	$ai = $dbh->query($query);
}

while ($ach = $ai->fetchObject()) {
	$entry = new OPDSEntry();
	$entry->setId("tag:authors:" . htmlspecialchars($ach->alpha, ENT_XML1, 'UTF-8'));
	$entry->setTitle(htmlspecialchars($ach->alpha, ENT_XML1, 'UTF-8'));
	$entry->setUpdated($cdt);
	$entry->setContent("$ach->cnt авторов на " . htmlspecialchars($ach->alpha, ENT_XML1, 'UTF-8'), 'text');
	
	if ($ach->cnt > 500) {
		$url = $webroot . '/opds/authorsindex?letters=' . urlencode($ach->alpha);
	} else {
		$url = $webroot . '/opds/search?by=author&q=' . urlencode($ach->alpha);
	}
	
	$entry->addLink(new OPDSLink(
		$url,
		'subsection',
		OPDSVersion::getProfile($version, 'acquisition')
	));
	
	$feed->addEntry($entry);
}

echo $feed->render();
?>
