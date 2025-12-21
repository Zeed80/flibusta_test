<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$get = "?q=" . urlencode($q);

if ($q == '') {
	die(':(');
}

// Настройка фида
$feed->setId('tag:root:authors');
$feed->setTitle('Поиск по книгам');
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
	$webroot . '/opds',
	'start',
	OPDSVersion::getProfile($version, 'navigation')
));

// Полнотекстовый поиск по названию, автору и аннотации
$searchParam = '%' . $q . '%';
$books = $dbh->prepare("SELECT DISTINCT b.BookId, b.Title as BookTitle, b.time, b.lang, b.year, b.filetype, b.filesize,
        (SELECT Body FROM libbannotations WHERE BookId=b.BookId LIMIT 1) as Body
		FROM libbook b
		LEFT JOIN libavtor USING(BookId)
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libbannotations USING(BookId)
		WHERE b.deleted='0' 
		AND (
			b.Title ILIKE :q 
			OR libavtorname.LastName ILIKE :q 
			OR libavtorname.FirstName ILIKE :q
			OR libbannotations.Body ILIKE :q
		)
		GROUP BY b.BookId, b.Title, b.time, b.lang, b.year, b.filetype, b.filesize, Body
		ORDER BY b.time DESC
		LIMIT 100");

$books->bindParam(":q", $searchParam);
$books->execute();

while ($b = $books->fetchObject()) {
	$entry = new OPDSEntry();
	$entry->setId("tag:book:{$b->bookid}");
	$entry->setTitle($b->booktitle);
	$entry->setUpdated($b->time ?: date('c'));
	
	// Авторы
	$as = '';
	$authors = $dbh->prepare("SELECT lastname, firstname, middlename FROM libavtorname, libavtor 
		WHERE libavtor.BookId=:bookid AND libavtor.AvtorId=libavtorname.AvtorId ORDER BY LastName");
	$authors->bindParam(":bookid", $b->bookid);
	$authors->execute();
	while ($a = $authors->fetchObject()) {
		$authorName = trim("$a->lastname $a->firstname $a->middlename");
		$as .= $authorName . ", ";
		$entry->addAuthor($authorName, $webroot . '/opds/author?author_id=' . $a->avtorid);
	}
	
	if ($b->body) {
		$entry->setContent($b->body, 'text/html');
	}
	
	// Ссылки на изображения
	$entry->addLink(new OPDSLink(
		$webroot . '/extract_cover.php?id=' . $b->bookid,
		'http://opds-spec.org/image/thumbnail',
		'image/jpeg'
	));
	
	$entry->addLink(new OPDSLink(
		$webroot . '/extract_cover.php?id=' . $b->bookid,
		'http://opds-spec.org/image',
		'image/jpeg'
	));
	
	// Ссылка на скачивание
	$fileType = trim($b->filetype);
	if ($fileType == 'fb2') {
		$mimeType = 'application/fb2+zip';
		$downloadUrl = $webroot . '/fb2.php?id=' . $b->bookid;
	} else {
		$mimeType = 'application/' . $fileType;
		$downloadUrl = $webroot . '/usr.php?id=' . $b->bookid;
	}
	
	$entry->addLink(new OPDSLink(
		$downloadUrl,
		'http://opds-spec.org/acquisition/open-access',
		$mimeType
	));
	
	$feed->addEntry($entry);
}

echo $feed->render();
?>
