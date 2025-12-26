/**
 * OPDS Viewer - Веб-клиент для просмотра OPDS каталогов
 * Поддерживает полную работу с кириллицей (UTF-8)
 */
class OpdsViewer {
    constructor(containerId, baseUrl) {
        this.container = document.getElementById(containerId);
        this.baseUrl = baseUrl || '';
        this.opdsBaseUrl = this.baseUrl + '/opds';
        this.currentUrl = this.opdsBaseUrl + '/';
        this.history = [];
        this.currentFeed = null;
    }

    /**
     * Инициализация клиента
     */
    init() {
        // Загружаем главную страницу каталога
        this.loadFeed(this.currentUrl);
        
        // Обработчики событий
        document.getElementById('opds-viewer-back').addEventListener('click', () => this.goBack());
        document.getElementById('opds-viewer-home').addEventListener('click', () => this.goHome());
        document.getElementById('opds-search-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleSearch();
        });
    }

    /**
     * Декодирование HTML entities для правильного отображения кириллицы
     * @param {string} text - Текст с HTML entities
     * @returns {string} - Декодированный текст
     */
    decodeHtmlEntities(text) {
        if (!text) return '';
        const textarea = document.createElement('textarea');
        textarea.innerHTML = text;
        return textarea.value;
    }

    /**
     * Загрузка OPDS фида
     * @param {string} url - URL фида
     */
    async loadFeed(url) {
        this.showLoading();
        this.hideError();
        
        try {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/atom+xml; charset=utf-8'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const text = await response.text(); // Автоматически декодирует UTF-8
            const feedData = this.parseFeed(text);
            this.currentFeed = feedData;
            this.currentUrl = url;
            this.render(feedData);
            
            // Обновляем историю
            if (this.history.length === 0 || this.history[this.history.length - 1] !== url) {
                this.history.push(url);
            }
            
            this.updateNavigationButtons();
        } catch (error) {
            console.error('Ошибка загрузки фида:', error);
            this.showError('Ошибка загрузки каталога: ' + error.message);
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Парсинг OPDS XML фида
     * @param {string} xmlString - XML строка
     * @returns {Object} - Распарсенные данные фида
     */
    parseFeed(xmlString) {
        const parser = new DOMParser();
        const xmlDoc = parser.parseFromString(xmlString, 'text/xml');
        
        // Проверка на ошибки парсинга
        const parserError = xmlDoc.querySelector('parsererror');
        if (parserError) {
            throw new Error('Ошибка парсинга XML: ' + parserError.textContent);
        }
        
        const feed = xmlDoc.querySelector('feed');
        if (!feed) {
            throw new Error('Не найден элемент feed в XML');
        }
        
        const feedData = {
            id: this.getTextContent(feed, 'id'),
            title: this.getTextContent(feed, 'title'),
            subtitle: this.getTextContent(feed, 'subtitle'),
            updated: this.getTextContent(feed, 'updated'),
            icon: this.getTextContent(feed, 'icon'),
            links: this.parseLinks(feed),
            entries: this.parseEntries(feed),
            facets: this.parseFacets(feed),
            navigation: this.parseNavigation(feed)
        };
        
        return feedData;
    }

    /**
     * Получение текстового содержимого элемента
     * @param {Element} parent - Родительский элемент
     * @param {string} tagName - Имя тега
     * @returns {string} - Текстовое содержимое
     */
    getTextContent(parent, tagName) {
        if (!parent) return '';
        
        // Пробуем querySelector
        if (parent.querySelector) {
            const element = parent.querySelector(tagName);
            if (element) {
                // textContent автоматически обрабатывает UTF-8
                return element.textContent.trim();
            }
        }
        
        // Fallback: поиск по localName
        if (parent.getElementsByTagName) {
            const elements = parent.getElementsByTagName(tagName);
            if (elements.length > 0) {
                return elements[0].textContent.trim();
            }
        }
        
        return '';
    }

    /**
     * Парсинг ссылок
     * @param {Element} feed - Элемент feed
     * @returns {Array} - Массив ссылок
     */
    parseLinks(feed) {
        const links = [];
        const linkElements = feed.querySelectorAll('link');
        
        linkElements.forEach(link => {
            links.push({
                href: link.getAttribute('href') || '',
                rel: link.getAttribute('rel') || 'alternate',
                type: link.getAttribute('type') || '',
                title: link.getAttribute('title') || ''
            });
        });
        
        return links;
    }

    /**
     * Парсинг записей
     * @param {Element} feed - Элемент feed
     * @returns {Array} - Массив записей
     */
    parseEntries(feed) {
        const entries = [];
        const entryElements = feed.querySelectorAll('entry');
        
        entryElements.forEach(entry => {
            const entryData = {
                id: this.getTextContent(entry, 'id'),
                title: this.getTextContent(entry, 'title'),
                updated: this.getTextContent(entry, 'updated'),
                summary: this.getTextContent(entry, 'summary'),
                summaryType: entry.querySelector('summary')?.getAttribute('type') || 'text',
                content: this.getTextContent(entry, 'content'),
                contentType: entry.querySelector('content')?.getAttribute('type') || 'text',
                authors: this.parseAuthors(entry),
                categories: this.parseCategories(entry),
                links: this.parseLinks(entry),
                metadata: this.parseMetadata(entry)
            };
            
            entries.push(entryData);
        });
        
        return entries;
    }

    /**
     * Парсинг авторов
     * @param {Element} entry - Элемент entry
     * @returns {Array} - Массив авторов
     */
    parseAuthors(entry) {
        const authors = [];
        const authorElements = entry.querySelectorAll('author');
        
        authorElements.forEach(author => {
            authors.push({
                name: this.getTextContent(author, 'name'),
                uri: this.getTextContent(author, 'uri')
            });
        });
        
        return authors;
    }

    /**
     * Парсинг категорий
     * @param {Element} entry - Элемент entry
     * @returns {Array} - Массив категорий
     */
    parseCategories(entry) {
        const categories = [];
        const categoryElements = entry.querySelectorAll('category');
        
        categoryElements.forEach(category => {
            categories.push({
                term: category.getAttribute('term') || '',
                label: category.getAttribute('label') || '',
                scheme: category.getAttribute('scheme') || ''
            });
        });
        
        return categories;
    }

    /**
     * Парсинг метаданных (dc:, opds: и т.д.)
     * @param {Element} entry - Элемент entry
     * @returns {Object} - Метаданные
     */
    parseMetadata(entry) {
        const metadata = {};
        const allElements = entry.querySelectorAll('*');
        
        allElements.forEach(element => {
            const tagName = element.tagName;
            if (tagName.includes(':')) {
                const [namespace, name] = tagName.split(':');
                if (!metadata[namespace]) {
                    metadata[namespace] = {};
                }
                metadata[namespace][name] = element.textContent.trim();
            }
        });
        
        return metadata;
    }

    /**
     * Парсинг фасетов (фильтров)
     * @param {Element} feed - Элемент feed
     * @returns {Array} - Массив фасетов
     */
    parseFacets(feed) {
        const facets = [];
        
        // Перебираем все элементы для поиска фасетов
        // querySelector не всегда работает с namespace префиксами, поэтому используем getElementsByTagNameNS или перебор
        const allElements = feed.getElementsByTagName('*');
        const facetGroupsMap = new Map();
        
        for (let i = 0; i < allElements.length; i++) {
            const element = allElements[i];
            const localName = element.localName || element.tagName.split(':').pop();
            
            // Ищем opds:facetGroup
            if (localName === 'facetGroup') {
                // Получаем заголовок группы из opds:title
                const titleElement = this.findChildByLocalName(element, 'title');
                const groupTitle = titleElement ? this.getTextContent({querySelector: () => titleElement}, '') : '';
                const groupKey = groupTitle || 'default';
                
                if (!facetGroupsMap.has(groupKey)) {
                    facetGroupsMap.set(groupKey, {
                        title: groupTitle,
                        facets: []
                    });
                }
                
                // Ищем дочерние элементы opds:facet
                const children = element.children || element.childNodes;
                for (let j = 0; j < children.length; j++) {
                    const child = children[j];
                    const childLocalName = child.localName || (child.tagName ? child.tagName.split(':').pop() : '');
                    
                    if (childLocalName === 'facet') {
                        // Получаем title из opds:title
                        const facetTitleElement = this.findChildByLocalName(child, 'title');
                        const facetTitle = facetTitleElement ? facetTitleElement.textContent.trim() : '';
                        
                        // Получаем href из opds:link
                        const facetLinkElement = this.findChildByLocalName(child, 'link');
                        const facetHref = facetLinkElement ? (facetLinkElement.getAttribute('href') || '') : '';
                        
                        const active = child.getAttribute('opds:active') === 'true' || child.getAttribute('active') === 'true';
                        const count = parseInt(child.getAttribute('opds:count') || child.getAttribute('count') || '0');
                        
                        facetGroupsMap.get(groupKey).facets.push({
                            title: facetTitle,
                            count: count,
                            href: facetHref,
                            active: active
                        });
                    }
                }
            }
        }
        
        // Преобразуем Map в массив
        facetGroupsMap.forEach((group) => {
            if (group.facets.length > 0) {
                facets.push(group);
            }
        });
        
        return facets;
    }
    
    /**
     * Поиск дочернего элемента по localName
     * @param {Element} parent - Родительский элемент
     * @param {string} localName - Local name искомого элемента
     * @returns {Element|null} - Найденный элемент или null
     */
    findChildByLocalName(parent, localName) {
        const children = parent.children || parent.childNodes;
        for (let i = 0; i < children.length; i++) {
            const child = children[i];
            if (child.nodeType === 1) { // ELEMENT_NODE
                const childLocalName = child.localName || (child.tagName ? child.tagName.split(':').pop() : '');
                if (childLocalName === localName) {
                    return child;
                }
            }
        }
        return null;
    }

    /**
     * Парсинг навигации
     * @param {Element} feed - Элемент feed
     * @returns {Object} - Данные навигации
     */
    parseNavigation(feed) {
        const navigation = {
            first: null,
            previous: null,
            next: null,
            last: null
        };
        
        const links = this.parseLinks(feed);
        links.forEach(link => {
            if (link.rel === 'first') navigation.first = link.href;
            if (link.rel === 'previous') navigation.previous = link.href;
            if (link.rel === 'next') navigation.next = link.href;
            if (link.rel === 'last') navigation.last = link.href;
        });
        
        return navigation;
    }

    /**
     * Рендеринг фида
     * @param {Object} feedData - Данные фида
     */
    render(feedData) {
        const contentDiv = document.getElementById('opds-viewer-content');
        contentDiv.innerHTML = '';
        
        // Рендерим breadcrumbs
        this.renderBreadcrumbs(feedData);
        
        // Рендерим навигационные ссылки
        if (feedData.links.length > 0 && feedData.entries.length === 0) {
            this.renderNavigationLinks(feedData.links, contentDiv);
        }
        
        // Рендерим фасеты (фильтры)
        if (feedData.facets.length > 0) {
            this.renderFacets(feedData.facets, contentDiv);
        }
        
        // Рендерим записи
        if (feedData.entries.length > 0) {
            this.renderEntries(feedData.entries, contentDiv);
        }
        
        // Рендерим пагинацию
        this.renderPagination(feedData.navigation);
    }

    /**
     * Рендеринг breadcrumbs
     * @param {Object} feedData - Данные фида
     */
    renderBreadcrumbs(feedData) {
        const breadcrumbsDiv = document.getElementById('opds-viewer-breadcrumbs');
        breadcrumbsDiv.innerHTML = '';
        
        const breadcrumbs = document.createElement('nav');
        breadcrumbs.setAttribute('aria-label', 'breadcrumb');
        breadcrumbs.className = 'breadcrumb mb-3';
        
        // Главная
        const homeCrumb = document.createElement('li');
        homeCrumb.className = 'breadcrumb-item';
        const homeLink = document.createElement('a');
        homeLink.href = '#';
        homeLink.textContent = 'Главная';
        homeLink.addEventListener('click', (e) => {
            e.preventDefault();
            this.goHome();
        });
        homeCrumb.appendChild(homeLink);
        breadcrumbs.appendChild(homeCrumb);
        
        // Текущая страница
        if (feedData.title) {
            const currentCrumb = document.createElement('li');
            currentCrumb.className = 'breadcrumb-item active';
            currentCrumb.setAttribute('aria-current', 'page');
            currentCrumb.textContent = feedData.title;
            breadcrumbs.appendChild(currentCrumb);
        }
        
        breadcrumbsDiv.appendChild(breadcrumbs);
    }

    /**
     * Рендеринг навигационных ссылок
     * @param {Array} links - Массив ссылок
     * @param {Element} container - Контейнер
     */
    renderNavigationLinks(links, container) {
        const linksDiv = document.createElement('div');
        linksDiv.className = 'opds-navigation-links row';
        
        links.forEach(link => {
            if (link.rel === 'self' || link.rel === 'alternate') return;
            
            const linkCard = document.createElement('div');
            linkCard.className = 'col-md-6 col-lg-4 mb-3';
            
            const card = document.createElement('div');
            card.className = 'card h-100';
            
            const cardBody = document.createElement('div');
            cardBody.className = 'card-body';
            
            const title = document.createElement('h5');
            title.className = 'card-title';
            title.textContent = link.title || this.getLinkRelLabel(link.rel);
            
            const linkBtn = document.createElement('a');
            linkBtn.href = '#';
            linkBtn.className = 'btn btn-primary';
            linkBtn.textContent = 'Открыть';
            linkBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.loadFeed(this.resolveUrl(link.href));
            });
            
            cardBody.appendChild(title);
            cardBody.appendChild(linkBtn);
            card.appendChild(cardBody);
            linkCard.appendChild(card);
            linksDiv.appendChild(linkCard);
        });
        
        container.appendChild(linksDiv);
    }

    /**
     * Получение метки для типа ссылки
     * @param {string} rel - Тип ссылки
     * @returns {string} - Метка
     */
    getLinkRelLabel(rel) {
        const labels = {
            'start': 'Главная',
            'subsection': 'Подраздел',
            'related': 'Связанное',
            'collection': 'Коллекция',
            'http://opds-spec.org/crawlable': 'Обходной',
            'http://opds-spec.org/sort/new': 'Новинки',
            'http://opds-spec.org/sort/popular': 'Популярные'
        };
        return labels[rel] || rel;
    }

    /**
     * Рендеринг фасетов (фильтров)
     * @param {Array} facets - Массив фасетов
     * @param {Element} container - Контейнер
     */
    renderFacets(facets, container) {
        const facetsDiv = document.createElement('div');
        facetsDiv.className = 'opds-facets mb-4';
        
        facets.forEach(group => {
            const groupDiv = document.createElement('div');
            groupDiv.className = 'opds-facet-group mb-3';
            
            const title = document.createElement('h6');
            title.textContent = group.title;
            groupDiv.appendChild(title);
            
            const facetList = document.createElement('div');
            facetList.className = 'btn-group flex-wrap';
            facetList.setAttribute('role', 'group');
            
            group.facets.forEach(facet => {
                const facetBtn = document.createElement('button');
                facetBtn.type = 'button';
                facetBtn.className = 'btn btn-sm ' + (facet.active ? 'btn-primary' : 'btn-outline-secondary');
                facetBtn.textContent = `${facet.title} (${facet.count})`;
                facetBtn.addEventListener('click', () => {
                    this.loadFeed(this.resolveUrl(facet.href));
                });
                facetList.appendChild(facetBtn);
            });
            
            groupDiv.appendChild(facetList);
            facetsDiv.appendChild(groupDiv);
        });
        
        container.appendChild(facetsDiv);
    }

    /**
     * Рендеринг записей
     * @param {Array} entries - Массив записей
     * @param {Element} container - Контейнер
     */
    renderEntries(entries, container) {
        const entriesDiv = document.createElement('div');
        entriesDiv.className = 'opds-entries row';
        
        entries.forEach(entry => {
            const entryCard = this.renderEntry(entry);
            entriesDiv.appendChild(entryCard);
        });
        
        container.appendChild(entriesDiv);
    }

    /**
     * Рендеринг одной записи
     * @param {Object} entry - Данные записи
     * @returns {Element} - DOM элемент
     */
    renderEntry(entry) {
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4 mb-4';
        
        const card = document.createElement('div');
        card.className = 'card h-100 opds-entry-card';
        
        // Обложка
        const coverLink = entry.links.find(l => 
            l.rel === 'http://opds-spec.org/image' || 
            l.rel === 'http://opds-spec.org/image/thumbnail'
        );
        
        if (coverLink) {
            const coverImg = document.createElement('img');
            coverImg.className = 'card-img-top opds-entry-cover';
            coverImg.src = this.resolveUrl(coverLink.href);
            coverImg.alt = entry.title || '';
            coverImg.style.maxHeight = '300px';
            coverImg.style.objectFit = 'contain';
            coverImg.onerror = function() {
                this.style.display = 'none';
            };
            card.appendChild(coverImg);
        }
        
        const cardBody = document.createElement('div');
        cardBody.className = 'card-body';
        
        // Заголовок
        const title = document.createElement('h5');
        title.className = 'card-title';
        title.textContent = entry.title || 'Без названия';
        cardBody.appendChild(title);
        
        // Авторы
        if (entry.authors.length > 0) {
            const authors = document.createElement('p');
            authors.className = 'card-text text-muted';
            authors.textContent = 'Автор: ' + entry.authors.map(a => a.name).join(', ');
            cardBody.appendChild(authors);
        }
        
        // Краткое описание
        if (entry.summary) {
            const summary = document.createElement('p');
            summary.className = 'card-text opds-entry-summary';
            if (entry.summaryType === 'html') {
                summary.innerHTML = this.decodeHtmlEntities(entry.summary);
            } else {
                summary.textContent = entry.summary;
            }
            // Ограничиваем длину
            if (summary.textContent.length > 200) {
                summary.textContent = summary.textContent.substring(0, 200) + '...';
            }
            cardBody.appendChild(summary);
        }
        
        // Категории (жанры)
        if (entry.categories.length > 0) {
            const categories = document.createElement('div');
            categories.className = 'mb-2';
            entry.categories.slice(0, 3).forEach(cat => {
                const badge = document.createElement('span');
                badge.className = 'badge bg-secondary me-1';
                badge.textContent = cat.label || cat.term;
                categories.appendChild(badge);
            });
            cardBody.appendChild(categories);
        }
        
        // Ссылки (скачивание, просмотр)
        const linksDiv = document.createElement('div');
        linksDiv.className = 'opds-entry-links';
        
        const acquisitionLinks = entry.links.filter(l => 
            l.rel === 'http://opds-spec.org/acquisition' ||
            l.rel === 'http://opds-spec.org/acquisition/open-access'
        );
        
        if (acquisitionLinks.length > 0) {
            const downloadBtn = document.createElement('a');
            downloadBtn.href = this.resolveUrl(acquisitionLinks[0].href);
            downloadBtn.className = 'btn btn-primary btn-sm';
            downloadBtn.textContent = 'Скачать';
            downloadBtn.target = '_blank';
            linksDiv.appendChild(downloadBtn);
        }
        
        // Ссылка на детали
        const detailsLink = entry.links.find(l => l.rel === 'alternate' || l.rel === 'self');
        if (detailsLink) {
            const detailsBtn = document.createElement('button');
            detailsBtn.className = 'btn btn-outline-secondary btn-sm ms-2';
            detailsBtn.textContent = 'Подробнее';
            detailsBtn.addEventListener('click', () => {
                this.showEntryDetails(entry);
            });
            linksDiv.appendChild(detailsBtn);
        }
        
        cardBody.appendChild(linksDiv);
        card.appendChild(cardBody);
        col.appendChild(card);
        
        return col;
    }

    /**
     * Показ детальной информации о записи
     * @param {Object} entry - Данные записи
     */
    showEntryDetails(entry) {
        // Создаем модальное окно
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'opds-entry-modal';
        modal.setAttribute('tabindex', '-1');
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${this.escapeHtml(entry.title)}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ${this.renderEntryDetails(entry)}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        
        modal.addEventListener('hidden.bs.modal', () => {
            modal.remove();
        });
    }

    /**
     * Рендеринг детальной информации о записи
     * @param {Object} entry - Данные записи
     * @returns {string} - HTML
     */
    renderEntryDetails(entry) {
        let html = '<div class="opds-entry-details">';
        
        // Обложка
        const coverLink = entry.links.find(l => 
            l.rel === 'http://opds-spec.org/image'
        );
        if (coverLink) {
            html += `<div class="text-center mb-3">
                <img src="${this.resolveUrl(coverLink.href)}" class="img-fluid" style="max-height: 400px;" alt="${this.escapeHtml(entry.title)}">
            </div>`;
        }
        
        // Авторы
        if (entry.authors.length > 0) {
            html += `<p><strong>Автор:</strong> ${entry.authors.map(a => this.escapeHtml(a.name)).join(', ')}</p>`;
        }
        
        // Описание
        if (entry.summary) {
            html += `<div class="mb-3"><strong>Описание:</strong>`;
            if (entry.summaryType === 'html') {
                html += `<div>${this.decodeHtmlEntities(entry.summary)}</div>`;
            } else {
                html += `<p>${this.escapeHtml(entry.summary)}</p>`;
            }
            html += `</div>`;
        }
        
        // Категории
        if (entry.categories.length > 0) {
            html += `<div class="mb-3"><strong>Категории:</strong> `;
            entry.categories.forEach(cat => {
                html += `<span class="badge bg-secondary me-1">${this.escapeHtml(cat.label || cat.term)}</span>`;
            });
            html += `</div>`;
        }
        
        // Ссылки на скачивание
        const downloadLinks = entry.links.filter(l => 
            l.rel === 'http://opds-spec.org/acquisition' ||
            l.rel === 'http://opds-spec.org/acquisition/open-access'
        );
        
        if (downloadLinks.length > 0) {
            html += `<div class="mb-3"><strong>Доступные форматы:</strong><br>`;
            downloadLinks.forEach(link => {
                const typeLabel = this.getMimeTypeLabel(link.type);
                html += `<a href="${this.resolveUrl(link.href)}" class="btn btn-primary btn-sm me-2 mb-2" target="_blank">${this.escapeHtml(typeLabel)}</a>`;
            });
            html += `</div>`;
        }
        
        html += '</div>';
        return html;
    }

    /**
     * Получение метки для MIME типа
     * @param {string} mimeType - MIME тип
     * @returns {string} - Метка
     */
    getMimeTypeLabel(mimeType) {
        const labels = {
            'application/fb2+zip': 'FB2',
            'application/epub+zip': 'EPUB',
            'application/pdf': 'PDF',
            'application/x-mobipocket-ebook': 'MOBI',
            'text/html': 'HTML',
            'text/plain': 'TXT'
        };
        return labels[mimeType] || mimeType;
    }

    /**
     * Рендеринг пагинации
     * @param {Object} navigation - Данные навигации
     */
    renderPagination(navigation) {
        const paginationDiv = document.getElementById('opds-viewer-pagination');
        paginationDiv.innerHTML = '';
        
        if (!navigation.first && !navigation.previous && !navigation.next && !navigation.last) {
            return;
        }
        
        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Навигация по страницам');
        
        const ul = document.createElement('ul');
        ul.className = 'pagination justify-content-center';
        
        // Первая
        if (navigation.first) {
            const li = this.createPaginationItem('Первая', navigation.first, false);
            ul.appendChild(li);
        }
        
        // Предыдущая
        if (navigation.previous) {
            const li = this.createPaginationItem('Предыдущая', navigation.previous, false);
            ul.appendChild(li);
        }
        
        // Следующая
        if (navigation.next) {
            const li = this.createPaginationItem('Следующая', navigation.next, false);
            ul.appendChild(li);
        }
        
        // Последняя
        if (navigation.last) {
            const li = this.createPaginationItem('Последняя', navigation.last, false);
            ul.appendChild(li);
        }
        
        nav.appendChild(ul);
        paginationDiv.appendChild(nav);
    }

    /**
     * Создание элемента пагинации
     * @param {string} label - Метка
     * @param {string} href - URL
     * @param {boolean} active - Активна ли
     * @returns {Element} - DOM элемент
     */
    createPaginationItem(label, href, active) {
        const li = document.createElement('li');
        li.className = 'page-item' + (active ? ' active' : '');
        
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.textContent = label;
        a.addEventListener('click', (e) => {
            e.preventDefault();
            this.loadFeed(this.resolveUrl(href));
        });
        
        li.appendChild(a);
        return li;
    }

    /**
     * Разрешение относительного URL
     * @param {string} url - URL (может быть относительным)
     * @returns {string} - Абсолютный URL
     */
    resolveUrl(url) {
        if (!url) return '';
        
        // Абсолютный URL
        if (url.startsWith('http://') || url.startsWith('https://')) {
            return url;
        }
        
        // Абсолютный путь от корня сайта
        if (url.startsWith('/')) {
            // Если baseUrl пустой, используем текущий origin
            if (!this.baseUrl) {
                return window.location.origin + url;
            }
            return this.baseUrl + url;
        }
        
        // Относительный URL - используем текущий базовый URL
        // Получаем базовый путь из currentUrl
        try {
            const currentUrlObj = new URL(this.currentUrl, window.location.origin);
            const resolvedUrl = new URL(url, currentUrlObj.href);
            return resolvedUrl.href;
        } catch (e) {
            // Fallback для старых браузеров или невалидных URL
            const base = this.currentUrl.substring(0, this.currentUrl.lastIndexOf('/') + 1);
            return base + url;
        }
    }

    /**
     * Экранирование HTML
     * @param {string} text - Текст
     * @returns {string} - Экранированный текст
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Обработка поиска
     */
    handleSearch() {
        const searchInput = document.getElementById('opds-search-input');
        const query = searchInput.value.trim();
        
        if (!query) {
            return;
        }
        
        const searchUrl = this.opdsBaseUrl + '/search?q=' + encodeURIComponent(query);
        this.loadFeed(searchUrl);
    }

    /**
     * Переход назад
     */
    goBack() {
        if (this.history.length > 1) {
            this.history.pop(); // Удаляем текущий
            const previousUrl = this.history[this.history.length - 1];
            this.history.pop(); // Удаляем предыдущий из истории, чтобы при загрузке он добавился обратно
            this.loadFeed(previousUrl);
        }
    }

    /**
     * Переход на главную
     */
    goHome() {
        this.history = [];
        this.loadFeed(this.opdsBaseUrl + '/');
    }

    /**
     * Обновление кнопок навигации
     */
    updateNavigationButtons() {
        const backBtn = document.getElementById('opds-viewer-back');
        backBtn.style.display = this.history.length > 1 ? 'inline-block' : 'none';
    }

    /**
     * Показ индикатора загрузки
     */
    showLoading() {
        document.getElementById('opds-viewer-loading').style.display = 'block';
        document.getElementById('opds-viewer-content').style.display = 'none';
    }

    /**
     * Скрытие индикатора загрузки
     */
    hideLoading() {
        document.getElementById('opds-viewer-loading').style.display = 'none';
        document.getElementById('opds-viewer-content').style.display = 'block';
    }

    /**
     * Показ ошибки
     * @param {string} message - Сообщение об ошибке
     */
    showError(message) {
        const errorDiv = document.getElementById('opds-viewer-error');
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }

    /**
     * Скрытие ошибки
     */
    hideError() {
        document.getElementById('opds-viewer-error').style.display = 'none';
    }
}
