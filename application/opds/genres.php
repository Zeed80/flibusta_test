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
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:internal</id>
  <title>Внутренняя ошибка сервера</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:init</id>
    <title>Ошибка инициализации</title>
    <summary type="text">Не удалось инициализировать необходимые переменные</summary>
  </entry>
</feed>';
    error_log("OPDS genres.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Создаем ключ кэша для страницы жанров
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_genres_v3_' . OPDSVersion::detect();

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

while ($g = $gs->fetch(PDO::FETCH_OBJ)) {
	$genre = $g->genre ?? '';
	if (!$genre) {
		continue;
	}
	$entry = new OPDSEntry();
	$entry->setId("tag:genre:" . urlencode($genre));
	$entry->setTitle($genre);
	$entry->setUpdated($cdt);
	$entry->setContent("Книги в жанре: $genre", 'text');
	$entry->addLink(new OPDSLink(
		$webroot . '/opds/listgenres/?id=' . urlencode($genre),
		'subsection',
		OPDSVersion::getProfile($version, 'navigation')
	));
	$feed->addEntry($entry);
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
