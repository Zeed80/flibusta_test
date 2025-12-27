<?php
declare(strict_types=1);

/**
 * Централизованный обработчик ошибок для OPDS
 * Предоставляет методы для генерации XML ошибок в соответствии со стандартом OPDS 1.2
 */
class OPDSErrorHandler {
    
    /**
     * Генерирует XML фид с ошибкой
     * 
     * @param string $id ID ошибки
     * @param string $title Заголовок ошибки
     * @param string $message Сообщение об ошибке
     * @param int $httpCode HTTP код ошибки
     * @return string XML строка с фидом ошибки
     */
    public static function generateErrorFeed(string $id, string $title, string $message, int $httpCode = 500): string {
        $xml = '<?xml version="1.0" encoding="utf-8"?>';
        $xml .= "\n<feed xmlns=\"http://www.w3.org/2005/Atom\" xmlns:opds=\"https://specs.opds.io/opds-1.2\">";
        $xml .= "\n <id>" . htmlspecialchars($id, ENT_XML1, 'UTF-8') . "</id>";
        $xml .= "\n <title>" . htmlspecialchars($title, ENT_XML1, 'UTF-8') . "</title>";
        $xml .= "\n <updated>" . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . "</updated>";
        $xml .= "\n <entry>";
        $xml .= "\n  <id>" . htmlspecialchars($id . ':entry', ENT_XML1, 'UTF-8') . "</id>";
        $xml .= "\n  <title>" . htmlspecialchars($title, ENT_XML1, 'UTF-8') . "</title>";
        $xml .= "\n  <updated>" . htmlspecialchars(date('c'), ENT_XML1, 'UTF-8') . "</updated>";
        $xml .= "\n  <summary type=\"text\">" . htmlspecialchars($message, ENT_XML1, 'UTF-8') . "</summary>";
        $xml .= "\n </entry>";
        $xml .= "\n</feed>";
        
        return $xml;
    }
    
    /**
     * Отправляет ошибку клиенту и завершает выполнение
     * 
     * @param string $id ID ошибки
     * @param string $title Заголовок ошибки
     * @param string $message Сообщение об ошибке
     * @param int $httpCode HTTP код ошибки
     * @return void
     */
    public static function sendError(string $id, string $title, string $message, int $httpCode = 500): void {
        http_response_code($httpCode);
        header('Content-Type: application/atom+xml; charset=utf-8');
        
        $xml = self::generateErrorFeed($id, $title, $message, $httpCode);
        echo $xml;
        exit;
    }
    
    /**
     * Обрабатывает исключение и отправляет ошибку клиенту
     * 
     * @param \Throwable $exception Исключение
     * @param int $httpCode HTTP код ошибки
     * @return void
     */
    public static function handleException(\Throwable $exception, int $httpCode = 500): void {
        // Логируем исключение
        error_log("OPDS Error: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        error_log("Stack trace: " . $exception->getTraceAsString());
        
        // Отправляем общее сообщение об ошибке клиенту (не раскрываем детали)
        $message = 'Произошла ошибка при обработке запроса';
        if ($httpCode === 404) {
            $message = 'Запрашиваемый ресурс не найден';
        } elseif ($httpCode === 400) {
            $message = 'Некорректный запрос';
        }
        
        self::sendError('tag:error:' . $httpCode, 'Ошибка', $message, $httpCode);
    }
    
    /**
     * Отправляет ошибку инициализации (отсутствие глобальных переменных)
     * 
     * @return void
     */
    public static function sendInitializationError(): void {
        self::sendError(
            'tag:error:init',
            'Ошибка инициализации',
            'Не удалось инициализировать необходимые переменные',
            500
        );
    }
    
    /**
     * Отправляет ошибку валидации параметров
     * 
     * @param string $message Сообщение об ошибке валидации
     * @return void
     */
    public static function sendValidationError(string $message): void {
        self::sendError(
            'tag:error:validation',
            'Ошибка валидации',
            $message,
            400
        );
    }
    
    /**
     * Отправляет ошибку SQL запроса
     * 
     * @param string $message Сообщение об ошибке
     * @return void
     */
    public static function sendSqlError(string $message): void {
        // Логируем детальную ошибку
        error_log("OPDS SQL Error: " . $message);
        
        // Отправляем общее сообщение клиенту
        self::sendError(
            'tag:error:sql',
            'Ошибка базы данных',
            'Не удалось выполнить запрос к базе данных',
            500
        );
    }
    
    /**
     * Отправляет ошибку "ресурс не найден"
     * 
     * @param string $resourceName Название ресурса (например, "книга", "автор")
     * @return void
     */
    public static function sendNotFoundError(string $resourceName = 'Ресурс'): void {
        self::sendError(
            'tag:error:notfound',
            'Не найдено',
            "$resourceName не найден",
            404
        );
    }
}
