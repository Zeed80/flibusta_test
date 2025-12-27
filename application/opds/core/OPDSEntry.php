<?php
declare(strict_types=1);

// Инвалидируем opcache для этого файла (на случай если изменения не применились)
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

/**
 * Класс для создания записей в OPDS 1.2 фидах
 */
class OPDSEntry {
    protected $id;
    protected $title;
    protected $updated;
    protected $content;
    protected $contentType = 'text';
    protected $summary;
    protected $summaryType = 'text';
    protected $authors = [];
    protected $categories = [];
    protected $links = [];
    protected $metadata = [];
    
    public function __construct() {
        $this->updated = date('c');
    }
    
    public function setId($id) {
        $this->id = $id;
        return $this;
    }
    
    public function getId() {
        return $this->id;
    }
    
    public function setTitle($title) {
        // Не нормализуем title для entry, чтобы сохранить оригинальный текст (включая кириллицу)
        // normalize_text_for_opds может удалить кириллицу
        $this->title = $title;
        return $this;
    }
    
    public function getTitle() {
        return $this->title;
    }
    
    public function setUpdated($updated) {
        $this->updated = $updated;
        return $this;
    }
    
    public function getUpdated() {
        return $this->updated;
    }
    
    public function setContent($content, $type = 'text') {
        // Для text типа не используем normalize_text_for_opds, чтобы избежать проблем
        // Для HTML контента сохраняем структуру, но нормализуем текст
        $preserve_html = ($type === 'html' || $type === 'text/html');
        if ($type === 'text' && !$preserve_html) {
            // Для обычного текста не нормализуем, чтобы сохранить оригинальный текст
            $this->content = $content;
        } else {
            $this->content = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($content, $preserve_html) : $content;
        }
        $this->contentType = $type;
        return $this;
    }
    
    public function setSummary($summary, $type = 'text') {
        // Для HTML контента сохраняем структуру, но нормализуем текст
        $preserve_html = ($type === 'html' || $type === 'text/html');
        $this->summary = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($summary, $preserve_html) : $summary;
        $this->summaryType = $type;
        return $this;
    }
    
    public function addAuthor($name, $uri = null) {
        // НЕ нормализуем имя автора - сохраняем оригинальный текст (включая кириллицу)
        // normalize_text_for_opds может удалить кириллицу
        $this->authors[] = ['name' => $name, 'uri' => $uri];
        return $this;
    }
    
    public function addCategory($term, $label = null, $scheme = null) {
        $normalizedLabel = $label && function_exists('normalize_text_for_opds') ? normalize_text_for_opds($label) : $label;
        $this->categories[] = [
            'term' => $term,
            'label' => $normalizedLabel,
            'scheme' => $scheme
        ];
        return $this;
    }
    
    public function addLink(OPDSLink $link) {
        $this->links[] = $link;
        return $this;
    }
    
    public function addMetadata($namespace, $name, $value) {
        if (!isset($this->metadata[$namespace])) {
            $this->metadata[$namespace] = [];
        }
        $this->metadata[$namespace][$name] = $value;
        return $this;
    }
    
    public function render(): string {
        $xml = '<entry>';
        
        if ($this->id) {
            $xml .= "\n <id>" . htmlspecialchars($this->id, ENT_XML1, 'UTF-8') . "</id>";
        }
        
        if ($this->title) {
            $xml .= "\n <title>" . htmlspecialchars($this->title, ENT_XML1, 'UTF-8') . "</title>";
        }
        
        if ($this->updated) {
            $xml .= "\n <updated>" . htmlspecialchars($this->updated, ENT_XML1, 'UTF-8') . "</updated>";
        }
        
        foreach ($this->authors as $author) {
            $xml .= "\n <author>";
            $xml .= "\n  <name>" . htmlspecialchars($author['name'], ENT_XML1, 'UTF-8') . "</name>";
            if ($author['uri']) {
                $xml .= "\n  <uri>" . htmlspecialchars($author['uri'], ENT_XML1, 'UTF-8') . "</uri>";
            }
            $xml .= "\n </author>";
        }
        
        foreach ($this->categories as $category) {
            $xml .= "\n <category";
            $xml .= ' term="' . htmlspecialchars($category['term'], ENT_XML1, 'UTF-8') . '"';
            if ($category['label']) {
                $xml .= ' label="' . htmlspecialchars($category['label'], ENT_XML1, 'UTF-8') . '"';
            }
            if ($category['scheme']) {
                $xml .= ' scheme="' . htmlspecialchars($category['scheme'], ENT_XML1, 'UTF-8') . '"';
            }
            $xml .= ' />';
        }
        
        if ($this->summary) {
            $xml .= "\n <summary type=\"" . htmlspecialchars($this->summaryType, ENT_XML1, 'UTF-8') . "\">";
            if ($this->summaryType === 'text' || $this->summaryType === 'html') {
                $xml .= htmlspecialchars($this->summary, ENT_XML1, 'UTF-8');
            } else {
                $xml .= $this->summary;
            }
            $xml .= "</summary>";
        }
        
        if ($this->content) {
            $xml .= "\n <content type=\"" . htmlspecialchars($this->contentType, ENT_XML1, 'UTF-8') . "\">";
            if ($this->contentType === 'text') {
                // Для обычного текста просто экранируем
                $content = $this->content;
                // Удаляем невалидные XML символы
                $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
                // Экранируем специальные символы XML
                $content = htmlspecialchars($content, ENT_XML1 | ENT_QUOTES, 'UTF-8', false);
                $xml .= $content;
            } elseif ($this->contentType === 'html' || $this->contentType === 'text/html') {
                // Для HTML контента используем более агрессивную очистку
                $content = $this->content;
                
                // Удаляем невалидные XML символы
                $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
                
                // Нормализуем незакрытые теги
                $content = preg_replace('/<br\s*>/i', '<br/>', $content);
                $content = preg_replace('/<hr\s*>/i', '<hr/>', $content);
                $content = preg_replace('/<img\s+([^>]*?)(?<!\s\/)>/i', '<img $1 />', $content);
                
                // Удаляем незакрытые теги (кроме самозакрывающихся)
                $content = preg_replace('/<(?!area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)[^>]+>(?![^<]*<\/[^>]+>)/i', '', $content);
                
                // Экранируем специальные символы XML
                $content = htmlspecialchars($content, ENT_XML1 | ENT_QUOTES, 'UTF-8', false);
                $xml .= $content;
            } else {
                // Для других типов контента используем как есть (но все равно экранируем)
                $content = htmlspecialchars($this->content, ENT_XML1 | ENT_QUOTES, 'UTF-8', false);
                $xml .= $content;
            }
            $xml .= "</content>";
        }
        
        foreach ($this->links as $link) {
            $xml .= "\n " . $link->render();
        }
        
        // Добавляем метаданные (dc:, opds: и т.д.)
        foreach ($this->metadata as $namespace => $elements) {
            foreach ($elements as $name => $value) {
                $xml .= "\n <" . htmlspecialchars($namespace . ':' . $name, ENT_XML1, 'UTF-8') . ">";
                $xml .= htmlspecialchars($value, ENT_XML1, 'UTF-8');
                $xml .= "</" . htmlspecialchars($namespace . ':' . $name, ENT_XML1, 'UTF-8') . ">";
            }
        }
        
        $xml .= "\n</entry>";
        return $xml;
    }
}
