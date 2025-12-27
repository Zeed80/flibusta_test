<?php
declare(strict_types=1);

/**
 * Класс для работы с OPDS 1.2
 * Проект использует только OPDS 1.2 - последнюю стабильную версию спецификации
 */
class OPDSVersion {
    const VERSION_1_2 = '1.2';
    
    /**
     * Получает namespace для OPDS 1.2
     * 
     * @return string Namespace URI
     */
    public static function getNamespace(): string {
        return 'https://specs.opds.io/opds-1.2';
    }
    
    /**
     * Получает profile для OPDS 1.2
     * 
     * @param string $kind Тип каталога (navigation, acquisition)
     * @return string Profile строка
     */
    public static function getProfile(string $kind = 'acquisition'): string {
        return 'application/atom+xml;profile=opds-catalog;kind=' . $kind;
    }
}
