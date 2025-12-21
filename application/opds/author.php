<?php
header('Content-Type: application/atom+xml; charset=utf-8');

// Создаем фид с автоматическим определением версии
$feed = OPDSFeedFactory::create();
$version = $feed->getVersion();

$author_id = isset($_GET['author_id']) ? (int)$_GET['author_id'] : 0;
if ($author_id == 0) {
    die('author.php called without specifying id');
}

$seq_mode = isset($_GET['seq']);

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
    $author_name = ($a->nickname !='')?"$a->firstname $a->middlename $a->lastname ($a->nickname)"
                            :"$a->firstname  $a->middlename $a->lastname";
   
    if ($seq_mode) { // show list of sequences with current author's works
        $feed->setId("tag:author:$author_id:sequences");
        $feed->setTitle("$author_name : Книги по сериям");
        $feed->setUpdated($cdt);
        $feed->setIcon($webroot . '/favicon.ico');
        
        $feed->addLink(new OPDSLink(
            $webroot . '/opds-opensearch.xml.php',
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
            $entry = new OPDSEntry();
            $entry->setId("tag:seq:$seq->seqid");
            $entry->setTitle($seq->seqname ?? '');
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
            $webroot . '/opds-opensearch.xml.php',
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
                'http://www.feedbooks.com/opds/facet',
                OPDSVersion::getProfile($version, 'acquisition'),
                'Книги автора по алфавиту'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/author?author_id=' . $author_id . '&seq=1',
                'http://www.feedbooks.com/opds/facet',
                OPDSVersion::getProfile($version, 'acquisition'),
                'Книжные серии с произведениями автора'
            ));
            $bioEntry->addLink(new OPDSLink(
                $webroot . '/opds/list?author_id=' . $author_id . '&display_type=sequenceless',
                'http://www.feedbooks.com/opds/facet',
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
    die("author with id $author_id not found in the data base");
}

echo $feed->render();
?>
