<?php
/**
 * Улучшенный класс для кэширования OPDS фидов с поддержкой инвалидации
 * и интеграцией с обновлением базы данных
 * Использует singleton паттерн для предотвращения ошибок с глобальной областью видимости
 */
class OPDSCache {
    private static $instance = null;
    protected $cacheDir;
    protected $ttl;
    protected $enabled;
    protected $dbName = 'flibusta';
    
    // Singleton паттерн - запрещаем прямое создание экземпляров
    private function __construct($cacheDir = null, $ttl = 3600, $enabled = true) {
        if (self::$instance !== null) {
            self::$instance = new self($cacheDir, $ttl, $enabled);
        }
    }
    
    /**
     * Получает единственный экземпляр класса (Singleton)
     * 
     * @return OPDSCache Единственный экземпляр
     */
    public static function getInstance($cacheDir = null, $ttl = 3600, $enabled = true) {
        if (self::$instance === null) {
            self::$instance = new self($cacheDir, $ttl, $enabled);
        }
        return self::$instance;
    }
    
    /**
     * Конструктор (приватный для singleton)
     */
    
    /**
     * Проверяет, включено ли кэширование
     */
    public function isEnabled() {
        return $this->enabled;
    }
    
    /**
     * Получает ключ кэша из параметров
     */
    protected function getCacheKey($params) {
        // Добавляем версию OPDS к ключу для разных форматов
        if (isset($params['version'])) {
            $params['_opds_version'] = $params['version'];
        }
        return md5(serialize($params));
    }
    
    /**
     * Получает путь к файлу кэша
     */
    protected function getCacheFile($key) {
        return $this->cacheDir . $key . '.xml';
    }
    
    /**
     * Получает путь к файлу метаданных кэша (для инвалидации)
     */
    protected function getMetaFile() {
        return $this->cacheDir . '.metadata';
    }
    
    /**
     * Получает хеш последнего обновления базы данных
     */
    protected function getDbHash() {
        global $dbh;
        try {
            $stmt = $dbh->query("SELECT MAX(time) as max_time FROM libbook");
            $result = $stmt->fetch();
            return $result ? md5($result->max_time) : null;
        } catch (Exception $e) {
            error_log("Ошибка получения хеша БД: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Сохраняет метаданные кэша (хеш БД, время создания)
     */
    protected function saveMetadata($dbHash = null) {
        $metaFile = $this->getMetaFile();
        $data = [
            'db_hash' => $dbHash ?: $this->getDbHash(),
            'created_at' => time(),
            'version' => '1.0'
        ];
        file_put_contents($metaFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Получает метаданные кэша
     */
    protected function getMetadata() {
        $metaFile = $this->getMetaFile();
        if (!file_exists($metaFile)) {
            return null;
        }
        $content = file_get_contents($metaFile);
        return json_decode($content, true);
    }
    
    /**
     * Проверяет, действителен ли кэш с учетом обновления БД
     */
    public function isValid($key) {
        if (!$this->enabled) {
            return false;
        }
        
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return false;
        }
        
        $age = time() - filemtime($file);
        if ($age >= $this->ttl) {
            return false;
        }
        
        // Проверяем, что БД не обновлялась после создания кэша
        $metadata = $this->getMetadata();
        if ($metadata && isset($metadata['db_hash'])) {
            $currentDbHash = $this->getDbHash();
            if ($currentDbHash && $currentDbHash !== $metadata['db_hash']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Получает данные из кэша
     */
    public function get($key) {
        if (!$this->isValid($key)) {
            return null;
        }
        
        $file = $this->getCacheFile($key);
        return file_get_contents($file);
    }
    
    /**
     * Сохраняет данные в кэш
     */
    public function set($key, $data) {
        if (!$this->enabled) {
            return false;
        }
        
        $file = $this->getCacheFile($key);
        $result = file_put_contents($file, $data);
        
        if ($result !== false) {
            // Сохраняем метаданные при успешном создании кэша
            $this->saveMetadata();
            return true;
        }
        
        return false;
    }
    
    /**
     * Удаляет кэш
     */
    public function delete($key) {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    /**
     * Очищает весь кэш
     */
    public function clear() {
        if (!$this->enabled) {
            return 0;
        }
        
        $count = 0;
        $files = glob($this->cacheDir . '*.xml');
        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }
        
        // Также удаляем метаданные
        $metaFile = $this->getMetaFile();
        if (file_exists($metaFile)) {
            unlink($metaFile);
        }
        
        error_log("Очищен OPDS кэш: удалено $count файлов");
        return $count;
    }
    
    /**
     * Инвалидирует кэш при обновлении базы данных
     */
    public function invalidateOnDbUpdate() {
        if (!$this->enabled) {
            return 0;
        }
        
        $oldMetadata = $this->getMetadata();
        $currentDbHash = $this->getDbHash();
        
        // Если хеш БД изменился, очищаем кэш
        if ($oldMetadata && isset($oldMetadata['db_hash']) && 
            $currentDbHash && $currentDbHash !== $oldMetadata['db_hash']) {
            
            $count = $this->clear();
            $this->saveMetadata($currentDbHash);
            
            error_log("OPDS кэш инвалидирован: хеш БД изменен, удалено $count файлов");
            return $count;
        }
        
        // Обновляем метаданные если их нет или они устарели
        if (!$oldMetadata || (time() - $oldMetadata['created_at']) > 3600) {
            $this->saveMetadata($currentDbHash);
        }
        
        return 0;
    }
    
    /**
     * Инвалидирует кэш по шаблону ключей
     */
    public function invalidatePattern($pattern) {
        if (!$this->enabled) {
            return 0;
        }
        
        $count = 0;
        $files = glob($this->cacheDir . '*.xml');
        foreach ($files as $file) {
            if (fnmatch($pattern, basename($file))) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }
        
        if ($count > 0) {
            error_log("Очищено $count файлов OPDS кэша по шаблону: $pattern");
        }
        
        return $count;
    }
    
    /**
     * Инвалидирует кэш для конкретного фида
     */
    public function invalidateFeed($feedName) {
        return $this->invalidatePattern($feedName . '*.xml');
    }
    
    /**
     * Генерирует ETag для контента
     */
    public function generateETag($content) {
        return md5($content);
    }
    
    /**
     * Проверяет ETag и возвращает 304 если контент не изменился
     */
    public function checkETag($etag) {
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=' . $this->ttl);
            exit;
        }
    }
    
    /**
     * Устанавливает заголовки кэширования
     */
    public function setCacheHeaders($etag, $lastModified = null) {
        header('ETag: ' . $etag);
        if ($lastModified) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }
        header('Cache-Control: public, max-age=' . $this->ttl);
        header('Pragma: public');
    }
    
    /**
     * Получает статистику кэша
     */
    public function getStats() {
        if (!$this->enabled) {
            return null;
        }
        
        $files = glob($this->cacheDir . '*.xml');
        $totalSize = 0;
        $fileCount = count($files);
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        $metadata = $this->getMetadata();
        
        return [
            'file_count' => $fileCount,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'metadata' => $metadata,
            'cache_dir' => $this->cacheDir
        ];
    }
    
    /**
     * Форматирует размер в читаемый вид
     */
    protected function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
