<?php
declare(strict_types=1);

/**
 * Сервис для генерации OPDS фидов
 * Централизует логику создания фидов с правильными настройками
 */
class OPDSFeedService extends OPDSService {
    
    /**
     * Создает базовый OPDS фид с обязательными элементами
     * 
     * @param string $id ID фида
     * @param string $title Заголовок фида
     * @param string $kind Тип фида: 'navigation' или 'acquisition'
     * @return OPDSFeed
     */
    public function createFeed(string $id, string $title, string $kind = 'navigation'): OPDSFeed {
        $feed = OPDSFeedFactory::create();
        $feed->setId($id);
        $feed->setTitle($title);
        $feed->setUpdated($this->cdt);
        $feed->setIcon($this->webroot . '/favicon.ico');
        
        // Добавляем обязательные ссылки
        $this->addStandardLinks($feed, $kind);
        
        return $feed;
    }
    
    /**
     * Добавляет стандартные ссылки в фид
     * 
     * @param OPDSFeed $feed Фид для добавления ссылок
     * @param string $kind Тип фида: 'navigation' или 'acquisition'
     */
    protected function addStandardLinks(OPDSFeed $feed, string $kind): void {
        // OpenSearch описание
        $feed->addLink(new OPDSLink(
            $this->webroot . '/opds/opensearch.xml.php',
            'search',
            'application/opensearchdescription+xml'
        ));
        
        // Поиск
        $feed->addLink(new OPDSLink(
            $this->webroot . '/opds/search?q={searchTerms}',
            'search',
            OPDSVersion::getProfile($kind)
        ));
        
        // Главная страница
        $feed->addLink(new OPDSLink(
            $this->webroot . '/opds/',
            'start',
            OPDSVersion::getProfile('navigation')
        ));
    }
    
    /**
     * Добавляет self ссылку в фид
     * 
     * @param OPDSFeed $feed Фид для добавления ссылки
     * @param string $url URL текущего фида
     * @param string $kind Тип фида: 'navigation' или 'acquisition'
     */
    public function addSelfLink(OPDSFeed $feed, string $url, string $kind = 'navigation'): void {
        $feed->addLink(new OPDSLink(
            $url,
            'self',
            OPDSVersion::getProfile($kind)
        ));
    }
}
