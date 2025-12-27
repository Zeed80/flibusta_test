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
    error_log("OPDS authorsindex.php: Missing required global variables (dbh, webroot, or cdt)");
    exit;
}

// Инициализируем кэш OPDS (используем singleton паттерн)
$opdsCache = OPDSCache::getInstance();

// Получаем параметры для кэша
$letters = isset($_GET['letters']) ? trim($_GET['letters']) : '';

// Создаем ключ кэша для индекса авторов
// Добавляем версию кэша для принудительного пересоздания при изменениях
$cacheKey = 'opds_authorsindex_v5_' . md5($letters);

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

$feed->setId('tag:root:authors');
$feed->setTitle('Книги по авторам');
$feed->setUpdated($cdt);
$feed->setIcon($webroot . '/favicon.ico');

// Добавляем ссылки
$feed->addLink(new OPDSLink(
	$webroot . '/opds/opensearch.xml.php',
	'search',
	'application/opensearchdescription+xml'
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex?letters={searchTerms}',
	'search',
	OPDSVersion::getProfile( 'acquisition')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds',
	'start',
	OPDSVersion::getProfile( 'navigation')
));

$feed->addLink(new OPDSLink(
	$webroot . '/opds/authorsindex' . ($letters ? '?letters=' . urlencode($letters) : ''),
	'self',
	OPDSVersion::getProfile( 'navigation')
));

$length_letters = mb_strlen($letters, 'UTF-8');

// Исправляем SQL-инъекцию, используя prepared statement
if ($length_letters > 0) {
	$pattern = $letters . '_';
	// Фильтруем на уровне SQL: исключаем пустые LastName и те, что начинаются не с буквы
	$alphaExpr = "UPPER(SUBSTR(TRIM(LastName), 1, " . ($length_letters + 1) . "))";
	// Применяем русский collation, если доступен
	$orderByExpr = $alphaExpr;
	if (class_exists('OPDSCollation')) {
		$orderByExpr = OPDSCollation::applyRussianCollation($alphaExpr, $dbh);
	}
	$query = "
		SELECT " . $alphaExpr . " as alpha, COUNT(*) as cnt
		FROM libavtorname
		WHERE LastName IS NOT NULL 
		AND TRIM(LastName) != ''
		AND SUBSTR(TRIM(LastName), 1, 1) ~ '^[[:alpha:]]'
		AND " . $alphaExpr . " SIMILAR TO :pattern
		GROUP BY " . $alphaExpr . "
		ORDER BY " . $orderByExpr;
	$ai = $dbh->prepare($query);
	$ai->bindParam(":pattern", $pattern);
	$ai->execute();
} else {
	// Фильтруем на уровне SQL: исключаем пустые LastName и те, что начинаются не с буквы
	// Используем TRIM для удаления пробелов и проверяем, что первый символ - буква
	$alphaExpr = "UPPER(SUBSTR(TRIM(LastName), 1, 1))";
	// Применяем русский collation, если доступен
	$orderByExpr = $alphaExpr;
	if (class_exists('OPDSCollation')) {
		$orderByExpr = OPDSCollation::applyRussianCollation($alphaExpr, $dbh);
	}
	$query = "
		SELECT " . $alphaExpr . " as alpha, COUNT(*) as cnt
		FROM libavtorname
		WHERE LastName IS NOT NULL 
		AND TRIM(LastName) != ''
		AND SUBSTR(TRIM(LastName), 1, 1) ~ '^[[:alpha:]]'
		GROUP BY " . $alphaExpr . "
		HAVING " . $alphaExpr . " ~ '^[A-ZА-ЯЁ]'
		ORDER BY " . $orderByExpr;
	$ai = $dbh->query($query);
}

while ($ach = $ai->fetchObject()) {
	// НЕ нормализуем алфавитный индекс - сохраняем оригинальный текст (включая кириллицу)
	$alpha = trim($ach->alpha ?? '');
	
	// Пропускаем пустые записи (дополнительная проверка на всякий случай)
	if (empty($alpha)) {
		continue;
	}
	
	// Дополнительная проверка: первый символ должен быть буквой
	// (хотя SQL уже фильтрует, но на всякий случай)
	$firstChar = mb_substr($alpha, 0, 1, 'UTF-8');
	if (!preg_match('/^[\p{Cyrillic}\p{Latin}]$/u', $firstChar)) {
		continue;
	}
	
	if ($ach->cnt > 500) {
		$url = $webroot . '/opds/authorsindex?letters=' . urlencode($alpha);
	} else {
		$url = $webroot . '/opds/search?by=author&q=' . urlencode($alpha);
	}
	
	$entry = new OPDSEntry();
	$entry->setId("tag:authors:" . htmlspecialchars($alpha, ENT_XML1, 'UTF-8'));
	$entry->setTitle($alpha);
	$entry->setUpdated($cdt);
	$entry->setContent("$ach->cnt авторов на " . $alpha, 'text');
	
	// Используем правильный rel для OPDS
	if ($ach->cnt > 500) {
		// Если авторов много, это navigation фид (подраздел индекса)
		$entry->addLink(new OPDSLink(
			$url,
			'subsection',
			OPDSVersion::getProfile( 'navigation')
		));
	} else {
		// Если авторов мало, это search результат, который ведет на navigation фид автора
		$entry->addLink(new OPDSLink(
			$url,
			'subsection',
			OPDSVersion::getProfile( 'navigation')
		));
	}
	
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
