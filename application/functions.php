<?php

function bbc2html($content) {
  $search = array (
    '/(\[b\])(.*?)(\[\/b\])/',
    '/(\[i\])(.*?)(\[\/i\])/',
    '/(\[u\])(.*?)(\[\/u\])/',
    '/(\[ul\])(.*?)(\[\/ul\])/',
    '/(\[li\])(.*?)(\[\/li\])/',
    '/(\[url=)(.*?)(\])(.*?)(\[\/url\])/',
    '/(\[url\])(.*?)(\[\/url\])/'
  );

  $replace = array (
    '<strong>$2</strong>',
    '<em>$2</em>',
    '<u>$2</u>',
    '<ul>$2</ul>',
    '<li>$2</li>',
    '<a href="$2" target="_blank">$4</a>',
    '<a href="$2" target="_blank">$2</a>'
  );

  return preg_replace($search, $replace, $content);
}


function show_gpager($page_count, $block_size = 100) {
	global $url;
	if (isset($_GET['page'])) {
		$page = intval($_GET['page']);
	} else {
		$page = 0;
	}
	if ($page_count > 1) {
		echo "<nav><ul class='pagination pagination-sm'>";

		$b1 = $page - $block_size;
		$b2 = $block_size + $page;


		if ($b1 < 1) {
			$b1 = 1;
		}
		if ($b2 > $page_count) {
			$b2 = $page_count;
		}

    	if ($b1 > 1) {
 			echo "<li class='page-item'><a class='page-link' href='?page=", $b1 - 2, "' aria-label='Previous'><span aria-hidden='true'><i class='fas fa-angle-left'></i></span></a></li>";
	    	
    	}

		for ($p = $b1; $p <= $b2; $p++) {
			if ($p == $page + 1) {
				$pv = 'active';
			} else {
				$pv = '';
			}
			echo "<li class='page-item $pv'><a class='page-link' href='?page=", $p - 1, "'>$p</a></li>";
		}
		$pv = '';		
    	
    	if ($b2 < $page_count) {
    		echo "<li class='page-item'><a class='page-link' href='?page=", $b2, "' aria-label='Next'><span aria-hidden='true'><i class='fas fa-angle-right'></i></span></a></li>";
    	}

		echo '</ul></nav>';
	}
}



function pg_array_parse($literal){
    if ($literal == '') return;
    preg_match_all('/(?<=^\{|,)(([^,"{]*)|\s*"((?:[^"\\\\]|\\\\(?:.|[0-9]+|x[0-9a-f]+))*)"\s*)(,|(?<!^\{)(?=\}$))/i', $literal, $matches, PREG_SET_ORDER);
    $values = [];
    foreach ($matches as $match) {
        $values[] = $match[3] != '' ? stripcslashes($match[3]) : (strtolower($match[2]) == 'null' ? null : $match[2]);
    }
    return $values;
}

function to_pg_array($set) {
    settype($set, 'array'); // can be called with a scalar or array
    $result = array();
    foreach ($set as $t) {
        if (is_array($t)) {
            $result[] = to_pg_array($t);
        } else {
            $t = str_replace('"', '\\"', $t); // escape double quote
            if (! is_numeric($t)) // quote only non-numeric values
                $t = '"' .addslashes($t) . '"';
            $result[] = $t;
        }
    }
    return '{' . implode(",", $result) . '}'; // format
}


function book_small_pg($book, $webroot='',$full = false) {
	global $dbh, $user_uuid;
	if (!isset($book->bookid)) {
		return;
	}
	echo "<div class='col-sm-2 col-6 mb-3'>";
	echo "<div style='height: 100%' class='cover rounded text-center d-flex align-items-end flex-column'>";
	echo "<a class='w-100' href='$webroot/book/view/$book->bookid'>";
	echo "<img class='w-100 card-image rounded-top' src='$webroot/extract_cover.php?id=$book->bookid&small' />";

	$dt =DateTime::createFromFormat('Y-m-d H:i:se', $book->time)->format('Y-m-d');
	if (trim($book->filetype) == 'fb2') {
		$ft = 'success';
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$ft = 'secondary';
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}

	$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav WHERE user_uuid=:uuid AND bookid=:id");
	$stmt->bindParam(":uuid", $user_uuid);
	$stmt->bindParam(":id", $book->bookid);
	$stmt->execute();
	if ($stmt->fetch()->cnt > 0) {
		$fav = 'btn-primary';
		$fav_url = "?unfav_book=$book->bookid";
	} else {
		$fav = 'btn-outline-secondary';
		$fav_url = "?fav_book=$book->bookid";
	}

	echo "<div>$book->title</div></a>";
	echo "<div class='btn-group w-100 mt-auto' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	echo "<a href='$fhref' title='Скачать' type='button' class='btn btn-outline-$ft btn-sm'>$book->filetype</a>";
//	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$book->lang</button>";
	echo "<a href='$fav_url' title='В избранное' type='button' class='btn $fav btn-sm'><i class='fas fa-heart'></i></a>";
	
	echo "</div></div></div>\n";
}

function book_info_pg($book, $webroot = '', $full = false) {
	global $dbh, $user_uuid;
	if (!isset($book->bookid)) {
		return;
	}
	echo "<div class='hic card mb-3' itemscope='' itemtype='http://schema.org/Book'>";
//	echo "<div class='card-header'>";
	echo "<h4 class='rounded-top' style='background: #d0d0d0;'><a class='book-link' href='$webroot/book/view/$book->bookid'><i class='fas'></i> $book->title</h4></a>";
//	echo "</div>";
	echo "<div class='card-body'>";
	echo "<div class='row'>";
	echo "<div class='col-sm-2'>";
	echo "<img class='w-100 card-image rounded cover' src='$webroot/extract_cover.php?id=$book->bookid&small' />";

	$dt =DateTime::createFromFormat('Y-m-d H:i:se', $book->time)->format('Y-m-d');
	if (trim($book->filetype) == 'fb2') {
		$ft = 'success';
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$ft = 'secondary';
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}
	

	echo "<div class='btn-group w-100 mt-1' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	echo "<a href='$fhref' title='Скачать' type='button' class='btn btn-outline-$ft btn-sm'>$book->filetype</a>";
//	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$book->lang</button>";
	if ($user_uuid != '') {
		$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav WHERE user_uuid=:uuid AND bookid=:id");
		$stmt->bindParam(":uuid", $user_uuid);
		$stmt->bindParam(":id", $book->bookid);
		$stmt->execute();
		if ($stmt->fetch()->cnt > 0) {
			$fav = 'btn-primary';
			$fav_url = "?unfav_book=$book->bookid";
		} else {
			$fav = 'btn-outline-secondary';
			$fav_url = "?fav_book=$book->bookid";
		}
		echo "<a href='$fav_url' title='В избранное' type='button' class='btn $fav btn-sm'><i class='fas fa-heart'></i></a>";
	}
	echo "</div>";
	
	echo "</div><div class='col-sm-10'>";
	echo "<div class='authors-list'>";
	$stmt = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE a.BookId=:id");
	$stmt->bindParam(":id", $book->bookid);
	$stmt->execute();
	while ($a = $stmt->fetch()) {
		echo "<div class='badge rounded-pill author'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
		}
		echo "<a href='$webroot/author/view/$a->avtorid'>$a->lastname $a->middlename $a->firstname $a->nickname</a>";
		echo "</div>";
	}
	echo "</div>";


	echo "<div style='margin-bottom: 3px;'>";
	$genres = $dbh->prepare("SELECT GenreId, GenreDesc FROM libgenre 
		JOIN libgenrelist USING(GenreId)
		WHERE BookId=:bookid");
	$genres->bindParam(":bookid", $book->bookid);
	$genres->execute();
	while ($g = $genres->fetch()) {
		echo "<a class='badge bg-success p-1 text-white' href='$webroot/?gid=$g->genreid'>$g->genredesc</a> ";
	}
	echo "</div>";
	
	echo "<div style='margin-bottom: 3px;'>";
	$seq = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
				JOIN libseqname USING(SeqId)
				WHERE BookId=:id");
	$seq->bindParam(":id", $book->bookid);
	$seq->execute();
	while ($s = $seq->fetch()) {
		echo "<a class='badge bg-danger p-1 text-white' href='$webroot/?sid=$s->seqid'>$s->seqname ";
		if ($s->seqnumb > 0) {
			echo " $s->seqnumb";
		}
		echo "</a> ";
	}
	echo "</div>";

	echo "<div style='margin-bottom: 3px;'>";
	if ($book->keywords != '') {
		$kw = explode(",", $book->keywords);
		foreach ($kw as $k) {
			echo "<a class='badge bg-secondary p-1 text-white' href='#'>$k</a> ";
		}
	}
	echo "</div>";

	echo "<div style='font-size: 0.8em;'>";
	if (isset($book->body)) {
		if ($full) {
			echo "<p>" . trim($book->body) . "</p>";
		} else {
			echo "<p>" . cut_str(trim(strip_tags($book->body))) . "</p>";
		}
	}
	echo "</div>";

	echo "</div>";
	echo "</div>";
	echo "</div></div>\n";
}

date_default_timezone_set('Europe/Minsk');
date_default_timezone_set('Etc/GMT-3');
setlocale(LC_ALL, 'ru_RU.UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

$m_time = explode(" ",microtime());
$m_time = $m_time[0] + $m_time[1];
$starttime = $m_time;
$sql_time = 0;


$cdt = date('Y-m-d H:i:s');
$today_from =  date('Y-m-d') . ' 00:00:00';
$today_to   = date('Y-m-d') . ' 23:59:59';


function russian_date() {
 $translation = array(
 "am" => "дп",
 "pm" => "пп",
 "AM" => "ДП",
 "PM" => "ПП",
 "Monday" => "Понедельник",
 "Mon" => "Пн",
 "Tuesday" => "Вторник",
 "Tue" => "Вт",
 "Wednesday" => "Среда",
 "Wed" => "Ср",
 "Thursday" => "Четверг",
 "Thu" => "Чт",
 "Friday" => "Пятница",
 "Fri" => "Пт",
 "Saturday" => "Суббота",
 "Sat" => "Сб",
 "Sunday" => "Воскресенье",
 "Sun" => "Вс",
 "January" => "Января",
 "Jan" => "Янв",
 "February" => "Февраля",
 "Feb" => "Фев",
 "March" => "Марта",
 "Mar" => "Мар",
 "April" => "Апреля",
 "Apr" => "Апр",
 "May" => "Мая",
 "May" => "Мая",
 "June" => "Июня",
 "Jun" => "Июн",
 "July" => "Июля",
 "Jul" => "Июл",
 "August" => "Августа",
 "Aug" => "Авг",
 "September" => "Сентября",
 "Sep" => "Сен",
 "October" => "Октября",
 "Oct" => "Окт",
 "November" => "Ноября",
 "Nov" => "Ноя",
 "December" => "Декабря",
 "Dec" => "Дек",
 "st" => "ое",
 "nd" => "ое",
 "rd" => "е",
 "th" => "ое",
 );
 if (func_num_args() > 1) {
	$timestamp = func_get_arg(1);
	return strtr(date(func_get_arg(0), $timestamp), $translation);
 } else {
	return strtr(date(func_get_arg(0)), $translation);
 };
}
/***************************************************************************/
function transliterate($string){
  $cyr=array(
     "Щ", "Ш", "Ч","Ц", "Ю", "Я", "Ж","А","Б","В",
     "Г","Д","Е","Ё","З","И","Й","К","Л","М","Н",
     "О","П","Р","С","Т","У","Ф","Х","Ь","Ы","Ъ",
     "Э","Є", "Ї","І",
     "щ", "ш", "ч","ц", "ю", "я", "ж","а","б","в",
     "г","д","е","ё","з","и","й","к","л","м","н",
     "о","п","р","с","т","у","ф","х","ь","ы","ъ",
     "э","є", "ї","і", " "
  );
  $lat=array(
     "Shch","Sh","Ch","C","Yu","Ya","J","A","B","V",
     "G","D","e","e","Z","I","y","K","L","M","N",
     "O","P","R","S","T","U","F","H","", 
     "Y","" ,"E","E","Yi","I",
     "shch","sh","ch","c","Yu","Ya","j","a","b","v",
     "g","d","e","e","z","i","y","k","l","m","n",
     "o","p","r","s","t","u","f","h",
     "", "y","" ,"e","e","yi","i", "%20"
  );
  for($i=0; $i<count($cyr); $i++)  {
     $c_cyr = $cyr[$i];
     $c_lat = $lat[$i];
     $string = str_replace($c_cyr, $c_lat, $string);
  }
  $string = 
  	preg_replace(
  		"/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]e/", 
  		"\${1}e", $string);
/*  $string = 
  	preg_replace(
  		"/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]/", 
  		"\${1}'", $string);*/
  $string = preg_replace("/([eyuioaEYUIOA]+)[Kk]h/", "\${1}h", $string);
  $string = preg_replace("/^kh/", "h", $string);
  $string = preg_replace("/^Kh/", "H", $string);
  return $string;
}


function stars($rating, $webroot) {
    $fullStar = '<img alt="1" class="star" src="'.$webroot.'/i/s1.png" />';
    $emptyStar = '<img alt="0" class="star" src="'.$webroot.'/i/s0.png" />';
    $rating = $rating <= 5?$rating:5;
    $fullStarCount = (int)$rating;
    $emptyStarCount = 5 - $fullStarCount;
    $html = str_repeat($fullStar,$fullStarCount);
    $html .= str_repeat($emptyStar,$emptyStarCount);
    echo $html;
}

/***************************************************************************/
function cut_str($string, $maxlen=700) {
    $len = (mb_strlen($string) > $maxlen)
        ? mb_strripos(mb_substr($string, 0, $maxlen), ' ')
        : $maxlen
    ;
    $cutStr = mb_substr($string, 0, $len);
    return (mb_strlen($string) > $maxlen)
        ? $cutStr . '...'
        : $cutStr
    ;
}

/***************************************************************************/
function cut_str2($string, $maxlen=700) {
    $len = (mb_strlen($string) > $maxlen)
        ? mb_strripos(mb_substr($string, 0, $maxlen), ' ')
        : $maxlen
    ;
    $cutStr = mb_substr($string, 0, $len);
    return $cutStr . $len;
}

/***************************************************************************/
function clean_str($input) {
  if (!$input)
	return $input;

  $input = strip_tags($input);

  $input = str_replace ("\n"," ", $input);
  $input = str_replace ("\r","", $input);

  $input = preg_replace("/[^(\w)|(\x7F-\xFF)|^(_,\-,\.,\;,\@)|(\s)]/", " ", $input);

  return $input;
}

/***************************************************************************/
function decode_gurl($webroot,$mobile = false)  {
  global $last_modified, $url, $robot;
  global $sex_post;

 
  $urlx = parse_url(urldecode($_SERVER['REQUEST_URI']));

  //remove leading webroot e.g. http://192.168.1.101/flibusta/authors/index.php should produce module= authors
  // note this assumes path is not utf-8
  $path = $urlx['path'];
  if (!empty($webroot) && str_starts_with($path,$webroot) ) {
		$path = substr($path, strlen($webroot));
  }
  list($x, $module, $action, $var1, $var2, $var3) = array_pad(explode('/', $path), 6, null);
  $url = new stdClass();

  $url->mod = safe_str($module);
  $url->action = safe_str($action);
  $url->var1 = intval($var1);
  $url->var2 = intval($var2);
  $url->var3 = intval($var3); 
  $url->title = '';
  $url->description = '';
  $url->mod_path = '';
  $url->mod_menu = '';
  $url->image = '';
  $url->noindex = 0;
  $url->index = 1;
  $url->follow = 1;
  $url->module_menu = '';
  $url->js = array();
  $url->editor = 0;
  $url->access = 0;
  $url->canonical = '';

  $menu = true;

  if ($url->mod == '') {
    $url->mod ='primary';
  }

  if (file_exists(ROOT_PATH . 'modules/' . $url->mod . '/module.conf')) {
    $last_modified = gmdate('D, d M Y H:i:s', filemtime(ROOT_PATH . 'modules/' . $url->mod . '/index.php')) . ' GMT';
    $url->module = ROOT_PATH . 'modules/' . $url->mod . '/index.php';
    $url->mod_path = ROOT_PATH . 'modules/' . $url->mod . '/';
    include(ROOT_PATH . 'modules/' . $url->mod . '/module.conf');
  } else {
    $menu = false;
    include(ROOT_PATH . 'modules/404/module.conf');
    $url->module = ROOT_PATH . 'modules/404/index.php';
    $url->mod = '404';  
  }

  if ($url->access > 0) {
   // if (!is_admin()) {
      include(ROOT_PATH . 'modules/403/module.conf');
      $url->module = ROOT_PATH . 'modules/403/index.php';
      $url->mod = '403';
      $menu = false;
   // }
  }

  if ( (file_exists(ROOT_PATH . 'modules/' . $url->mod . '/module_menu.php')) && ($menu) ) {
    $url->module_menu = ROOT_PATH . 'modules/' . $url->mod . '/module_menu.php';
  }

  return $url;
}

function safe_str($str) {
        return ($str)?preg_replace("/[^A-Za-z0-9 -_]/", '', $str):$str;
}


function mobile() {
        $devices = array(
                "android" => "android.*mobile",
                "androidtablet" => "android(?!.*mobile)",
                "iphone" => "(iphone|ipod)",
                "ipad" => "(ipad)",
                "generic" => "(kindle|mobile|mmp|midp|pocket|psp|symbian|smartphone|treo|up.browser|up.link|vodafone|wap|opera mini)"
        );
        $isMobile = false;
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
                $userAgent = "";
        }
        if (isset($_SERVER['HTTP_ACCEPT'])) {
               $accept = $_SERVER['HTTP_ACCEPT'];
        } else {
                $accept = '';
        }
        if (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
                $isMobile = true;
        } elseif (strpos($accept, 'text/vnd.wap.wml') > 0 || strpos($accept, 'application/vnd.wap.xhtml+xml') > 0) {
                $isMobile = true;
        } else {
                foreach ($devices as $device => $regexp) {
                        if (preg_match("/" . $devices[strtolower($device)] . "/i", $userAgent)) {
                                $isMobile = true;
                        }
                }
        }
        return $isMobile;
}

function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824)
        {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        }
        elseif ($bytes >= 1048576)
        {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        }
        elseif ($bytes >= 1024)
        {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        }
        elseif ($bytes > 1)
        {
            $bytes = $bytes . ' bytes';
        }
        elseif ($bytes == 1)
        {
            $bytes = $bytes . ' byte';
        }
        else
        {
            $bytes = '0 bytes';
        }

	return $bytes;
}

/**
 * Создает OPDSEntry с acquisition ссылками для OPDS
 * 
 * @param string $id ID записи
 * @param string $title Название
 * @param string $updated Дата обновления
 * @param string $content Контент (описание)
 * @param string $href URL ресурса
 * @param string $version Версия OPDS ('1.0' или '1.2')
 * @param string $rel Rel тип (по умолчанию 'subsection' для навигации)
 * @param string $contentType MIME тип
 * @param string $linkTitle Заголовок ссылки
 * @return OPDSEntry
 */
function opds_acquisition_entry($id, $title, $updated, $content, $href, $version = '1.2', $rel = 'subsection', $contentType = null, $linkTitle = '') {
	global $webroot;
	
	$entry = new OPDSEntry();
	$entry->setId($id);
	$entry->setTitle($title);
	$entry->setUpdated($updated);
	
	if ($content) {
		$entry->setContent($content, 'text');
	}
	
	$profile = OPDSVersion::getProfile($version, 'acquisition');
	$entry->addLink(new OPDSLink(
		$href,
		$rel,
		$profile,
		$linkTitle
	));
	
	return $entry;
}

/**
 * Создает OPDSEntry объект для книги
 * 
 * @param object $b Объект книги из БД
 * @param string $webroot Базовый URL
 * @param string $version Версия OPDS ('1.0' или '1.2')
 * @return OPDSEntry
 */
function opds_book_entry($b, $webroot = '', $version = '1.2') {
	global $dbh;
	
	$entry = new OPDSEntry();
	$entry->setId("tag:book:{$b->bookid}");
	$entry->setTitle($b->title);
	$entry->setUpdated($b->time ?: date('c'));
	
	// Аннотация
	$ann = $dbh->prepare("SELECT annotation FROM libbannotations WHERE BookId=:id LIMIT 1");
	$ann->bindParam(":id", $b->BookId);
	$ann->execute();
	if ($tmp = $ann->fetch()) {
		$an = $tmp->annotation;
	} else {
		$an = '';
	}
	
	// Жанры
	$genres = $dbh->prepare("SELECT genrecode, GenreId, GenreDesc FROM libgenre 
		JOIN libgenrelist USING(GenreId)
		WHERE BookId=:id");
	$genres->bindParam(":id", $b->BookId);
	$genres->execute();
	while ($g = $genres->fetch()) {
		$normalizedGenreDesc = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($g->genredesc) : $g->genredesc;
		$entry->addCategory(
			$webroot . '/subject/' . urlencode($g->genrecode),
			$normalizedGenreDesc
		);
		// Также добавляем как dc:subject
		$entry->addMetadata('dc', 'subject', $normalizedGenreDesc);
	}
	
	// Серии
	$sq = '';
	$seq = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
		JOIN libseqname USING(SeqId)
		WHERE BookId=:id");
	$seq->bindParam(":id", $b->BookId);
	$seq->execute();
	while ($s = $seq->fetch()) {
		$ssq = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($s->seqname) : $s->seqname;
		if ($s->seqnumb > 0) {
			$ssq .= " ($s->seqnumb)";
		}
		$sq .= ($sq ? ', ' : '') . $ssq;
		$link = new OPDSLink(
			$webroot . '/opds/list?seq_id=' . $s->seqid,
			'related',
			OPDSVersion::getProfile($version, 'acquisition'),
			'Все книги серии "' . $ssq . '"'
		);
		$entry->addLink($link);
	}
	if ($sq != '') {
		$sq = "Сборник: $sq";
	}
	
	// Авторы
	$au = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE a.BookId=:id");
	$au->bindParam(":id", $b->BookId);
	$au->execute();
	while ($a = $au->fetch()) {
		$authorName = trim("$a->lastname $a->firstname $a->middlename");
		// Нормализация уже применяется в addAuthor(), но нормализуем для ссылки
		$normalizedAuthorName = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($authorName) : $authorName;
		$entry->addAuthor($authorName, $webroot . '/opds/author?author_id=' . $a->avtorid);
		$link = new OPDSLink(
			$webroot . '/opds/list?author_id=' . $a->avtorid,
			'related',
			OPDSVersion::getProfile($version, 'acquisition'),
			'Все книги автора ' . $normalizedAuthorName
		);
		$entry->addLink($link);
	}
	
	// Метаданные
	if (trim($b->lang)) {
		$entry->addMetadata('dc', 'language', trim($b->lang));
	}
	if ($b->year > 0) {
		$entry->addMetadata('dc', 'issued', (string)$b->year);
	}
	$entry->addMetadata('dc', 'format', trim($b->filetype));
	if (isset($b->filesize) && $b->filesize > 0) {
		$entry->addMetadata('dcterms', 'extent', formatSizeUnits($b->filesize));
	}
	
	// Summary
	$summary = '';
	if ($an) {
		$summary .= strip_tags($an) . "\n";
	}
	if ($sq) {
		$summary .= $sq . "\n";
	}
	if (isset($b->keywords) && $b->keywords) {
		$summary .= $b->keywords . "\n";
	}
	if ($b->year > 0) {
		$summary .= "Год издания: $b->year\n";
	}
	$summary .= "Формат: " . trim($b->filetype) . "\n";
	if (trim($b->lang)) {
		$summary .= "Язык: " . trim($b->lang) . "\n";
	}
	if (isset($b->filesize) && $b->filesize > 0) {
		$summary .= "Размер: " . formatSizeUnits($b->filesize);
	}
	if ($summary) {
		$entry->setSummary(trim($summary), 'text');
	}
	
	// Ссылки на изображения
	$entry->addLink(new OPDSLink(
		$webroot . '/extract_cover.php?id=' . $b->bookid,
		'http://opds-spec.org/image/thumbnail',
		'image/jpeg'
	));
	$entry->addLink(new OPDSLink(
		$webroot . '/extract_cover.php?id=' . $b->bookid,
		'http://opds-spec.org/image',
		'image/jpeg'
	));
	
	// Ссылка на скачивание с правильными MIME-типами
	$fileType = trim($b->filetype);
	$mimeTypes = [
		'fb2' => 'application/fb2+zip',
		'epub' => 'application/epub+zip',
		'mobi' => 'application/x-mobipocket-ebook',
		'pdf' => 'application/pdf',
		'txt' => 'text/plain',
		'html' => 'text/html',
		'htm' => 'text/html',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'rtf' => 'application/rtf',
		'djvu' => 'image/vnd.djvu',
		'djv' => 'image/vnd.djvu'
	];
	
	$mimeType = isset($mimeTypes[$fileType]) ? $mimeTypes[$fileType] : 'application/' . $fileType;
	
	if ($fileType == 'fb2') {
		$downloadUrl = $webroot . '/fb2.php?id=' . $b->bookid;
	} else {
		$downloadUrl = $webroot . '/usr.php?id=' . $b->bookid;
	}
	
	$entry->addLink(new OPDSLink(
		$downloadUrl,
		'http://opds-spec.org/acquisition/open-access',
		$mimeType
	));
	
	// Ссылка на веб-страницу
	$entry->addLink(new OPDSLink(
		$webroot . '/book/view/' . $b->bookid,
		'alternate',
		'text/html',
		'Книга на сайте'
	));
	
	return $entry;
}

/**
 * Старая функция для обратной совместимости
 * @deprecated Используйте opds_book_entry() вместо этого
 */
function opds_book($b, $webroot = '') {
	$version = OPDSVersion::detect();
	if ($version === OPDSVersion::VERSION_AUTO) {
		$version = OPDSVersion::VERSION_1_2;
	}
	$entry = opds_book_entry($b, $webroot, $version);
	echo $entry->render($version);
}

/**
 * Нормализует текст для OPDS, оставляя только кириллицу и латиницу
 * Удаляет диакритические знаки и нечитаемые символы
 * 
 * @param string $text Текст для нормализации
 * @param bool $preserve_html Сохранять HTML структуру (для аннотаций)
 * @return string Нормализованный текст
 */
function normalize_text_for_opds($text, $preserve_html = false) {
	if (empty($text)) {
		return $text;
	}
	
	// Если нужно сохранить HTML структуру
	if ($preserve_html) {
		// Используем DOMDocument для обработки HTML
		if (class_exists('DOMDocument') && function_exists('libxml_use_internal_errors')) {
			libxml_use_internal_errors(true);
			$dom = new DOMDocument('1.0', 'UTF-8');
			// Добавляем теги для корректной обработки фрагментов HTML
			$html = mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');
			@$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
			libxml_clear_errors();
			
			// Нормализуем текстовые узлы
			$xpath = new DOMXPath($dom);
			$textNodes = $xpath->query('//text()');
			
			foreach ($textNodes as $node) {
				$normalized = normalize_text_for_opds($node->nodeValue, false);
				$node->nodeValue = $normalized;
			}
			
			// Извлекаем body содержимое
			$body = $dom->getElementsByTagName('body')->item(0);
			if ($body) {
				$result = '';
				foreach ($body->childNodes as $child) {
					$result .= $dom->saveHTML($child);
				}
				return $result;
			}
		}
		
		// Fallback: используем регулярные выражения для простых случаев
		$text = preg_replace_callback('/>([^<]+)</u', function($matches) {
			$normalized = normalize_text_for_opds($matches[1], false);
			return '>' . $normalized . '<';
		}, $text);
		
		return $text;
	}
	
	// Unicode нормализация (NFD -> NFC)
	if (class_exists('Normalizer') && function_exists('normalizer_normalize')) {
		$text = Normalizer::normalize($text, Normalizer::FORM_C);
	}
	
	// Удаление диакритических знаков через транслитерацию
	if (function_exists('iconv')) {
		// Пробуем транслитерацию
		$translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
		if ($translit !== false) {
			$text = $translit;
		} else {
			// Если транслитерация не удалась, используем ручную замену
			$text = remove_diacritics_manual($text);
		}
	} else {
		// Fallback: ручная замена диакритики
		$text = remove_diacritics_manual($text);
	}
	
	// Оставляем только кириллицу, латиницу, цифры и пробелы
	// Кириллица: \p{Cyrillic} (U+0400-U+04FF)
	// Латиница: a-zA-Z
	// Цифры: 0-9
	// Пробелы и основные знаки препинания
	$text = preg_replace('/[^\p{Cyrillic}a-zA-Z0-9\s\.,;:!?\-\(\)\[\]\"\']/u', '', $text);
	
	// Удаляем множественные пробелы
	$text = preg_replace('/\s+/u', ' ', $text);
	
	return trim($text);
}

/**
 * Ручная замена диакритических знаков (fallback)
 * 
 * @param string $text Текст для обработки
 * @return string Текст без диакритики
 */
function remove_diacritics_manual($text) {
	// Таблица замены диакритических знаков
	$diacritics = [
		// Латиница с диакритикой
		'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
		'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
		'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
		'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
		'Ý' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
		'Ç' => 'C', 'ç' => 'c',
		'Ñ' => 'N', 'ñ' => 'n',
		'Œ' => 'OE', 'œ' => 'oe',
		'Æ' => 'AE', 'æ' => 'ae',
		'Ð' => 'D', 'ð' => 'd',
		'Þ' => 'TH', 'þ' => 'th',
		'Š' => 'S', 'š' => 's',
		'Ž' => 'Z', 'ž' => 'z',
		'Č' => 'C', 'č' => 'c',
		'Ć' => 'C', 'ć' => 'c',
		'Đ' => 'D', 'đ' => 'd',
		'Ğ' => 'G', 'ğ' => 'g',
		'İ' => 'I', 'ı' => 'i',
		'Ł' => 'L', 'ł' => 'l',
		'Ń' => 'N', 'ń' => 'n',
		'Ř' => 'R', 'ř' => 'r',
		'Ť' => 'T', 'ť' => 't',
		'Ů' => 'U', 'ů' => 'u',
		'Ű' => 'U', 'ű' => 'u',
		'Ÿ' => 'Y', 'ÿ' => 'y',
		'Ż' => 'Z', 'ż' => 'z',
	];
	
	return strtr($text, $diacritics);
}
