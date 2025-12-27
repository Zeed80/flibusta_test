<?php
declare(strict_types=1);

/**
 * Класс для создания фасетной навигации в OPDS 1.2 фидах
 */
class OPDSFacet {
    protected string $facetType;
    protected string $facetGroup;
    /** @var string[] */
    protected array $activeFacets = [];
    /** @var array<int, array{term: string, label: string, href: string, count: int|null, active: bool}> */
    protected array $facets = [];
    
    public function __construct(string $facetType, ?string $facetGroup = null) {
        $this->facetType = $facetType;
        $this->facetGroup = $facetGroup ?: $facetType;
    }
    
    /**
     * Добавляет фасет
     * 
     * @param string $term Значение фасета
     * @param string $label Отображаемое название
     * @param string $href URL для фильтрации
     * @param int|null $count Количество элементов
     * @param bool $active Является ли фасет активным
     * @return $this
     */
    public function addFacet(string $term, string $label, string $href, ?int $count = null, bool $active = false): self {
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
     * Добавляет фасет (алиас для addFacet для обратной совместимости)
     * 
     * @param string $term Значение фасета
     * @param string $label Отображаемое название
     * @param string $href URL для фильтрации
     * @param int|null $count Количество элементов
     * @param bool $active Является ли фасет активным
     * @return $this
     */
    public function addFacetValue(string $term, string $label, string $href, ?int $count = null, bool $active = false): self {
        return $this->addFacet($term, $label, $href, $count, $active);
    }
    
    /**
     * Рендерит фасетную группу в XML (OPDS 1.2)
     * 
     * @return string XML строка
     */
    public function render(): string {
        if (empty($this->facets)) {
            return '';
        }
        
        $xml = "\n <opds:facetGroup>";
        $xml .= "\n  <opds:title>" . htmlspecialchars($this->facetGroup, ENT_XML1, 'UTF-8') . "</opds:title>";
        
        foreach ($this->facets as $facet) {
            $xml .= "\n  <opds:facet";
            $xml .= ' opds:active="' . ($facet['active'] ? 'true' : 'false') . '"';
            if ($facet['count'] !== null) {
                $xml .= ' opds:count="' . (int)$facet['count'] . '"';
            }
            $xml .= '>';
            // Используем стандартный Atom link элемент, а не opds:link
            $xml .= "\n   <link";
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
     * @return string[] Массив активных фасетов
     */
    public function getActiveFacets(): array {
        return $this->activeFacets;
    }
}
