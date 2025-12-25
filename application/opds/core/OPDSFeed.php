<?php
/**
 * Базовый класс для генерации OPDS фидов
 * Абстрактный класс, используется как основа для версионных реализаций
 */
abstract class OPDSFeed {
    protected $version;
    protected $id;
    protected $title;
    protected $updated;
    protected $icon;
    protected $entries = [];
    protected $links = [];
    protected $facets = [];
    protected $navigation = null;
    protected $metadata = [];
    
    public function __construct($version = '1.2') {
        $this->version = $version;
        $this->updated = date('c');
    }
    
    public function setVersion($version) {
        $this->version = $version;
        return $this;
    }
    
    public function getVersion() {
        return $this->version;
    }
    
    public function setId($id) {
        $this->id = $id;
        return $this;
    }
    
    public function getId() {
        return $this->id;
    }
    
    public function setTitle($title) {
        $this->title = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($title) : $title;
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
     * Получает namespace для текущей версии
     * 
     * @return string Namespace URI
     */
    protected function getNamespace() {
        return OPDSVersion::getNamespace($this->version);
    }
    
    /**
     * Получает profile для текущей версии
     * 
     * @param string $kind Тип каталога
     * @return string Profile строка
     */
    protected function getProfile($kind = 'acquisition') {
        return OPDSVersion::getProfile($this->version, $kind);
    }
}
