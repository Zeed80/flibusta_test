<?php
declare(strict_types=1);

/**
 * Утилита для работы с collations в PostgreSQL
 * Проверяет доступность collation и предоставляет безопасные методы для использования
 */
class OPDSCollation {
    
    private static ?bool $russianCollationAvailable = null;
    
    /**
     * Проверяет, доступен ли collation ru_RU.UTF-8
     * 
     * @param PDO $dbh Подключение к базе данных
     * @return bool true, если collation доступен
     */
    public static function isRussianCollationAvailable(PDO $dbh): bool {
        if (self::$russianCollationAvailable !== null) {
            return self::$russianCollationAvailable;
        }
        
        try {
            $stmt = $dbh->query("
                SELECT EXISTS (
                    SELECT 1 
                    FROM pg_collation 
                    WHERE collname = 'ru_RU.UTF-8'
                ) as exists
            ");
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            self::$russianCollationAvailable = (bool)($result->exists ?? false);
            return self::$russianCollationAvailable;
        } catch (PDOException $e) {
            error_log("OPDSCollation::isRussianCollationAvailable: Error checking collation: " . $e->getMessage());
            self::$russianCollationAvailable = false;
            return false;
        }
    }
    
    /**
     * Возвращает выражение ORDER BY с применением русского collation, если доступен
     * 
     * @param string $expression SQL выражение для сортировки (например, 'b.title')
     * @param PDO|null $dbh Подключение к БД (опционально, для проверки доступности)
     * @param string $direction Направление сортировки (ASC или DESC)
     * @return string SQL выражение с COLLATE или без него
     */
    public static function applyRussianCollation(string $expression, ?PDO $dbh = null, string $direction = 'ASC'): string {
        $collationSuffix = '';
        
        // Если передан $dbh, проверяем доступность collation
        if ($dbh !== null && self::isRussianCollationAvailable($dbh)) {
            $collationSuffix = ' COLLATE "ru_RU.UTF-8"';
        }
        
        // Если direction не пустой, добавляем его
        if ($direction !== '') {
            return $expression . $collationSuffix . ' ' . $direction;
        }
        
        return $expression . $collationSuffix;
    }
    
    /**
     * Применяет русский collation к нескольким полям
     * 
     * @param array<string> $expressions Массив SQL выражений
     * @param PDO|null $dbh Подключение к БД (опционально)
     * @param string $direction Направление сортировки
     * @return string ORDER BY строка с примененным collation
     */
    public static function applyRussianCollationToMultiple(array $expressions, ?PDO $dbh = null, string $direction = 'ASC'): string {
        $result = [];
        $useCollation = ($dbh !== null && self::isRussianCollationAvailable($dbh));
        
        foreach ($expressions as $expr) {
            if ($useCollation) {
                $result[] = $expr . ' COLLATE "ru_RU.UTF-8"';
            } else {
                $result[] = $expr;
            }
        }
        
        return implode(', ', $result) . ' ' . $direction;
    }
}
