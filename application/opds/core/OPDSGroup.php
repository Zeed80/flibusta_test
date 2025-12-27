<?php
declare(strict_types=1);

require_once(__DIR__ . '/OPDSEntry.php');

/**
 * Класс для группировки записей в OPDS 1.2 фидах (opds:group)
 * Используется для группировки entries по какому-либо признаку (автор, год и т.д.)
 */
class OPDSGroup {
    protected string $title;
    /** @var OPDSEntry[] */
    protected array $entries = [];
    
    /**
     * Конструктор
     * 
     * @param string $title Заголовок группы
     */
    public function __construct(string $title) {
        $this->title = $title;
    }
    
    /**
     * Добавляет entry в группу
     * 
     * @param OPDSEntry $entry Entry для добавления
     * @return OPDSGroup
     */
    public function addEntry(OPDSEntry $entry): OPDSGroup {
        $this->entries[] = $entry;
        return $this;
    }
    
    /**
     * Получить заголовок группы
     * 
     * @return string
     */
    public function getTitle(): string {
        return $this->title;
    }
    
    /**
     * Получить все entries в группе
     * 
     * @return array Массив OPDSEntry
     */
    public function getEntries(): array {
        return $this->entries;
    }
    
    /**
     * Рендерит группу в XML (OPDS 1.2)
     * 
     * @return string XML строка
     */
    public function render(): string {
        if (empty($this->entries)) {
            return '';
        }
        
        $xml = "\n <opds:group>";
        $xml .= "\n  <opds:title>" . htmlspecialchars($this->title, ENT_XML1, 'UTF-8') . "</opds:title>";
        
        foreach ($this->entries as $entry) {
            $xml .= "\n" . $entry->render();
        }
        
        $xml .= "\n </opds:group>";
        return $xml;
    }
}
