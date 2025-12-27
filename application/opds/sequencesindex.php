<?php
declare(strict_types=1);

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Устанавливаем заголовок сразу, чтобы в случае ошибки вернуть XML
header('Content-Type: application/atom+xml; charset=utf-8');

// Проверяем наличие необходимых глобальных переменных
if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="https://specs.opds.io/opds-1.2">
  <id>tag:error:internal</id>
  <title>Внутренняя ошибка сервера</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:init</id>
    <title>Ошибка инициализации</title>
    <summary type="text">Не удалось инициализировать необходимые переменные</summary>
  </entry>
</feed>';
    error_log("OPDS sequencesindex.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры для кэша
$letters = isset($_GET['letters']) ? trim($_GET['letters']) : '';

// Создаем ключ кэша для индекса серий
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_sequencesindex_v4_' . md5($letters);

// Проверяем кэш
$cachedContent = $opdsCache->get($cacheKey);
if ($cachedContent !== null) {
    // Кэш действителен, отправляем с заголовками кэширования
    // ВАЖНО: устанавливаем Content-Type ДО setCacheHeaders
    header('Content-Type: application/atom+xml; charset=utf-8');
    $etag = $opdsCache->generateETag($cachedContent);
    $opdsCache->checkETag($etag);
    $opdsCache->setCacheHeaders($etag);
    echo $cachedContent;
    exit;
}

// Если кэша нет или устарел, генерируем фид
// Создаем фид OPDS 1.2
$feed = OPDSFeedFactory::create();

$feed->setId('tag:root:sequences');
$feed->setTitle('Книги по сериям');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds/opensearch.xml.php',
	'search',
	'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex?letters={searchTerms}',
	'search',
	OPDSVersion::getProfile( 'acquisition')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds',
	'start',
	OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/sequencesindex' . ($letters ? '?letters=' . urlencode($letters) : ''),
	'self',
	OPDSVersion::getProfile( 'navigation')
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
	// НЕ нормализуем алфавитный индекс - сохраняем оригинальный текст (включая кириллицу)
	$alpha = trim($ach->alpha ?? '');
	
	// Пропускаем пустые записи
	if (empty($alpha)) {
		continue;
	}
	
	if ($ach->cnt > 30) {
		$entry = new OPDSEntry();
		$entry->setId("tag:sequences:" . urlencode($alpha));
		$entry->setTitle($alpha);
		$entry->setUpdated($cdt);
		$entry->setContent("$ach->cnt книжных серий на " . $alpha, 'text');
		$entry->addLink(new OPDSLink(
			$webroot . '/opds/sequencesindex?letters=' . urlencode($alpha),
			'subsection',
			OPDSVersion::getProfile( 'acquisition')
		));
		$feed->addEntry($entry);
	} else {
		// list individual serie
		$sq = $dbh->prepare("SELECT SeqName, SeqId 
				from libseqname 
				where UPPER(SUBSTR(SeqName, 1, " . ($length_letters + 1) . ")) = :pattern
				ORDER BY UPPER(SeqName)");
		$sq->bindParam(":pattern", $alpha);
		$sq->execute();
		while($s = $sq->fetchObject()){
			// НЕ нормализуем название серии - сохраняем оригинальный текст (включая кириллицу)
			$seqName = trim($s->seqname ?? '');
			if (empty($seqName)) {
				continue;
			}
			
			$entry = new OPDSEntry();
			$entry->setId("tag:sequence:$s->seqid");
			$entry->setTitle($seqName);
			$entry->setUpdated($cdt);
			$entry->setContent('', 'text');
			$entry->addLink(new OPDSLink(
				$webroot . '/opds/list?seq_id=' . (int)$s->seqid,
				'http://opds-spec.org/acquisition',
				OPDSVersion::getProfile( 'acquisition')
			));
			$feed->addEntry($entry);
		}
	}
}

// Рендерим фид
$content = $feed->render();

// Сохраняем в кэш
$opdsCache->set($cacheKey, $content);

// Устанавливаем заголовки кэширования и отправляем ответ
// ВАЖНО: устанавливаем Content-Type перед setCacheHeaders
header('Content-Type: application/atom+xml; charset=utf-8');
$etag = $opdsCache->generateETag($content);
$opdsCache->setCacheHeaders($etag);
echo $content;
?>
