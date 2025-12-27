<?php
declare(strict_types=1);

/**
 * Класс для создания ссылок в OPDS 1.2 фидах
 */
class OPDSLink {
    protected $href;
    protected $rel;
    protected $type;
    protected $title;
    protected $hrefLang;
    protected $length;
    protected $properties = [];
    
    public function __construct($href, $rel, $type = null, $title = null) {
        $this->href = $href;
        $this->rel = $rel;
        $this->type = $type;
        $this->title = $title;
    }
    
    public function setHref($href) {
        $this->href = $href;
        return $this;
    }
    
    public function getHref() {
        return $this->href;
    }
    
    public function setRel($rel) {
        $this->rel = $rel;
        return $this;
    }
    
    public function getRel() {
        return $this->rel;
    }
    
    public function setType($type) {
        $this->type = $type;
        return $this;
    }
    
    public function getType() {
        return $this->type;
    }
    
    public function setTitle($title) {
        $this->title = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($title) : $title;
        return $this;
    }
    
    public function getTitle() {
        return $this->title;
    }
    
    public function setHrefLang($lang) {
        $this->hrefLang = $lang;
        return $this;
    }
    
    public function setLength($length) {
        $this->length = $length;
        return $this;
    }
    
    public function addProperty($name, $value) {
        $this->properties[$name] = $value;
        return $this;
    }
    
    public function render(): string {
        $href = htmlspecialchars($this->href, ENT_XML1, 'UTF-8');
        $rel = htmlspecialchars($this->rel, ENT_XML1, 'UTF-8');
        
        $attrs = ['href' => $href, 'rel' => $rel];
        
        if ($this->type) {
            $attrs['type'] = htmlspecialchars($this->type, ENT_XML1, 'UTF-8');
        }
        
        if ($this->title) {
            $attrs['title'] = htmlspecialchars($this->title, ENT_XML1, 'UTF-8');
        }
        
        if ($this->hrefLang) {
            $attrs['hreflang'] = htmlspecialchars($this->hrefLang, ENT_XML1, 'UTF-8');
        }
        
        if ($this->length !== null) {
            $attrs['length'] = (int)$this->length;
        }
        
        // Добавляем свойства для OPDS 1.2
        foreach ($this->properties as $name => $value) {
            $attrs['properties'] = ($attrs['properties'] ?? '') . ($attrs['properties'] ? ' ' : '') . $name . ':' . htmlspecialchars($value, ENT_XML1, 'UTF-8');
        }
        
        $attrString = '';
        foreach ($attrs as $key => $value) {
            $attrString .= ' ' . $key . '="' . $value . '"';
        }
        
        return '<link' . $attrString . ' />';
    }
}
