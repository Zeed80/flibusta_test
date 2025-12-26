<?php
// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Создаем ключ кэша для главной страницы
$cacheKey = 'opds_main_' . OPDSVersion::detect();

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

// Если кэша нет, генерируем фид
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$feed->setId('tag:root');
$feed->setTitle('Домашняя библиотека');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки поиска
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
    $webroot . '/opds/',
    'self',
    OPDSVersion::getProfile($version, 'navigation')
));

// Новинки
$newEntry = new OPDSEntry();
$newEntry->setId('tag:root:new');
$newEntry->setTitle('Новинки');
$newEntry->setUpdated($cdt);
$newEntry->setContent('Последние поступления в библиотеку', 'text');
$newEntry->addLink(new OPDSLink(
    $webroot . '/opds/list/',
    'http://opds-spec.org/sort/new',
    OPDSVersion::getProfile($version, 'acquisition')
));
$feed->addEntry($newEntry);

// Книжные полки
$shelfEntry = new OPDSEntry();
$shelfEntry->setId('tag:root:shelf');
$shelfEntry->setTitle('Книжные полки');
$shelfEntry->setUpdated($cdt);
$shelfEntry->setContent('Избранное', 'text');
$shelfEntry->addLink(new OPDSLink(
    $webroot . '/opds/favs/',
    'subsection',
    OPDSVersion::getProfile($version, 'acquisition')
));
$feed->addEntry($shelfEntry);

// По жанрам
$genreEntry = new OPDSEntry();
$genreEntry->setId('tag:root:genre');
$genreEntry->setTitle('По жанрам');
$genreEntry->setUpdated($cdt);
$genreEntry->setContent('Поиск книг по жанрам', 'text');
$genreEntry->addLink(new OPDSLink(
    $webroot . '/opds/genres',
    'subsection',
    OPDSVersion::getProfile($version, 'acquisition')
));
$feed->addEntry($genreEntry);

// По авторам
$authorsEntry = new OPDSEntry();
$authorsEntry->setId('tag:root:authors');
$authorsEntry->setTitle('По авторам');
$authorsEntry->setUpdated($cdt);
$authorsEntry->setContent('Поиск книг по авторам', 'text');
$authorsEntry->addLink(new OPDSLink(
    $webroot . '/opds/authorsindex',
    'subsection',
    OPDSVersion::getProfile($version, 'acquisition')
));
$feed->addEntry($authorsEntry);

// По сериям
$sequencesEntry = new OPDSEntry();
$sequencesEntry->setId('tag:root:sequences');
$sequencesEntry->setTitle('По сериям');
$sequencesEntry->setUpdated($cdt);
$sequencesEntry->setContent('Поиск книг по сериям', 'text');
$sequencesEntry->addLink(new OPDSLink(
    $webroot . '/opds/sequencesindex',
    'subsection',
    OPDSVersion::getProfile($version, 'acquisition')
));
$feed->addEntry($sequencesEntry);

// Рендерим фид
$content = $feed->render();

// Сохраняем в кэш
$opdsCache->set($cacheKey, $content);

// Устанавливаем заголовки кэширования и отправляем ответ
$etag = $opdsCache->generateETag($content);
$opdsCache->setCacheHeaders($etag);
echo $content;
?>
