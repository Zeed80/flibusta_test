<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Инициализируем кэш OPDS
$opdsCache = new OPDSCache(null, 3600, true); // 1 час TTL

// Получаем параметры для кэша
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Валидация поискового запроса
if ($q == '') {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:search:author:empty</id>
  <title>Ошибка поиска</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:empty_query</id>
    <title>Пустой запрос</title>
    <summary type="text">Пожалуйста, укажите строку для поиска авторов</summary>
  </entry>
</feed>';
    exit;
}

// Создаем ключ кэша для поиска авторов
$cacheKey = 'opds_search_author_' . md5($q) . '_' . OPDSVersion::detect();

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

// Настройка фида
$feed->setId('tag:root:search:author:' . md5($q));
$feed->setTitle('Поиск по авторам: ' . htmlspecialchars($q, ENT_XML1, 'UTF-8'));
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
    $webroot . '/opds/opensearch.xml.php',
    'search',
    'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/search?by=author&searchTerm={searchTerms}',
    'search',
    OPDSVersion::getProfile($version, 'acquisition')
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/',
    'start',
    OPDSVersion::getProfile($version, 'navigation')
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/authorsindex',
    'up',
    OPDSVersion::getProfile($version, 'navigation')
));

// Полнотекстовый поиск по фамилии авторов
$queryParam = $q . '%';
$authors = $dbh->prepare("SELECT *, 
    (SELECT COUNT(*) FROM libavtor, libbook WHERE 
    libbook.deleted='0' AND
    libbook.bookid=libavtor.bookid AND
    libavtor.avtorid=libavtorname.avtorid) cnt
    FROM libavtorname
    WHERE lastname ILIKE :q 
    ORDER BY lastname, firstname
    LIMIT 100");
$authors->bindParam(":q", $queryParam);
$authors->execute();

while ($a = $authors->fetch()) {
    if ($a->cnt > 0) {
        // Нормализуем имя автора
        $authorName = trim("$a->lastname $a->firstname $a->middlename $a->nickname");
        $normalizedAuthorName = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($authorName) : $authorName;
        
        $entry = new OPDSEntry();
        $entry->setId("tag:author:$a->avtorid");
        $entry->setTitle($normalizedAuthorName);
        $entry->setUpdated($cdt);
        $entry->setContent("$a->cnt книг", 'text');
        $entry->addLink(new OPDSLink(
            $webroot . '/opds/author?author_id=' . $a->avtorid,
            'subsection',
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        $feed->addEntry($entry);
    }
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
