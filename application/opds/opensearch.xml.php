<?php
declare(strict_types=1);

// Устанавливаем правильные заголовки для OpenSearch
header('Content-Type: application/opensearchdescription+xml; charset=utf-8');

include("../init.php");

// Проверяем наличие необходимых глобальных переменных
if (!isset($webroot)) {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="utf-8"?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
<ShortName>Ошибка</ShortName>
<Description>Ошибка инициализации</Description>
</OpenSearchDescription>';
    exit;
}

// Экранируем URL для безопасности
$searchUrl = htmlspecialchars($webroot . '/opds/search?q={searchTerms}', ENT_XML1, 'UTF-8');
$searchFormUrl = htmlspecialchars($webroot . '/opds/search', ENT_XML1, 'UTF-8');
$iconUrl = htmlspecialchars($webroot . '/favicon.ico', ENT_XML1, 'UTF-8');

echo '<?xml version="1.0" encoding="utf-8"?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
<ShortName>' . htmlspecialchars('Библиотека', ENT_XML1, 'UTF-8') . '</ShortName>
<Description>' . htmlspecialchars('Поиск книг в библиотеке', ENT_XML1, 'UTF-8') . '</Description>
<Tags/>
<Contact/>
<Url type="application/atom+xml" indexOffset="0" pageOffset="0" template="' . $searchUrl . '"/>
<SearchForm>' . $searchFormUrl . '</SearchForm>
<LongName>' . htmlspecialchars('Домашняя библиотека', ENT_XML1, 'UTF-8') . '</LongName>
<Image>' . $iconUrl . '</Image>
<Developer/>
<Attribution/>
<SyndicationRight>open</SyndicationRight>
<AdultContent>false</AdultContent>
<Language>*</Language>
<OutputEncoding>UTF-8</OutputEncoding>
<InputEncoding>UTF-8</InputEncoding>
</OpenSearchDescription>';
?>
