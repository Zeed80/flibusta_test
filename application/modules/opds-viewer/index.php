<?php
declare(strict_types=1);

// Стили и скрипты подключаются в renderer.php
echo <<< __HTML
<div id="opds-viewer-container" class="opds-viewer">
    <div class="opds-viewer-header">
        <h2>OPDS Каталог</h2>
        <div class="opds-viewer-controls">
            <button id="opds-viewer-back" class="btn btn-secondary btn-sm" style="display:none;">
                <i class="fas fa-arrow-left"></i> Назад
            </button>
            <button id="opds-viewer-home" class="btn btn-primary btn-sm">
                <i class="fas fa-home"></i> Главная
            </button>
        </div>
    </div>
    
    <div id="opds-viewer-breadcrumbs" class="opds-viewer-breadcrumbs"></div>
    
    <div id="opds-viewer-search" class="opds-viewer-search mb-3">
        <form id="opds-search-form" class="d-flex">
            <input type="text" id="opds-search-input" class="form-control" placeholder="Поиск книг..." aria-label="Поиск">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="fas fa-search"></i> Найти
            </button>
        </form>
    </div>
    
    <div id="opds-viewer-loading" class="opds-viewer-loading text-center" style="display:none;">
        <div class="spinner-border" role="status">
            <span class="visually-hidden">Загрузка...</span>
        </div>
        <p>Загрузка каталога...</p>
    </div>
    
    <div id="opds-viewer-error" class="opds-viewer-error alert alert-danger" style="display:none;"></div>
    
    <div id="opds-viewer-content" class="opds-viewer-content">
        <!-- Контент будет добавлен через JavaScript -->
    </div>
    
    <div id="opds-viewer-pagination" class="opds-viewer-pagination mt-3"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof OpdsViewer !== 'undefined') {
        const viewer = new OpdsViewer('opds-viewer-container', '$webroot');
        viewer.init();
    } else {
        console.error('OpdsViewer class not found');
        document.getElementById('opds-viewer-error').textContent = 'Ошибка загрузки OPDS клиента';
        document.getElementById('opds-viewer-error').style.display = 'block';
    }
});
</script>
__HTML;
