<?php
require_once(__DIR__ . '/../core/OPDSFeed.php');
require_once(__DIR__ . '/../core/OPDSLink.php');
require_once(__DIR__ . '/../core/OPDSEntry.php');
require_once(__DIR__ . '/../core/OPDSNavigation.php');
require_once(__DIR__ . '/../core/OPDSFacet.php');

/**
 * Реализация OPDS 1.2 фида
 */
class OPDS2Feed extends OPDSFeed {
    protected $subtitle;
    protected $rights;
    
    public function __construct() {
        parent::__construct('1.2');
    }
    
    public function setSubtitle($subtitle) {
        $this->subtitle = function_exists('normalize_text_for_opds') ? normalize_text_for_opds($subtitle) : $subtitle;
        return $this;
    }
    
    public function setRights($rights) {
        $this->rights = $rights;
        return $this;
    }
    
    public function render() {
        $namespace = $this->getNamespace();
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= "\n<feed xmlns=\"http://www.w3.org/2005/Atom\"";
        $xml .= " xmlns:dc=\"http://purl.org/dc/terms/\"";
        $xml .= " xmlns:os=\"http://a9.com/-/spec/opensearch/1.1/\"";
        $xml .= " xmlns:opds=\"" . htmlspecialchars($namespace, ENT_XML1, 'UTF-8') . "\">";
        
        if ($this->id) {
            $xml .= "\n <id>" . htmlspecialchars($this->id, ENT_XML1, 'UTF-8') . "</id>";
        }
        
        if ($this->title) {
            $xml .= "\n <title>" . htmlspecialchars($this->title, ENT_XML1, 'UTF-8') . "</title>";
        }
        
        if ($this->subtitle) {
            $xml .= "\n <subtitle>" . htmlspecialchars($this->subtitle, ENT_XML1, 'UTF-8') . "</subtitle>";
        }
        
        if ($this->updated) {
            $xml .= "\n <updated>" . htmlspecialchars($this->updated, ENT_XML1, 'UTF-8') . "</updated>";
        }
        
        if ($this->icon) {
            $xml .= "\n <icon>" . htmlspecialchars($this->icon, ENT_XML1, 'UTF-8') . "</icon>";
        }
        
        if ($this->rights) {
            $xml .= "\n <rights>" . htmlspecialchars($this->rights, ENT_XML1, 'UTF-8') . "</rights>";
        }
        
        // Добавляем ссылки
        foreach ($this->links as $link) {
            $xml .= "\n " . $link->render($this->version);
        }
        
        // Добавляем навигационные ссылки
        if ($this->navigation) {
            $xml .= $this->navigation->render($this->version);
            
            // Добавляем метаданные пагинации для OPDS 1.2
            $navMetadata = $this->navigation->getMetadata();
            $xml .= "\n <opds:numberOfItems>" . (int)$navMetadata['numberOfItems'] . "</opds:numberOfItems>";
            $xml .= "\n <opds:itemsPerPage>" . (int)$navMetadata['itemsPerPage'] . "</opds:itemsPerPage>";
        }
        
        // Добавляем фасеты (только для OPDS 1.2)
        foreach ($this->facets as $facet) {
            $xml .= $facet->render($this->version);
        }
        
        // Добавляем метаданные
        foreach ($this->metadata as $ns => $elements) {
            foreach ($elements as $name => $value) {
                $xml .= "\n <" . htmlspecialchars($ns . ':' . $name, ENT_XML1, 'UTF-8') . ">";
                $xml .= htmlspecialchars($value, ENT_XML1, 'UTF-8');
                $xml .= "</" . htmlspecialchars($ns . ':' . $name, ENT_XML1, 'UTF-8') . ">";
            }
        }
        
        // Добавляем записи
        foreach ($this->entries as $entry) {
            $xml .= "\n" . $entry->render($this->version);
        }
        
        $xml .= "\n</feed>";
        return $xml;
    }
}
