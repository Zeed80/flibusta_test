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
    ];
    
    if (isset($coreClasses[$class])) {
        require_once $coreClasses[$class];
        return;
    }
    
    // Версионные классы
    $versionClasses = [
        'OPDS1Feed' => $baseDir . '../v1/OPDS1Feed.php',
        'OPDS2Feed' => $baseDir . '../v2/OPDS2Feed.php',
    ];
    
    if (isset($versionClasses[$class])) {
        require_once $versionClasses[$class];
        return;
    }
});
