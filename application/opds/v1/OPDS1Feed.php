<?php
require_once(__DIR__ . '/../core/OPDSFeed.php');
require_once(__DIR__ . '/../core/OPDSLink.php');
require_once(__DIR__ . '/../core/OPDSEntry.php');
require_once(__DIR__ . '/../core/OPDSNavigation.php');

/**
 * Реализация OPDS 1.0 фида
 */
class OPDS1Feed extends OPDSFeed {
    public function __construct() {
        parent::__construct('1.0');
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
        
        if ($this->updated) {
            $xml .= "\n <updated>" . htmlspecialchars($this->updated, ENT_XML1, 'UTF-8') . "</updated>";
        }
        
        if ($this->icon) {
            $xml .= "\n <icon>" . htmlspecialchars($this->icon, ENT_XML1, 'UTF-8') . "</icon>";
        }
        
        // Добавляем ссылки
        foreach ($this->links as $link) {
            $xml .= "\n " . $link->render($this->version);
        }
        
        // Добавляем навигационные ссылки
        if ($this->navigation) {
            $xml .= $this->navigation->render($this->version);
        }
        
        // Добавляем фасеты (в OPDS 1.0 они не поддерживаются, но можем добавить как обычные ссылки)
        // Пропускаем для совместимости
        
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
