<?php
declare(strict_types=1);

/**
 * Базовый класс для генерации OPDS 1.2 фидов
 * Абстрактный класс, используется как основа для реализации OPDS2Feed
 */
abstract class OPDSFeed {
    protected $id;
    protected $title;
    protected $updated;
    protected $icon;
    protected $entries = [];
    protected $links = [];
    protected $facets = [];
    protected $navigation = null;
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
        // Не нормализуем title для feed, чтобы сохранить оригинальный текст
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
    
    public function setIcon($icon) {
        $this->icon = $icon;
        return $this;
    }
    
    public function addEntry(OPDSEntry $entry) {
        $this->entries[] = $entry;
        return $this;
    }
    
    public function addLink(OPDSLink $link) {
        $this->links[] = $link;
        return $this;
    }
    
    public function addFacet(OPDSFacet $facet) {
        $this->facets[] = $facet;
        return $this;
    }
    
    public function setNavigation(OPDSNavigation $navigation) {
        $this->navigation = $navigation;
        return $this;
    }
    
    public function addMetadata($namespace, $name, $value) {
        if (!isset($this->metadata[$namespace])) {
            $this->metadata[$namespace] = [];
        }
        $this->metadata[$namespace][$name] = $value;
        return $this;
    }
    
    /**
     * Рендерит фид в XML
     * 
     * @return string XML строка
     */
    abstract public function render();
    
    /**
     * Получает namespace для OPDS 1.2
     * 
     * @return string Namespace URI
     */
    protected function getNamespace(): string {
        return OPDSVersion::getNamespace();
    }
    
    /**
     * Получает profile для OPDS 1.2
     * 
     * @param string $kind Тип каталога
     * @return string Profile строка
     */
    protected function getProfile(string $kind = 'acquisition'): string {
        return OPDSVersion::getProfile($kind);
    }
}
