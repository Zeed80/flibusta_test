<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$feed->setId('tag:root');
$feed->setTitle('Домашняя библиотека');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки поиска
$feed->addLink(new OPDSLink(
    $webroot . '/opds-opensearch.xml.php',
    'search',
    'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/search?q={searchTerms}',
    'search',
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/',
    'start',
    OPDSVersion::getProfile($feed->getVersion(), 'navigation')
));

$feed->addLink(new OPDSLink(
    $webroot . '/opds/',
    'self',
    OPDSVersion::getProfile($feed->getVersion(), 'navigation')
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
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
));
$newEntry->addLink(new OPDSLink(
    $webroot . '/opds/list/',
    'subsection',
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
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
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
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
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
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
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
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
    OPDSVersion::getProfile($feed->getVersion(), 'acquisition')
));
$feed->addEntry($sequencesEntry);

echo $feed->render();
?>