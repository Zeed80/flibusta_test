<?php
/**
 * Класс для генерации навигационных ссылок в OPDS фидах
 * Поддерживает пагинацию с ссылками first, previous, next, last
 */
class OPDSNavigation {
    protected $currentPage;
    protected $totalPages;
    protected $totalItems;
    protected $itemsPerPage;
    protected $baseUrl;
    protected $params;
    
    public function __construct($currentPage, $totalPages, $totalItems, $itemsPerPage, $baseUrl, $params = []) {
        $this->currentPage = max(1, (int)$currentPage);
        $this->totalPages = max(1, (int)$totalPages);
        $this->totalItems = max(0, (int)$totalItems);
        $this->itemsPerPage = max(1, (int)$itemsPerPage);
        $this->baseUrl = $baseUrl;
        $this->params = $params;
    }
    
    /**
     * Генерирует навигационные ссылки
     * 
     * @param string $version Версия OPDS
     * @return array Массив OPDSLink объектов
     */
    public function generateLinks($version = '1.2') {
        $links = [];
        
        // Ссылка на первую страницу
        if ($this->currentPage > 1) {
            $firstLink = new OPDSLink(
                $this->buildUrl(1),
                'first',
                OPDSVersion::getProfile($version, 'acquisition')
            );
            $links[] = $firstLink;
        }
        
        // Ссылка на предыдущую страницу
        if ($this->currentPage > 1) {
            $prevPage = $this->currentPage - 1;
            $prevLink = new OPDSLink(
                $this->buildUrl($prevPage),
                'previous',
                OPDSVersion::getProfile($version, 'acquisition')
            );
            $links[] = $prevLink;
        }
        
        // Ссылка на следующую страницу
        if ($this->currentPage < $this->totalPages) {
            $nextPage = $this->currentPage + 1;
            $nextLink = new OPDSLink(
                $this->buildUrl($nextPage),
                'next',
                OPDSVersion::getProfile($version, 'acquisition')
            );
            $links[] = $nextLink;
        }
        
        // Ссылка на последнюю страницу
        if ($this->currentPage < $this->totalPages) {
            $lastLink = new OPDSLink(
                $this->buildUrl($this->totalPages),
                'last',
                OPDSVersion::getProfile($version, 'acquisition')
            );
            $links[] = $lastLink;
        }
        
        return $links;
    }
    
    /**
     * Строит URL с параметрами пагинации
     * 
     * @param int $page Номер страницы
     * @return string URL
     */
    protected function buildUrl($page) {
        $params = array_merge($this->params, ['page' => $page]);
        $queryString = http_build_query($params);
        $separator = strpos($this->baseUrl, '?') !== false ? '&' : '?';
        return $this->baseUrl . $separator . $queryString;
    }
    
    /**
     * Получает метаданные для пагинации (OPDS 1.2)
     * 
     * @return array Массив с numberOfItems и itemsPerPage
     */
    public function getMetadata() {
        return [
            'numberOfItems' => $this->totalItems,
            'itemsPerPage' => $this->itemsPerPage
        ];
    }
    
    /**
     * Рендерит навигационные ссылки в XML
     * 
     * @param string $version Версия OPDS
     * @return string XML строка
     */
    public function render($version = '1.2') {
        $xml = '';
        $links = $this->generateLinks($version);
        
        foreach ($links as $link) {
            $xml .= "\n " . $link->render($version);
        }
        
        return $xml;
    }
}
