<?php
/**
 * Класс для определения версии OPDS клиента
 * Поддерживает автоматическое определение версии по заголовкам запроса
 */
class OPDSVersion {
    const VERSION_1_0 = '1.0';
    const VERSION_1_2 = '1.2';
    const VERSION_AUTO = 'auto';
    
    /**
     * Определяет версию OPDS клиента по заголовкам
     * 
     * @param string|null $userAgent User-Agent заголовок
     * @param string|null $accept Accept заголовок
     * @return string Версия OPDS ('1.0', '1.2' или 'auto')
     */
    public static function detect($userAgent = null, $accept = null) {
        if ($userAgent === null && isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
        }
        
        if ($accept === null && isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = $_SERVER['HTTP_ACCEPT'];
        }
        
        // Проверяем явное указание версии в параметрах
        if (isset($_GET['opds_version'])) {
            $version = $_GET['opds_version'];
            if (in_array($version, [self::VERSION_1_0, self::VERSION_1_2])) {
                return $version;
            }
        }
        
        // Определяем по User-Agent известных клиентов
        if ($userAgent) {
            $userAgentLower = strtolower($userAgent);
            
            // Клиенты, поддерживающие OPDS 1.2
            $opds12Clients = [
                'calibre',
                'koreader',
                'aldiko',
                'readium',
                'thorium',
                'opds-reader'
            ];
            
            // Клиенты, поддерживающие только OPDS 1.0
            $opds10Clients = [
                'fbreader',
                'moon+ reader',
                'cool reader',
                'bookari'
            ];
            
            foreach ($opds12Clients as $client) {
                if (strpos($userAgentLower, $client) !== false) {
                    return self::VERSION_1_2;
                }
            }
            
            foreach ($opds10Clients as $client) {
                if (strpos($userAgentLower, $client) !== false) {
                    return self::VERSION_1_0;
                }
            }
        }
        
        // Проверяем Accept заголовок
        if ($accept) {
            // Если клиент явно запрашивает OPDS 1.2
            if (strpos($accept, 'opds-catalog') !== false && 
                (strpos($accept, 'opds-1.2') !== false || strpos($accept, 'opds/1.2') !== false)) {
                return self::VERSION_1_2;
            }
            
            // Если клиент запрашивает старый формат
            if (strpos($accept, 'application/atom+xml') !== false && 
                strpos($accept, 'opds') === false) {
                return self::VERSION_1_0;
            }
        }
        
        // По умолчанию возвращаем auto для адаптивной генерации
        return self::VERSION_AUTO;
    }
    
    /**
     * Получает namespace для указанной версии OPDS
     * 
     * @param string $version Версия OPDS
     * @return string Namespace URI
     */
    public static function getNamespace($version) {
        if ($version === self::VERSION_1_0) {
            return 'http://opds-spec.org/2010/catalog';
        }
        return 'https://specs.opds.io/opds-1.2';
    }
    
    /**
     * Получает profile для указанной версии OPDS
     * 
     * @param string $version Версия OPDS
     * @param string $kind Тип каталога (navigation, acquisition)
     * @return string Profile строка
     */
    public static function getProfile($version, $kind = 'acquisition') {
        if ($version === self::VERSION_1_0) {
            return 'application/atom+xml;profile=opds-catalog';
        }
        return 'application/atom+xml;profile=opds-catalog;kind=' . $kind;
    }
}
