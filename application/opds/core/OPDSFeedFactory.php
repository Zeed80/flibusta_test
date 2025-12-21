<?php
require_once(__DIR__ . '/autoload.php');

/**
 * Фабрика для создания OPDS фидов с автоматическим определением версии
 */
class OPDSFeedFactory {
    /**
     * Создает фид с автоматическим определением версии клиента
     * 
     * @param string|null $userAgent User-Agent заголовок
     * @param string|null $accept Accept заголовок
     * @return OPDSFeed Экземпляр фида (OPDS1Feed или OPDS2Feed)
     */
    public static function create($userAgent = null, $accept = null) {
        $version = OPDSVersion::detect($userAgent, $accept);
        
        // Если версия auto, используем 1.2 по умолчанию
        if ($version === OPDSVersion::VERSION_AUTO) {
            $version = OPDSVersion::VERSION_1_2;
        }
        
        if ($version === OPDSVersion::VERSION_1_0) {
            return new OPDS1Feed();
        }
        
        return new OPDS2Feed();
    }
    
    /**
     * Создает фид указанной версии
     * 
     * @param string $version Версия OPDS ('1.0' или '1.2')
     * @return OPDSFeed Экземпляр фида
     */
    public static function createVersion($version) {
        if ($version === OPDSVersion::VERSION_1_0) {
            return new OPDS1Feed();
        }
        
        return new OPDS2Feed();
    }
}
