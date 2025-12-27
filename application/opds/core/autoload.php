<?php
/**
 * Автозагрузка классов OPDS
 */
spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/';
    
    // Базовые классы
    $coreClasses = [
        'OPDSLink' => $baseDir . 'OPDSLink.php',
        'OPDSEntry' => $baseDir . 'OPDSEntry.php',
        'OPDSFeed' => $baseDir . 'OPDSFeed.php',
        'OPDSVersion' => $baseDir . 'OPDSVersion.php',
        'OPDSNavigation' => $baseDir . 'OPDSNavigation.php',
        'OPDSFacet' => $baseDir . 'OPDSFacet.php',
        'OPDSFeedFactory' => $baseDir . 'OPDSFeedFactory.php',
        'OPDSCache' => $baseDir . 'OPDSCache.php',
        'OPDSGroup' => $baseDir . 'OPDSGroup.php',
        'OPDSErrorHandler' => $baseDir . 'OPDSErrorHandler.php',
        'OPDSValidator' => $baseDir . 'OPDSValidator.php',
        'OPDSCollation' => $baseDir . 'OPDSCollation.php',
    ];
    
    if (isset($coreClasses[$class])) {
        require_once $coreClasses[$class];
        return;
    }
    
    // Сервисы
    $servicesPath = $baseDir . '../services/';
    $serviceClasses = [
        'OPDSService' => $servicesPath . 'OPDSService.php',
        'OPDSFeedService' => $servicesPath . 'OPDSFeedService.php',
        'OPDSBookService' => $servicesPath . 'OPDSBookService.php',
        'OPDSNavigationService' => $servicesPath . 'OPDSNavigationService.php',
    ];
    
    if (isset($serviceClasses[$class])) {
        require_once $serviceClasses[$class];
        return;
    }
    
    // Класс реализации OPDS 1.2
    $versionClasses = [
        'OPDS2Feed' => $baseDir . '../v2/OPDS2Feed.php',
    ];
    
    if (isset($versionClasses[$class])) {
        require_once $versionClasses[$class];
        return;
    }
});
