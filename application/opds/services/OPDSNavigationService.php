<?php
declare(strict_types=1);

/**
 * Сервис для работы с навигацией в OPDS
 * Предоставляет методы для создания навигационных ссылок и пагинации
 */
class OPDSNavigationService extends OPDSService {
    
    /**
     * Создает объект навигации для пагинации
     * 
     * @param int $currentPage Текущая страница
     * @param int $totalPages Всего страниц
     * @param int $totalItems Всего элементов
     * @param int $itemsPerPage Элементов на странице
     * @param string $baseUrl Базовый URL для навигации
     * @param array $params Дополнительные параметры для URL
     * @return OPDSNavigation
     */
    public function createNavigation(
        int $currentPage,
        int $totalPages,
        int $totalItems,
        int $itemsPerPage,
        string $baseUrl,
        array $params = []
    ): OPDSNavigation {
        return new OPDSNavigation(
            $currentPage,
            $totalPages,
            $totalItems,
            $itemsPerPage,
            $baseUrl,
            $params
        );
    }
    
    /**
     * Добавляет навигационные ссылки для сортировки (opds:sortBy)
     * 
     * @param OPDSFeed $feed Фид для добавления ссылок
     * @param string $baseUrl Базовый URL
     * @param array $currentParams Текущие параметры запроса
     */
    public function addSortByLinks(OPDSFeed $feed, string $baseUrl, array $currentParams = []): void {
        $sorts = [
            'new' => [
                'href' => $baseUrl . '?' . http_build_query(array_merge($currentParams, ['sort' => 'new'])),
                'title' => 'По дате добавления (новые)',
                'rel' => 'http://opds-spec.org/sort/new'
            ],
            'title' => [
                'href' => $baseUrl . '?' . http_build_query(array_merge($currentParams, ['sort' => 'title'])),
                'title' => 'По названию (алфавит)',
                'rel' => 'http://opds-spec.org/sort'
            ],
            'author' => [
                'href' => $baseUrl . '?' . http_build_query(array_merge($currentParams, ['sort' => 'author'])),
                'title' => 'По автору',
                'rel' => 'http://opds-spec.org/sort'
            ],
            'year' => [
                'href' => $baseUrl . '?' . http_build_query(array_merge($currentParams, ['sort' => 'year'])),
                'title' => 'По году издания',
                'rel' => 'http://opds-spec.org/sort'
            ],
        ];
        
        foreach ($sorts as $sort) {
            $feed->addLink(new OPDSLink(
                $sort['href'],
                $sort['rel'],
                OPDSVersion::getProfile('acquisition'),
                $sort['title']
            ));
        }
    }
    
    /**
     * Получить правильный ORDER BY для сортировки с русским алфавитом
     * 
     * @param string $sortType Тип сортировки: 'new', 'title', 'author', 'year'
     * @param bool $ascending Сортировка по возрастанию (true) или убыванию (false)
     * @return string SQL выражение для ORDER BY
     */
    public function getOrderByForSort(string $sortType, bool $ascending = false): string {
        $direction = $ascending ? 'ASC' : 'DESC';
        
        // Используем OPDSCollation для безопасного применения русского collation
        $useCollation = class_exists('OPDSCollation') && OPDSCollation::isRussianCollationAvailable($this->dbh);
        
        switch ($sortType) {
            case 'title':
                // Сортировка по названию с русским collation, если доступен
                if ($useCollation) {
                    return OPDSCollation::applyRussianCollation("b.title", $this->dbh, $direction);
                }
                return "b.title $direction";
                
            case 'author':
                // Сортировка по автору с русским collation, если доступен
                if ($useCollation) {
                    return OPDSCollation::applyRussianCollationToMultiple(
                        ['lastname', 'firstname'], 
                        $this->dbh, 
                        $direction
                    );
                }
                return "lastname $direction, firstname $direction";
                
            case 'year':
                // Сортировка по году издания
                return "b.year $direction";
                
            case 'new':
            default:
                // Сортировка по дате добавления (новые сначала)
                return "b.time DESC";
        }
    }
}
