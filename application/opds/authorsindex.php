<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS
$opdsCache = new OPDSCache(null, 3600, true); // 1 час TTL

// Получаем параметры для кэша
$letters = isset($_GET['letters']) ? trim($_GET['letters']) : '';

// Создаем ключ кэша для индекса авторов
$cacheKey = 'opds_authorsindex_' . md5($letters) . '_' . OPDSVersion::detect();

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

$feed->setId('tag:root:authors');
$feed->setTitle('Книги по авторам');
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
	// Нормализуем алфавитный индекс
	$normalizedAlpha = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($ach->alpha) : $ach->alpha;
	
	if ($ach->cnt > 500) {
		$url = $webroot . '/opds/authorsindex?letters=' . urlencode($normalizedAlpha);
	} else {
		$url = $webroot . '/opds/search?by=author&q=' . urlencode($normalizedAlpha);
	}
	
	$entry = new OPDSEntry();
	$entry->setId("tag:authors:" . htmlspecialchars($normalizedAlpha, ENT_XML1, 'UTF-8'));
	$entry->setTitle($normalizedAlpha);
	$entry->setUpdated($cdt);
	$entry->setContent("$ach->cnt авторов на " . $normalizedAlpha, 'text');
	
	// Используем правильный rel для OPDS
	if ($ach->cnt > 500) {
		$entry->addLink(new OPDSLink(
			$url,
			'subsection',
			OPDSVersion::getProfile($version, 'acquisition')
		));
	} else {
		$entry->addLink(new OPDSLink(
			$url,
			'http://opds-spec.org/acquisition',
			OPDSVersion::getProfile($version, 'acquisition')
		));
	}
	
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
