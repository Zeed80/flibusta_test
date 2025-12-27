<?php
declare(strict_types=1);

require_once(__DIR__ . '/OPDSErrorHandler.php');

/**
 * Класс для валидации входных данных OPDS запросов
 * Проверяет параметры GET запросов и возвращает валидные значения
 */
class OPDSValidator {
    
    /**
     * Валидирует и возвращает ID из GET параметра
     * 
     * @param string $paramName Имя параметра (например, 'author_id', 'book_id')
     * @param int $minValue Минимальное значение (по умолчанию 1)
     * @param int|null $maxValue Максимальное значение (null = без ограничения)
     * @return int|null Валидный ID или null
     * @throws \InvalidArgumentException Если значение невалидно
     */
    public static function validateId(string $paramName, int $minValue = 1, ?int $maxValue = null): ?int {
        if (!isset($_GET[$paramName])) {
            return null;
        }
        
        $value = $_GET[$paramName];
        
        // Проверяем, что это число
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("Параметр $paramName должен быть числом");
        }
        
        $id = (int)$value;
        
        // Проверяем минимальное значение
        if ($id < $minValue) {
            throw new \InvalidArgumentException("Параметр $paramName должен быть >= $minValue");
        }
        
        // Проверяем максимальное значение
        if ($maxValue !== null && $id > $maxValue) {
            throw new \InvalidArgumentException("Параметр $paramName должен быть <= $maxValue");
        }
        
        return $id;
    }
    
    /**
     * Валидирует строковый параметр
     * 
     * @param string $paramName Имя параметра
     * @param int $maxLength Максимальная длина строки
     * @param bool $required Обязателен ли параметр
     * @param string|null $pattern Регулярное выражение для проверки формата
     * @return string|null Валидная строка или null
     * @throws \InvalidArgumentException Если значение невалидно
     */
    public static function validateString(
        string $paramName, 
        int $maxLength = 255, 
        bool $required = false,
        ?string $pattern = null
    ): ?string {
        if (!isset($_GET[$paramName])) {
            if ($required) {
                throw new \InvalidArgumentException("Параметр $paramName обязателен");
            }
            return null;
        }
        
        $value = trim((string)$_GET[$paramName]);
        
        // Проверяем максимальную длину
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new \InvalidArgumentException("Параметр $paramName слишком длинный (максимум $maxLength символов)");
        }
        
        // Проверяем формат по регулярному выражению
        if ($pattern !== null && !preg_match($pattern, $value)) {
            throw new \InvalidArgumentException("Параметр $paramName имеет неверный формат");
        }
        
        return $value === '' ? null : $value;
    }
    
    /**
     * Валидирует номер страницы
     * 
     * @param string $paramName Имя параметра (по умолчанию 'page')
     * @param int $defaultValue Значение по умолчанию
     * @param int $minValue Минимальное значение
     * @return int Валидный номер страницы
     */
    public static function validatePage(string $paramName = 'page', int $defaultValue = 1, int $minValue = 1): int {
        if (!isset($_GET[$paramName])) {
            return $defaultValue;
        }
        
        $value = $_GET[$paramName];
        
        if (!is_numeric($value)) {
            return $defaultValue;
        }
        
        $page = (int)$value;
        return max($minValue, $page);
    }
    
    /**
     * Валидирует значение из списка допустимых значений
     * 
     * @param string $paramName Имя параметра
     * @param array<string> $allowedValues Список допустимых значений
     * @param string|null $defaultValue Значение по умолчанию
     * @return string|null Валидное значение или значение по умолчанию
     * @throws \InvalidArgumentException Если значение не в списке допустимых
     */
    public static function validateEnum(string $paramName, array $allowedValues, ?string $defaultValue = null): ?string {
        if (!isset($_GET[$paramName])) {
            return $defaultValue;
        }
        
        $value = trim((string)$_GET[$paramName]);
        
        if (!in_array($value, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                "Параметр $paramName должен быть одним из: " . implode(', ', $allowedValues)
            );
        }
        
        return $value;
    }
    
    /**
     * Валидирует UUID
     * 
     * @param string $paramName Имя параметра
     * @param bool $required Обязателен ли параметр
     * @return string|null Валидный UUID или null
     * @throws \InvalidArgumentException Если UUID невалиден
     */
    public static function validateUuid(string $paramName = 'uuid', bool $required = false): ?string {
        if (!isset($_GET[$paramName])) {
            if ($required) {
                throw new \InvalidArgumentException("Параметр $paramName обязателен");
            }
            return null;
        }
        
        $value = trim((string)$_GET[$paramName]);
        
        // UUID v4 формат: 8-4-4-4-12 hex символов
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        
        if (!preg_match($uuidPattern, $value)) {
            throw new \InvalidArgumentException("Параметр $paramName должен быть валидным UUID");
        }
        
        return $value;
    }
    
    /**
     * Валидирует поисковый запрос
     * 
     * @param string $paramName Имя параметра (по умолчанию 'q')
     * @param int $minLength Минимальная длина запроса
     * @param int $maxLength Максимальная длина запроса
     * @param bool $required Обязателен ли параметр
     * @return string|null Валидный поисковый запрос или null
     * @throws \InvalidArgumentException Если запрос невалиден
     */
    public static function validateSearchQuery(
        string $paramName = 'q',
        int $minLength = 1,
        int $maxLength = 255,
        bool $required = false
    ): ?string {
        if (!isset($_GET[$paramName])) {
            if ($required) {
                throw new \InvalidArgumentException("Параметр поиска $paramName обязателен");
            }
            return null;
        }
        
        $value = trim((string)$_GET[$paramName]);
        
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException("Параметр поиска $paramName не может быть пустым");
            }
            return null;
        }
        
        $length = mb_strlen($value, 'UTF-8');
        
        if ($length < $minLength) {
            throw new \InvalidArgumentException("Параметр поиска $paramName должен содержать минимум $minLength символов");
        }
        
        if ($length > $maxLength) {
            throw new \InvalidArgumentException("Параметр поиска $paramName слишком длинный (максимум $maxLength символов)");
        }
        
        // Экранируем специальные символы для безопасности
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        
        return $value;
    }
    
    /**
     * Обрабатывает исключения валидации и отправляет ошибку клиенту
     * 
     * @param \Throwable $exception Исключение валидации
     * @return void
     */
    public static function handleValidationException(\Throwable $exception): void {
        OPDSErrorHandler::sendValidationError($exception->getMessage());
    }
}
