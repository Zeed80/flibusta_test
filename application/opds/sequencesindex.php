<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$letters = isset($_GET['letters']) ? trim($_GET['letters']) : '';

$feed->setId('tag:root:sequences');
$feed->setTitle('Книги по сериям');
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
	$webroot . '/opds/sequencesindex' . ($letters ? '?letters=' . urlencode($letters) : ''),
	'self',
	OPDSVersion::getProfile($version, 'navigation')
));

$length_letters = mb_strlen($letters, 'UTF-8');

if ($length_letters > 0) {
	$pattern = $letters . '_';
	$query = "
		SELECT UPPER(SUBSTR(SeqName, 1, " . ($length_letters + 1) . ")) as alpha, COUNT(*) as cnt
		FROM libseqname
		WHERE UPPER(SUBSTR(SeqName, 1, " . ($length_letters + 1) . ")) SIMILAR TO :pattern
		GROUP BY UPPER(SUBSTR(SeqName, 1, " . ($length_letters + 1) . "))
		ORDER BY alpha";
	$ai = $dbh->prepare($query);
	$ai->bindParam(":pattern", $pattern);
	$ai->execute();
} else {
	$query = "
		SELECT UPPER(SUBSTR(SeqName, 1, 1)) as alpha, COUNT(*) as cnt
		FROM libseqname
		GROUP BY UPPER(SUBSTR(SeqName, 1, 1))
		ORDER BY alpha";
	$ai = $dbh->query($query);
}

while ($ach = $ai->fetchObject()) {
	// Нормализуем алфавитный индекс
	$normalizedAlpha = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($ach->alpha) : $ach->alpha;
	
	if ($ach->cnt > 30) {
		$entry = new OPDSEntry();
		$entry->setId("tag:sequences:" . urlencode($normalizedAlpha));
		$entry->setTitle($normalizedAlpha);
		$entry->setUpdated($cdt);
		$entry->setContent("$ach->cnt книжных серий на " . $normalizedAlpha, 'text');
		$entry->addLink(new OPDSLink(
			$webroot . '/opds/sequencesindex?letters=' . urlencode($normalizedAlpha),
			'subsection',
			OPDSVersion::getProfile($version, 'acquisition')
		));
		$feed->addEntry($entry);
	} else {
		// list individual serie
		$sq = $dbh->prepare("SELECT SeqName, SeqId 
			from libseqname 
			where UPPER(SUBSTR(SeqName, 1, " . ($length_letters + 1) . ")) = :pattern
			ORDER BY UPPER(SeqName)");
		$sq->bindParam(":pattern", $ach->alpha);
		$sq->execute();
		while($s = $sq->fetchObject()){
			// Нормализуем название серии
			$normalizedSeqName = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($s->seqname) : $s->seqname;
			
			$entry = new OPDSEntry();
			$entry->setId("tag:sequence:$s->seqid");
			$entry->setTitle($normalizedSeqName);
			$entry->setUpdated($cdt);
			$entry->setContent('', 'text');
			$entry->addLink(new OPDSLink(
				$webroot . '/opds/list?seq_id=' . (int)$s->seqid,
				'subsection',
				OPDSVersion::getProfile($version, 'acquisition')
			));
			$feed->addEntry($entry);
		}
	}
}

echo $feed->render();
?>
