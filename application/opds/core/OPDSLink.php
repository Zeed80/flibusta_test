<?php
declare(strict_types=1);

/**
 * Класс для создания ссылок в OPDS 1.2 фидах
 */
class OPDSLink {
    protected string $href;
    protected string $rel;
    protected ?string $type = null;
    protected ?string $title = null;
    protected ?string $hrefLang = null;
    protected ?int $length = null;
    /** @var array<string, string> */
    protected array $properties = [];
    
    public function __construct(string $href, string $rel, ?string $type = null, ?string $title = null) {
        $this->href = $href;
        $this->rel = $rel;
        $this->type = $type;
        $this->title = $title;
    }
    
    public function setHref(string $href): self {
        $this->href = $href;
        return $this;
    }
    
    public function getHref(): string {
        return $this->href;
    }
    
    public function setRel(string $rel): self {
        $this->rel = $rel;
        return $this;
    }
    
    public function getRel(): string {
        return $this->rel;
    }
    
    public function setType(?string $type): self {
        $this->type = $type;
        return $this;
    }
    
    public function getType(): ?string {
        return $this->type;
    }
    
    public function setTitle(?string $title): self {
        $this->title = $title && function_exists('normalize_text_for_opds') ? normalize_text_for_opds($title) : $title;
        return $this;
    }
    
    public function getTitle(): ?string {
        return $this->title;
    }
    
    public function setHrefLang(?string $lang): self {
        $this->hrefLang = $lang;
        return $this;
    }
    
    public function setLength(?int $length): self {
        $this->length = $length;
        return $this;
    }
    
    /**
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function addProperty(string $name, string $value): self {
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
