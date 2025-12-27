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
    error_log("OPDS author.php: Missing required global variables (dbh, webroot, or cdt)");
    OPDSErrorHandler::sendInitializationError();
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Валидируем author_id
try {
    $author_id = OPDSValidator::validateId('author_id', 1);
    if ($author_id === null) {
        OPDSValidator::handleValidationException(new \InvalidArgumentException('Параметр author_id обязателен'));
    }
} catch (\InvalidArgumentException $e) {
    OPDSValidator::handleValidationException($e);
}

// Получаем параметры для кэша
$seq_mode = isset($_GET['seq']);

// Получаем параметры для кэша
$cacheParams = [
    'author_id' => $author_id,
    'seq_mode' => $seq_mode ? 1 : 0
];

// Создаем ключ кэша
$cacheKey = 'opds_author_' . $opdsCache->getCacheKey($cacheParams);

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
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид OPDS 1.2
$feed = OPDSFeedFactory::create();

if (! $seq_mode) {  
    $stmt = $dbh->prepare("SELECT a.LastName as LastName, a.MiddleName as MiddleName, a.FirstName as FirstName, a.NickName as NickName,
        aa.Body as Body,  p.File as picFile 
        from libavtorname a 
        LEFT JOIN libaannotations aa on a.avtorid = aa.avtorid
        LEFT JOIN libapics p on a.avtorid=p.avtorid
        where a.avtorID=:authorid ");
} else {
    $stmt = $dbh->prepare("SELECT LastName, MiddleName, FirstName, NickName from libavtorname where avtorID=:authorid ");
}

$stmt->bindParam(':authorid', $author_id, PDO::PARAM_INT);
$stmt->execute();
if ($a = $stmt->fetchObject()){
    $author_name_raw = ($a->nickname !='')?"$a->firstname $a->middlename $a->lastname ($a->nickname)"
                            :"$a->firstname  $a->middlename $a->lastname";
    // НЕ нормализуем имя автора - сохраняем оригинальный текст (включая кириллицу)
    $author_name = trim($author_name_raw);
   
    if ($seq_mode) { // show list of sequences with current author's works
        $feed->setId("tag:author:$author_id:sequences");
        $feed->setTitle("$author_name : Книги по сериям");
        $feed->setUpdated($cdt);
        $feed->setIcon($webroot . '/favicon.ico');
        
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
            $webroot . '/opds',
            'start',
            OPDSVersion::getProfile( 'navigation')
        ));
        
        $sequences = $dbh->prepare("SELECT distinct sn.seqid seqid, sn.seqname seqname
        from libseqname sn, libseq s, libavtor a 
        where sn.seqid = s.seqid and s.bookId= a.bookId and a.avtorId= :aid");
        $sequences->bindParam(":aid", $author_id, PDO::PARAM_INT);
        $sequences->execute();
        while($seq = $sequences->fetchObject()){
            // НЕ нормализуем название серии - сохраняем оригинальный текст (включая кириллицу)
            $seqName = trim($seq->seqname ?? '');
            if (empty($seqName)) {
                continue;
            }
            $entry = new OPDSEntry();
            $entry->setId("tag:seq:$seq->seqid");
            $entry->setTitle($seqName);
            $entry->setUpdated($cdt);
            $entry->addLink(new OPDSLink(
                $webroot . '/opds/list?seq_id=' . $seq->seqid,
                'http://opds-spec.org/acquisition',
                OPDSVersion::getProfile( 'acquisition')
            ));
            $feed->addEntry($entry);
        }
    } else {
        $feed->setId("tag:author:$author_id");
        $feed->setTitle($author_name);
        $feed->setUpdated($cdt);
        $feed->setIcon($webroot . '/favicon.ico');
        
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
            $webroot . '/opds',
            'start',
            OPDSVersion::getProfile( 'navigation')
        ));

        if ($a->body != '') {
            $bioEntry = new OPDSEntry();
            $bioEntry->setId("tag:author:bio:$author_id");
            $bioEntry->setTitle('Об авторе');
            $bioEntry->setUpdated($cdt);
            
            if (!is_null($a->picfile)){
                $bioEntry->addLink(new OPDSLink(
                    $webroot . '/extract_author.php?id=' . $author_id,
                    'http://opds-spec.org/image',
                    'image/jpeg'
                ));
                $bioEntry->addLink(new OPDSLink(
                    $webroot . '/extract_author.php?id=' . $author_id,
                    'http://opds-spec.org/image/thumbnail',
                    'image/jpeg'
                ));
            }
            
            $bioEntry->setContent($a->body, 'text/html');
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/author/view/' . $author_id,
                'alternate',
                'text/html',
                'Страница автора на сайте'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/list?author_id=' . $author_id . '&display_type=alphabet',
                'http://opds-spec.org/acquisition',
                OPDSVersion::getProfile( 'acquisition'),
                'Книги автора по алфавиту'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/author?author_id=' . $author_id . '&seq=1',
                'subsection',
                OPDSVersion::getProfile( 'navigation'),
                'Книжные серии с произведениями автора'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/list?author_id=' . $author_id . '&display_type=sequenceless',
                'http://opds-spec.org/acquisition',
                OPDSVersion::getProfile( 'acquisition'),
                'Книги автора вне серий'
            ));
            $feed->addEntry($bioEntry);
        }
        
        // Все книги автора - это acquisition фид
        $allBooksEntry = new OPDSEntry();
        $allBooksEntry->setId("tag:author:$author_id:list");
        $allBooksEntry->setTitle('Все книги автора (без сортировки)');
        $allBooksEntry->setUpdated($cdt);
        $allBooksEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id,
            'http://opds-spec.org/acquisition',
            OPDSVersion::getProfile( 'acquisition')
        ));
        $feed->addEntry($allBooksEntry);
        
        // По алфавиту - это acquisition фид
        $alphabetEntry = new OPDSEntry();
        $alphabetEntry->setId("tag:author:$author_id:alphabet");
        $alphabetEntry->setTitle('Книги автора по алфавиту');
        $alphabetEntry->setUpdated($cdt);
        $alphabetEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id . '&display_type=alphabet',
            'http://opds-spec.org/acquisition',
            OPDSVersion::getProfile( 'acquisition')
        ));
        $feed->addEntry($alphabetEntry);
        
        // По году - это acquisition фид
        $yearEntry = new OPDSEntry();
        $yearEntry->setId("tag:author:$author_id:year");
        $yearEntry->setTitle('Книги автора по году издания');
        $yearEntry->setUpdated($cdt);
        $yearEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id . '&display_type=year',
            'http://opds-spec.org/acquisition',
            OPDSVersion::getProfile( 'acquisition')
        ));
        $feed->addEntry($yearEntry);
        
        // Серии - это navigation фид
        $seqEntry = new OPDSEntry();
        $seqEntry->setId("tag:author:$author_id:sequences");
        $seqEntry->setTitle('Книжные серии с произведениями автора');
        $seqEntry->setUpdated($cdt);
        $seqEntry->addLink(new OPDSLink(
            $webroot . '/opds/author?author_id=' . $author_id . '&seq=1',
            'subsection',
            OPDSVersion::getProfile( 'navigation')
        ));
        $feed->addEntry($seqEntry);
        
        // Вне серий - это acquisition фид
        $sequencelessEntry = new OPDSEntry();
        $sequencelessEntry->setId("tag:author:$author_id:sequenceless");
        $sequencelessEntry->setTitle('Произведения вне серий');
        $sequencelessEntry->setUpdated($cdt);
        $sequencelessEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id . '&display_type=sequenceless',
            'http://opds-spec.org/acquisition',
            OPDSVersion::getProfile( 'acquisition')
        ));
        $feed->addEntry($sequencelessEntry);
    } 
} else {
    OPDSErrorHandler::sendNotFoundError('Автор');
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
