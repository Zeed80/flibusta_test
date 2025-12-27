<?php
declare(strict_types=1);

require_once(__DIR__ . '/autoload.php');

/**
 * Фабрика для создания OPDS 1.2 фидов
 */
class OPDSFeedFactory {
    /**
     * Создает OPDS 1.2 фид
     * 
     * @return OPDSFeed Экземпляр OPDS2Feed
     */
    public static function create(): OPDSFeed {
        return new OPDS2Feed();
    }
}
