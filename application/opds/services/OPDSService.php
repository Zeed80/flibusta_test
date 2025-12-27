<?php
declare(strict_types=1);

/**
 * Базовый класс для всех OPDS сервисов
 * Предоставляет Dependency Injection для общих зависимостей
 */
abstract class OPDSService {
    protected PDO $dbh;
    protected string $webroot;
    protected string $cdt;
    
    /**
     * Конструктор с Dependency Injection
     * 
     * @param PDO $dbh Подключение к базе данных
     * @param string $webroot Базовый URL веб-приложения
     * @param string $cdt Текущая дата/время для использования в фидах
     */
    public function __construct(PDO $dbh, string $webroot, string $cdt) {
        $this->dbh = $dbh;
        $this->webroot = $webroot;
        $this->cdt = $cdt;
    }
    
    /**
     * Получить подключение к базе данных
     * 
     * @return PDO
     */
    protected function getDbh(): PDO {
        return $this->dbh;
    }
    
    /**
     * Получить базовый URL
     * 
     * @return string
     */
    protected function getWebroot(): string {
        return $this->webroot;
    }
    
    /**
     * Получить текущую дату/время
     * 
     * @return string
     */
    protected function getCdt(): string {
        return $this->cdt;
    }
}
