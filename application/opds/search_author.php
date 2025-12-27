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
    error_log("OPDS search_author.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры для кэша
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Валидация поискового запроса
if ($q == '') {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="https://specs.opds.io/opds-1.2">
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
$cacheKey = 'opds_search_author_' . md5($q) . '_v2';

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
    OPDSVersion::getProfile( 'acquisition')
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/',
    'start',
    OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/authorsindex',
    'up',
    OPDSVersion::getProfile( 'navigation')
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
        // НЕ нормализуем имя автора - сохраняем оригинальный текст (включая кириллицу)
        $authorName = trim("$a->lastname $a->firstname $a->middlename $a->nickname");
        if (empty($authorName)) {
            continue;
        }
        
        $entry = new OPDSEntry();
        $entry->setId("tag:author:$a->avtorid");
        $entry->setTitle($authorName);
        $entry->setUpdated($cdt);
        $entry->setContent("$a->cnt книг", 'text');
        $entry->addLink(new OPDSLink(
            $webroot . '/opds/author?author_id=' . $a->avtorid,
            'subsection',
            OPDSVersion::getProfile( 'navigation')
        ));
        $feed->addEntry($entry);
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
