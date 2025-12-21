<?php
/**
 * Класс для кэширования OPDS фидов
 */
class OPDSCache {
    protected $cacheDir;
    protected $ttl;
    
    public function __construct($cacheDir = null, $ttl = 3600) {
        $this->cacheDir = $cacheDir ?: ROOT_PATH . 'cache/opds/';
        $this->ttl = $ttl;
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Получает ключ кэша из параметров
     */
    protected function getCacheKey($params) {
        return md5(serialize($params));
    }
    
    /**
     * Получает путь к файлу кэша
     */
    protected function getCacheFile($key) {
        return $this->cacheDir . $key . '.xml';
    }
    
    /**
     * Проверяет, действителен ли кэш
     */
    public function isValid($key) {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return false;
        }
        
        $age = time() - filemtime($file);
        return $age < $this->ttl;
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
        $file = $this->getCacheFile($key);
        file_put_contents($file, $data);
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
        $files = glob($this->cacheDir . '*.xml');
        foreach ($files as $file) {
            unlink($file);
        }
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
    }
}
