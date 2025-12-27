<?php
declare(strict_types=1);

/**
 * Сервис для работы с книгами в OPDS
 * Предоставляет методы для получения книг из БД и создания OPDS entries
 */
class OPDSBookService extends OPDSService {
    
    /**
     * Получить книгу по ID
     * 
     * @param int $bookId ID книги
     * @return object|null Объект книги или null, если не найдена
     */
    public function getBookById(int $bookId): ?object {
        try {
            $stmt = $this->dbh->prepare("SELECT * FROM libbook WHERE BookId = :id AND deleted = '0' LIMIT 1");
            $stmt->bindParam(':id', $bookId, PDO::PARAM_INT);
            $stmt->execute();
            $book = $stmt->fetch(PDO::FETCH_OBJ);
            return $book ?: null;
        } catch (PDOException $e) {
            error_log("OPDSBookService::getBookById: SQL error for book $bookId: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Создает OPDS entry для книги
     * Использует существующую функцию opds_book_entry для совместимости
     * 
     * @param object $book Объект книги из БД
     * @return OPDSEntry|null
     */
    public function createBookEntry(object $book): ?OPDSEntry {
        // Используем существующую функцию для сохранения совместимости
        if (function_exists('opds_book_entry')) {
            return opds_book_entry($book, $this->webroot);
        }
        
        error_log("OPDSBookService::createBookEntry: opds_book_entry function not found");
        return null;
    }
    
    /**
     * Получить книги с применением фильтров и сортировки
     * 
     * @param array $filters Массив фильтров (genre_id, seq_id, author_id, lang, format и т.д.)
     * @param string $orderBy Поле для сортировки
     * @param int $limit Лимит записей
     * @param int $offset Смещение для пагинации
     * @return array Массив объектов книг
     */
    public function getBooks(array $filters = [], string $orderBy = 'time DESC', int $limit = 100, int $offset = 0): array {
        try {
            $where = ["b.deleted = '0'"];
            $join = '';
            $params = [];
            
            // Применяем фильтры
            if (isset($filters['genre_id']) && $filters['genre_id'] > 0) {
                $where[] = 'g.genreid = :genre_id';
                $join .= 'LEFT JOIN libgenre g ON g.BookId = b.BookId ';
                $params['genre_id'] = (int)$filters['genre_id'];
            }
            
            if (isset($filters['seq_id']) && $filters['seq_id'] > 0) {
                $where[] = 's.seqid = :seq_id';
                $join .= 'LEFT JOIN libseq s ON s.BookId = b.BookId ';
                $params['seq_id'] = (int)$filters['seq_id'];
            }
            
            if (isset($filters['author_id']) && $filters['author_id'] > 0) {
                $where[] = 'a.avtorid = :author_id';
                $join .= 'JOIN libavtor a ON a.BookId = b.BookId ';
                $params['author_id'] = (int)$filters['author_id'];
            }
            
            if (isset($filters['lang']) && !empty($filters['lang'])) {
                $where[] = 'b.lang = :lang';
                $params['lang'] = $filters['lang'];
            }
            
            if (isset($filters['format']) && !empty($filters['format'])) {
                $where[] = 'b.filetype = :format';
                $params['format'] = $filters['format'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Применяем правильную сортировку с русским алфавитом
            $orderByClause = $this->applyRussianCollation($orderBy);
            
            $query = "SELECT DISTINCT b.* 
                      FROM libbook b 
                      $join 
                      WHERE $whereClause 
                      ORDER BY $orderByClause 
                      LIMIT :limit OFFSET :offset";
            
            $stmt = $this->dbh->prepare($query);
            
            // Биндим параметры
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $books = [];
            while ($book = $stmt->fetch(PDO::FETCH_OBJ)) {
                $books[] = $book;
            }
            
            return $books;
        } catch (PDOException $e) {
            error_log("OPDSBookService::getBooks: SQL error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Подсчитать общее количество книг с применением фильтров
     * 
     * @param array $filters Массив фильтров
     * @return int Количество книг
     */
    public function countBooks(array $filters = []): int {
        try {
            $where = ["b.deleted = '0'"];
            $join = '';
            $params = [];
            
            // Применяем те же фильтры, что и в getBooks
            if (isset($filters['genre_id']) && $filters['genre_id'] > 0) {
                $where[] = 'g.genreid = :genre_id';
                $join .= 'LEFT JOIN libgenre g ON g.BookId = b.BookId ';
                $params['genre_id'] = (int)$filters['genre_id'];
            }
            
            if (isset($filters['seq_id']) && $filters['seq_id'] > 0) {
                $where[] = 's.seqid = :seq_id';
                $join .= 'LEFT JOIN libseq s ON s.BookId = b.BookId ';
                $params['seq_id'] = (int)$filters['seq_id'];
            }
            
            if (isset($filters['author_id']) && $filters['author_id'] > 0) {
                $where[] = 'a.avtorid = :author_id';
                $join .= 'JOIN libavtor a ON a.BookId = b.BookId ';
                $params['author_id'] = (int)$filters['author_id'];
            }
            
            if (isset($filters['lang']) && !empty($filters['lang'])) {
                $where[] = 'b.lang = :lang';
                $params['lang'] = $filters['lang'];
            }
            
            if (isset($filters['format']) && !empty($filters['format'])) {
                $where[] = 'b.filetype = :format';
                $params['format'] = $filters['format'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $query = "SELECT COUNT(DISTINCT b.BookId) as cnt 
                      FROM libbook b 
                      $join 
                      WHERE $whereClause";
            
            $stmt = $this->dbh->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            
            return $result ? (int)$result->cnt : 0;
        } catch (PDOException $e) {
            error_log("OPDSBookService::countBooks: SQL error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Применяет русскую локализацию к ORDER BY запросу
     * Использует COLLATE для правильной сортировки с приоритетом кириллицы
     * 
     * @param string $orderBy Исходное выражение ORDER BY
     * @return string ORDER BY с примененным COLLATE
     */
    protected function applyRussianCollation(string $orderBy): string {
        // Возвращаем ORDER BY без изменений
        // Используется collation базы данных по умолчанию
        // Если нужна специальная сортировка, можно создать collation в БД
        return $orderBy;
    }
}
