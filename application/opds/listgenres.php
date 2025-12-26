<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS
$opdsCache = new OPDSCache(null, 3600, true); // 1 час TTL

// Получаем параметры для кэша
$genreMeta = isset($_GET['id']) ? trim($_GET['id']) : '';

// Валидация жанра перед кэшем
if ($genreMeta == '') {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:listgenres:missing</id>
  <title>Ошибка</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:missing_genre</id>
    <title>Не указан жанр</title>
    <summary type="text">Необходимо указать идентификатор жанра (параметр id)</summary>
  </entry>
</feed>';
    exit;
}

// Валидация входных данных
$genreMeta = htmlspecialchars($genreMeta, ENT_QUOTES, 'UTF-8');

// Создаем ключ кэша для списка жанров
$cacheKey = 'opds_listgenres_' . md5($genreMeta) . '_' . OPDSVersion::detect();

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

$feed->setId('tag:root:listgenres');
$feed->setTitle("Жанры в $genreMeta");
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

// Рендерим фид
$content = $feed->render();

// Сохраняем в кэш
$opdsCache->set($cacheKey, $content);

// Устанавливаем заголовки кэширования и отправляем ответ
$etag = $opdsCache->generateETag($content);
$opdsCache->setCacheHeaders($etag);
echo $content;
?>
