<?php
declare(strict_types=1);

require_once(__DIR__ . '/../core/OPDSFeed.php');
require_once(__DIR__ . '/../core/OPDSLink.php');
require_once(__DIR__ . '/../core/OPDSEntry.php');
require_once(__DIR__ . '/../core/OPDSNavigation.php');
require_once(__DIR__ . '/../core/OPDSFacet.php');

/**
 * Реализация OPDS 1.2 фида
 */
class OPDS2Feed extends OPDSFeed {
    protected ?string $subtitle = null;
    protected ?string $rights = null;
    
    public function __construct() {
        parent::__construct();
    }
    
    public function setSubtitle(?string $subtitle): self {
        $this->subtitle = $subtitle && function_exists('normalize_text_for_opds') ? normalize_text_for_opds($subtitle) : $subtitle;
        return $this;
    }
    
    public function setRights(?string $rights): self {
        $this->rights = $rights;
        return $this;
    }
    
    public function render(): string {
        $namespace = $this->getNamespace();
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= "\n<feed xmlns=\"http://www.w3.org/2005/Atom\"";
        $xml .= " xmlns:dc=\"http://purl.org/dc/terms/\"";
        $xml .= " xmlns:dcterms=\"http://purl.org/dc/terms/\"";
        $xml .= " xmlns:os=\"http://a9.com/-/spec/opensearch/1.1/\"";
        $xml .= " xmlns:opds=\"" . htmlspecialchars($namespace, ENT_XML1, 'UTF-8') . "\">";
        
        // 1. id (обязательный)
        if ($this->id) {
            $xml .= "\n <id>" . htmlspecialchars($this->id, ENT_XML1, 'UTF-8') . "</id>";
        }
        
        // 2. title (обязательный)
        if ($this->title) {
            $xml .= "\n <title>" . htmlspecialchars($this->title, ENT_XML1, 'UTF-8') . "</title>";
        }
        
        // 3. updated (обязательный)
        if ($this->updated) {
            $xml .= "\n <updated>" . htmlspecialchars($this->updated, ENT_XML1, 'UTF-8') . "</updated>";
        }
        
        // 4. icon (опционально)
        if ($this->icon) {
            $xml .= "\n <icon>" . htmlspecialchars($this->icon, ENT_XML1, 'UTF-8') . "</icon>";
        }
        
        // 5. subtitle (опционально)
        if ($this->subtitle) {
            $xml .= "\n <subtitle>" . htmlspecialchars($this->subtitle, ENT_XML1, 'UTF-8') . "</subtitle>";
        }
        
        // 6. rights (опционально)
        if ($this->rights) {
            $xml .= "\n <rights>" . htmlspecialchars($this->rights, ENT_XML1, 'UTF-8') . "</rights>";
        }
        
        // 7. links (обязательно, должны быть перед entries)
        foreach ($this->links as $link) {
            $xml .= "\n " . $link->render();
        }
        
        // Добавляем навигационные ссылки (пагинация) - тоже links
        if ($this->navigation) {
            $xml .= $this->navigation->render();
        }
        
        // 8. metadata (opds:numberOfItems, opds:itemsPerPage и другие метаданные) - ДО entries
        if ($this->navigation) {
            $navMetadata = $this->navigation->getMetadata();
            $xml .= "\n <opds:numberOfItems>" . (int)$navMetadata['numberOfItems'] . "</opds:numberOfItems>";
            $xml .= "\n <opds:itemsPerPage>" . (int)$navMetadata['itemsPerPage'] . "</opds:itemsPerPage>";
        }
        
        // Добавляем другие метаданные (dc:, opds: и т.д.)
        foreach ($this->metadata as $ns => $elements) {
            foreach ($elements as $name => $value) {
                $xml .= "\n <" . htmlspecialchars($ns . ':' . $name, ENT_XML1, 'UTF-8') . ">";
                $xml .= htmlspecialchars($value, ENT_XML1, 'UTF-8');
                $xml .= "</" . htmlspecialchars($ns . ':' . $name, ENT_XML1, 'UTF-8') . ">";
            }
        }
        
        // 9. facets (OPDS 1.2) - перед entries
        foreach ($this->facets as $facet) {
            $xml .= $facet->render();
        }
        
        // 10. entries и groups (обязательно, должны быть последними)
        // Если есть группы, выводим их, иначе выводим обычные entries
        if (!empty($this->groups)) {
            foreach ($this->groups as $group) {
                $xml .= $group->render();
            }
        } else {
            // Выводим обычные entries, если нет групп
            foreach ($this->entries as $entry) {
                $xml .= "\n" . $entry->render();
            }
        }
        
        $xml .= "\n</feed>";
        return $xml;
    }
}
