<?php
/**
 * Класс для создания фасетной навигации в OPDS фидах
 * Поддерживает OPDS 1.2 faceted navigation
 */
class OPDSFacet {
    protected $facetType;
    protected $facetGroup;
    protected $activeFacets = [];
    protected $facets = [];
    
    public function __construct($facetType, $facetGroup = null) {
        $this->facetType = $facetType;
        $this->facetGroup = $facetGroup ?: $facetType;
    }
    
    /**
     * Добавляет фасет
     * 
     * @param string $term Значение фасета
     * @param string $label Отображаемое название
     * @param string $href URL для фильтрации
     * @param int $count Количество элементов
     * @param bool $active Является ли фасет активным
     */
    public function addFacet($term, $label, $href, $count = null, $active = false) {
        $this->facets[] = [
            'term' => $term,
            'label' => $label,
            'href' => $href,
            'count' => $count,
            'active' => $active
        ];
        
        if ($active) {
            $this->activeFacets[] = $term;
        }
        
        return $this;
    }
    
    /**
     * Рендерит фасетную группу в XML
     * 
     * @param string $version Версия OPDS
     * @return string XML строка
     */
    public function render($version = '1.2') {
        if ($version !== '1.2' || empty($this->facets)) {
            return '';
        }
        
        $xml = "\n <opds:facetGroup>";
        $xml .= "\n  <opds:title>" . htmlspecialchars($this->facetGroup, ENT_XML1, 'UTF-8') . "</opds:title>";
        
        foreach ($this->facets as $facet) {
            $xml .= "\n  <opds:facet";
            $xml .= ' opds:active="' . ($facet['active'] ? 'true' : 'false') . '"';
            $xml .= ' opds:count="' . (int)$facet['count'] . '"';
            $xml .= '>';
            $xml .= "\n   <opds:link";
            $xml .= ' href="' . htmlspecialchars($facet['href'], ENT_XML1, 'UTF-8') . '"';
            $xml .= ' rel="http://opds-spec.org/facet"';
            $xml .= ' />';
            $xml .= "\n   <opds:title>" . htmlspecialchars($facet['label'], ENT_XML1, 'UTF-8') . "</opds:title>";
            $xml .= "\n  </opds:facet>";
        }
        
        $xml .= "\n </opds:facetGroup>";
        return $xml;
    }
    
    /**
     * Получает активные фасеты
     * 
     * @return array Массив активных фасетов
     */
    public function getActiveFacets() {
        return $this->activeFacets;
    }
}
