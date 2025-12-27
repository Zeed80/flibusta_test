<?php
declare(strict_types=1);

// Инвалидируем opcache для этого файла (на случай если изменения не применились)
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

// Включаем обработку ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Устанавливаем заголовок сразу, чтобы в случае ошибки вернуть XML
header('Content-Type: application/atom+xml; charset=utf-8');

// Проверяем наличие необходимых глобальных переменных
if (!isset($dbh) || !isset($webroot) || !isset($cdt)) {
    error_log("OPDS main.php: Missing required global variables (dbh, webroot, or cdt)");
    OPDSErrorHandler::sendInitializationError();
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Создаем ключ кэша для главной страницы
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_main_v4';

// Проверяем кэш
$cachedContent = $opdsCache->get($cacheKey);
if ($cachedContent !== null) {
    // Кэш действителен, отправляем с заголовками кэширования
    // ВАЖНО: устанавливаем Content-Type ДО setCacheHeaders (хотя он уже установлен выше, но для надежности)
    header('Content-Type: application/atom+xml; charset=utf-8');
    $etag = $opdsCache->generateETag($cachedContent);
    $opdsCache->checkETag($etag);
    $opdsCache->setCacheHeaders($etag);
    echo $cachedContent;
    exit;
}

// Если кэша нет, генерируем фид
// Заголовок уже установлен выше

// Создаем фид OPDS 1.2 используя сервис
$feedService = new OPDSFeedService($dbh, $webroot, $cdt);
$feed = $feedService->createFeed('tag:root', 'Домашняя библиотека', 'navigation');

// Добавляем self ссылку
$feedService->addSelfLink($feed, $webroot . '/opds/', 'navigation');

// Новинки - это ссылка на acquisition фид (список книг)
$newEntry = new OPDSEntry();
$newEntry->setId('tag:root:new');
$newEntry->setTitle('Новинки');
$newEntry->setUpdated($cdt);
$newEntry->setContent('Последние поступления в библиотеку', 'text');
$newEntry->addLink(new OPDSLink(
    $webroot . '/opds/list/',
    'http://opds-spec.org/sort/new',
    OPDSVersion::getProfile('acquisition')
));
$feed->addEntry($newEntry);

// Книжные полки - это navigation фид (список полок)
$shelfEntry = new OPDSEntry();
$shelfEntry->setId('tag:root:shelf');
$shelfEntry->setTitle('Книжные полки');
$shelfEntry->setUpdated($cdt);
$shelfEntry->setContent('Избранное', 'text');
$shelfEntry->addLink(new OPDSLink(
    $webroot . '/opds/favs/',
    'subsection',
    OPDSVersion::getProfile('navigation')
));
$feed->addEntry($shelfEntry);

// По жанрам - это navigation фид (список категорий жанров)
$genreEntry = new OPDSEntry();
$genreEntry->setId('tag:root:genre');
$genreEntry->setTitle('По жанрам');
$genreEntry->setUpdated($cdt);
$genreEntry->setContent('Поиск книг по жанрам', 'text');
$genreEntry->addLink(new OPDSLink(
    $webroot . '/opds/genres',
    'subsection',
    OPDSVersion::getProfile('navigation')
));
$feed->addEntry($genreEntry);

// По авторам - это navigation фид (индекс авторов)
$authorsEntry = new OPDSEntry();
$authorsEntry->setId('tag:root:authors');
$authorsEntry->setTitle('По авторам');
$authorsEntry->setUpdated($cdt);
$authorsEntry->setContent('Поиск книг по авторам', 'text');
$authorsEntry->addLink(new OPDSLink(
    $webroot . '/opds/authorsindex',
    'subsection',
    OPDSVersion::getProfile('navigation')
));
$feed->addEntry($authorsEntry);

// По сериям - это navigation фид (индекс серий)
$sequencesEntry = new OPDSEntry();
$sequencesEntry->setId('tag:root:sequences');
$sequencesEntry->setTitle('По сериям');
$sequencesEntry->setUpdated($cdt);
$sequencesEntry->setContent('Поиск книг по сериям', 'text');
$sequencesEntry->addLink(new OPDSLink(
    $webroot . '/opds/sequencesindex',
    'subsection',
    OPDSVersion::getProfile('navigation')
));
$feed->addEntry($sequencesEntry);

// Рендерим фид
$content = $feed->render();

// Сохраняем в кэш
$opdsCache->set($cacheKey, $content);

// Устанавливаем заголовки кэширования и отправляем ответ
// ВАЖНО: устанавливаем Content-Type перед setCacheHeaders (хотя он уже установлен выше, но для надежности)
header('Content-Type: application/atom+xml; charset=utf-8');
$etag = $opdsCache->generateETag($content);
$opdsCache->setCacheHeaders($etag);
echo $content;
?>
