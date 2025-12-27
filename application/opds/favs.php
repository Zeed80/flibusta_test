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
    error_log("OPDS favs.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Создаем ключ кэша для страницы избранных пользователей
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_favs_v3';

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
	OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/favs/',
	'self',
	OPDSVersion::getProfile( 'navigation')
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
		'http://opds-spec.org/acquisition',
		OPDSVersion::getProfile( 'acquisition')
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
