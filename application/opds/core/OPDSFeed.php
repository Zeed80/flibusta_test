<?php
declare(strict_types=1);

/**
 * Базовый класс для генерации OPDS 1.2 фидов
 * Абстрактный класс, используется как основа для реализации OPDS2Feed
 */
abstract class OPDSFeed {
    protected ?string $id = null;
    protected ?string $title = null;
    protected string $updated;
    protected ?string $icon = null;
    /** @var OPDSEntry[] */
    protected array $entries = [];
    /** @var OPDSGroup[] */
    protected array $groups = [];
    /** @var OPDSLink[] */
    protected array $links = [];
    /** @var OPDSFacet[] */
    protected array $facets = [];
    protected ?OPDSNavigation $navigation = null;
    /** @var array<string, array<string, string>> */
    protected array $metadata = [];
    
    public function __construct() {
        $this->updated = date('c');
    }
    
    public function setId(string $id): self {
        $this->id = $id;
        return $this;
    }
    
    public function getId(): ?string {
        return $this->id;
    }
    
    public function setTitle(string $title): self {
        // Не нормализуем title для feed, чтобы сохранить оригинальный текст
        // normalize_text_for_opds может удалить кириллицу
        $this->title = $title;
        return $this;
    }
    
    public function getTitle(): ?string {
        return $this->title;
    }
    
    public function setUpdated(string $updated): self {
        $this->updated = $updated;
        return $this;
    }
    
    public function getUpdated(): string {
        return $this->updated;
    }
    
    public function setIcon(string $icon): self {
        $this->icon = $icon;
        return $this;
    }
    
    public function addEntry(OPDSEntry $entry): self {
        $this->entries[] = $entry;
        return $this;
    }
    
    /**
     * Добавляет группу entries (opds:group)
     * 
     * @param OPDSGroup $group Группа для добавления
     * @return OPDSFeed
     */
    public function addGroup(OPDSGroup $group): self {
        $this->groups[] = $group;
        return $this;
    }
    
    /**
     * Получить все группы
     * 
     * @return OPDSGroup[]
     */
    public function getGroups(): array {
        return $this->groups;
    }
    
    public function addLink(OPDSLink $link): self {
        $this->links[] = $link;
        return $this;
    }
    
    public function addFacet(OPDSFacet $facet): self {
        $this->facets[] = $facet;
        return $this;
    }
    
    public function setNavigation(?OPDSNavigation $navigation): self {
        $this->navigation = $navigation;
        return $this;
    }
    
    /**
     * @param string $namespace
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function addMetadata(string $namespace, string $name, string $value): self {
        if (!isset($this->metadata[$namespace])) {
            $this->metadata[$namespace] = [];
        }
        $this->metadata[$namespace][$name] = $value;
        return $this;
    }
    
    /**
     * Рендерит фид в XML
     * 
     * @return string XML строка
     */
    abstract public function render(): string;
    
    /**
     * Получает namespace для OPDS 1.2
     * 
     * @return string Namespace URI
     */
    protected function getNamespace(): string {
        return OPDSVersion::getNamespace();
    }
    
    /**
     * Получает profile для OPDS 1.2
     * 
     * @param string $kind Тип каталога
     * @return string Profile строка
     */
    protected function getProfile(string $kind = 'acquisition'): string {
        return OPDSVersion::getProfile($kind);
    }
}
