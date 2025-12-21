<?php
/**
 * Класс для создания записей в OPDS фидах
 * Поддерживает OPDS 1.0 и 1.2
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
        $this->content = $content;
        $this->contentType = $type;
        return $this;
    }
    
    public function setSummary($summary, $type = 'text') {
        $this->summary = $summary;
        $this->summaryType = $type;
        return $this;
    }
    
    public function addAuthor($name, $uri = null) {
        $this->authors[] = ['name' => $name, 'uri' => $uri];
        return $this;
    }
    
    public function addCategory($term, $label = null, $scheme = null) {
        $this->categories[] = [
            'term' => $term,
            'label' => $label,
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
    
    public function render($version = '1.2') {
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
            if ($this->contentType === 'text' || $this->contentType === 'html') {
                $xml .= htmlspecialchars($this->content, ENT_XML1, 'UTF-8');
            } else {
                $xml .= $this->content;
            }
            $xml .= "</content>";
        }
        
        foreach ($this->links as $link) {
            $xml .= "\n " . $link->render($version);
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
