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
    error_log("OPDS author.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

$author_id = isset($_GET['author_id']) ? (int)$_GET['author_id'] : 0;
if ($author_id == 0) {
    http_response_code(400);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:author:missing</id>
  <title>Ошибка</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:missing_author_id</id>
    <title>Не указан автор</title>
    <summary type="text">Необходимо указать идентификатор автора (параметр author_id)</summary>
  </entry>
</feed>';
    exit;
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
    $etag = $opdsCache->generateETag($cachedContent);
    $opdsCache->checkETag($etag);
    $opdsCache->setCacheHeaders($etag);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo $cachedContent;
    exit;
}

// Если кэша нет или устарел, генерируем фид
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

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
    $author_name = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($author_name_raw) : $author_name_raw;
   
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
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        
        $feed->addLink(new OPDSLink(
            $webroot . '/opds',
            'start',
            OPDSVersion::getProfile($version, 'navigation')
        ));
        
        $sequences = $dbh->prepare("SELECT distinct sn.seqid seqid, sn.seqname seqname
        from libseqname sn, libseq s, libavtor a 
        where sn.seqid = s.seqid and s.bookId= a.bookId and a.avtorId= :aid");
        $sequences->bindParam(":aid", $author_id, PDO::PARAM_INT);
        $sequences->execute();
        while($seq = $sequences->fetchObject()){
            $normalizedSeqName = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($seq->seqname ?? '') : ($seq->seqname ?? '');
            $entry = new OPDSEntry();
            $entry->setId("tag:seq:$seq->seqid");
            $entry->setTitle($normalizedSeqName);
            $entry->setUpdated($cdt);
            $entry->addLink(new OPDSLink(
                $webroot . '/opds/list?seq_id=' . $seq->seqid,
                'subsection',
                OPDSVersion::getProfile($version, 'acquisition')
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
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        
        $feed->addLink(new OPDSLink(
            $webroot . '/opds',
            'start',
            OPDSVersion::getProfile($version, 'navigation')
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
                'subsection',
                OPDSVersion::getProfile($version, 'acquisition'),
                'Книги автора по алфавиту'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/author?author_id=' . $author_id . '&seq=1',
                'subsection',
                OPDSVersion::getProfile($version, 'acquisition'),
                'Книжные серии с произведениями автора'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/list?author_id=' . $author_id . '&display_type=sequenceless',
                'subsection',
                OPDSVersion::getProfile($version, 'acquisition'),
                'Книги автора вне серий'
            ));
            $feed->addEntry($bioEntry);
        }
        
        // Все книги автора
        $allBooksEntry = new OPDSEntry();
        $allBooksEntry->setId("tag:author:$author_id:list");
        $allBooksEntry->setTitle('Все книги автора (без сортировки)');
        $allBooksEntry->setUpdated($cdt);
        $allBooksEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id,
            'subsection',
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        $feed->addEntry($allBooksEntry);
        
        // По алфавиту
        $alphabetEntry = new OPDSEntry();
        $alphabetEntry->setId("tag:author:$author_id:alphabet");
        $alphabetEntry->setTitle('Книги автора по алфавиту');
        $alphabetEntry->setUpdated($cdt);
        $alphabetEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id . '&display_type=alphabet',
            'subsection',
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        $feed->addEntry($alphabetEntry);
        
        // По году
        $yearEntry = new OPDSEntry();
        $yearEntry->setId("tag:author:$author_id:year");
        $yearEntry->setTitle('Книги автора по году издания');
        $yearEntry->setUpdated($cdt);
        $yearEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id . '&display_type=year',
            'subsection',
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        $feed->addEntry($yearEntry);
        
        // Серии
        $seqEntry = new OPDSEntry();
        $seqEntry->setId("tag:author:$author_id:sequences");
        $seqEntry->setTitle('Книжные серии с произведениями автора');
        $seqEntry->setUpdated($cdt);
        $seqEntry->addLink(new OPDSLink(
            $webroot . '/opds/author?author_id=' . $author_id . '&seq=1',
            'subsection',
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        $feed->addEntry($seqEntry);
        
        // Вне серий
        $sequencelessEntry = new OPDSEntry();
        $sequencelessEntry->setId("tag:author:$author_id:sequenceless");
        $sequencelessEntry->setTitle('Произведения вне серий');
        $sequencelessEntry->setUpdated($cdt);
        $sequencelessEntry->addLink(new OPDSLink(
            $webroot . '/opds/list?author_id=' . $author_id . '&display_type=sequenceless',
            'subsection',
            OPDSVersion::getProfile($version, 'acquisition')
        ));
        $feed->addEntry($sequencelessEntry);
    } 
} else {
    http_response_code(404);
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <id>tag:error:author:not_found</id>
  <title>Ошибка</title>
  <updated>' . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . '</updated>
  <entry>
    <id>tag:error:author_not_found</id>
    <title>Автор не найден</title>
    <summary type="text">Автор с идентификатором ' . htmlspecialchars($author_id, ENT_XML1, 'UTF-8') . ' не найден в базе данных</summary>
  </entry>
</feed>';
    exit;

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
